<script setup lang="ts">
/**
 * Attendance register for managers: everyone's day (with the selfies), a month grid, and
 * regularisation — add a missed punch or remove a wrong one, always with a reason.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { hrApi, DAY_LABELS, DAY_SHORT, DAY_COLORS, hours, monthLabel, thisMonth, localDate, type Punch, type AttendanceDayRow, type DayStatus } from '@/api/hr.api'
import { useBranchStore } from '@/stores/branch.store'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const branchStore = useBranchStore()
const { can } = usePermissions()
const toast = useToast()
const canManage = computed(() => can('hr.attendance.manage'))

const view = ref<'day' | 'month'>('day')
const date = ref(localDate())
const period = ref(thisMonth())
const loading = ref(false)

type DayRow = { user_id: number; name: string; designation: string | null; branch: string | null; day: AttendanceDayRow | null; punches: Punch[] }
const rows = ref<DayRow[]>([])
const counts = ref<Record<string, number>>({})
const monthRows = ref<{ user_id: number; name: string; days: Record<number, { status: DayStatus; ot: number; late: number; off_work: boolean; regularised: boolean }> }[]>([])
const daysInMonth = ref(30)

async function load() {
  loading.value = true
  try {
    if (view.value === 'day') {
      const data = (await hrApi.day(date.value, branchStore.currentBranchId)).data.data
      rows.value = data.rows
      counts.value = data.counts
    } else {
      const data = (await hrApi.month(period.value, branchStore.currentBranchId)).data.data
      monthRows.value = data.rows
      daysInMonth.value = data.days_in_month
    }
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}
watch([view, date, period, () => branchStore.currentBranchId], load)
onMounted(load)

const shift = (days: number) => {
  const d = new Date(date.value)
  d.setDate(d.getDate() + days)
  date.value = localDate(d)
}

// ------------------------------------------------------------- one person
const person = ref<{ user_id: number; name: string; date: string; day: AttendanceDayRow | null; punches: Punch[] } | null>(null)
const selfie = ref<string | null>(null)
const addForm = ref({ type: 'in' as 'in' | 'out', time: '09:30', reason: '' })
const busy = ref(false)

async function openPerson(userId: number, name: string, onDate: string) {
  try {
    const data = (await hrApi.punches(userId, onDate)).data.data
    person.value = { user_id: userId, name, date: onDate, day: data.day, punches: data.punches }
    const valid = data.punches.filter((p) => !p.voided)
    const last = valid[valid.length - 1]
    addForm.value = { type: last?.type === 'in' ? 'out' : 'in', time: last?.type === 'in' ? '19:00' : '09:30', reason: '' }
  } catch (err) {
    toast.apiError(err)
  }
}

async function addPunch() {
  if (!person.value) return
  busy.value = true
  try {
    await hrApi.addPunch(person.value.user_id, { date: person.value.date, ...addForm.value })
    toast.success('Punch added')
    await openPerson(person.value.user_id, person.value.name, person.value.date)
    load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

const voiding = ref<Punch | null>(null)
const voidReason = ref('')
async function voidPunch() {
  if (!voiding.value || !person.value) return
  busy.value = true
  try {
    await hrApi.voidPunch(voiding.value.id, voidReason.value)
    toast.success('Punch removed')
    voiding.value = null
    voidReason.value = ''
    await openPerson(person.value.user_id, person.value.name, person.value.date)
    load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

const SOURCE: Record<string, string> = { selfie: 'Selfie', trip: 'From a trip', manual: 'Added by manager' }
const mapUrl = (p: Punch) => (p.lat !== null && p.lng !== null ? `https://maps.google.com/?q=${p.lat},${p.lng}` : null)
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Attendance register</h1>
        <p class="text-sm text-gray-500">Selfie punches at the workshop; tap a person to see or correct their punches.</p>
      </div>
      <div class="flex rounded-xl bg-gray-100 p-1 text-sm font-medium">
        <button type="button" class="rounded-lg px-3 py-1.5" :class="view === 'day' ? 'bg-white shadow-sm' : 'text-gray-600'" @click="view = 'day'">Day</button>
        <button type="button" class="rounded-lg px-3 py-1.5" :class="view === 'month' ? 'bg-white shadow-sm' : 'text-gray-600'" @click="view = 'month'">Month</button>
      </div>
    </div>

    <!-- Day -->
    <template v-if="view === 'day'">
      <div class="mb-3 flex items-center gap-2">
        <Button variant="secondary" size="sm" @click="shift(-1)">‹</Button>
        <input v-model="date" type="date" class="input w-auto" :max="localDate()" />
        <Button variant="secondary" size="sm" :disabled="date >= localDate()" @click="shift(1)">›</Button>
      </div>
      <div class="mb-3 grid grid-cols-2 sm:grid-cols-4 gap-2 text-center text-sm">
        <div class="rounded-xl bg-white ring-1 ring-gray-200 p-2"><p class="text-xs text-gray-500">Employees</p><p class="text-lg font-semibold">{{ counts.employees ?? 0 }}</p></div>
        <div class="rounded-xl bg-green-50 p-2"><p class="text-xs text-gray-500">Present</p><p class="text-lg font-semibold">{{ counts.present ?? 0 }}</p></div>
        <div class="rounded-xl bg-red-50 p-2"><p class="text-xs text-gray-500">Not in</p><p class="text-lg font-semibold">{{ counts.absent ?? 0 }}</p></div>
        <div class="rounded-xl bg-purple-50 p-2"><p class="text-xs text-gray-500">On leave</p><p class="text-lg font-semibold">{{ counts.on_leave ?? 0 }}</p></div>
      </div>

      <div v-if="loading" class="flex justify-center py-10"><Spinner size="lg" /></div>
      <Card v-else :padding="false">
        <p v-if="!rows.length" class="p-4 text-sm text-gray-500">No employees on attendance. Add their details under People › Employees.</p>
        <ul class="divide-y divide-gray-100">
          <li v-for="r in rows" :key="r.user_id">
            <button type="button" class="w-full px-4 py-3 text-left flex items-center gap-3 hover:bg-gray-50" @click="openPerson(r.user_id, r.name, date)">
              <img v-if="r.punches.find((p) => !p.voided && p.selfie_thumb_url)" :src="r.punches.find((p) => !p.voided && p.selfie_thumb_url)!.selfie_thumb_url!" alt="" class="h-10 w-10 rounded-full object-cover" />
              <span v-else class="h-10 w-10 rounded-full bg-gray-100 flex items-center justify-center text-sm font-semibold text-gray-500">{{ r.name.slice(0, 1) }}</span>
              <span class="min-w-0 flex-1">
                <span class="block font-semibold text-gray-900">{{ r.name }}</span>
                <span class="block text-xs text-gray-500">
                  <template v-if="r.day?.first_in">In {{ r.day.first_in }}<template v-if="r.day.last_out"> · Out {{ r.day.last_out }}</template> · {{ hours(r.day.worked_minutes) }}</template>
                  <template v-else>{{ r.designation ?? '' }}</template>
                  <template v-if="r.day?.late_minutes"> · {{ r.day.late_minutes }} min late</template>
                  <template v-if="r.day?.ot_minutes"> · OT {{ hours(r.day.ot_minutes) }}</template>
                </span>
              </span>
              <span v-if="r.day" class="rounded-full px-2 py-0.5 text-xs font-medium" :class="DAY_COLORS[r.day.status]">{{ DAY_LABELS[r.day.status] }}</span>
              <span v-else class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">Not in yet</span>
            </button>
          </li>
        </ul>
      </Card>
    </template>

    <!-- Month -->
    <template v-else>
      <div class="mb-3 flex items-center gap-2">
        <input v-model="period" type="month" class="input w-auto" :max="thisMonth()" />
        <span class="text-sm text-gray-500">{{ monthLabel(period) }}</span>
      </div>
      <div v-if="loading" class="flex justify-center py-10"><Spinner size="lg" /></div>
      <Card v-else :padding="false">
        <div class="overflow-x-auto">
          <table class="min-w-full text-xs">
            <thead>
              <tr class="bg-gray-50 text-gray-500">
                <th class="sticky left-0 bg-gray-50 px-3 py-2 text-left font-medium">Employee</th>
                <th v-for="d in daysInMonth" :key="d" class="px-1 py-2 font-medium">{{ d }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="r in monthRows" :key="r.user_id">
                <td class="sticky left-0 bg-white px-3 py-1.5 font-medium whitespace-nowrap">{{ r.name }}</td>
                <td v-for="d in daysInMonth" :key="d" class="px-0.5 py-1 text-center">
                  <button
                    v-if="r.days[d]"
                    type="button"
                    class="w-7 rounded py-1 font-semibold"
                    :class="[DAY_COLORS[r.days[d].status], r.days[d].regularised ? 'ring-1 ring-amber-400' : '']"
                    :title="DAY_LABELS[r.days[d].status] + (r.days[d].ot ? ` · OT ${hours(r.days[d].ot)}` : '')"
                    @click="openPerson(r.user_id, r.name, `${period}-${String(d).padStart(2, '0')}`)"
                  >{{ DAY_SHORT[r.days[d].status] }}</button>
                  <span v-else class="text-gray-300">·</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="px-4 py-2 text-xs text-gray-500">P present · ½ half day · A absent · W weekly off · H holiday · L paid leave · LW leave without pay · ! out-punch missing. Amber ring = corrected by a manager.</p>
      </Card>
    </template>

    <!-- One person's day -->
    <Modal :open="!!person" :title="person ? `${person.name} — ${person.date}` : ''" size="lg" @close="person = null">
      <div v-if="person" class="space-y-4 text-sm">
        <p v-if="person.day">
          <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="DAY_COLORS[person.day.status]">{{ DAY_LABELS[person.day.status] }}</span>
          <span class="ml-2 text-gray-600">Worked {{ hours(person.day.worked_minutes) }}<template v-if="person.day.ot_minutes"> · OT {{ hours(person.day.ot_minutes) }}</template></span>
        </p>
        <p v-if="!person.punches.length" class="text-gray-500">No punches this day.</p>
        <ul class="divide-y divide-gray-100">
          <li v-for="p in person.punches" :key="p.id" class="py-2 flex items-center gap-3" :class="p.voided ? 'opacity-50 line-through' : ''">
            <button v-if="p.selfie_thumb_url" type="button" @click="selfie = p.selfie_url">
              <img :src="p.selfie_thumb_url" alt="Selfie" class="h-14 w-14 rounded-lg object-cover" />
            </button>
            <span class="flex-1">
              <span class="block font-semibold">{{ p.type === 'in' ? 'In' : 'Out' }} {{ p.time }}</span>
              <span class="block text-xs text-gray-500">
                {{ SOURCE[p.source] }}<template v-if="p.by"> ({{ p.by }})</template>
                <template v-if="p.distance_m !== null"> · {{ p.distance_m }} m from {{ p.branch }}</template>
                <template v-if="p.accuracy_m"> · ±{{ Math.round(p.accuracy_m) }} m</template>
              </span>
              <span v-if="p.reason" class="block text-xs text-amber-700">{{ p.reason }}</span>
              <a v-if="mapUrl(p)" :href="mapUrl(p)!" target="_blank" rel="noopener" class="text-xs text-primary-700">Map</a>
            </span>
            <Button v-if="canManage && !p.voided" size="sm" variant="ghost" @click="voiding = p">Remove</Button>
          </li>
        </ul>

        <div v-if="canManage" class="rounded-xl bg-gray-50 p-3">
          <p class="mb-2 font-semibold text-gray-700">Add a missed punch</p>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
            <select v-model="addForm.type" class="input"><option value="in">In</option><option value="out">Out</option></select>
            <input v-model="addForm.time" type="time" class="input" />
            <input v-model="addForm.reason" class="input col-span-2" placeholder="Reason (required)" />
          </div>
          <Button class="mt-2" size="sm" :loading="busy" :disabled="!addForm.reason.trim()" @click="addPunch">Add punch</Button>
        </div>
      </div>
    </Modal>

    <Modal :open="!!voiding" title="Remove this punch?" size="sm" @close="voiding = null">
      <p class="text-sm text-gray-600 mb-2">It stays on record as removed, with your reason.</p>
      <input v-model="voidReason" class="input" placeholder="Reason" />
      <template #footer>
        <Button variant="secondary" @click="voiding = null">Keep</Button>
        <Button variant="danger" :loading="busy" :disabled="!voidReason.trim()" @click="voidPunch">Remove</Button>
      </template>
    </Modal>

    <Modal :open="!!selfie" title="Selfie" @close="selfie = null">
      <img v-if="selfie" :src="selfie" alt="Selfie" class="mx-auto max-h-[70vh] rounded-lg" />
    </Modal>
  </div>
</template>
