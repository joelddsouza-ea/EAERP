<script setup lang="ts">
/**
 * Employees: every user logs in to the app with their phone number; here their HR details —
 * designation, branch, shift, weekly off, joining date and (for those who prepare payroll)
 * salary, OT and off-day rates, PT / PF / ESI and bank details.
 */
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { hrApi, WEEKDAYS, type EmployeeRow, type EmployeeProfile, type Shift } from '@/api/hr.api'
import { inr } from '@/api/accounts.api'
import { useAuthStore } from '@/stores/auth.store'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const auth = useAuthStore()
const { can } = usePermissions()
const toast = useToast()
const canPay = computed(() => can('hr.payroll.prepare'))

const rows = ref<EmployeeRow[]>([])
const shifts = ref<Shift[]>([])
const loading = ref(true)
const q = ref('')
const showInactive = ref(false)

const shown = computed(() => {
  const t = q.value.trim().toLowerCase()
  return rows.value.filter((r) => !t || r.name.toLowerCase().includes(t) || (r.phone ?? '').includes(t) || (r.profile?.designation ?? '').toLowerCase().includes(t))
})

async function load() {
  loading.value = true
  try {
    rows.value = (await hrApi.employees(showInactive.value)).data.data
    if (!shifts.value.length) shifts.value = (await hrApi.masters()).data.data.shifts
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}
onMounted(load)

const editing = ref<EmployeeRow | null>(null)
const form = ref<EmployeeProfile>({} as EmployeeProfile)
const busy = ref(false)

function edit(r: EmployeeRow) {
  editing.value = r
  form.value = {
    branch_id: null,
    employee_code: null,
    designation: null,
    gender: 'male',
    date_joined: null,
    date_left: null,
    shift_id: null,
    weekly_off_day: null,
    attendance_required: true,
    monthly_salary: 0,
    basic_salary: null,
    ot_hourly_rate: null,
    offday_work_amount: null,
    pt_enabled: true,
    pf_enabled: false,
    esi_enabled: false,
    ...(r.profile ?? {}),
  }
}

async function save() {
  if (!editing.value) return
  busy.value = true
  try {
    const payload: Partial<EmployeeProfile> = { ...form.value }
    if (!canPay.value) {
      for (const k of ['monthly_salary', 'basic_salary', 'ot_hourly_rate', 'offday_work_amount', 'pt_enabled', 'pf_enabled', 'esi_enabled', 'uan', 'esic_no', 'pan', 'bank_name', 'bank_account', 'bank_ifsc'] as const) delete payload[k]
    }
    for (const k of ['basic_salary', 'ot_hourly_rate', 'offday_work_amount'] as const) {
      if ((payload[k] as unknown) === '') payload[k] = null
    }
    delete payload.shift
    await hrApi.saveProfile(editing.value.id, payload)
    toast.success('Employee details saved')
    editing.value = null
    load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Employees</h1>
        <p class="text-sm text-gray-500">Each employee signs in to the app with their phone number and password (set under Users).</p>
      </div>
      <RouterLink v-if="can('user-admin.users.create')" to="/users/create" class="text-sm font-medium text-primary-700">+ New user</RouterLink>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-3">
      <input v-model="q" class="input max-w-xs" placeholder="Search name, phone, designation" />
      <label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="showInactive" type="checkbox" class="h-4 w-4 rounded" @change="load" /> Show inactive</label>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><Spinner size="lg" /></div>
    <Card v-else :padding="false">
      <ul class="divide-y divide-gray-100">
        <li v-for="r in shown" :key="r.id">
          <button type="button" class="w-full px-4 py-3 text-left flex items-center gap-3 hover:bg-gray-50" @click="edit(r)">
            <span class="min-w-0 flex-1">
              <span class="block font-semibold text-gray-900">
                {{ r.name }}
                <Badge v-if="!r.is_active" size="sm">Inactive</Badge>
                <Badge v-if="!r.profile" size="sm" variant="warning">Details missing</Badge>
              </span>
              <span class="block text-xs text-gray-500">{{ [r.profile?.designation, r.roles.join(', '), r.phone].filter(Boolean).join(' · ') }}</span>
            </span>
            <span class="text-right text-sm">
              <span v-if="canPay && r.profile" class="block font-medium">{{ inr(r.profile.monthly_salary ?? 0) }}</span>
              <span v-if="r.custody_held > 0" class="block text-xs text-red-700">Holds {{ inr(r.custody_held) }}</span>
              <span v-if="r.advance_due" class="block text-xs text-amber-700">Advance {{ inr(r.advance_due) }}</span>
            </span>
          </button>
        </li>
      </ul>
    </Card>

    <Modal :open="!!editing" :title="editing?.name ?? ''" size="lg" @close="editing = null">
      <div class="space-y-4 text-sm">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div><label class="label">Designation</label><input v-model="form.designation" class="input" placeholder="Senior technician" /></div>
          <div><label class="label">Employee code</label><input v-model="form.employee_code" class="input" /></div>
          <div>
            <label class="label">Branch</label>
            <select v-model="form.branch_id" class="input">
              <option :value="null">—</option>
              <option v-for="b in auth.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </div>
          <div>
            <label class="label">Gender (for professional tax)</label>
            <select v-model="form.gender" class="input"><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select>
          </div>
          <div><label class="label">Joined on</label><input v-model="form.date_joined" type="date" class="input" /></div>
          <div><label class="label">Left on</label><input v-model="form.date_left" type="date" class="input" /></div>
          <div>
            <label class="label">Shift</label>
            <select v-model="form.shift_id" class="input">
              <option :value="null">Default ({{ shifts[0]?.name }})</option>
              <option v-for="s in shifts" :key="s.id" :value="s.id">{{ s.name }} {{ s.start_time.slice(0, 5) }}–{{ s.end_time.slice(0, 5) }}</option>
            </select>
          </div>
          <div>
            <label class="label">Weekly off</label>
            <select v-model="form.weekly_off_day" class="input">
              <option :value="null">Branch's weekly off</option>
              <option v-for="(d, i) in WEEKDAYS" :key="i" :value="i">{{ d }}</option>
            </select>
          </div>
        </div>
        <label class="flex items-center gap-2"><input v-model="form.attendance_required" type="checkbox" class="h-4 w-4 rounded" /> Keeps attendance (punch in / out). Untick for staff paid the full month regardless.</label>

        <template v-if="canPay">
          <h4 class="font-semibold text-gray-700 border-t pt-3">Pay</h4>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div><label class="label">Monthly salary ₹</label><input v-model.number="form.monthly_salary" type="number" min="0" class="input" /></div>
            <div><label class="label">Basic (PF wage) ₹</label><input v-model.number="form.basic_salary" type="number" min="0" class="input" placeholder="= salary" /></div>
            <div><label class="label">OT ₹ / hour</label><input v-model.number="form.ot_hourly_rate" type="number" min="0" class="input" placeholder="Default" /></div>
            <div><label class="label">Off-day work ₹ / day</label><input v-model.number="form.offday_work_amount" type="number" min="0" class="input" placeholder="Default" /></div>
          </div>
          <div class="flex flex-wrap gap-4">
            <label class="flex items-center gap-2"><input v-model="form.pt_enabled" type="checkbox" class="h-4 w-4 rounded" /> Professional tax</label>
            <label class="flex items-center gap-2"><input v-model="form.pf_enabled" type="checkbox" class="h-4 w-4 rounded" /> PF</label>
            <label class="flex items-center gap-2"><input v-model="form.esi_enabled" type="checkbox" class="h-4 w-4 rounded" /> ESI</label>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div><label class="label">UAN</label><input v-model="form.uan" class="input" /></div>
            <div><label class="label">ESIC no.</label><input v-model="form.esic_no" class="input" /></div>
            <div><label class="label">PAN</label><input v-model="form.pan" class="input uppercase" /></div>
            <div><label class="label">Bank</label><input v-model="form.bank_name" class="input" /></div>
            <div><label class="label">Account no.</label><input v-model="form.bank_account" class="input" /></div>
            <div><label class="label">IFSC</label><input v-model="form.bank_ifsc" class="input uppercase" /></div>
          </div>
        </template>
      </div>
      <template #footer>
        <Button variant="secondary" @click="editing = null">Cancel</Button>
        <Button :loading="busy" @click="save">Save</Button>
      </template>
    </Modal>
  </div>
</template>
