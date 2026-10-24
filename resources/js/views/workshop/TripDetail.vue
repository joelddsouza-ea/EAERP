<script setup lang="ts">
/**
 * One pickup, drop or home visit, step by step, for the driver / home technician:
 *  - pickup: Start → photos/videos (no limit), odometer, fuel → Collected → Reached workshop.
 *    This is the job's intake: the advisor does not repeat it.
 *  - drop: Start (needs the gate pass; the car leaves) → front/rear/left/right photos → Delivered.
 *  - home visit: Start → Arrived → intake photos + readings → job sheet → payment → closing photos → Close visit.
 */
import { ref, computed, onMounted, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  PhoneIcon,
  MapIcon,
  CameraIcon,
  VideoCameraIcon,
  CheckCircleIcon,
  ArrowLeftIcon,
  ClipboardDocumentListIcon,
} from '@heroicons/vue/20/solid'
import { tripsApi, slotText, FUEL_LEVELS, TRIP_STATUS_LABELS, type TripDetail, type FuelLevel } from '@/api/trips.api'
import type { JobCardMedia } from '@/api/types'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'
import Modal from '@/components/ui/Modal.vue'
import VoiceInput from '@/components/ui/VoiceInput.vue'
import MediaStrip from '@/components/jobsheet/MediaStrip.vue'
import { prepareMedia } from '@/components/jobsheet/media'
import SendUpdateButton from '@/components/jobcard/SendUpdateButton.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const trip = ref<TripDetail | null>(null)
const loading = ref(true)
const busy = ref(false)
const uploadPercent = ref<number | null>(null)

const form = reactive({
  km_reading: '' as string | number,
  fuel_level: '' as FuelLevel | '',
  notes: '',
  handover_name: '',
})
const pay = reactive({ method: 'cash', amount: '' as string | number, reference_number: '' })
const turnBackOpen = ref(false)
const turnBackReason = ref('')

const photoInput = ref<HTMLInputElement | null>(null)
const videoInput = ref<HTMLInputElement | null>(null)
const sideInput = ref<HTMLInputElement | null>(null)
let pending: { purpose: 'intake' | 'handover'; side?: string } = { purpose: 'intake' }

const id = computed(() => Number(route.params.id))
const kind = computed(() => trip.value?.kind)
const status = computed(() => trip.value?.status)
const canDrive = computed(() => !!trip.value?.can_drive)

const intakeOk = computed(() => {
  const i = trip.value?.intake
  return !!i && i.counts.photo >= i.min_photos && i.counts.video >= i.min_videos
})
const handoverOk = computed(() => !!trip.value?.handover && trip.value.handover.photos >= trip.value.handover.min_photos)
const sidesTaken = computed(() => new Set((trip.value?.handover?.media ?? []).map((m) => m.tag).filter(Boolean)))

/** Pickup on the way / collected, or home visit at the customer's (before work starts): intake photos */
const showIntake = computed(() => {
  if (!trip.value?.intake) return false
  if (kind.value === 'pickup') return ['en_route', 'collected'].includes(status.value!)
  return status.value === 'arrived' && trip.value.intake.editable
})
const showHandover = computed(() => (kind.value === 'drop' && status.value === 'en_route') || (kind.value === 'home_visit' && status.value === 'arrived'))

