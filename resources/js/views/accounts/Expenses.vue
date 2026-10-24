<script setup lang="ts">
/**
 * Expenses: quick entry on the phone (with a photo of the bill), paid in cash, from the
 * bank, from customer money an employee holds, or owed to a supplier. GST on a GST bill
 * is claimed as input tax.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { accountsApi, inr, PAID_VIA, type ExpenseRow, type ExpenseOptions } from '@/api/accounts.api'
import { localDate } from '@/api/hr.api'
import { useBranchStore } from '@/stores/branch.store'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'
import MediaPicker from '@/components/media/MediaPicker.vue'

const branchStore = useBranchStore()
const { can } = usePermissions()
const toast = useToast()

const today = localDate()
const from = ref(today.slice(0, 8) + '01')
const to = ref(today)
const q = ref('')
const rows = ref<ExpenseRow[]>([])
const total = ref(0)
const byHead = ref<{ account: string; total: number }[]>([])
const loading = ref(true)
const options = ref<ExpenseOptions | null>(null)

async function load() {
  loading.value = true
  try {
    const data = (await accountsApi.expenses({ from: from.value, to: to.value, branch_id: branchStore.currentBranchId, q: q.value || undefined })).data.data
    rows.value = data.rows
    total.value = data.total
    byHead.value = data.by_head
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}
watch([from, to, () => branchStore.currentBranchId], load)
onMounted(async () => {
  load()
  try {
    options.value = (await accountsApi.expenseOptions()).data.data
  } catch (err) {
    toast.apiError(err)
  }
})

// ------------------------------------------------------------------ add
const addOpen = ref(false)
const bill = ref<File | null>(null)
const busy = ref(false)
const blank = () => ({
  expense_date: localDate(),
  account_id: 0,
  paid_to: '',
  amount: '' as number | string,
  gst_bill: false,
  gst_rate: 18,
  bill_number: '',
  supplier_gstin: '',
  paid_via: 'cash' as ExpenseRow['paid_via'],
  bank_account_id: null as number | null,
  paid_by_user_id: null as number | null,
  supplier_id: null as number | null,
  note: '',
})
const form = ref(blank())
const gstIn = computed(() => {
  const a = Number(form.value.amount) || 0
  return form.value.gst_bill ? Math.round((a - a / (1 + form.value.gst_rate / 100)) * 100) / 100 : 0
})
const holderMax = computed(() => options.value?.staff_holding.find((s) => s.user_id === form.value.paid_by_user_id)?.held ?? 0)

function openAdd() {
  form.value = blank()
  form.value.bank_account_id = options.value?.banks.find((b) => b.is_default)?.id ?? null
  bill.value = null
  addOpen.value = true
}
function onBill(e: Event) {
  const input = e.target as HTMLInputElement
  bill.value = input.files?.[0] ?? null
}
async function save() {
  busy.value = true
  try {
    const fd = new FormData()
    const f = form.value
    const fields: Record<string, unknown> = {
      expense_date: f.expense_date, account_id: f.account_id, paid_to: f.paid_to, amount: f.amount, gst_bill: f.gst_bill ? 1 : 0,
      paid_via: f.paid_via, note: f.note, branch_id: branchStore.currentBranchId,
    }
    if (f.gst_bill) Object.assign(fields, { gst_rate: f.gst_rate, bill_number: f.bill_number, supplier_gstin: f.supplier_gstin })
    if (f.paid_via === 'bank') fields.bank_account_id = f.bank_account_id
    if (f.paid_via === 'staff_custody') fields.paid_by_user_id = f.paid_by_user_id
    if (f.paid_via === 'credit') fields.supplier_id = f.supplier_id
    for (const [k, v] of Object.entries(fields)) if (v !== null && v !== undefined && v !== '') fd.append(k, String(v))
    if (bill.value) fd.append('bill', bill.value)

    const res = await accountsApi.addExpense(fd)
    toast.success(res.data.message ?? 'Saved')
    addOpen.value = false
    load()
    options.value = (await accountsApi.expenseOptions()).data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

// -------------------------------------------------------------- detail
const open = ref<ExpenseRow | null>(null)
const cancelReason = ref('')
async function viewBill(e: ExpenseRow) {
  try {
    window.open((await accountsApi.expenseBillUrl(e.id)).data.data.url, '_blank')
  } catch (err) {
    toast.apiError(err)
  }
}
async function cancel() {
  if (!open.value) return
  busy.value = true
  try {
    await accountsApi.cancelExpense(open.value.id, cancelReason.value)
    toast.success('Expense cancelled')
    open.value = null
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
        <h1 class="text-2xl font-bold text-gray-900">Expenses</h1>
        <p class="text-sm text-gray-500">Total {{ inr(total) }} from {{ from }} to {{ to }}</p>
      </div>
      <Button v-if="can('accounts.expenses.create')" @click="openAdd">Add expense</Button>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-2">
      <input v-model="from" type="date" class="input w-auto" />
      <span class="text-gray-400">→</span>
      <input v-model="to" type="date" class="input w-auto" />
      <input v-model="q" class="input w-48" placeholder="Search" @keyup.enter="load" />
    </div>

    <div v-if="byHead.length" class="mb-3 flex gap-2 overflow-x-auto scrollbar-none">
      <span v-for="h in byHead" :key="h.account" class="shrink-0 rounded-full bg-white ring-1 ring-gray-200 px-3 py-1 text-xs">{{ h.account }} <b>{{ inr(h.total) }}</b></span>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><Spinner size="lg" /></div>
    <Card v-else :padding="false">
      <p v-if="!rows.length" class="p-4 text-sm text-gray-500">No expenses in these dates.</p>
      <ul class="divide-y divide-gray-100">
        <li v-for="e in rows" :key="e.id">
          <button type="button" class="w-full px-4 py-3 text-left flex items-center gap-3 hover:bg-gray-50" :class="e.status === 'cancelled' ? 'opacity-50' : ''" @click="open = e; cancelReason = ''">
            <span class="w-16 shrink-0 text-xs text-gray-500">{{ e.date.slice(5) }}</span>
            <span class="min-w-0 flex-1">
              <span class="block font-medium text-gray-900 truncate">{{ e.paid_to }}</span>
              <span class="block text-xs text-gray-500 truncate">{{ e.account }} · {{ PAID_VIA[e.paid_via] }}<template v-if="e.paid_by"> ({{ e.paid_by }})</template><template v-if="e.job_number"> · {{ e.job_number }}</template></span>
            </span>
            <span class="text-right">
              <span class="block font-semibold" :class="e.status === 'cancelled' ? 'line-through' : ''">{{ inr(e.amount) }}</span>
              <Badge v-if="e.gst_bill" size="sm" variant="info">GST</Badge>
            </span>
          </button>
        </li>
      </ul>
    </Card>

    <!-- Add -->
    <Modal :open="addOpen" title="Add expense" @close="addOpen = false">
      <div v-if="options" class="space-y-3 text-sm">
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Date</label><input v-model="form.expense_date" type="date" class="input" :max="today" /></div>
          <div><label class="label">Amount ₹ (incl. GST)</label><input v-model="form.amount" type="number" min="1" step="0.01" class="input" inputmode="decimal" /></div>
        </div>
        <div>
          <label class="label">For</label>
          <select v-model.number="form.account_id" class="input">
            <option :value="0" disabled>Choose…</option>
            <option v-for="h in options.heads" :key="h.id" :value="h.id">{{ h.name }}</option>
          </select>
        </div>
        <div><label class="label">Paid to / item</label><input v-model="form.paid_to" class="input" placeholder="Petrol for Jupiter, porter, bolts…" /></div>
        <div>
          <label class="label">Paid from</label>
          <select v-model="form.paid_via" class="input">
            <option v-for="(label, k) in PAID_VIA" :key="k" :value="k" :disabled="k === 'staff_custody' && !options.staff_holding.length">{{ label }}</option>
          </select>
        </div>
        <div v-if="form.paid_via === 'bank'">
          <label class="label">Bank</label>
          <select v-model="form.bank_account_id" class="input"><option v-for="b in options.banks" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        </div>
        <div v-if="form.paid_via === 'staff_custody'">
          <label class="label">Whose collected money</label>
          <select v-model="form.paid_by_user_id" class="input">
            <option v-for="s in options.staff_holding" :key="s.user_id" :value="s.user_id">{{ s.name }} (holds {{ inr(s.held) }})</option>
          </select>
          <p v-if="form.paid_by_user_id && Number(form.amount) > holderMax" class="mt-1 text-xs text-red-700">More than they hold.</p>
        </div>
        <div v-if="form.paid_via === 'credit'">
          <label class="label">Supplier</label>
          <select v-model="form.supplier_id" class="input"><option v-for="s in options.suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
        </div>
        <label class="flex items-center gap-2"><input v-model="form.gst_bill" type="checkbox" class="h-4 w-4 rounded" /> GST bill (claim the GST)</label>
        <div v-if="form.gst_bill" class="grid grid-cols-3 gap-3">
          <div>
            <label class="label">GST %</label>
            <select v-model.number="form.gst_rate" class="input"><option v-for="r in [5, 12, 18, 28]" :key="r" :value="r">{{ r }}%</option></select>
          </div>
          <div><label class="label">Bill no.</label><input v-model="form.bill_number" class="input" /></div>
          <div><label class="label">Supplier GSTIN</label><input v-model="form.supplier_gstin" class="input uppercase" /></div>
          <p class="col-span-3 text-xs text-gray-500">GST in this bill: {{ inr(gstIn, 2) }}</p>
        </div>
        <div><label class="label">Note</label><input v-model="form.note" class="input" placeholder="Car / job, if for one" /></div>
        <div>
          <label class="label">Photo of the bill</label>
          <MediaPicker size="sm" :kinds="['photo']" files @change="onBill" />
        </div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="addOpen = false">Cancel</Button>
        <Button :loading="busy" :disabled="!form.account_id || !form.paid_to.trim() || !(Number(form.amount) > 0)" @click="save">Save</Button>
      </template>
    </Modal>

    <!-- Detail -->
    <Modal :open="!!open" :title="open ? `${open.number} — ${open.paid_to}` : ''" @close="open = null">
      <div v-if="open" class="space-y-2 text-sm">
        <p><b>{{ inr(open.amount, 2) }}</b> on {{ open.date }} · {{ open.account }}</p>
        <p>{{ PAID_VIA[open.paid_via] }}<template v-if="open.bank"> — {{ open.bank }}</template><template v-if="open.paid_by"> — {{ open.paid_by }}</template><template v-if="open.supplier"> — {{ open.supplier }}</template></p>
        <p v-if="open.gst_bill">GST bill {{ open.bill_number }} · {{ open.gst_rate }}% · GST {{ inr(open.gst_amount, 2) }}</p>
        <p v-if="open.note" class="text-gray-600">{{ open.note }}</p>
        <p class="text-xs text-gray-500">Entered by {{ open.by }}</p>
        <Button v-if="open.has_bill" size="sm" variant="secondary" @click="viewBill(open)">View bill</Button>
        <p v-if="open.status === 'cancelled'" class="text-red-700">Cancelled: {{ open.cancel_reason }}</p>
        <div v-else-if="can('accounts.journals.create')" class="border-t pt-3">
          <label class="label">Cancel (entered wrongly)</label>
          <input v-model="cancelReason" class="input" placeholder="Reason" />
          <Button class="mt-2" size="sm" variant="danger" :loading="busy" :disabled="!cancelReason.trim()" @click="cancel">Cancel expense</Button>
        </div>
      </div>
    </Modal>
  </div>
</template>
