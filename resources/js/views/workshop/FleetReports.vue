<script setup lang="ts">
/**
 * Fleet reports: problems fleet drivers (and owners) reported from the app. Breakdowns first.
 * Book the cab in (drive-in or pickup from where it stands), or close it with a note; a job
 * card opened for the cab picks the report up by itself.
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { PhoneIcon, MapPinIcon, PlayIcon } from '@heroicons/vue/24/outline'
import { fleetApi, type FleetReport } from '@/api/fleet.api'
import { crmApi, type Slot } from '@/api/crm.api'
import { useAuthStore } from '@/stores/auth.store'
import { useBranchStore } from '@/stores/branch.store'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Input from '@/components/ui/Input.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const auth = useAuthStore()
const branchStore = useBranchStore()
const toast = useToast()

const status = ref<'active' | 'open' | 'booked' | 'job_opened' | 'closed' | 'all'>('active')
const customerId = computed(() => Number(route.query.customer) || undefined)
const reports = ref<FleetReport[]>([])
const loading = ref(true)
const highlight = computed(() => Number(route.query.report) || null)

async function load() {
  loading.value = true
  try {
    reports.value = (await fleetApi.reports(status.value, customerId.value)).data.data.reports
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(status, load)

// ---- book in
const booking = ref<FleetReport | null>(null)
const form = ref({ booking_date: '', slot_from: '', service_mode: 'drive_in', address: '', note: '' })
const slots = ref<Slot[]>([])
const loadingSlots = ref(false)
const busy = ref(false)

function openBook(r: FleetReport) {
  booking.value = r
  const pickup = r.kind === 'breakdown' || r.kind === 'accident'
  form.value = { booking_date: new Date().toISOString().slice(0, 10), slot_from: '', service_mode: pickup ? 'pickup_drop' : 'drive_in', address: r.location_text ?? '', note: '' }
}

watch(() => booking.value && form.value.booking_date, async (day) => {
  if (!day) return
  form.value.slot_from = ''
  loadingSlots.value = true
  try {
    slots.value = (await crmApi.availability(String(day), booking.value?.branch_id ?? branchStore.currentBranchId)).data.data
  } catch {
    slots.value = []
  } finally {
    loadingSlots.value = false
  }
})

async function book() {
  if (!booking.value) return
  busy.value = true
  try {
    await fleetApi.book(booking.value.id, {
      booking_date: form.value.booking_date,
      slot_from: form.value.slot_from || null,
      service_mode: form.value.service_mode,
      address: form.value.address || null,
      maps_url: booking.value.maps_url,
      note: form.value.note || null,
    })
    toast.success('Booked. The driver and the fleet owner are told in the app.')
    booking.value = null
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

// ---- close
const closing = ref<FleetReport | null>(null)
const closeNote = ref('')
async function close() {
  if (!closing.value) return
  busy.value = true
  try {
    await fleetApi.close(closing.value.id, closeNote.value)
    toast.success('Closed')
    closing.value = null
    closeNote.value = ''
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

const statusVariant = (s: FleetReport['status']) => (s === 'open' ? 'warning' : s === 'closed' ? 'default' : 'success')
const when = (d: string | null) => (d ? new Date(d).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '')
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Fleet Reports</h1>
        <p class="text-sm text-gray-500">Problems reported by fleet drivers and owners in the app.</p>
      </div>
      <select v-model="status" class="rounded-lg border-gray-300 text-sm">
        <option value="active">Open &amp; booked</option>
        <option value="open">Open</option>
        <option value="booked">Booked in</option>
        <option value="job_opened">At the workshop</option>
        <option value="closed">Closed</option>
        <option value="all">All</option>
      </select>
    </div>

    <div v-if="loading" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <Card v-else-if="!reports.length"><p class="text-center text-gray-500">No reports here.</p></Card>

    <div v-else class="space-y-3">
      <Card v-for="r in reports" :key="r.id" :class="[r.kind === 'breakdown' && r.status === 'open' ? 'ring-2 ring-red-500' : '', highlight === r.id ? 'ring-2 ring-primary-500' : '']">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="font-semibold text-gray-900">
              <span :class="r.kind === 'breakdown' ? 'text-red-700' : ''">{{ r.kind_label }}</span> · {{ r.vehicle.registration_no }}
            </p>
            <p class="text-sm text-gray-500">
              <RouterLink :to="`/customers/${r.customer.id}`" class="hover:text-primary-700">{{ r.customer.name }}</RouterLink>
              · {{ r.number }} · {{ when(r.created_at) }}
            </p>
          </div>
          <Badge :variant="statusVariant(r.status)">{{ r.status_label }}</Badge>
        </div>

        <p class="mt-2 text-sm text-gray-800 whitespace-pre-wrap">{{ r.description }}</p>
        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600">
          <a v-if="r.driver" :href="`tel:${r.driver.phone}`" class="inline-flex items-center gap-1 text-primary-700"><PhoneIcon class="h-4 w-4" /> {{ r.driver.name }} · {{ r.driver.phone }}</a>
          <a v-else-if="r.customer.phone" :href="`tel:${r.customer.phone}`" class="inline-flex items-center gap-1 text-primary-700"><PhoneIcon class="h-4 w-4" /> Owner · {{ r.customer.phone }}</a>
          <span v-if="r.odometer_km">{{ r.odometer_km.toLocaleString('en-IN') }} km</span>
          <a v-if="r.maps_url" :href="r.maps_url" target="_blank" class="inline-flex items-center gap-1 text-primary-700"><MapPinIcon class="h-4 w-4" /> Location</a>
          <span v-if="r.location_text">{{ r.location_text }}</span>
        </div>
        <div v-if="r.media.length" class="mt-2 flex gap-2 overflow-x-auto">
          <a v-for="m in r.media" :key="m.id" :href="m.url" target="_blank" class="relative block h-16 w-16 shrink-0 overflow-hidden rounded bg-gray-100">
            <img v-if="m.media_type === 'photo'" :src="m.url" class="h-full w-full object-cover" alt="" />
            <PlayIcon v-else class="absolute inset-0 m-auto h-6 w-6 text-gray-600" />
          </a>
        </div>

        <p v-if="r.booking" class="mt-2 text-sm text-green-800">Booking {{ r.booking.number }} · {{ r.booking.date }}<template v-if="r.booking.slot">, {{ r.booking.slot }}</template> · {{ r.booking.mode === 'pickup_drop' ? 'pickup' : r.booking.mode === 'home' ? 'home service' : 'drive-in' }}</p>
        <RouterLink v-if="r.job" :to="`/job-cards/${r.job.id}`" class="mt-2 block text-sm font-medium text-primary-700">Job {{ r.job.job_number }} ›</RouterLink>
        <p v-if="r.staff_note" class="mt-2 rounded-lg bg-gray-50 p-2 text-sm text-gray-700">{{ r.staff_note }}<span v-if="r.handled_by" class="text-gray-500"> — {{ r.handled_by }}</span></p>

        <div v-if="r.status === 'open'" class="mt-3 flex flex-wrap gap-2">
          <Button v-if="auth.hasFeature('crm')" size="sm" @click="openBook(r)">Book in</Button>
          <Button size="sm" variant="secondary" @click="$router.push(`/job-cards/create?customer_id=${r.customer.id}&vehicle_id=${r.vehicle.id}${r.driver ? `&fleet_driver_id=${r.driver.id}` : ''}`)">Open job card</Button>
          <Button size="sm" variant="ghost" @click="closing = r">Close</Button>
        </div>
      </Card>
    </div>

    <Modal :open="booking !== null" title="Book the cab in" @close="booking = null">
      <form v-if="booking" class="space-y-3" @submit.prevent="book">
        <p class="text-sm text-gray-600">{{ booking.vehicle.registration_no }} · {{ booking.kind_label }}</p>
        <div class="grid grid-cols-3 gap-2 text-sm">
          <button v-for="m in [{ k: 'drive_in', l: 'Drive-in' }, { k: 'pickup_drop', l: 'Pickup' }, { k: 'home', l: 'Home / on-site' }]" :key="m.k" type="button"
            class="rounded-lg px-2 py-2 ring-1" :class="form.service_mode === m.k ? 'bg-primary-600 text-white ring-primary-600' : 'ring-gray-200'" @click="form.service_mode = m.k">{{ m.l }}</button>
        </div>
        <Input v-model="form.booking_date" type="date" label="Day" required />
        <div>
          <label class="label">Time</label>
          <p v-if="loadingSlots" class="text-sm text-gray-500">Checking slots…</p>
          <p v-else-if="!slots.length" class="text-sm text-gray-500">No slots set for this day: booked without a time.</p>
          <div v-else class="grid grid-cols-3 sm:grid-cols-5 gap-2">
            <button v-for="s in slots" :key="s.from" type="button" :disabled="s.free < 1"
              class="rounded-lg px-2 py-2 text-center text-sm ring-1 disabled:opacity-40" :class="form.slot_from === s.from ? 'bg-primary-600 text-white ring-primary-600' : 'ring-gray-200'"
              @click="form.slot_from = s.from">{{ s.from }}</button>
          </div>
        </div>
        <Input v-if="form.service_mode !== 'drive_in'" v-model="form.address" label="Where is the cab" required />
        <Input v-model="form.note" label="Note (shown to the driver and owner)" />
        <div class="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" @click="booking = null">Cancel</Button>
          <Button type="submit" :loading="busy" :disabled="slots.length > 0 && !form.slot_from">Book</Button>
        </div>
      </form>
    </Modal>

    <Modal :open="closing !== null" title="Close the report" @close="closing = null">
      <form class="space-y-3" @submit.prevent="close">
        <Input v-model="closeNote" label="Why is it closed?" placeholder="e.g. solved on the phone, driver fixed the tyre" required />
        <div class="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" @click="closing = null">Cancel</Button>
          <Button type="submit" :loading="busy">Close report</Button>
        </div>
      </form>
    </Modal>
  </div>
</template>