async function load() {
  loading.value = true
  try {
    apply((await tripsApi.get(id.value)).data.data)
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

function apply(data: TripDetail) {
  trip.value = data
  if (data.intake) {
    form.km_reading = form.km_reading || (data.intake.km_reading ?? '')
    form.fuel_level = form.fuel_level || (data.intake.fuel_level ?? '')
  }
  form.handover_name ||= data.job?.customer?.name ?? ''
  if (data.bill && data.bill.balance > 0 && !pay.amount) pay.amount = data.bill.balance
}

async function run(action: () => Promise<{ data: { data: TripDetail; message?: string } }>, done?: string): Promise<boolean> {
  busy.value = true
  try {
    const response = await action()
    apply(response.data.data)
    toast.success(done ?? response.data.message ?? 'Saved')
    return true
  } catch (err) {
    toast.apiError(err)
    return false
  } finally {
    busy.value = false
  }
}

const start = () => run(() => tripsApi.step(id.value, 'start'))
const arrive = () => run(() => tripsApi.step(id.value, 'arrive'))
const reachWorkshop = () => run(() => tripsApi.step(id.value, 'reach-workshop'))

function collect() {
  if (form.km_reading === '' || !form.fuel_level) {
    toast.error('Odometer and fuel', 'Enter the odometer reading and the fuel level.')
    return
  }
  run(() => tripsApi.collect(id.value, {
    km_reading: Number(form.km_reading),
    fuel_level: form.fuel_level as FuelLevel,
    notes: form.notes.trim() || null,
    handover_name: form.handover_name.trim() || null,
  }))
}

function saveReadings() {
  if (form.km_reading === '' || !form.fuel_level) {
    toast.error('Odometer and fuel', 'Enter the odometer reading and the fuel level.')
    return
  }
  run(() => tripsApi.readings(id.value, { km_reading: Number(form.km_reading), fuel_level: form.fuel_level as FuelLevel }))
}

function finish() {
  run(() => tripsApi.finish(id.value, kind.value === 'drop' ? 'deliver' : 'close', {
    notes: form.notes.trim() || null,
    handover_name: form.handover_name.trim() || null,
  }))
}

async function collectPayment() {
  if (!Number(pay.amount)) {
    toast.error('Amount', 'Enter the amount received.')
    return
  }
  const ok = await run(() => tripsApi.payment(id.value, {
    method: pay.method,
    amount: Number(pay.amount),
    reference_number: pay.reference_number.trim() || null,
  }))
  if (ok) {
    pay.amount = ''
    pay.reference_number = ''
  }
}

async function turnBack() {
  if (!turnBackReason.value.trim()) {
    toast.error('Reason', 'Say why you are turning back.')
    return
  }
  if (await run(() => tripsApi.turnBack(id.value, turnBackReason.value.trim()))) {
    turnBackOpen.value = false
    turnBackReason.value = ''
  }
}

// ------------------------------------------------------------------ media

function capture(input: HTMLInputElement | null, purpose: 'intake' | 'handover', side?: string) {
  pending = { purpose, side }
  input?.click()
}

async function onFiles(event: Event) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''
  const { purpose, side } = pending

  for (const file of files) {
    try {
      const prepared = await prepareMedia(file)
      const data = new FormData()
      data.append('file', prepared.file, prepared.fileName)
      if (prepared.poster) data.append('poster', prepared.poster, 'poster.jpg')
      if (prepared.durationSeconds != null) data.append('duration_seconds', String(prepared.durationSeconds))
      data.append('captured_at', new Date().toISOString())
      data.append('purpose', purpose)
      if (side) data.append('side', side)

      uploadPercent.value = 0
      apply((await tripsApi.upload(id.value, data, (p) => (uploadPercent.value = p))).data.data.trip)
    } catch (err: any) {
      if (err instanceof Error && !('response' in err)) toast.error('Cannot upload', err.message)
      else toast.apiError(err)
    } finally {
      uploadPercent.value = null
    }
  }
}

async function removeMedia(media: JobCardMedia) {
  try {
    apply((await tripsApi.removeMedia(id.value, media.id)).data.data)
  } catch (err) {
    toast.apiError(err)
  }
}

const money = (n: number | null | undefined) => `Rs ${Number(n ?? 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`
const whatsapp = computed(() => {
  const phone = (trip.value?.job?.customer?.whatsapp_phone || trip.value?.job?.customer?.phone || '').replace(/\D/g, '')
  return phone ? `https://wa.me/${phone.length === 10 ? `91${phone}` : phone}` : null
})

onMounted(load)
</script>

<template>
  <div class="max-w-2xl mx-auto pb-24">
    <button type="button" class="mb-3 inline-flex items-center gap-1 text-sm text-gray-600" @click="router.push('/trips')">
      <ArrowLeftIcon class="h-4 w-4" /> My Trips
    </button>

    <div v-if="loading && !trip" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <template v-else-if="trip">
      <!-- Who / where -->
      <Card class="mb-4">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ trip.label }} · {{ trip.job?.job_number }}</p>
            <h1 class="text-2xl font-bold tracking-wide text-gray-900">{{ trip.job?.vehicle?.registration_no }}</h1>
            <p class="text-sm text-gray-600">{{ trip.job?.vehicle?.name }}<span v-if="trip.job?.vehicle?.color"> · {{ trip.job.vehicle.color }}</span></p>
          </div>
          <Badge :variant="status === 'done' ? 'success' : status === 'cancelled' ? 'danger' : 'info'">{{ TRIP_STATUS_LABELS[trip.status] }}</Badge>
        </div>

        <div class="mt-3 text-sm text-gray-700 space-y-1">
          <p><span class="text-gray-500">Customer:</span> {{ trip.job?.customer?.name }}</p>
          <p><span class="text-gray-500">When:</span> {{ slotText(trip) }}</p>
          <p v-if="trip.address"><span class="text-gray-500">Address:</span> {{ trip.address }}<span v-if="trip.landmark"> ({{ trip.landmark }})</span></p>
          <p v-if="trip.notes" class="whitespace-pre-wrap"><span class="text-gray-500">Notes:</span> {{ trip.notes }}</p>
        </div>

        <div class="mt-4 grid grid-cols-3 gap-2">
          <a v-if="trip.job?.customer?.phone" :href="`tel:${trip.job.customer.phone}`" class="flex flex-col items-center justify-center gap-1 rounded-lg border border-gray-200 bg-white py-2 text-xs font-medium text-gray-700 active:bg-gray-50">
            <PhoneIcon class="h-5 w-5" /> Call
          </a>
          <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noopener" class="flex flex-col items-center justify-center gap-1 rounded-lg border border-gray-200 bg-white py-2 text-xs font-medium text-gray-700 active:bg-gray-50">
            <span class="text-base leading-5">💬</span> WhatsApp
          </a>
          <a v-if="trip.navigate_url" :href="trip.navigate_url" target="_blank" rel="noopener" class="flex flex-col items-center justify-center gap-1 rounded-lg border border-gray-200 bg-white py-2 text-xs font-medium text-gray-700 active:bg-gray-50">
            <MapIcon class="h-5 w-5" /> Navigate
          </a>
        </div>

        <!-- After a step: the advisor gets the customer's WhatsApp update ready to send -->
        <div v-if="canDrive && trip.job && status !== 'scheduled'" class="mt-3 flex justify-end">
          <SendUpdateButton :key="`${trip.job.status}-${status}`" :job-card-id="trip.job.id" kind="stage" :label="`Send update: ${trip.job.status_label}`" />
        </div>
      </Card>

      <p v-if="!canDrive" class="mb-4 text-sm text-gray-500">View only: this trip is given to {{ trip.assignee?.name ?? 'nobody yet' }}.</p>

      <!-- Start -->
      <Card v-if="canDrive && status === 'scheduled'" class="mb-4">
        <template v-if="kind === 'drop' && !trip.gate_pass">
          <p class="text-sm text-amber-700">Waiting for the gate pass: the car leaves only after the bill is paid (or the owner / manager releases it).</p>
          <Button class="mt-3" variant="secondary" block @click="load">Check again</Button>
        </template>
        <template v-else>
          <p class="text-sm text-gray-600 mb-3">
            {{ kind === 'drop' ? `Gate pass ${trip.gate_pass?.number}. Starting lets the car out of the workshop.` : 'Tap Start when you leave; the customer is told you are on the way.' }}
          </p>
          <Button size="lg" block :loading="busy" @click="start">Start {{ trip.label.toLowerCase() }}</Button>
        </template>
      </Card>

      <!-- Home visit: arrived -->
      <Card v-if="canDrive && kind === 'home_visit' && status === 'en_route'" class="mb-4">
        <Button size="lg" block :loading="busy" @click="arrive">Arrived at the customer</Button>
        <button type="button" class="mt-3 w-full text-sm text-gray-500 underline" @click="turnBackOpen = true">Turn back</button>
      </Card>

      <!-- Intake: pickup photos/videos and readings (no upper limit) -->
      <Card v-if="showIntake && trip.intake" class="mb-4">
        <template #header>
          <div class="flex items-center justify-between gap-2">
            <h3 class="text-lg font-semibold">Car condition</h3>
            <span class="inline-flex items-center gap-1 text-xs font-medium" :class="intakeOk ? 'text-green-700' : 'text-amber-700'">
              <CheckCircleIcon v-if="intakeOk" class="h-4 w-4" />
              Photos {{ trip.intake.counts.photo }}/{{ trip.intake.min_photos }}+ · Videos {{ trip.intake.counts.video }}/{{ trip.intake.min_videos }}+
            </span>
          </div>
        </template>
        <p class="text-xs text-gray-500 mb-3">
          Take as many as you can: all four sides, every scratch and dent, wheels, interior, dashboard with the odometer and fuel,
          the boot, and valuables left in the car. These are the job's intake — the advisor does not repeat them.
        </p>

        <MediaStrip :media="trip.intake.media" :can-delete="canDrive" :job-card-id="trip.job?.id" @delete="removeMedia" />

        <div v-if="canDrive" class="mt-3 grid grid-cols-2 gap-2">
          <Button variant="secondary" size="lg" :disabled="uploadPercent !== null" @click="capture(photoInput, 'intake')">
            <CameraIcon class="h-5 w-5 mr-1" /> Photos
          </Button>
          <Button variant="secondary" size="lg" :disabled="uploadPercent !== null" @click="capture(videoInput, 'intake')">
            <VideoCameraIcon class="h-5 w-5 mr-1" /> Video
          </Button>
        </div>
        <p v-if="uploadPercent !== null" class="mt-2 text-xs text-gray-500">Uploading… {{ uploadPercent }}%</p>

        <!-- Odometer / fuel -->
        <div v-if="canDrive && (kind === 'home_visit' || status === 'en_route')" class="mt-4 grid grid-cols-2 gap-3">
          <label class="text-sm">
            <span class="text-gray-600">Odometer (km)</span>
            <input v-model="form.km_reading" type="number" inputmode="numeric" min="0" class="input mt-1" />
          </label>
          <label class="text-sm">
            <span class="text-gray-600">Fuel</span>
            <select v-model="form.fuel_level" class="input mt-1">
              <option value="" disabled>Choose…</option>
              <option v-for="f in FUEL_LEVELS" :key="f.value" :value="f.value">{{ f.label }}</option>
            </select>
          </label>
        </div>
        <Button v-if="canDrive && kind === 'home_visit'" class="mt-3" variant="secondary" block :loading="busy" @click="saveReadings">Save readings</Button>
      </Card>

      <!-- Pickup: leave with the car -->
      <Card v-if="canDrive && kind === 'pickup' && status === 'en_route'" class="mb-4">
        <label class="block text-sm">
          <span class="text-gray-600">How the car drove / anything the customer said</span>
          <div class="mt-1 flex items-start gap-2">
            <textarea v-model="form.notes" rows="2" class="input flex-1" placeholder="e.g. brakes squeal, AC weak" />
            <VoiceInput @text="(t: string) => (form.notes = form.notes ? `${form.notes} ${t}` : t)" />
          </div>
        </label>
        <label class="block text-sm mt-3">
          <span class="text-gray-600">Handed over by</span>
          <input v-model="form.handover_name" class="input mt-1" />
        </label>
        <Button class="mt-4" size="lg" block :loading="busy" :disabled="!intakeOk" @click="collect">Collected — leaving with the car</Button>
        <p v-if="!intakeOk" class="mt-2 text-xs text-amber-700">Take at least the minimum photos and videos first.</p>
        <button type="button" class="mt-3 w-full text-sm text-gray-500 underline" @click="turnBackOpen = true">Turn back (car not collected)</button>
      </Card>

      <Card v-if="canDrive && kind === 'pickup' && status === 'collected'" class="mb-4">
        <p class="text-sm text-gray-600 mb-3">Drive the car to the workshop and hand over the key.</p>
        <Button size="lg" block :loading="busy" @click="reachWorkshop">Reached workshop</Button>
      </Card>

      <!-- Home visit: work and payment -->
      <Card v-if="kind === 'home_visit' && status === 'arrived'" class="mb-4">
        <template #header><h3 class="text-lg font-semibold">Work and payment</h3></template>
        <Button v-if="trip.job_sheet_url" variant="secondary" block @click="router.push(trip.job_sheet_url)">
          <ClipboardDocumentListIcon class="h-5 w-5 mr-1" /> Open the job sheet
        </Button>
        <p class="mt-2 text-xs text-gray-500">Job: {{ trip.job?.status_label }}. Workshop-only activities (body wash…) are already marked N/A.</p>

        <div v-if="trip.bill" class="mt-4 text-sm">
          <p v-if="trip.bill.total === null" class="text-gray-500">The bill is made by the back office after the final QC.</p>
          <template v-else>
            <p>Bill {{ money(trip.bill.total) }} · Paid {{ money(trip.bill.paid) }} ·
              <strong :class="trip.bill.balance > 0 ? 'text-amber-700' : 'text-green-700'">Balance {{ money(Math.max(0, trip.bill.balance)) }}</strong>
            </p>
            <div v-if="canDrive && trip.bill.balance > 0" class="mt-3 grid grid-cols-2 gap-2">
              <select v-model="pay.method" class="input">
                <option v-for="m in trip.bill.methods" :key="m" :value="m">{{ m.toUpperCase() }}</option>
              </select>
              <input v-model="pay.amount" type="number" inputmode="decimal" min="1" class="input" placeholder="Amount" />
              <input v-if="pay.method !== 'cash'" v-model="pay.reference_number" class="input col-span-2" placeholder="UPI / card reference" />
              <Button class="col-span-2" :loading="busy" @click="collectPayment">Record payment received</Button>
            </div>
          </template>
        </div>
      </Card>

      <!-- Handover photos: drop (4 sides) / home visit closing -->
      <Card v-if="showHandover && trip.handover" class="mb-4">
        <template #header>
          <div class="flex items-center justify-between gap-2">
            <h3 class="text-lg font-semibold">{{ kind === 'drop' ? 'Car at the drop' : 'Closing photos' }}</h3>
            <span class="text-xs font-medium" :class="handoverOk ? 'text-green-700' : 'text-amber-700'">
              Photos {{ trip.handover.photos }}/{{ trip.handover.min_photos }}
            </span>
          </div>
        </template>

        <div v-if="kind === 'drop' && canDrive" class="grid grid-cols-2 gap-2 mb-3">
          <Button
            v-for="side in trip.handover.sides"
            :key="side"
            :variant="sidesTaken.has(side) ? 'secondary' : 'primary'"
            :disabled="uploadPercent !== null"
            @click="capture(sideInput, 'handover', side)"
          >
            <CheckCircleIcon v-if="sidesTaken.has(side)" class="h-4 w-4 mr-1 text-green-600" />
            <CameraIcon v-else class="h-4 w-4 mr-1" />
            {{ side.charAt(0).toUpperCase() + side.slice(1) }}
          </Button>
        </div>

        <MediaStrip :media="trip.handover.media" :can-delete="canDrive" @delete="removeMedia" />

        <div v-if="canDrive" class="mt-3">
          <Button variant="secondary" block :disabled="uploadPercent !== null" @click="capture(photoInput, 'handover')">
            <CameraIcon class="h-5 w-5 mr-1" /> More photos
          </Button>
          <p v-if="uploadPercent !== null" class="mt-2 text-xs text-gray-500">Uploading… {{ uploadPercent }}%</p>

          <label class="block text-sm mt-4">
            <span class="text-gray-600">Received by</span>
            <input v-model="form.handover_name" class="input mt-1" />
          </label>
          <label class="block text-sm mt-3">
            <span class="text-gray-600">Notes (optional)</span>
            <textarea v-model="form.notes" rows="2" class="input mt-1" />
          </label>
          <Button class="mt-4" size="lg" block :loading="busy" :disabled="!handoverOk" @click="finish">
            {{ kind === 'drop' ? 'Delivered to the customer' : 'Close the home visit' }}
          </Button>
          <p v-if="kind === 'home_visit' && !trip.gate_pass" class="mt-2 text-xs text-amber-700">
            Closing needs the final QC done and the bill paid.
          </p>
        </div>
      </Card>

      <Card v-if="status === 'done'" class="mb-4">
        <p class="flex items-center gap-2 text-green-700 font-medium"><CheckCircleIcon class="h-5 w-5" /> Done</p>
        <p v-if="trip.handover_name" class="mt-1 text-sm text-gray-600">Handed over: {{ trip.handover_name }}</p>
      </Card>
      <Card v-if="status === 'cancelled'" class="mb-4">
        <p class="text-sm text-red-700">Cancelled<span v-if="trip.cancel_reason">: {{ trip.cancel_reason }}</span></p>
      </Card>

      <input ref="photoInput" type="file" accept="image/*" capture="environment" class="hidden" @change="onFiles" />
      <input ref="videoInput" type="file" accept="video/*" capture="environment" class="hidden" @change="onFiles" />
      <input ref="sideInput" type="file" accept="image/*" capture="environment" class="hidden" @change="onFiles" />

      <Modal :open="turnBackOpen" title="Turn back" size="sm" @close="turnBackOpen = false">
        <label class="block text-sm">
          <span class="text-gray-600">Why? (the advisor sees this)</span>
          <textarea v-model="turnBackReason" rows="3" class="input mt-1" placeholder="e.g. customer not at home, car not ready" />
        </label>
        <template #footer>
          <div class="flex justify-end gap-2">
            <Button variant="secondary" @click="turnBackOpen = false">Keep going</Button>
            <Button variant="danger" :loading="busy" @click="turnBack">Turn back</Button>
          </div>
        </template>
      </Modal>
    </template>
  </div>
</template>

