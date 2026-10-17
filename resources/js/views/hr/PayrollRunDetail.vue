<script setup lang="ts">
/**
 * One month's payroll: each payslip with its lines (a line can be corrected with a
 * reason), recalculate, finalise (owner), pay, payslip PDFs.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { hrApi, PAY_METHODS, openBlob, localDate, type PayrollRunRow, type Payslip, type PayslipLineRow } from '@/api/hr.api'
import { inr } from '@/api/accounts.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const toast = useToast()

const run = ref<PayrollRunRow | null>(null)
const busy = ref(false)
const open = ref<number | null>(null)
const q = ref('')

const slips = computed(() => (run.value?.payslips ?? []).filter((s) => !q.value || (s.employee ?? '').toLowerCase().includes(q.value.toLowerCase())))
const draft = computed(() => run.value?.status === 'draft')

async function load() {
  try {
    run.value = (await hrApi.run(Number(route.params.id))).data.data
  } catch (err) {
    toast.apiError(err)
  }
}
onMounted(load)

async function act(fn: () => Promise<{ data: { data: PayrollRunRow; message?: string } }>) {
  busy.value = true
  try {
    const res = await fn()
    run.value = res.data.data
    toast.success(res.data.message ?? 'Done')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

const recalc = () => act(() => hrApi.recalculate(run.value!.id))
const confirmFinalise = ref(false)
const finalise = async () => {
  confirmFinalise.value = false
  await act(() => hrApi.finalise(run.value!.id))
}
const reopen = () => act(() => hrApi.reopen(run.value!.id))

async function remove() {
  if (!run.value) return
  busy.value = true
  try {
    await hrApi.deleteRun(run.value.id)
    toast.success('Draft deleted')
    router.push('/hr/payroll')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

// Correct a line
const editing = ref<PayslipLineRow | null>(null)
const editForm = ref({ amount: 0, note: '' })
function editLine(l: PayslipLineRow) {
  editing.value = l
  editForm.value = { amount: l.amount, note: l.edited_note ?? '' }
}
async function saveLine() {
  if (!editing.value) return
  const line = editing.value
  editing.value = null
  await act(() => hrApi.editLine(line.id, Number(editForm.value.amount), editForm.value.note))
}

// Pay
const payOpen = ref(false)
const payIds = ref<number[]>([])
const payForm = ref({ method: 'bank_transfer', paid_on: localDate(), reference: '' })
function openPay(slip?: Payslip) {
  payIds.value = slip ? [slip.id] : []
  payForm.value = { method: 'bank_transfer', paid_on: localDate(), reference: '' }
  payOpen.value = true
}
async function pay() {
  payOpen.value = false
  await act(() => hrApi.pay(run.value!.id, { payslip_ids: payIds.value, ...payForm.value, reference: payForm.value.reference || null }))
}

async function pdf(s: Payslip) {
  try {
    const res = await hrApi.payslipPdf(s.id)
    openBlob(res.data as Blob, `payslip-${s.employee}-${s.period}.pdf`)
  } catch (err) {
    toast.apiError(err)
  }
}

const STATUS: Record<string, { label: string; variant: 'warning' | 'info' | 'success' }> = {
  draft: { label: 'Draft', variant: 'warning' },
  finalised: { label: 'Finalised', variant: 'info' },
  paid: { label: 'Paid', variant: 'success' },
}
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div v-if="!run" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <template v-else>
      <div class="mb-4">
        <RouterLink to="/hr/payroll" class="text-sm text-primary-700">‹ Payroll</RouterLink>
        <div class="mt-1 flex flex-wrap items-center gap-2">
          <h1 class="text-2xl font-bold text-gray-900">{{ run.label }}</h1>
          <Badge :variant="STATUS[run.status].variant">{{ STATUS[run.status].label }}</Badge>
          <span class="text-sm text-gray-500">{{ run.branch }}</span>
        </div>
        <p v-if="run.finalised_by" class="text-xs text-gray-500">Finalised by {{ run.finalised_by }}</p>
      </div>

      <div class="mb-4 grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm">
        <div class="rounded-xl bg-white ring-1 ring-gray-200 p-3"><p class="text-xs text-gray-500">Gross</p><p class="font-semibold">{{ inr(run.gross) }}</p></div>
        <div class="rounded-xl bg-white ring-1 ring-gray-200 p-3"><p class="text-xs text-gray-500">Deductions</p><p class="font-semibold">{{ inr(run.deductions) }}</p></div>
        <div class="rounded-xl bg-green-50 p-3"><p class="text-xs text-gray-500">Net pay</p><p class="font-semibold">{{ inr(run.net) }}</p></div>
        <div class="rounded-xl bg-white ring-1 ring-gray-200 p-3"><p class="text-xs text-gray-500">Employer PF / ESI</p><p class="font-semibold">{{ inr(run.employer_cost) }}</p></div>
      </div>

      <div class="mb-4 flex flex-wrap gap-2">
        <template v-if="draft">
          <Button variant="secondary" :loading="busy" @click="recalc">Recalculate</Button>
          <Button v-if="can('hr.payroll.finalise')" :loading="busy" :disabled="!run.month_over" @click="confirmFinalise = true">Finalise</Button>
          <Button variant="ghost" :loading="busy" @click="remove">Delete draft</Button>
        </template>
        <template v-else>
          <Button v-if="can('hr.payroll.pay') && run.unpaid" :loading="busy" @click="openPay()">Mark all unpaid as paid</Button>
          <Button v-if="can('hr.payroll.finalise') && run.status === 'finalised' && run.unpaid === run.employees" variant="ghost" :loading="busy" @click="reopen">Reopen</Button>
        </template>
      </div>
      <p v-if="draft && !run.month_over" class="mb-3 text-xs text-amber-700">The month is not over: days still ahead are counted as paid. Finalise after the month ends.</p>

      <input v-model="q" class="input mb-3 max-w-xs" placeholder="Search employee" />

      <Card :padding="false">
        <ul class="divide-y divide-gray-100">
          <li v-for="s in slips" :key="s.id">
            <button type="button" class="w-full px-4 py-3 text-left flex items-center gap-3 hover:bg-gray-50" @click="open = open === s.id ? null : s.id">
              <span class="flex-1">
                <span class="block font-semibold text-gray-900">{{ s.employee }} <Badge v-if="s.has_edits" size="sm" variant="warning">Edited</Badge></span>
                <span class="block text-xs text-gray-500">{{ s.paid_days }}/{{ s.days_in_month }} days<template v-if="s.lop_days"> · {{ s.lop_days }} LOP</template><template v-if="s.ot_hours"> · OT {{ s.ot_hours }} h</template></span>
              </span>
              <span class="text-right">
                <span class="block font-semibold">{{ inr(s.net) }}</span>
                <span v-if="run.status !== 'draft'" class="block text-xs" :class="s.pay_status === 'paid' ? 'text-green-700' : 'text-amber-700'">{{ s.pay_status === 'paid' ? 'Paid ' + s.paid_on : 'Unpaid' }}</span>
              </span>
            </button>

            <div v-if="open === s.id" class="px-4 pb-4 text-sm">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div v-for="kind in (['earning', 'deduction'] as const)" :key="kind">
                  <h4 class="mb-1 font-semibold text-gray-700">{{ kind === 'earning' ? 'Earnings' : 'Deductions' }}</h4>
                  <div v-for="l in s.lines.filter((x) => x.kind === kind)" :key="l.id" class="flex items-start gap-2 py-0.5">
                    <span class="flex-1">{{ l.label }}<span v-if="l.edited_note" class="block text-xs text-amber-700">Changed: {{ l.edited_note }}</span></span>
                    <button v-if="draft" type="button" class="font-medium text-primary-700 underline decoration-dotted" @click="editLine(l)">{{ inr(l.amount, 2) }}</button>
                    <span v-else>{{ inr(l.amount, 2) }}</span>
                  </div>
                  <p v-if="!s.lines.some((x) => x.kind === kind)" class="text-gray-400">None</p>
                </div>
              </div>
              <p v-if="s.lines.some((x) => x.kind === 'employer')" class="mt-2 text-xs text-gray-500">
                Employer: <template v-for="l in s.lines.filter((x) => x.kind === 'employer')" :key="l.id">{{ l.label }} {{ inr(l.amount) }} </template>
              </p>
              <div class="mt-3 flex flex-wrap gap-2">
                <Button size="sm" variant="secondary" @click="pdf(s)">Payslip PDF</Button>
                <Button v-if="!draft && s.pay_status === 'unpaid' && can('hr.payroll.pay')" size="sm" @click="openPay(s)">Mark paid</Button>
                <span v-if="s.pay_status === 'paid'" class="text-xs text-gray-500 self-center">{{ PAY_METHODS[s.pay_method ?? ''] ?? s.pay_method }}<template v-if="s.pay_reference"> · {{ s.pay_reference }}</template></span>
              </div>
            </div>
          </li>
        </ul>
      </Card>
    </template>

    <Modal :open="!!editing" :title="editing?.label ?? ''" size="sm" @close="editing = null">
      <div class="space-y-3">
        <div><label class="label">Amount ₹</label><input v-model.number="editForm.amount" type="number" min="0" class="input" /></div>
        <div><label class="label">Why (shown to the owner)</label><input v-model="editForm.note" class="input" /></div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="editing = null">Cancel</Button>
        <Button :disabled="!editForm.note.trim()" @click="saveLine">Save</Button>
      </template>
    </Modal>

    <Modal :open="confirmFinalise" title="Finalise this payroll?" size="sm" @close="confirmFinalise = false">
      <p class="text-sm text-gray-600">The month's attendance is locked, advances and customer money are recovered, and the salary cost goes on the books. It can be reopened until a salary is paid.</p>
      <template #footer>
        <Button variant="secondary" @click="confirmFinalise = false">Cancel</Button>
        <Button @click="finalise">Finalise</Button>
      </template>
    </Modal>

    <Modal :open="payOpen" :title="payIds.length ? 'Salary paid' : 'Mark all unpaid salaries as paid'" size="sm" @close="payOpen = false">
      <div class="space-y-3">
        <div>
          <label class="label">Paid by</label>
          <select v-model="payForm.method" class="input">
            <option v-for="(label, k) in PAY_METHODS" :key="k" :value="k">{{ label }}</option>
          </select>
        </div>
        <div><label class="label">Paid on</label><input v-model="payForm.paid_on" type="date" class="input" :max="localDate()" /></div>
        <div><label class="label">Reference</label><input v-model="payForm.reference" class="input" placeholder="UTR / batch no." /></div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="payOpen = false">Cancel</Button>
        <Button @click="pay">Save</Button>
      </template>
    </Modal>
  </div>
</template>
