<script setup lang="ts">
/**
 * Bookings for a day (or a week): slots and how full they are, who is coming, and the
 * hand-off — "Car arrived" opens the job card form already filled in from the booking.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { crmApi, MODE_LABELS, type BookingRow, type Slot, type LeadOptions } from '@/api/crm.api'
import { localDate } from '@/api/hr.api'
import { useBranchStore } from '@/stores/branch.store'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const branchStore = useBranchStore()
const { can } = usePermissions()
const toast = useToast()

const view = ref<'day' | 'week'>('day')
const date = ref((route.query.date as string) || localDate())
const status = ref((route.query.status as string) || '')
const rows = ref<BookingRow[]>([])
const slots = ref<Slot[]>([])
const pending = ref(0)
const loading = ref(false)
const options = ref<LeadOptions | null>(null)

const weekDays = computed(() => {
  const start = new Date(date.value)
  return Array.from({ length: 7 }, (_, i) => localDate(new Date(start.getTime() + i * 86400000)))
})

async function load() {
  loading.value = true
  try {
    const params = view.value === 'day'
      ? { from: date.value, to: date.value, branch_id: branchStore.currentBranchId, status: status.value || undefined }
      : { from: weekDays.value[0], to: weekDays.value[6], branch_id: branchStore.currentBranchId, status: status.value || undefined }
    const data = (await crmApi.bookings(params)).data.data
    rows.value = data.rows
    slots.value = data.slots
    pending.value = data.pending_confirmation
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}
watch([view, date, status, () => branchStore.currentBranchId], load)
onMounted(async () => {
  load()
  options.value = (await crmApi.options()).data.data
})

const shift = (days: number) => {
  const d = new Date(date.value)
  d.setDate(d.getDate() + days)
  date.value = localDate(d)
}

const bySlot = computed(() => {
  const groups: { slot: string; rows: BookingRow[]; capacity: number | null }[] = []
  for (const s of slots.value) groups.push({ slot: `${s.from}–${s.to}`, rows: rows.value.filter((r) => r.slot_from === s.from), capacity: s.capacity })
  const loose = rows.value.filter((r) => !slots.value.some((s) => s.from === r.slot_from))
  if (loose.length) groups.push({ slot: 'Any time', rows: loose, capacity: null })
  return groups
})

const VARIANT: Record<string, 'warning' | 'info' | 'success' | 'danger' | 'default'> = {
  booked: 'warning', confirmed: 'info', arrived: 'info', converted: 'success', no_show: 'danger', cancelled: 'default',
}

async function act(fn: () => Promise<{ data: { message?: string } }>) {
  try {
    const res = await fn()
    if (res.data.message) toast.success(res.data.message)
    load()
  } catch (err) {
    toast.apiError(err)
  }
}

// ---------------------------------------------------------------- arrive
const arriving = ref<BookingRow | null>(null)
const car = ref({ registration_no: '', category_id: null as number | null })
const busy = ref(false)
function openArrive(b: BookingRow) {
  arriving.value = b
  // Blank keeps what the lead / vehicle already has
  car.value = { registration_no: '', category_id: null }
}
async function arrive() {
  if (!arriving.value) return
  busy.value = true
  try {
    const p = (await crmApi.arrive(arriving.value.id, { registration_no: car.value.registration_no || null, category_id: car.value.category_id })).data.data
    router.push({
      path: '/job-cards/create',
      query: {
        customer_id: String(p.customer_id),
        vehicle_id: String(p.vehicle_id),
        booking_id: String(p.booking_id),
        ...(p.package_id ? { package_id: String(p.package_id) } : { service_type: p.service_type }),
        ...(p.oil_type_id ? { oil_type_id: String(p.oil_type_id) } : {}),
        ...(p.complaints.length ? { complaints: p.complaints.join('\n') } : {}),
      },
    })
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

// ------------------------------------------------------- move / cancel
const moving = ref<BookingRow | null>(null)
const move = ref({ date: '', slot: null as string | null })
const moveSlots = ref<Slot[]>([])
async function openMove(b: BookingRow) {
  moving.value = b
  move.value = { date: b.date, slot: null }
  moveSlots.value = (await crmApi.availability(b.date, b.branch_id)).data.data
}
watch(() => move.value.date, async (d) => {
  if (moving.value && d) moveSlots.value = (await crmApi.availability(d, moving.value.branch_id)).data.data
})
async function saveMove() {
  await act(() => crmApi.reschedule(moving.value!.id, move.value.date, move.value.slot))
  moving.value = null
}
const cancelling = ref<BookingRow | null>(null)
const cancelReason = ref('')
async function saveCancel() {
  await act(() => crmApi.cancelBooking(cancelling.value!.id, cancelReason.value))
  cancelling.value = null
}
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Bookings</h1>
        <p class="text-sm text-gray-500">Book from a lead or a customer. Cars arriving here open their job card in one tap.</p>
      </div>
      <div class="flex rounded-xl bg-gray-100 p-1 text-sm font-medium">
        <button type="button" class="rounded-lg px-3 py-1.5" :class="view === 'day' ? 'bg-white shadow-sm' : 'text-gray-600'" @click="view = 'day'">Day</button>
        <button type="button" class="rounded-lg px-3 py-1.5" :class="view === 'week' ? 'bg-white shadow-sm' : 'text-gray-600'" @click="view = 'week'">Week</button>
      </div>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-2">
      <Button size="sm" variant="secondary" @click="shift(view === 'day' ? -1 : -7)">‹</Button>
      <input v-model="date" type="date" class="input w-auto" />
      <Button size="sm" variant="secondary" @click="shift(view === 'day' ? 1 : 7)">›</Button>
      <Button size="sm" variant="ghost" @click="date = localDate()">Today</Button>
      <select v-model="status" class="input w-auto">
        <option value="">All</option><option value="booked">To confirm</option><option value="confirmed">Confirmed</option><option value="converted">Job opened</option><option value="no_show">Did not come</option><option value="cancelled">Cancelled</option>
      </select>
      <button v-if="pending" type="button" class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800" @click="status = 'booked'">{{ pending }} online booking(s) to confirm</button>
    </div>

    <div v-if="loading && !rows.length" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <!-- Day -->
    <div v-else-if="view === 'day'" class="space-y-3">
      <p v-if="!slots.length && !rows.length" class="rounded-xl bg-gray-50 p-4 text-sm text-gray-500">No slots this day (weekly off or holiday).</p>
      <Card v-for="g in bySlot" :key="g.slot" :padding="false">
        <div class="flex items-center justify-between px-4 py-2 bg-gray-50 text-sm">
          <span class="font-semibold">{{ g.slot }}</span>
          <span v-if="g.capacity !== null" class="text-xs text-gray-500">{{ g.rows.filter((r) => !['cancelled', 'no_show'].includes(r.status)).length }} / {{ g.capacity }}</span>
        </div>
        <p v-if="!g.rows.length" class="px-4 py-2 text-xs text-gray-400">Free</p>
        <ul class="divide-y divide-gray-100">
          <li v-for="b in g.rows" :key="b.id" class="px-4 py-3 text-sm">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-semibold text-gray-900">{{ b.name }}</span>
              <span class="text-gray-500">{{ b.car }}</span>
              <Badge size="sm" :variant="VARIANT[b.status]">{{ b.status_label }}</Badge>
              <Badge v-if="b.booked_via !== 'staff'" size="sm" variant="purple">Online</Badge>
              <span class="ml-auto text-xs text-gray-500">{{ b.number }}</span>
            </div>
            <p class="text-xs text-gray-600">{{ b.package ?? b.service_type.replace('_', ' ') }} · {{ MODE_LABELS[b.service_mode] }}<template v-if="b.address"> · {{ b.address }}</template></p>
            <p v-if="b.notes" class="text-xs text-gray-500">“{{ b.notes }}”</p>
            <div class="mt-2 flex flex-wrap gap-2">
              <a v-if="b.phone" :href="`tel:+${b.phone.replace(/\D/g, '')}`" class="text-xs font-medium text-green-700">Call</a>
              <RouterLink v-if="b.lead_id" :to="`/crm/leads/${b.lead_id}`" class="text-xs font-medium text-primary-700">Lead</RouterLink>
              <template v-if="['booked', 'confirmed', 'arrived'].includes(b.status)">
                <Button v-if="b.status === 'booked'" size="sm" variant="secondary" @click="act(() => crmApi.confirm(b.id))">Confirm</Button>
                <Button v-if="b.service_mode === 'drive_in' && can('workshop.job-cards.create')" size="sm" @click="openArrive(b)">Car arrived</Button>
                <Button size="sm" variant="ghost" @click="openMove(b)">Move</Button>
                <Button size="sm" variant="ghost" @click="act(() => crmApi.noShow(b.id))">Did not come</Button>
                <Button size="sm" variant="ghost" @click="cancelling = b; cancelReason = ''">Cancel</Button>
              </template>
              <RouterLink v-if="b.job_card_id" :to="`/job-cards/${b.job_card_id}`" class="text-xs font-medium text-primary-700">Job {{ b.job_number }}</RouterLink>
            </div>
          </li>
        </ul>
      </Card>
    </div>

    <!-- Week -->
    <div v-else class="grid grid-cols-1 sm:grid-cols-7 gap-2">
      <div v-for="d in weekDays" :key="d" class="rounded-xl bg-white ring-1 ring-gray-200 p-2 min-h-[8rem]">
        <button type="button" class="mb-1 w-full text-left text-xs font-semibold text-gray-600" @click="date = d; view = 'day'">
          {{ new Date(d).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' }) }}
        </button>
        <div v-for="b in rows.filter((r) => r.date === d)" :key="b.id" class="mb-1 rounded bg-gray-50 px-1.5 py-1 text-[11px]" :class="['cancelled', 'no_show'].includes(b.status) ? 'line-through opacity-50' : ''">
          <b>{{ b.slot_from ?? '' }}</b> {{ b.name }}
        </div>
      </div>
    </div>

    <!-- Arrived -->
    <Modal :open="!!arriving" :title="arriving ? `${arriving.name} has arrived` : ''" size="sm" @close="arriving = null">
      <div class="space-y-3 text-sm">
        <p class="text-gray-600">Check the car details; the job card form opens filled in from the booking.</p>
        <div><label class="label">Car number</label><input v-model="car.registration_no" class="input uppercase" placeholder="MH04AB1234" /></div>
        <div>
          <label class="label">Car type</label>
          <select v-model="car.category_id" class="input"><option :value="null">As on the lead / vehicle</option><option v-for="c in options?.categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        </div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="arriving = null">Cancel</Button>
        <Button :loading="busy" @click="arrive">Open job card</Button>
      </template>
    </Modal>

    <Modal :open="!!moving" title="Move booking" size="sm" @close="moving = null">
      <div class="space-y-3 text-sm">
        <input v-model="move.date" type="date" class="input" :min="localDate()" />
        <div class="grid grid-cols-3 gap-2">
          <button v-for="s in moveSlots" :key="s.from" type="button" :disabled="s.free < 1" class="rounded-lg py-2 ring-1 disabled:opacity-40" :class="move.slot === s.from ? 'bg-primary-600 text-white ring-primary-600' : 'ring-gray-200'" @click="move.slot = s.from">{{ s.from }}</button>
        </div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="moving = null">Cancel</Button>
        <Button :disabled="moveSlots.length > 0 && !move.slot" @click="saveMove">Move</Button>
      </template>
    </Modal>

    <Modal :open="!!cancelling" title="Cancel booking" size="sm" @close="cancelling = null">
      <input v-model="cancelReason" class="input" placeholder="Why?" />
      <template #footer>
        <Button variant="secondary" @click="cancelling = null">Keep</Button>
        <Button variant="danger" :disabled="!cancelReason.trim()" @click="saveCancel">Cancel booking</Button>
      </template>
    </Modal>
  </div>
</template>
