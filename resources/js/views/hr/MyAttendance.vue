<script setup lang="ts">
/**
 * The employee's own screen (every role): punch in / out with a front-camera selfie at
 * the workshop (location on, within the workshop's radius), this month's attendance,
 * leave, payslips, advances and customer money they hold.
 */
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CameraIcon, MapPinIcon, ExclamationTriangleIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline'
import {
  hrApi, DAY_LABELS, DAY_COLORS, PAY_METHODS, hours, monthLabel, thisMonth, localDate, openBlob,
  type Today, type MonthAttendance, type LeaveBalance, type LeaveRequestRow, type Payslip, type Advance, type CustodyEntry,
} from '@/api/hr.api'
import { inr } from '@/api/accounts.api'
import { useToast } from '@/composables/useToast'
import { useLocationTracker } from '@/composables/useLocationTracker'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const toast = useToast()
const tracker = useLocationTracker()
const route = useRoute()
const router = useRouter()

type Tab = 'month' | 'leave' | 'payslips' | 'money'
const tab = ref<Tab>((route.query.tab as Tab) || 'month')
watch(tab, (t) => router.replace({ query: { ...route.query, tab: t } }))

// ------------------------------------------------------------ today / punch
const today = ref<Today | null>(null)
const punching = ref(false)
const camera = ref<HTMLInputElement | null>(null)

async function loadToday() {
  try {
    today.value = (await hrApi.today()).data.data
  } catch (err) {
    toast.apiError(err)
  }
}

// Live location while the screen is open
const pos = ref<{ lat: number; lng: number; accuracy: number } | null>(null)
const geoError = ref<string | null>(null)
let watchId: number | null = null

function startLocation() {
  if (!('geolocation' in navigator)) {
    geoError.value = 'This phone cannot share its location.'
    return
  }
  geoError.value = null
  watchId = navigator.geolocation.watchPosition(
    (p) => {
      pos.value = { lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }
      geoError.value = null
    },
    (e) => {
      geoError.value = e.code === e.PERMISSION_DENIED
        ? 'Location is blocked. Allow location for this app in the phone settings, then reopen this screen.'
        : 'Waiting for the phone’s location… turn on Location (GPS).'
    },
    { enableHighAccuracy: true, maximumAge: 10000, timeout: 30000 },
  )
}

function distance(a: { lat: number; lng: number }, b: { lat: number; lng: number }) {
  const r = 6371000
  const dLat = ((b.lat - a.lat) * Math.PI) / 180
  const dLng = ((b.lng - a.lng) * Math.PI) / 180
  const x = Math.sin(dLat / 2) ** 2 + Math.cos((a.lat * Math.PI) / 180) * Math.cos((b.lat * Math.PI) / 180) * Math.sin(dLng / 2) ** 2
  return r * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x))
}

