<script setup lang="ts">
/**
 * Payroll: one run per month (all branches or one), and the month's manual lines —
 * incentives, bonus, reimbursements, deductions, penalties.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { hrApi, ADJUSTMENT_KINDS, monthLabel, lastMonth, thisMonth, type PayrollRunRow, type Adjustment } from '@/api/hr.api'
import { inr } from '@/api/accounts.api'
import { useAuthStore } from '@/stores/auth.store'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const runs = ref<PayrollRunRow[]>([])
const loading = ref(true)
async function load() {
  loading.value = true
  try {
    runs.value = (await hrApi.runs()).data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

const newOpen = ref(false)
const newForm = ref({ period: lastMonth(), branch_id: null as number | null })
const busy = ref(false)
async function create() {
  busy.value = true
  try {
    const run = (await hrApi.createRun(newForm.value.period, newForm.value.branch_id)).data.data
    toast.success('Payroll worked out')
    router.push(`/hr/payroll/${run.id}`)
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

// --------------------------------------------------------- adjustments
const adjPeriod = ref(thisMonth())
const adjustments = ref<Adjustment[]>([])
const people = ref<{ id: number; name: string }[]>([])
const adjOpen = ref(false)
const adjForm = ref({ user_id: 0, kind: 'incentive' as Adjustment['kind'], amount: '' as number | string, note: '' })

async function loadAdjustments() {
  try {
    adjustments.value = (await hrApi.adjustments(adjPeriod.value)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}
watch(adjPeriod, loadAdjustments)

async function openAdj() {
  if (!people.value.length) {
    try {
      people.value = (await hrApi.staffDirectory()).data.data
    } catch (err) {
      toast.apiError(err)
    }
  }
  adjForm.value = { user_id: people.value[0]?.id ?? 0, kind: 'incentive', amount: '', note: '' }
  adjOpen.value = true
}
async function addAdj() {
  busy.value = true
  try {
    const res = await hrApi.addAdjustment({ ...adjForm.value, period: adjPeriod.value, amount: Number(adjForm.value.amount), note: adjForm.value.note || null })
    toast.success(res.data.message ?? 'Added')
    adjOpen.value = false
    loadAdjustments()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
async function removeAdj(a: Adjustment) {
  try {
    await hrApi.deleteAdjustment(a.id)
    loadAdjustments()
  } catch (err) {
    toast.apiError(err)
  }
}
const earning = (k: string) => ['incentive', 'bonus', 'reimbursement'].includes(k)
const adjTotal = computed(() => adjustments.value.reduce((s, a) => s + (earning(a.kind) ? a.amount : -a.amount), 0))

const STATUS: Record<string, { label: string; variant: 'warning' | 'info' | 'success' }> = {
  draft: { label: 'Draft', variant: 'warning' },
  finalised: { label: 'Finalised', variant: 'info' },
  paid: { label: 'Paid', variant: 'success' },
}

onMounted(() => {
  load()
  loadAdjustments()
})
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Payroll</h1>
        <p class="text-sm text-gray-500">Worked out from attendance, leave, OT, incentive rules, advances and customer money held.</p>
      </div>
      <Button @click="newOpen = true">Work out a month</Button>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><Spinner size="lg" /></div>
    <Card v-else :padding="false" class="mb-6">
      <p v-if="!runs.length" class="p-4 text-sm text-gray-500">No payroll yet. Set salaries under People › Employees, then work out last month.</p>
      <ul class="divide-y divide-gray-100">
        <li v-for="r in runs" :key="r.id">
          <RouterLink :to="`/hr/payroll/${r.id}`" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50">
            <span class="flex-1">
              <span class="block font-semibold text-gray-900">{{ r.label }} <span class="font-normal text-gray-500">· {{ r.branch }}</span></span>
              <span class="block text-xs text-gray-500">{{ r.employees }} employees<template v-if="r.status !== 'draft' && r.unpaid"> · {{ r.unpaid }} unpaid</template></span>
            </span>
            <span class="text-right">
              <span class="block font-semibold">{{ inr(r.net) }}</span>
              <Badge size="sm" :variant="STATUS[r.status].variant">{{ STATUS[r.status].label }}</Badge>
            </span>
          </RouterLink>
        </li>
      </ul>
    </Card>

    <Card>
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="text-lg font-semibold">Manual lines for {{ monthLabel(adjPeriod) }}</h3>
          <div class="flex items-center gap-2">
            <input v-model="adjPeriod" type="month" class="input w-auto" />
            <Button size="sm" @click="openAdj">Add</Button>
          </div>
        </div>
      </template>
      <p v-if="!adjustments.length" class="text-sm text-gray-500">Incentives (beyond the automatic rules), bonus, reimbursements, deductions and penalties for the month. Recalculate the payroll after adding.</p>
      <ul class="divide-y divide-gray-100 text-sm">
        <li v-for="a in adjustments" :key="a.id" class="py-2 flex items-center gap-3">
          <span class="flex-1"><span class="font-medium">{{ a.employee }}</span> · {{ ADJUSTMENT_KINDS[a.kind] }}<span v-if="a.note" class="block text-xs text-gray-500">{{ a.note }}</span></span>
          <span class="font-semibold" :class="earning(a.kind) ? 'text-green-700' : 'text-red-700'">{{ earning(a.kind) ? '+' : '−' }}{{ inr(a.amount) }}</span>
          <button type="button" class="text-xs text-gray-500 hover:text-red-700" @click="removeAdj(a)">Remove</button>
        </li>
      </ul>
      <p v-if="adjustments.length" class="mt-2 text-right text-sm text-gray-600">Net {{ adjTotal >= 0 ? '+' : '−' }}{{ inr(Math.abs(adjTotal)) }}</p>
    </Card>

    <Modal :open="newOpen" title="Work out payroll" @close="newOpen = false">
      <div class="space-y-3">
        <div><label class="label">Month</label><input v-model="newForm.period" type="month" class="input" :max="thisMonth()" /></div>
        <div>
          <label class="label">Branch</label>
          <select v-model="newForm.branch_id" class="input">
            <option :value="null">All branches</option>
            <option v-for="b in auth.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </div>
        <p class="text-xs text-gray-500">A draft can be recalculated and corrected any time; the owner finalises it once the month is over.</p>
      </div>
      <template #footer>
        <Button variant="secondary" @click="newOpen = false">Cancel</Button>
        <Button :loading="busy" @click="create">Work out</Button>
      </template>
    </Modal>

    <Modal :open="adjOpen" title="Add a line to the month's pay" @close="adjOpen = false">
      <div class="space-y-3">
        <div>
          <label class="label">Employee</label>
          <select v-model.number="adjForm.user_id" class="input">
            <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="label">What</label>
          <select v-model="adjForm.kind" class="input">
            <option v-for="(label, k) in ADJUSTMENT_KINDS" :key="k" :value="k">{{ label }}</option>
          </select>
        </div>
        <div><label class="label">Amount ₹</label><input v-model="adjForm.amount" type="number" min="1" class="input" /></div>
        <div><label class="label">Note</label><input v-model="adjForm.note" class="input" placeholder="Shown on the payslip" /></div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="adjOpen = false">Cancel</Button>
        <Button :loading="busy" :disabled="!adjForm.user_id || !(Number(adjForm.amount) > 0)" @click="addAdj">Add</Button>
      </template>
    </Modal>
  </div>
</template>
