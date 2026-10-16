<script setup lang="ts">
/**
 * Suppliers and what the workshop owes each (credit GRNs less payments made); a
 * supplier's ledger and "Record payment to supplier".
 */
import { ref, computed, onMounted } from 'vue'
import { purchasesApi, money, BILL_TYPE_LABELS, type SupplierDue, type SupplierLedger } from '@/api/purchases.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const { can } = usePermissions()
const toast = useToast()

const suppliers = ref<SupplierDue[]>([])
const totalDue = ref(0)
const methods = ref<string[]>([])
const loading = ref(true)
const dueOnly = ref(false)
const METHODS: Record<string, string> = { cash: 'Cash', card: 'Card', upi: 'UPI', bank_transfer: 'Bank transfer', cheque: 'Cheque', wallet: 'Wallet' }

const shown = computed(() => suppliers.value.filter((s) => !dueOnly.value || s.due > 0))

async function load() {
  loading.value = true
  try {
    const data = (await purchasesApi.suppliers()).data.data
    suppliers.value = data.suppliers
    totalDue.value = data.total_due
    methods.value = data.methods
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

const ledger = ref<SupplierLedger | null>(null)
async function openLedger(s: SupplierDue) {
  try {
    ledger.value = (await purchasesApi.supplier(s.id)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}

const payOpen = ref(false)
const pay = ref({ amount: '' as number | string, method: 'bank_transfer', reference_number: '', notes: '' })
const busy = ref(false)
function openPay() {
  pay.value = { amount: ledger.value?.balance.due ?? '', method: 'bank_transfer', reference_number: '', notes: '' }
  payOpen.value = true
}
async function savePay() {
  if (!ledger.value) return
  busy.value = true
  try {
    ledger.value = (
      await purchasesApi.paySupplier(ledger.value.supplier.id, {
        amount: Number(pay.value.amount),
        method: pay.value.method,
        reference_number: pay.value.reference_number || null,
        notes: pay.value.notes || null,
      })
    ).data.data
    toast.success('Payment recorded')
    payOpen.value = false
    load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">Suppliers</h1>
      <p class="text-sm text-gray-500">Owed to suppliers on credit purchases: <b class="text-gray-900">₹ {{ money(totalDue, 0) }}</b></p>
    </div>

    <label class="mb-3 flex items-center gap-2 text-sm text-gray-700">
      <input v-model="dueOnly" type="checkbox" class="h-4 w-4 rounded" /> Only suppliers we owe
    </label>

    <div v-if="loading" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <Card v-else :padding="false">
      <ul class="divide-y divide-gray-100">
        <li v-for="s in shown" :key="s.id">
          <button type="button" class="w-full text-left px-4 py-3 flex items-center gap-3 hover:bg-gray-50" @click="openLedger(s)">
            <span class="min-w-0 flex-1">
              <span class="block font-semibold text-gray-900">{{ s.name }}</span>
              <span class="block text-xs text-gray-500">{{ [s.phone, s.gstin].filter(Boolean).join(' · ') || '—' }}</span>
            </span>
            <span class="text-right">
              <span class="block font-semibold" :class="s.due > 0 ? 'text-red-700' : 'text-gray-500'">₹ {{ money(s.due, 0) }}</span>
              <span class="block text-xs text-gray-500">due</span>
            </span>
          </button>
        </li>
      </ul>
    </Card>

    <Modal :open="!!ledger" :title="ledger?.supplier.name ?? ''" size="lg" @close="ledger = null">
      <div v-if="ledger" class="space-y-4">
        <dl class="grid grid-cols-3 gap-2 text-sm">
          <div class="rounded-lg bg-gray-50 p-2"><dt class="text-xs text-gray-500">Bought on credit</dt><dd class="font-semibold">₹ {{ money(ledger.balance.purchases, 0) }}</dd></div>
          <div class="rounded-lg bg-gray-50 p-2"><dt class="text-xs text-gray-500">Paid</dt><dd class="font-semibold">₹ {{ money(ledger.balance.paid, 0) }}</dd></div>
          <div class="rounded-lg p-2" :class="ledger.balance.due > 0 ? 'bg-red-50' : 'bg-green-50'"><dt class="text-xs text-gray-500">Due</dt><dd class="font-semibold">₹ {{ money(ledger.balance.due, 0) }}</dd></div>
        </dl>
        <Button v-if="can('purchases.suppliers.pay') && ledger.balance.due > 0" block @click="openPay">Record payment to supplier</Button>

        <section>
          <h4 class="text-sm font-semibold text-gray-700 mb-1">GRNs</h4>
          <p v-if="!ledger.grns.length" class="text-sm text-gray-500">None yet.</p>
          <ul class="divide-y divide-gray-100 text-sm">
            <li v-for="g in ledger.grns" :key="g.id" class="py-2 flex flex-wrap gap-x-3">
              <RouterLink :to="`/purchases/grns/${g.id}`" class="font-medium text-primary-700">{{ g.grn_number }}</RouterLink>
              <span class="text-gray-600">{{ BILL_TYPE_LABELS[g.bill_type] }} {{ g.supplier_bill_no }} · {{ g.bill_date }}</span>
              <Badge v-if="g.status === 'cancelled'" size="sm" variant="danger">Cancelled</Badge>
              <Badge v-else size="sm" :variant="g.payment_mode === 'credit' ? 'warning' : 'default'">{{ g.payment_mode === 'credit' ? 'Credit' : 'Paid' }}</Badge>
              <span class="ml-auto font-medium">₹ {{ money(g.cost_after_returns) }}</span>
            </li>
          </ul>
        </section>

        <section>
          <h4 class="text-sm font-semibold text-gray-700 mb-1">Payments</h4>
          <p v-if="!ledger.payments.length" class="text-sm text-gray-500">None yet.</p>
          <ul class="divide-y divide-gray-100 text-sm">
            <li v-for="p in ledger.payments" :key="p.id" class="py-2 flex flex-wrap gap-x-3">
              <span class="font-medium">₹ {{ money(p.amount) }}</span>
              <span class="text-gray-600">{{ METHODS[p.method] ?? p.method }}<template v-if="p.reference_number"> · {{ p.reference_number }}</template></span>
              <span class="ml-auto text-xs text-gray-500">{{ p.paid_on }}<template v-if="p.by"> · {{ p.by }}</template></span>
            </li>
          </ul>
        </section>
      </div>
    </Modal>

    <Modal :open="payOpen" title="Payment to supplier" @close="payOpen = false">
      <div class="space-y-3">
        <div><label class="label">Amount (₹)</label><input v-model="pay.amount" type="number" min="1" class="input" /></div>
        <div>
          <label class="label">Paid by</label>
          <select v-model="pay.method" class="input">
            <option v-for="m in methods" :key="m" :value="m">{{ METHODS[m] ?? m }}</option>
          </select>
        </div>
        <div><label class="label">Reference</label><input v-model="pay.reference_number" class="input" placeholder="UTR / cheque no." /></div>
        <div><label class="label">Note</label><input v-model="pay.notes" class="input" /></div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="payOpen = false">Cancel</Button>
        <Button :loading="busy" :disabled="!(Number(pay.amount) > 0)" @click="savePay">Save payment</Button>
      </template>
    </Modal>
  </div>
</template>
