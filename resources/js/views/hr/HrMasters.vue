<script setup lang="ts">
/**
 * Masters › HR & Payroll: shifts, holidays, leave types, incentive rules, and the payroll
 * settings — OT (counted after the first hour past the shift), off-day pay, punch rules,
 * and the statutory switch with PT / PF / ESI rates (settings use the Save bar).
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { hrApi, type HrMasters, type Shift, type Holiday, type LeaveType, type IncentiveRule } from '@/api/hr.api'
import { useAuthStore } from '@/stores/auth.store'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'
import UnsavedChangesBar from '@/components/ui/UnsavedChangesBar.vue'

type Tab = 'settings' | 'shifts' | 'holidays' | 'leave' | 'incentives'
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { can } = usePermissions()
const toast = useToast()
const canEdit = computed(() => can('hr.settings.manage'))

const tab = ref<Tab>((route.query.tab as Tab) || 'settings')
watch(tab, (t) => router.replace({ query: { tab: t } }))

const data = ref<HrMasters | null>(null)
const busy = ref(false)

async function load() {
  try {
    data.value = (await hrApi.masters()).data.data
    resetSettings()
  } catch (err) {
    toast.apiError(err)
  }
}
onMounted(load)

async function run(fn: () => Promise<unknown>, done: string) {
  busy.value = true
  try {
    await fn()
    toast.success(done)
    await load()
    return true
  } catch (err) {
    toast.apiError(err)
    return false
  } finally {
    busy.value = false
  }
}

// -------------------------------------------------------------- settings
const SETTINGS: { key: string; label: string; hint?: string; type?: 'bool' }[] = [
  { key: 'hr.ot_threshold_minutes', label: 'OT starts after (minutes past shift end)', hint: 'Only the time after this counts as OT (60 = the first hour is not OT).' },
  { key: 'hr.ot_rounding_minutes', label: 'OT rounded down to (minutes)' },
  { key: 'hr.default_ot_rate', label: 'Default OT ₹ per hour', hint: 'Used when an employee has no rate of their own.' },
  { key: 'hr.default_offday_amount', label: 'Default extra ₹ for working a weekly off / holiday (per day)' },
  { key: 'hr.max_gps_accuracy_m', label: 'Punch: GPS accuracy needed (metres)' },
  { key: 'hr.selfie_window_seconds', label: 'Punch: selfie must be taken within (seconds)', hint: '0 = no check.' },
  { key: 'hr.tracking.enabled', label: 'Live location from punch in to punch out', type: 'bool', hint: 'Owner and managers see staff on the live map. Each employee can be switched off in Employees.' },
  { key: 'hr.tracking.interval_seconds', label: 'Live location: send a position every (seconds)', hint: 'While standing still. Lower = more detail, more battery.' },
  { key: 'hr.tracking.distance_filter_m', label: 'Live location: or after moving (metres)' },
  { key: 'hr.tracking.max_accuracy_m', label: 'Live location: ignore positions rougher than (metres)' },
  { key: 'hr.tracking.stale_minutes', label: 'Live location: "No signal" after (minutes)' },
  { key: 'hr.tracking.stop_minutes', label: 'Live location: a stop is at least (minutes)' },
  { key: 'hr.tracking.retention_days', label: 'Live location: keep positions for (days)' },
  { key: 'hr.statutory.enabled', label: 'Statutory deductions (PT, PF, ESI)', type: 'bool', hint: 'Off: no PT, PF or ESI on any payslip.' },
  { key: 'hr.statutory.pf_employee_rate', label: 'PF — employee %' },
  { key: 'hr.statutory.pf_employer_rate', label: 'PF — employer %' },
  { key: 'hr.statutory.pf_wage_ceiling', label: 'PF wage ceiling ₹ (0 = none)' },
  { key: 'hr.statutory.esi_employee_rate', label: 'ESI — employee %' },
  { key: 'hr.statutory.esi_employer_rate', label: 'ESI — employer %' },
  { key: 'hr.statutory.esi_gross_limit', label: 'ESI applies up to salary ₹' },
  { key: 'hr.statutory.pt_february_top', label: 'PT in February (top slab) ₹' },
]
const settings = ref<Record<string, any>>({})
function resetSettings() {
  settings.value = JSON.parse(JSON.stringify(data.value?.settings ?? {}))
}
const changedSettings = computed(() =>
  Object.keys(settings.value).filter((k) => JSON.stringify(settings.value[k]) !== JSON.stringify(data.value?.settings[k])),
)
async function saveSettings() {
  busy.value = true
  try {
    const payload = Object.fromEntries(changedSettings.value.map((k) => [k, settings.value[k]]))
    data.value = (await hrApi.saveSettings(payload)).data.data
    resetSettings()
    toast.success('Settings saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
const settingLabel = (k: string) => SETTINGS.find((s) => s.key === k)?.label ?? (k.includes('pt_slabs_female') ? 'PT slabs (women)' : k.includes('pt_slabs') ? 'PT slabs (men)' : k)

// ---------------------------------------------------------------- shifts
const shift = ref<Partial<Shift> | null>(null)
const newShift = (): Partial<Shift> => ({ name: '', start_time: '09:30', end_time: '19:00', break_minutes: 30, late_grace_minutes: 10, half_day_minutes: 240, is_active: true })
async function saveShift() {
  const s = { ...shift.value!, start_time: shift.value!.start_time!.slice(0, 5), end_time: shift.value!.end_time!.slice(0, 5) }
  if (await run(() => hrApi.saveShift(s), 'Shift saved')) shift.value = null
}

// -------------------------------------------------------------- holidays
const holiday = ref<Partial<Holiday> | null>(null)
async function saveHoliday() {
  if (await run(() => hrApi.saveHoliday(holiday.value!), 'Holiday saved')) holiday.value = null
}
async function deleteHoliday(h: Holiday) {
  await run(() => hrApi.deleteHoliday(h.id), 'Holiday removed')
}
const branchName = (id: number | null) => (id ? auth.branches.find((b) => b.id === id)?.name ?? '—' : 'All branches')

// ----------------------------------------------------------- leave types
const leaveType = ref<Partial<LeaveType> | null>(null)
async function saveLeaveType() {
  if (await run(() => hrApi.saveLeaveType(leaveType.value!), 'Leave type saved')) leaveType.value = null
}

// ------------------------------------------------------- incentive rules
const rule = ref<Partial<IncentiveRule> | null>(null)
async function saveRule() {
  if (await run(() => hrApi.saveIncentiveRule(rule.value!), 'Incentive rule saved')) rule.value = null
}
async function deleteRule(r: IncentiveRule) {
  await run(() => hrApi.deleteIncentiveRule(r.id), 'Rule removed')
}
const isPct = (basis?: string) => !!basis && basis.startsWith('pct_')
const roleName = (slug: string) => data.value?.roles.find((r) => r.slug === slug)?.name ?? slug
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">HR &amp; Payroll masters</h1>
      <p class="text-sm text-gray-500">Shifts, holidays, leave, incentives and how pay is worked out.</p>
    </div>

    <div class="mb-4 flex gap-1 overflow-x-auto scrollbar-none rounded-xl bg-gray-100 p-1 text-sm font-medium">
      <button
        v-for="t in ([['settings', 'Pay & OT'], ['shifts', 'Shifts'], ['holidays', 'Holidays'], ['leave', 'Leave types'], ['incentives', 'Incentive rules']] as const)"
        :key="t[0]"
        type="button"
        class="flex-1 whitespace-nowrap rounded-lg px-3 py-1.5"
        :class="tab === t[0] ? 'bg-white shadow-sm' : 'text-gray-600'"
        @click="tab = t[0]"
      >{{ t[1] }}</button>
    </div>

    <div v-if="!data" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <template v-else>
      <!-- Settings -->
      <template v-if="tab === 'settings'">
        <Card class="mb-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <label v-for="s in SETTINGS" :key="s.key" class="block" :class="s.type === 'bool' ? 'sm:col-span-2' : ''">
              <template v-if="s.type === 'bool'">
                <span class="flex items-center gap-2 font-medium text-gray-800">
                  <input v-model="settings[s.key]" type="checkbox" class="h-4 w-4 rounded" :disabled="!canEdit" /> {{ s.label }}
                </span>
              </template>
              <template v-else>
                <span class="text-gray-600">{{ s.label }}</span>
                <input v-model.number="settings[s.key]" type="number" min="0" step="any" class="input mt-1" :disabled="!canEdit || (s.key.includes('statutory') && !settings['hr.statutory.enabled']) || (s.key.startsWith('hr.tracking.') && !settings['hr.tracking.enabled'])" />
              </template>
              <span v-if="s.hint" class="mt-0.5 block text-xs text-gray-500">{{ s.hint }}</span>
            </label>
          </div>
        </Card>
        <Card v-if="settings['hr.statutory.enabled']" class="mb-4">
          <template #header><h3 class="font-semibold">Professional tax slabs (Maharashtra, monthly)</h3></template>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 text-sm">
            <div v-for="key in ['hr.statutory.pt_slabs_male', 'hr.statutory.pt_slabs_female']" :key="key">
              <p class="mb-1 font-medium">{{ key.endsWith('female') ? 'Women' : 'Men' }}</p>
              <div v-for="(slab, i) in settings[key]" :key="i" class="mb-1 flex items-center gap-2">
                <span class="text-gray-500 w-16">Up to ₹</span>
                <input v-model.number="slab[0]" type="number" class="input w-28" :placeholder="'above'" :disabled="!canEdit" />
                <span class="text-gray-500">tax ₹</span>
                <input v-model.number="slab[1]" type="number" class="input w-20" :disabled="!canEdit" />
              </div>
              <p class="text-xs text-gray-500">Leave the last "up to" blank for "above".</p>
            </div>
          </div>
        </Card>
        <UnsavedChangesBar
          v-if="canEdit"
          :count="changedSettings.length"
          :labels="changedSettings.map(settingLabel)"
          :saving="busy"
          @save="saveSettings"
          @discard="resetSettings"
        />
      </template>

      <!-- Shifts -->
      <Card v-if="tab === 'shifts'" :padding="false">
        <template #header>
          <div class="flex items-center justify-between"><h3 class="font-semibold">Shifts</h3><Button v-if="canEdit" size="sm" @click="shift = newShift()">Add shift</Button></div>
        </template>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="s in data.shifts" :key="s.id">
            <button type="button" class="w-full px-4 py-3 text-left hover:bg-gray-50" :disabled="!canEdit" @click="shift = { ...s }">
              <span class="font-semibold">{{ s.name }}</span> <Badge v-if="!s.is_active" size="sm">Inactive</Badge>
              <span class="block text-xs text-gray-500">{{ s.start_time.slice(0, 5) }} – {{ s.end_time.slice(0, 5) }} · break {{ s.break_minutes }} min · late after {{ s.late_grace_minutes }} min · half day under {{ s.half_day_minutes / 60 }} h</span>
            </button>
          </li>
        </ul>
        <p class="px-4 py-2 text-xs text-gray-500">The first active shift is everyone's default; pick another per employee.</p>
      </Card>

      <!-- Holidays -->
      <Card v-if="tab === 'holidays'" :padding="false">
        <template #header>
          <div class="flex items-center justify-between"><h3 class="font-semibold">Paid holidays</h3><Button v-if="canEdit" size="sm" @click="holiday = { date: '', name: '', branch_id: null }">Add holiday</Button></div>
        </template>
        <p v-if="!data.holidays.length" class="p-4 text-sm text-gray-500">No holidays yet.</p>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="h in data.holidays" :key="h.id" class="px-4 py-2 flex items-center gap-3">
            <span class="w-24 text-gray-600">{{ h.date }}</span>
            <span class="flex-1 font-medium">{{ h.name }} <span class="font-normal text-gray-500">· {{ branchName(h.branch_id) }}</span></span>
            <template v-if="canEdit">
              <button type="button" class="text-xs text-primary-700" @click="holiday = { ...h }">Edit</button>
              <button type="button" class="text-xs text-gray-500 hover:text-red-700" @click="deleteHoliday(h)">Remove</button>
            </template>
          </li>
        </ul>
        <p class="px-4 py-2 text-xs text-gray-500">Holidays are paid; working on one earns the off-day amount.</p>
      </Card>

      <!-- Leave types -->
      <Card v-if="tab === 'leave'" :padding="false">
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">Leave types</h3>
            <Button v-if="canEdit" size="sm" @click="leaveType = { name: '', code: '', is_paid: true, annual_quota: 0, carry_forward: false, max_carry: 0, is_active: true }">Add</Button>
          </div>
        </template>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="t in data.leave_types" :key="t.id">
            <button type="button" class="w-full px-4 py-3 text-left hover:bg-gray-50" :disabled="!canEdit" @click="leaveType = { ...t }">
              <span class="font-semibold">{{ t.name }} ({{ t.code }})</span>
              <Badge size="sm" class="ml-1" :variant="t.is_paid ? 'success' : 'default'">{{ t.is_paid ? 'Paid' : 'Unpaid' }}</Badge>
              <Badge v-if="!t.is_active" size="sm" class="ml-1">Inactive</Badge>
              <span v-if="t.is_paid" class="block text-xs text-gray-500">{{ t.annual_quota }} days a year<template v-if="t.carry_forward"> · up to {{ t.max_carry }} carried forward</template></span>
            </button>
          </li>
        </ul>
      </Card>

      <!-- Incentive rules -->
      <Card v-if="tab === 'incentives'" :padding="false">
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">Incentive rules</h3>
            <Button v-if="canEdit" size="sm" @click="rule = { name: '', roles: ['technician'], basis: 'pct_labour', package_id: null, value: 0, min_monthly_billing: 0, is_active: true }">Add rule</Button>
          </div>
        </template>
        <p v-if="!data.incentive_rules.length" class="p-4 text-sm text-gray-500">No automatic incentives. Manual incentives can be added on the Payroll screen.</p>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="r in data.incentive_rules" :key="r.id" class="px-4 py-3 flex items-start gap-3">
            <button type="button" class="flex-1 text-left" :disabled="!canEdit" @click="rule = { ...r, roles: [...r.roles] }">
              <span class="font-semibold">{{ r.name }}</span> <Badge v-if="!r.is_active" size="sm">Off</Badge>
              <span class="block text-xs text-gray-500">
                {{ isPct(r.basis) ? r.value + '%' : '₹' + r.value }} — {{ data.incentive_bases[r.basis] }} · {{ r.roles.map(roleName).join(', ') }}
                <template v-if="r.min_monthly_billing"> · only from ₹{{ r.min_monthly_billing }} billed in the month</template>
              </span>
            </button>
            <button v-if="canEdit" type="button" class="text-xs text-gray-500 hover:text-red-700" @click="deleteRule(r)">Remove</button>
          </li>
        </ul>
        <p class="px-4 py-2 text-xs text-gray-500">Worked out from the month's bills (before GST). Technicians on a job share it evenly; the job's advisor gets advisor rules; other roles earn on every bill of their branches.</p>
      </Card>
    </template>

    <!-- Shift form -->
    <Modal :open="!!shift" :title="shift?.id ? 'Edit shift' : 'New shift'" @close="shift = null">
      <div v-if="shift" class="grid grid-cols-2 gap-3 text-sm">
        <div class="col-span-2"><label class="label">Name</label><input v-model="shift.name" class="input" /></div>
        <div><label class="label">Starts</label><input v-model="shift.start_time" type="time" class="input" /></div>
        <div><label class="label">Ends</label><input v-model="shift.end_time" type="time" class="input" /></div>
        <div><label class="label">Break (min)</label><input v-model.number="shift.break_minutes" type="number" min="0" class="input" /></div>
        <div><label class="label">Late after (min)</label><input v-model.number="shift.late_grace_minutes" type="number" min="0" class="input" /></div>
        <div><label class="label">Half day if worked under (min)</label><input v-model.number="shift.half_day_minutes" type="number" min="0" class="input" /></div>
        <label class="flex items-center gap-2 self-end"><input v-model="shift.is_active" type="checkbox" class="h-4 w-4 rounded" /> Active</label>
      </div>
      <template #footer>
        <Button variant="secondary" @click="shift = null">Cancel</Button>
        <Button :loading="busy" :disabled="!shift?.name" @click="saveShift">Save</Button>
      </template>
    </Modal>

    <!-- Holiday form -->
    <Modal :open="!!holiday" :title="holiday?.id ? 'Edit holiday' : 'New holiday'" size="sm" @close="holiday = null">
      <div v-if="holiday" class="space-y-3 text-sm">
        <div><label class="label">Date</label><input v-model="holiday.date" type="date" class="input" /></div>
        <div><label class="label">Name</label><input v-model="holiday.name" class="input" placeholder="Diwali" /></div>
        <div>
          <label class="label">Branch</label>
          <select v-model="holiday.branch_id" class="input">
            <option :value="null">All branches</option>
            <option v-for="b in auth.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="holiday = null">Cancel</Button>
        <Button :loading="busy" :disabled="!holiday?.date || !holiday?.name" @click="saveHoliday">Save</Button>
      </template>
    </Modal>

    <!-- Leave type form -->
    <Modal :open="!!leaveType" :title="leaveType?.id ? 'Edit leave type' : 'New leave type'" size="sm" @close="leaveType = null">
      <div v-if="leaveType" class="space-y-3 text-sm">
        <div class="grid grid-cols-3 gap-3">
          <div class="col-span-2"><label class="label">Name</label><input v-model="leaveType.name" class="input" /></div>
          <div><label class="label">Code</label><input v-model="leaveType.code" class="input uppercase" /></div>
        </div>
        <label class="flex items-center gap-2"><input v-model="leaveType.is_paid" type="checkbox" class="h-4 w-4 rounded" /> Paid</label>
        <template v-if="leaveType.is_paid">
          <div><label class="label">Days a year</label><input v-model.number="leaveType.annual_quota" type="number" min="0" step="0.5" class="input" /></div>
          <label class="flex items-center gap-2"><input v-model="leaveType.carry_forward" type="checkbox" class="h-4 w-4 rounded" /> Unused days carry forward</label>
          <div v-if="leaveType.carry_forward"><label class="label">At most (days)</label><input v-model.number="leaveType.max_carry" type="number" min="0" step="0.5" class="input" /></div>
        </template>
        <label class="flex items-center gap-2"><input v-model="leaveType.is_active" type="checkbox" class="h-4 w-4 rounded" /> Active</label>
      </div>
      <template #footer>
        <Button variant="secondary" @click="leaveType = null">Cancel</Button>
        <Button :loading="busy" :disabled="!leaveType?.name || !leaveType?.code" @click="saveLeaveType">Save</Button>
      </template>
    </Modal>

    <!-- Incentive rule form -->
    <Modal :open="!!rule" :title="rule?.id ? 'Edit incentive rule' : 'New incentive rule'" @close="rule = null">
      <div v-if="rule && data" class="space-y-3 text-sm">
        <div><label class="label">Name (shown on the payslip)</label><input v-model="rule.name" class="input" placeholder="Labour incentive" /></div>
        <div>
          <label class="label">Basis</label>
          <select v-model="rule.basis" class="input">
            <option v-for="(label, k) in data.incentive_bases" :key="k" :value="k">{{ label }}</option>
          </select>
        </div>
        <div v-if="rule.basis === 'per_plan_sold'">
          <label class="label">Plan</label>
          <select v-model="rule.package_id" class="input">
            <option :value="null">Any service plan</option>
            <option v-for="p in data.plans" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">{{ isPct(rule.basis) ? 'Percent' : 'Rupees' }}</label><input v-model.number="rule.value" type="number" min="0" step="any" class="input" /></div>
          <div><label class="label">Only from ₹ billed / month</label><input v-model.number="rule.min_monthly_billing" type="number" min="0" class="input" /></div>
        </div>
        <div>
          <label class="label">Who earns it</label>
          <div class="grid grid-cols-2 gap-1">
            <label v-for="r in data.roles" :key="r.slug" class="flex items-center gap-2">
              <input v-model="rule.roles" :value="r.slug" type="checkbox" class="h-4 w-4 rounded" /> {{ r.name }}
            </label>
          </div>
        </div>
        <label class="flex items-center gap-2"><input v-model="rule.is_active" type="checkbox" class="h-4 w-4 rounded" /> Active</label>
      </div>
      <template #footer>
        <Button variant="secondary" @click="rule = null">Cancel</Button>
        <Button :loading="busy" :disabled="!rule?.name || !rule?.roles?.length" @click="saveRule">Save</Button>
      </template>
    </Modal>
  </div>
</template>
