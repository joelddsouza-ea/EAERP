<script setup lang="ts">
/**
 * Pickup, drop and home visit on the job card: who does it, when, where, and the
 * optional charge. Advisors book a drop for any job ("Arrange drop"), give trips to a
 * driver (home visits to a technician), reschedule or cancel them before they start.
 */
import { ref, computed, onMounted, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { TruckIcon, HomeIcon, MapPinIcon } from '@heroicons/vue/20/solid'
import { tripsApi, slotText, TRIP_STATUS_LABELS, type JobTrips, type Trip, type TripKind, type TripForm } from '@/api/trips.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const props = defineProps<{ jobCardId: number; jobStatus: string }>()
const emit = defineEmits<{ changed: [] }>()

const router = useRouter()
const toast = useToast()
const { can } = usePermissions()

const data = ref<JobTrips | null>(null)
const loading = ref(true)
const saving = ref(false)

const editing = ref<{ trip: Trip | null; kind: TripKind } | null>(null)
const form = reactive<TripForm & { addressId: number | '' }>({ addressId: '' })
const cancelling = ref<Trip | null>(null)
const cancelReason = ref('')

const canManage = computed(() => can('workshop.trips.manage'))
const closed = computed(() => ['delivered', 'cancelled'].includes(props.jobStatus))
const trips = computed(() => data.value?.trips ?? [])
const canArrangeDrop = computed(() =>
  canManage.value && !closed.value && data.value?.service_mode !== 'home'
  && !trips.value.some((t) => t.kind === 'drop' && t.status !== 'cancelled'))

/** Drivers for pickups / drops; technicians for home visits */
const people = computed(() => {
  const kind = editing.value?.kind
  return (data.value?.people ?? []).filter((p) => (kind === 'home_visit' ? !p.is_driver : p.is_driver))
})

async function load() {
  loading.value = true
  try {
    data.value = (await tripsApi.forJob(props.jobCardId)).data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

function open(trip: Trip | null, kind: TripKind) {
  editing.value = { trip, kind }
  const base = trip ?? trips.value.find((t) => t.address) ?? null
  Object.assign(form, {
    addressId: '',
    assigned_to: trip?.assigned_to ?? null,
    address: base?.address ?? data.value?.addresses[0]?.address ?? '',
    landmark: base?.landmark ?? data.value?.addresses[0]?.landmark ?? '',
    maps_url: base?.maps_url ?? data.value?.addresses[0]?.maps_url ?? '',
    lat: base?.lat ?? null,
    lng: base?.lng ?? null,
    slot_date: trip?.slot_date ?? new Date().toISOString().slice(0, 10),
    slot_from: trip?.slot_from ?? '',
    slot_to: trip?.slot_to ?? '',
    with_charge: (trip?.charge ?? 0) > 0,
    save_address: false,
  })
}

function pickAddress() {
  const a = data.value?.addresses.find((x) => x.id === form.addressId)
  if (a) Object.assign(form, { address: a.address, landmark: a.landmark, maps_url: a.maps_url, lat: a.lat, lng: a.lng })
}

const started = computed(() => !!editing.value?.trip && !['unassigned', 'scheduled'].includes(editing.value.trip.status))

async function save() {
  if (!editing.value) return
  const { addressId, ...rest } = form
  void addressId
  const payload: TripForm = {
    ...rest,
    slot_from: rest.slot_from || null,
    slot_to: rest.slot_to || null,
    maps_url: rest.maps_url || null,
  }
  saving.value = true
  try {
    if (editing.value.trip) await tripsApi.update(editing.value.trip.id, payload)
    else await tripsApi.create(props.jobCardId, editing.value.kind, payload)
    toast.success('Trip saved')
    editing.value = null
    await load()
    emit('changed')
  } catch (err) {
    toast.apiError(err)
  } finally {
    saving.value = false
  }
}

async function cancel() {
  if (!cancelling.value || !cancelReason.value.trim()) {
    toast.error('Reason', 'Say why the trip is cancelled.')
    return
  }
  saving.value = true
  try {
    await tripsApi.cancel(cancelling.value.id, cancelReason.value.trim())
    toast.success('Trip cancelled')
    cancelling.value = null
    cancelReason.value = ''
    await load()
    emit('changed')
  } catch (err) {
    toast.apiError(err)
  } finally {
    saving.value = false
  }
}

const statusVariant = (t: Trip) => (t.status === 'done' ? 'success' : t.status === 'cancelled' ? 'danger' : t.status === 'unassigned' ? 'warning' : 'info')
const chargeFor = (kind: TripKind) => data.value?.charges[kind] ?? 0

onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <Card v-if="loading || trips.length || canArrangeDrop">
    <template #header>
      <div class="flex items-center justify-between gap-2">
        <h3 class="text-lg font-semibold">
          {{ data?.service_mode === 'home' ? 'Home visit' : data?.service_mode === 'pickup_drop' ? 'Pickup & drop' : 'Drop' }}
        </h3>
        <Button v-if="canArrangeDrop" size="sm" variant="secondary" @click="open(null, 'drop')">Arrange drop</Button>
      </div>
    </template>

    <div v-if="loading" class="flex justify-center py-4"><Spinner /></div>

    <ul v-else class="divide-y divide-gray-100">
      <li v-for="t in trips" :key="t.id" class="py-3 first:pt-0 last:pb-0">
        <div class="flex items-start justify-between gap-2">
          <div class="flex items-start gap-2">
            <HomeIcon v-if="t.kind === 'home_visit'" class="h-5 w-5 mt-0.5 text-indigo-500" />
            <TruckIcon v-else class="h-5 w-5 mt-0.5" :class="t.kind === 'drop' ? 'text-green-600' : 'text-blue-500'" />
            <div class="text-sm">
              <p class="font-medium text-gray-900">{{ t.label }} <span v-if="t.charge > 0" class="text-gray-500 font-normal">· Rs {{ t.charge }}</span></p>
              <p class="text-gray-600">{{ t.assignee?.name ?? 'Not given to anyone yet' }} · {{ slotText(t) }}</p>
              <p v-if="t.address" class="text-gray-500 flex items-start gap-1"><MapPinIcon class="h-4 w-4 mt-0.5 shrink-0" />{{ t.address }}</p>
              <p v-if="t.cancel_reason" class="text-red-600">{{ t.cancel_reason }}</p>
            </div>
          </div>
          <Badge :variant="statusVariant(t)" size="sm">{{ TRIP_STATUS_LABELS[t.status] }}</Badge>
        </div>

        <div class="mt-2 flex flex-wrap gap-2 pl-7">
          <Button v-if="t.status !== 'cancelled'" size="sm" variant="ghost" @click="router.push(`/trips/${t.id}`)">Open</Button>
          <template v-if="canManage && ['unassigned', 'scheduled', 'en_route', 'collected', 'arrived'].includes(t.status)">
            <Button size="sm" variant="secondary" @click="open(t, t.kind)">
              {{ ['unassigned', 'scheduled'].includes(t.status) ? (t.assigned_to ? 'Change' : 'Assign') : 'Charge / notes' }}
            </Button>
            <Button v-if="['unassigned', 'scheduled'].includes(t.status)" size="sm" variant="ghost" @click="cancelling = t">Cancel</Button>
          </template>
        </div>
      </li>
    </ul>

    <!-- Book / change a trip -->
    <Modal :open="!!editing" :title="editing?.trip ? `${editing.trip.label}` : 'Arrange drop'" @close="editing = null">
      <div v-if="editing" class="space-y-3 text-sm">
        <template v-if="!started">
          <label class="block">
            <span class="text-gray-600">{{ editing.kind === 'home_visit' ? 'Technician' : 'Driver' }}</span>
            <select v-model="form.assigned_to" class="input mt-1">
              <option :value="null">Not yet</option>
              <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </label>
          <div class="grid grid-cols-3 gap-2">
            <label class="block col-span-3 sm:col-span-1">
              <span class="text-gray-600">Date</span>
              <input v-model="form.slot_date" type="date" class="input mt-1" />
            </label>
            <label class="block">
              <span class="text-gray-600">From</span>
              <input v-model="form.slot_from" type="time" class="input mt-1" />
            </label>
            <label class="block">
              <span class="text-gray-600">To</span>
              <input v-model="form.slot_to" type="time" class="input mt-1" />
            </label>
          </div>
          <label v-if="data?.addresses.length" class="block">
            <span class="text-gray-600">Saved address</span>
            <select v-model="form.addressId" class="input mt-1" @change="pickAddress">
              <option value="">Choose…</option>
              <option v-for="a in data.addresses" :key="a.id" :value="a.id">{{ a.label ? `${a.label}: ` : '' }}{{ a.address }}</option>
            </select>
          </label>
          <label class="block">
            <span class="text-gray-600">Address</span>
            <textarea v-model="form.address" rows="2" class="input mt-1" />
          </label>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <label class="block">
              <span class="text-gray-600">Landmark</span>
              <input v-model="form.landmark" class="input mt-1" />
            </label>
            <label class="block">
              <span class="text-gray-600">Google Maps link (from the customer's location)</span>
              <input v-model="form.maps_url" type="url" class="input mt-1" placeholder="https://maps.app.goo.gl/…" />
            </label>
          </div>
          <label class="flex items-center gap-2">
            <input v-model="form.save_address" type="checkbox" class="rounded border-gray-300" />
            Save this address for the customer
          </label>
        </template>
        <label class="flex items-center gap-2">
          <input v-model="form.with_charge" type="checkbox" class="rounded border-gray-300" />
          Charge for this {{ editing.kind === 'home_visit' ? 'home visit' : editing.kind }}
          <span class="text-gray-500">(Rs {{ editing.trip && editing.trip.charge > 0 ? editing.trip.charge : chargeFor(editing.kind) }}, incl. GST, on the bill)</span>
        </label>
        <label class="block">
          <span class="text-gray-600">Notes for the {{ editing.kind === 'home_visit' ? 'technician' : 'driver' }}</span>
          <textarea v-model="form.notes" rows="2" class="input mt-1" />
        </label>
      </div>
      <template #footer>
        <div class="flex justify-end gap-2">
          <Button variant="secondary" @click="editing = null">Close</Button>
          <Button :loading="saving" @click="save">Save</Button>
        </div>
      </template>
    </Modal>

    <Modal :open="!!cancelling" title="Cancel trip" size="sm" @close="cancelling = null">
      <p v-if="cancelling && cancelling.kind !== 'drop'" class="text-sm text-gray-600 mb-2">
        The job then waits for the car at the workshop{{ cancelling.kind === 'home_visit' ? ' and becomes a workshop job' : '' }}.
      </p>
      <textarea v-model="cancelReason" rows="3" class="input" placeholder="Reason (logged)" />
      <template #footer>
        <div class="flex justify-end gap-2">
          <Button variant="secondary" @click="cancelling = null">Keep</Button>
          <Button variant="danger" :loading="saving" @click="cancel">Cancel trip</Button>
        </div>
      </template>
    </Modal>
  </Card>
</template>