const nearest = computed(() => {
  if (!pos.value || !today.value) return null
  let best: { name: string; d: number; radius: number } | null = null
  for (const b of today.value.branches) {
    if (b.lat === null || b.lng === null) continue
    const d = distance(pos.value, { lat: b.lat, lng: b.lng })
    if (!best || d < best.d) best = { name: b.name, d, radius: b.radius_m }
  }
  return best
})
const noWorkshopLocation = computed(() => !!today.value && today.value.branches.every((b) => b.lat === null))
const inside = computed(() => !!nearest.value && nearest.value.d <= nearest.value.radius)
const roughFix = computed(() => !!pos.value && !!today.value && pos.value.accuracy > today.value.max_accuracy_m)
const canPunch = computed(() => !!pos.value && !roughFix.value && (inside.value || !!today.value?.trip))
const metres = (m: number) => (m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${Math.round(m)} m`)

function startPunch() {
  if (!pos.value) {
    toast.error('Location is needed', 'Turn on Location (GPS) and allow it for this app.')
    return
  }
  camera.value?.click()
}

/** Phone photos are large: send a 1280 px JPEG (the capture time is kept separately) */
async function shrink(file: File): Promise<Blob> {
  try {
    const bitmap = await createImageBitmap(file)
    const scale = Math.min(1, 1280 / Math.max(bitmap.width, bitmap.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)
    canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    return await new Promise((resolve) => canvas.toBlob((b) => resolve(b ?? file), 'image/jpeg', 0.8))
  } catch {
    return file
  }
}

async function onSelfie(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !pos.value) return

  punching.value = true
  try {
    const form = new FormData()
    form.append('selfie', await shrink(file), 'selfie.jpg')
    form.append('lat', String(pos.value.lat))
    form.append('lng', String(pos.value.lng))
    form.append('accuracy', String(Math.round(pos.value.accuracy)))
    form.append('captured_at', new Date(file.lastModified || Date.now()).toISOString())
    form.append('client_time', new Date().toISOString())
    form.append('device', navigator.userAgent.slice(0, 250))
    const res = await hrApi.punch(form)
    today.value = res.data.data
    toast.success(res.data.message ?? 'Done')
    // Punch in starts live location (the first time after the notice); punch out stops it
    void tracker.sync(res.data.data.tracking)
    if (tab.value === 'month') loadMonth()
  } catch (err) {
    toast.apiError(err)
  } finally {
    punching.value = false
  }
}

// ------------------------------------------------------------------- month
const period = ref(thisMonth())
const month = ref<MonthAttendance | null>(null)
async function loadMonth() {
  try {
    month.value = (await hrApi.myAttendance(period.value)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}
watch(period, loadMonth)

const calendar = computed(() => {
  const [y, m] = period.value.split('-').map(Number)
  const first = new Date(y, m - 1, 1)
  const days = new Date(y, m, 0).getDate()
  const byDate = Object.fromEntries((month.value?.days ?? []).map((d) => [d.date, d]))
  const cells: ({ day: number; date: string; row: (typeof byDate)[string] | undefined } | null)[] = Array(first.getDay()).fill(null)
  for (let d = 1; d <= days; d++) {
    const date = `${period.value}-${String(d).padStart(2, '0')}`
    cells.push({ day: d, date, row: byDate[date] })
  }
  return cells
})
const picked = ref<string | null>(null)
const pickedRow = computed(() => month.value?.days.find((d) => d.date === picked.value) ?? null)

// ------------------------------------------------------------------- leave
const balances = ref<LeaveBalance[]>([])
const requests = ref<LeaveRequestRow[]>([])
const leaveOpen = ref(false)
const leaveForm = ref({ leave_type_id: 0, from_date: localDate(), to_date: localDate(), half_day: false, reason: '' })
const busy = ref(false)

async function loadLeave() {
  try {
    const data = (await hrApi.myLeave()).data.data
    balances.value = data.balances
    requests.value = data.requests
  } catch (err) {
    toast.apiError(err)
  }
}
function openLeave() {
  leaveForm.value = { leave_type_id: balances.value[0]?.leave_type_id ?? 0, from_date: localDate(), to_date: localDate(), half_day: false, reason: '' }
  leaveOpen.value = true
}
async function applyLeave() {
  busy.value = true
  try {
    const f = leaveForm.value
    const res = await hrApi.applyLeave({ ...f, to_date: f.half_day ? f.from_date : f.to_date, reason: f.reason || null })
    toast.success(res.data.message ?? 'Sent')
    leaveOpen.value = false
    loadLeave()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
async function withdraw(r: LeaveRequestRow) {
  try {
    await hrApi.cancelMyLeave(r.id)
    toast.success('Leave cancelled')
    loadLeave()
  } catch (err) {
    toast.apiError(err)
  }
}
const STATUS_VARIANT: Record<string, 'warning' | 'success' | 'danger' | 'default'> = { pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'default' }

// ----------------------------------------------------------- payslips / money
const payslips = ref<Payslip[]>([])
const openSlip = ref<Payslip | null>(null)
async function loadPayslips() {
  try {
    payslips.value = (await hrApi.myPayslips()).data.data
  } catch (err) {
    toast.apiError(err)
  }
}
async function downloadSlip(s: Payslip) {
  try {
    const res = await hrApi.payslipPdf(s.id)
    openBlob(res.data as Blob, `payslip-${s.period}.pdf`)
  } catch (err) {
    toast.apiError(err)
  }
}

const advances = ref<Advance[]>([])
const custodyBalance = ref(0)
const custody = ref<CustodyEntry[]>([])
async function loadMoney() {
  try {
    const data = (await hrApi.myMoney()).data.data
    advances.value = data.advances
    custodyBalance.value = data.custody_balance
    custody.value = data.custody_entries
  } catch (err) {
    toast.apiError(err)
  }
}
const CUSTODY_KINDS: Record<string, string> = { collected: 'Collected from customer', handed_over: 'Handed over', spent: 'Spent for the workshop', salary_recovery: 'Recovered from salary' }

watch(tab, (t) => {
  if (t === 'leave') loadLeave()
  if (t === 'payslips') loadPayslips()
  if (t === 'money') loadMoney()
}, { immediate: true })

onMounted(() => {
  loadToday()
  loadMonth()
  startLocation()
})
onBeforeUnmount(() => {
  if (watchId !== null) navigator.geolocation.clearWatch(watchId)
})
</script>

<template>
  <div class="max-w-xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-3">Attendance</h1>

    <!-- Punch card -->
    <Card class="mb-4">
      <div v-if="!today" class="flex justify-center py-6"><Spinner /></div>
      <template v-else>
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">{{ new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' }) }}</p>
            <p class="mt-0.5 text-lg font-semibold text-gray-900">
              <template v-if="today.next === 'out'">You are in</template>
              <template v-else-if="today.punches.length">You are out</template>
              <template v-else>Not punched in yet</template>
            </p>
            <p v-if="today.shift" class="text-sm text-gray-500">Shift {{ today.shift.start }} – {{ today.shift.end }}</p>
          </div>
          <div class="text-right">
            <p class="text-xs text-gray-500">Worked today</p>
            <p class="text-lg font-semibold">{{ hours(today.worked_minutes) }}</p>
          </div>
        </div>

        <!-- Where am I -->
        <div class="mt-3 rounded-xl p-3 text-sm" :class="canPunch ? 'bg-green-50 text-green-800' : 'bg-gray-50 text-gray-700'">
          <p class="flex items-center gap-1.5 font-medium">
            <MapPinIcon class="h-4 w-4 shrink-0" />
            <template v-if="geoError">{{ geoError }}</template>
            <template v-else-if="!pos">Finding your location…</template>
            <template v-else-if="noWorkshopLocation">The workshop location is not set yet — ask the manager.</template>
            <template v-else-if="nearest">
              {{ inside ? 'At' : metres(nearest.d) + ' from' }} {{ nearest.name }}
              <span v-if="!inside" class="font-normal">(punch within {{ nearest.radius }} m)</span>
            </template>
          </p>
          <p v-if="pos" class="mt-0.5 text-xs opacity-80">GPS ±{{ Math.round(pos.accuracy) }} m<span v-if="roughFix"> — too rough, step outside or wait a moment</span></p>
          <p v-if="today.trip && !inside" class="mt-1 text-xs">On a {{ today.trip.label.toLowerCase() }} today: you may punch from where you are.</p>
        </div>

        <input ref="camera" type="file" accept="image/*" capture="user" class="hidden" @change="onSelfie" />
        <Button
          class="mt-4"
          size="lg"
          block
          :variant="today.next === 'out' ? 'dark' : 'primary'"
          :loading="punching"
          :disabled="!canPunch"
          @click="startPunch"
        >
          <CameraIcon class="h-5 w-5 mr-2" />
          {{ today.next === 'out' ? 'Punch out' : 'Punch in' }} with a selfie
        </Button>
        <p class="mt-1 text-center text-xs text-gray-500">The front camera opens; take the selfie at the workshop.</p>

        <ul v-if="today.punches.length" class="mt-4 flex flex-wrap gap-2">
          <li v-for="p in today.punches" :key="p.id" class="flex items-center gap-2 rounded-lg bg-gray-50 px-2 py-1 text-sm">
            <img v-if="p.selfie_thumb_url" :src="p.selfie_thumb_url" alt="" class="h-8 w-8 rounded-full object-cover" />
            <span class="font-medium">{{ p.type === 'in' ? 'In' : 'Out' }} {{ p.time }}</span>
            <Badge v-if="p.source !== 'selfie'" size="sm" :variant="p.source === 'trip' ? 'info' : 'warning'">{{ p.source === 'trip' ? 'Trip' : 'By manager' }}</Badge>
          </li>
        </ul>
      </template>
    </Card>

    <!-- Tabs -->
    <div class="mb-3 flex gap-1 overflow-x-auto scrollbar-none rounded-xl bg-gray-100 p-1 text-sm font-medium">
      <button
        v-for="t in ([['month', 'This month'], ['leave', 'Leave'], ['payslips', 'Payslips'], ['money', 'Advances']] as const)"
        :key="t[0]"
        type="button"
        class="flex-1 whitespace-nowrap rounded-lg px-3 py-2"
        :class="tab === t[0] ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600'"
        @click="tab = t[0]"
      >{{ t[1] }}</button>
    </div>

    <!-- Month -->
    <Card v-if="tab === 'month'">
      <div class="mb-3 flex items-center justify-between gap-2">
        <input v-model="period" type="month" class="input w-auto" :max="thisMonth()" />
        <span class="text-sm text-gray-500">{{ monthLabel(period) }}</span>
      </div>
      <div v-if="!month" class="flex justify-center py-6"><Spinner /></div>
      <template v-else>
        <div class="grid grid-cols-7 gap-1 text-center text-xs">
          <span v-for="(d, i) in ['S', 'M', 'T', 'W', 'T', 'F', 'S']" :key="'h' + i" class="py-1 font-medium text-gray-400">{{ d }}</span>
          <template v-for="(c, i) in calendar" :key="i">
            <span v-if="!c" />
            <button
              v-else
              type="button"
              class="aspect-square rounded-lg text-sm font-medium flex flex-col items-center justify-center"
              :class="[c.row ? DAY_COLORS[c.row.status] : 'text-gray-300', picked === c.date ? 'ring-2 ring-primary-500' : '']"
              @click="picked = c.date"
            >
              {{ c.day }}
              <span v-if="c.row?.ot_minutes" class="text-[9px] leading-none">OT</span>
            </button>
          </template>
        </div>

        <div v-if="pickedRow" class="mt-3 rounded-lg bg-gray-50 p-3 text-sm">
          <p class="font-semibold">{{ new Date(pickedRow.date).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' }) }} · {{ DAY_LABELS[pickedRow.status] }}</p>
          <p v-if="pickedRow.first_in" class="text-gray-600">
            In {{ pickedRow.first_in }}<template v-if="pickedRow.last_out"> · Out {{ pickedRow.last_out }}</template> · {{ hours(pickedRow.worked_minutes) }}
            <template v-if="pickedRow.late_minutes"> · {{ pickedRow.late_minutes }} min late</template>
            <template v-if="pickedRow.ot_minutes"> · OT {{ hours(pickedRow.ot_minutes) }}</template>
          </p>
          <p v-if="pickedRow.worked_off_day" class="text-green-700">Worked on a weekly off / holiday</p>
          <p v-if="pickedRow.regularised" class="text-amber-700">Corrected by the manager</p>
        </div>

        <dl class="mt-4 grid grid-cols-3 gap-2 text-center text-sm">
          <div class="rounded-lg bg-green-50 p-2"><dt class="text-xs text-gray-500">Present</dt><dd class="font-semibold">{{ month.summary.present }}</dd></div>
          <div class="rounded-lg bg-red-50 p-2"><dt class="text-xs text-gray-500">Absent</dt><dd class="font-semibold">{{ month.summary.absent }}</dd></div>
          <div class="rounded-lg bg-purple-50 p-2"><dt class="text-xs text-gray-500">Leave</dt><dd class="font-semibold">{{ month.summary.leave_paid + month.summary.leave_unpaid }}</dd></div>
          <div class="rounded-lg bg-gray-50 p-2"><dt class="text-xs text-gray-500">Late days</dt><dd class="font-semibold">{{ month.summary.late_days }}</dd></div>
          <div class="rounded-lg bg-gray-50 p-2"><dt class="text-xs text-gray-500">OT hours</dt><dd class="font-semibold">{{ month.summary.ot_hours }}</dd></div>
          <div class="rounded-lg bg-gray-50 p-2"><dt class="text-xs text-gray-500">Off days worked</dt><dd class="font-semibold">{{ month.summary.off_days_worked }}</dd></div>
        </dl>
        <p v-if="month.summary.incomplete" class="mt-3 flex items-center gap-1.5 text-sm text-orange-700">
          <ExclamationTriangleIcon class="h-4 w-4" /> {{ month.summary.incomplete }} day(s) with no out-punch — ask the manager to correct them.
        </p>
      </template>
    </Card>

    <!-- Leave -->
    <template v-if="tab === 'leave'">
      <Card class="mb-3">
        <div class="grid grid-cols-2 gap-2 text-sm">
          <div v-for="b in balances" :key="b.leave_type_id" class="rounded-lg bg-gray-50 p-2">
            <p class="text-xs text-gray-500">{{ b.name }}</p>
            <p class="font-semibold">
              <template v-if="b.is_paid">{{ b.available }} left <span class="font-normal text-gray-500">of {{ (b.allotted ?? 0) + (b.carried ?? 0) }}</span></template>
              <template v-else>{{ b.taken }} taken</template>
            </p>
            <p v-if="b.pending" class="text-xs text-amber-700">{{ b.pending }} waiting</p>
          </div>
        </div>
        <Button class="mt-3" block @click="openLeave">Apply for leave</Button>
      </Card>
      <Card :padding="false">
        <p v-if="!requests.length" class="p-4 text-sm text-gray-500">No leave requests yet.</p>
        <ul class="divide-y divide-gray-100">
          <li v-for="r in requests" :key="r.id" class="px-4 py-3 text-sm">
            <div class="flex items-center gap-2">
              <span class="font-semibold">{{ r.leave_type }}</span>
              <Badge size="sm" :variant="STATUS_VARIANT[r.status]">{{ r.status }}</Badge>
              <span class="ml-auto text-gray-600">{{ r.days }} day{{ r.days === 1 ? '' : 's' }}</span>
            </div>
            <p class="text-gray-600">{{ r.from_date }}<template v-if="r.to_date !== r.from_date"> → {{ r.to_date }}</template><template v-if="r.half_day"> (half day)</template></p>
            <p v-if="r.unpaid_days" class="text-xs text-amber-700">{{ r.unpaid_days }} day(s) without pay</p>
            <p v-if="r.decision_note" class="text-xs text-gray-500">{{ r.decided_by }}: {{ r.decision_note }}</p>
            <button v-if="r.status === 'pending'" type="button" class="mt-1 text-xs font-medium text-primary-700" @click="withdraw(r)">Withdraw</button>
          </li>
        </ul>
      </Card>
    </template>

    <!-- Payslips -->
    <Card v-if="tab === 'payslips'" :padding="false">
      <p v-if="!payslips.length" class="p-4 text-sm text-gray-500">Payslips appear here once the month's payroll is finalised.</p>
      <ul class="divide-y divide-gray-100">
        <li v-for="s in payslips" :key="s.id">
          <button type="button" class="w-full px-4 py-3 text-left flex items-center gap-3 hover:bg-gray-50" @click="openSlip = s">
            <span class="flex-1">
              <span class="block font-semibold">{{ s.period_label }}</span>
              <span class="block text-xs text-gray-500">{{ s.paid_days }} paid days · {{ s.pay_status === 'paid' ? 'Paid ' + s.paid_on : 'To be paid' }}</span>
            </span>
            <span class="font-semibold">{{ inr(s.net) }}</span>
          </button>
        </li>
      </ul>
    </Card>

    <!-- Advances & money held -->
    <template v-if="tab === 'money'">
      <Card class="mb-3">
        <template #header><h3 class="font-semibold">Salary advances</h3></template>
        <p v-if="!advances.length" class="text-sm text-gray-500">No advance outstanding.</p>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="a in advances" :key="a.id" class="py-2">
            <div class="flex justify-between"><span>{{ a.given_on }} · {{ inr(a.amount) }}</span><span class="font-semibold">{{ inr(a.balance) }} left</span></div>
            <p class="text-xs text-gray-500">{{ a.recover_per_month ? inr(a.recover_per_month) + ' a month from salary' : 'Recovered from the next salary' }}</p>
          </li>
        </ul>
      </Card>
      <Card>
        <template #header><h3 class="font-semibold">Customer money with you</h3></template>
        <p class="text-2xl font-bold" :class="custodyBalance > 0 ? 'text-red-700' : 'text-gray-900'">{{ inr(custodyBalance) }}</p>
        <p class="text-xs text-gray-500">Hand it over at the counter; anything still with you at month end comes off your salary.</p>
        <ul class="mt-3 divide-y divide-gray-100 text-sm">
          <li v-for="e in custody" :key="e.id" class="py-2 flex gap-2">
            <span class="text-gray-500 w-20 shrink-0">{{ e.date }}</span>
            <span class="flex-1">{{ CUSTODY_KINDS[e.kind] }}<span v-if="e.note" class="block text-xs text-gray-500">{{ e.note }}</span></span>
            <span class="font-medium" :class="e.amount > 0 ? 'text-red-700' : 'text-green-700'">{{ e.amount > 0 ? '+' : '−' }}{{ inr(Math.abs(e.amount)) }}</span>
          </li>
        </ul>
      </Card>
    </template>

    <!-- Apply for leave -->
    <Modal :open="leaveOpen" title="Apply for leave" @close="leaveOpen = false">
      <div class="space-y-3">
        <div>
          <label class="label">Type</label>
          <select v-model.number="leaveForm.leave_type_id" class="input">
            <option v-for="b in balances" :key="b.leave_type_id" :value="b.leave_type_id">{{ b.name }}{{ b.is_paid ? ` (${b.available} left)` : '' }}</option>
          </select>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="leaveForm.half_day" type="checkbox" class="h-4 w-4 rounded" /> Half day</label>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">{{ leaveForm.half_day ? 'Date' : 'From' }}</label><input v-model="leaveForm.from_date" type="date" class="input" /></div>
          <div v-if="!leaveForm.half_day"><label class="label">To</label><input v-model="leaveForm.to_date" type="date" class="input" :min="leaveForm.from_date" /></div>
        </div>
        <div><label class="label">Reason</label><input v-model="leaveForm.reason" class="input" placeholder="Optional" /></div>
        <p class="text-xs text-gray-500">Weekly offs and holidays in between are not counted. Paid leave beyond your balance is taken without pay.</p>
      </div>
      <template #footer>
        <Button variant="secondary" @click="leaveOpen = false">Cancel</Button>
        <Button :loading="busy" :disabled="!leaveForm.leave_type_id" @click="applyLeave">Send request</Button>
      </template>
    </Modal>

    <!-- Payslip -->
    <Modal :open="!!openSlip" :title="openSlip ? `Payslip — ${openSlip.period_label}` : ''" @close="openSlip = null">
      <div v-if="openSlip" class="space-y-3 text-sm">
        <p class="text-gray-600">{{ openSlip.paid_days }} of {{ openSlip.days_in_month }} days paid · {{ openSlip.lop_days }} without pay · OT {{ openSlip.ot_hours }} h</p>
        <div>
          <h4 class="font-semibold text-gray-700">Earnings</h4>
          <div v-for="l in openSlip.lines.filter((x) => x.kind === 'earning')" :key="l.id" class="flex justify-between py-0.5"><span>{{ l.label }}</span><span>{{ inr(l.amount, 2) }}</span></div>
        </div>
        <div>
          <h4 class="font-semibold text-gray-700">Deductions</h4>
          <div v-for="l in openSlip.lines.filter((x) => x.kind === 'deduction')" :key="l.id" class="flex justify-between py-0.5"><span>{{ l.label }}</span><span>{{ inr(l.amount, 2) }}</span></div>
          <p v-if="!openSlip.lines.some((x) => x.kind === 'deduction')" class="text-gray-500">None</p>
        </div>
        <div class="flex justify-between border-t pt-2 text-base font-bold"><span>Net pay</span><span>{{ inr(openSlip.net, 2) }}</span></div>
        <p v-if="openSlip.pay_status === 'paid'" class="text-green-700">Paid {{ openSlip.paid_on }} by {{ PAY_METHODS[openSlip.pay_method ?? ''] ?? openSlip.pay_method }}</p>
      </div>
      <template #footer>
        <Button variant="secondary" @click="openSlip && downloadSlip(openSlip)"><ArrowDownTrayIcon class="h-4 w-4 mr-1" /> PDF</Button>
        <Button @click="openSlip = null">Close</Button>
      </template>
    </Modal>
  </div>
</template>
