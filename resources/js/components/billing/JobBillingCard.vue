<script setup lang="ts">
/**
 * Billing & Delivery for a job: payments at any time (advance, during work, final),
 * the final bill (proforma) made from the approved estimate — back office deletes
 * lines, adds other items and approves it — then a GST tax invoice or "close on
 * proforma", the gate pass (issued when paid; owner/manager may override) and
 * "Vehicle out", which delivers the job. A payment may be split over several methods;
 * money paid beyond the bill is refunded or kept as the customer's credit before the
 * gate pass.
 */
import { ref, computed, onMounted } from 'vue'
import { TrashIcon, DocumentArrowDownIcon, PlusIcon, TruckIcon, ShieldCheckIcon } from '@heroicons/vue/20/solid'
import { jobBillingApi } from '@/api/billing.api'
import { openPdfBlob } from '@/api/estimates.api'
import { useToast } from '@/composables/useToast'
import type { JobBillingSummary, BillLine } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'

const props = defineProps<{ jobCardId: number; jobStatus: string }>()
const emit = defineEmits<{ changed: [] }>()
const toast = useToast()

const data = ref<JobBillingSummary | null>(null)
const busy = ref<string | null>(null)

const bill = computed(() => data.value?.bill ?? null)
const can = computed(() => data.value?.can ?? { manage: false, approve: false, pay: false, settle: false, override_gate_pass: false, vehicle_out: false })
const closed = computed(() => ['delivered', 'cancelled'].includes(props.jobStatus))
const refundDue = computed(() => data.value?.refund_due ?? 0)
// Exactly paid: an overpayment still has to be refunded or kept as credit
const paidInFull = computed(() => data.value?.bill_total != null && data.value.balance <= 0 && refundDue.value <= 0)

const money = (n: number | null | undefined, digits = 2) =>
  new Intl.NumberFormat('en-IN', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(n ?? 0))

const STATUS: Record<string, { label: string; variant: 'default' | 'info' | 'success' | 'warning' | 'danger' }> = {
  draft: { label: 'Draft — not approved', variant: 'warning' },
  approved: { label: 'Approved', variant: 'info' },
  converted: { label: 'Converted to tax invoice', variant: 'default' },
  closed: { label: 'Closed on proforma', variant: 'success' },
  issued: { label: 'Issued', variant: 'info' },
  partial: { label: 'Part paid', variant: 'warning' },
  paid: { label: 'Paid', variant: 'success' },
}
const STAGES: Record<string, string> = {
  advance: 'Advance',
  part: 'Part payment',
  final: 'Final payment',
  refund: 'Refund',
  credit: 'Kept as credit',
}
const METHODS: Record<string, string> = {
  cash: 'Cash',
  card: 'Card',
  upi: 'UPI',
  bank_transfer: 'Bank transfer',
  cheque: 'Cheque',
  wallet: 'Wallet',
  split: 'Split',
  customer_credit: 'Customer credit',
}

async function load() {
  try {
    data.value = (await jobBillingApi.get(props.jobCardId)).data.data
  } catch {
    data.value = null
  }
}

/** Run an action that returns the new summary; the job may have moved on, so the page refreshes too */
async function act(key: string, call: () => Promise<{ data: { data: JobBillingSummary; message?: string } }>, success?: string) {
  busy.value = key
  try {
    const res = await call()
    data.value = res.data.data
    toast.success(success ?? res.data.message ?? 'Saved')
    emit('changed')
    return true
  } catch (err) {
    toast.apiError(err)
    return false
  } finally {
    busy.value = null
  }
}

async function pdf(call: () => Promise<{ data: Blob }>) {
  try {
    openPdfBlob((await call()).data)
  } catch (err) {
    toast.apiError(err)
  }
}

// ---------------------------------------------------------------- payments
// A receipt is one or more parts (split payment: e.g. cash + UPI + card)
interface PayPart {
  method: string
  amount: string | number
  reference_number: string
  cheque_number: string
}
const payOpen = ref(false)
const payParts = ref<PayPart[]>([])
const payNotes = ref('')
const newPart = (amount: string | number = ''): PayPart => ({ method: 'cash', amount, reference_number: '', cheque_number: '' })
const balanceToPay = computed(() => (data.value?.bill_total != null ? Math.max(0, data.value.balance) : 0))
const payTotal = computed(() => Math.round(payParts.value.reduce((sum, p) => sum + (Number(p.amount) || 0), 0) * 100) / 100)
const payLeft = computed(() => Math.round((balanceToPay.value - payTotal.value) * 100) / 100)
const payMethods = computed(() => [
  ...(data.value?.methods ?? []).map((m) => ({ value: m, label: METHODS[m] ?? m })),
  ...((data.value?.customer_credit ?? 0) > 0
    ? [{ value: data.value!.credit_method, label: `Customer credit (₹ ${money(data.value!.customer_credit, 0)} available)` }]
    : []),
])
const payValid = computed(
  () =>
    payTotal.value > 0 &&
    payParts.value.every((p) => Number(p.amount) > 0 && (p.method !== 'cheque' || p.cheque_number.trim())) &&
    (data.value?.bill_total == null || payTotal.value <= balanceToPay.value + 0.001)
)

function openPay() {
  payParts.value = [newPart(balanceToPay.value > 0 ? balanceToPay.value : '')]
  payNotes.value = ''
  payOpen.value = true
}

function addPart() {
  // The new part starts with what is still to pay
  payParts.value.push(newPart(payLeft.value > 0 ? payLeft.value : ''))
}

async function savePayment() {
  const ok = await act('pay', () =>
    jobBillingApi.recordPayment(props.jobCardId, {
      parts: payParts.value.map((p) => ({
        method: p.method,
        amount: Number(p.amount),
        reference_number: p.reference_number.trim() || null,
        cheque_number: p.method === 'cheque' ? p.cheque_number.trim() : null,
      })),
      notes: payNotes.value || null,
    })
  )
  if (ok) payOpen.value = false
}

// Paid beyond the bill: refund it, or keep it as the customer's credit
const settleOpen = ref(false)
const settle = ref({ mode: 'refund' as 'refund' | 'credit', amount: '' as string | number, method: 'cash', reference_number: '', notes: '' })
function openSettle() {
  settle.value = { mode: 'refund', amount: refundDue.value, method: 'cash', reference_number: '', notes: '' }
  settleOpen.value = true
}
async function saveSettle() {
  const ok = await act('settle', () =>
    jobBillingApi.settleExcess(props.jobCardId, {
      mode: settle.value.mode,
      amount: Number(settle.value.amount),
      method: settle.value.mode === 'refund' ? settle.value.method : null,
      reference_number: settle.value.reference_number.trim() || null,
      notes: settle.value.notes.trim() || null,
    })
  )
  if (ok) settleOpen.value = false
}

// ------------------------------------------------------------------ bill
const itemOpen = ref(false)
const item = ref({ description: '', quantity: 1 as number | string, unit_price: '' as number | string, gst: 'yes', tax_rate: 18 as number | string })

function openItem() {
  item.value = { description: '', quantity: 1, unit_price: '', gst: 'yes', tax_rate: 18 }
  itemOpen.value = true
}

const itemPreview = computed(() => {
  const base = Number(item.value.quantity || 0) * Number(item.value.unit_price || 0)
  const tax = item.value.gst === 'yes' ? (base * Number(item.value.tax_rate || 0)) / 100 : 0
  return { base, tax, total: base + tax }
})

async function saveItem() {
  if (!bill.value) return
  const ok = await act('item', () =>
    jobBillingApi.addItem(props.jobCardId, bill.value!.id, {
      description: item.value.description,
      quantity: Number(item.value.quantity),
      unit_price: Number(item.value.unit_price),
      gst_applicable: item.value.gst === 'yes',
      tax_rate: item.value.gst === 'yes' ? Number(item.value.tax_rate) : null,
    })
  )
  if (ok) itemOpen.value = false
}

const SECTIONS: Record<string, string> = { plan: 'Service plan', additional: 'Additional parts & labour', other: 'Other items' }
const showSection = (i: number) => !!bill.value && (i === 0 || bill.value.lines[i - 1].section !== bill.value.lines[i].section)

// HSN/SAC of a line, editable while the bill can change
const hsnEditing = ref<number | null>(null)
const hsnValue = ref('')
function editHsn(l: BillLine) {
  hsnEditing.value = l.id
  hsnValue.value = l.hsn_code ?? ''
}
async function saveHsn(l: BillLine) {
  if (!bill.value || hsnValue.value.trim() === (l.hsn_code ?? '')) {
    hsnEditing.value = null
    return
  }
  const ok = await act(`hsn-${l.id}`, () => jobBillingApi.setHsn(props.jobCardId, bill.value!.id, l.id, hsnValue.value.trim()), 'HSN/SAC saved')
  if (ok) hsnEditing.value = null
}

const removing = ref<BillLine | null>(null)
async function removeLine() {
  if (!bill.value || !removing.value) return
  const line = removing.value
  removing.value = null
  await act(`rm-${line.id}`, () => jobBillingApi.removeItem(props.jobCardId, bill.value!.id, line.id), 'Line removed')
}

// Convert to tax invoice / close on proforma
const convertOpen = ref(false)
const convert = ref({ customer_gstin: '', is_igst: 'no' })
// Lines ticked here go on the tax invoice; unticked ones are removed from the proforma
const keepIds = ref<number[]>([])
function openConvert() {
  convert.value = { customer_gstin: bill.value?.customer_gstin ?? '', is_igst: bill.value?.is_igst ? 'yes' : 'no' }
  keepIds.value = bill.value?.lines.map((l) => l.id) ?? []
  convertOpen.value = true
}
const convertTotal = computed(() =>
  Math.round((bill.value?.lines ?? []).filter((l) => keepIds.value.includes(l.id)).reduce((sum, l) => sum + l.total, 0))
)
async function saveConvert() {
  const exclude = (bill.value?.lines ?? []).filter((l) => !keepIds.value.includes(l.id)).map((l) => l.id)
  const ok = await act('convert', () =>
    jobBillingApi.convert(props.jobCardId, bill.value!.id, {
      customer_gstin: convert.value.customer_gstin.trim().toUpperCase() || null,
      is_igst: convert.value.is_igst === 'yes',
      exclude_item_ids: exclude,
    })
  )
  if (ok) convertOpen.value = false
}

// The car must reach Ready for Delivery before the gate pass / delivery
const beforeReady = computed(() =>
  ['intake_pending', 'intake_in_progress', 'awaiting_approval', 'work_in_progress', 'awaiting_parts', 'post_work_trial', 'wash_pending', 'qc_pending'].includes(
    props.jobStatus
  )
)

const closeOpen = ref(false)
const closeNote = ref('')
async function saveClose() {
  const ok = await act('close', () => jobBillingApi.close(props.jobCardId, bill.value!.id, closeNote.value || null))
  if (ok) closeOpen.value = false
}

// Gate pass
const overrideOpen = ref(false)
const overrideReason = ref('')
async function saveOverride() {
  const ok = await act('override', () => jobBillingApi.overrideGatePass(props.jobCardId, overrideReason.value))
  if (ok) overrideOpen.value = false
}
const vehicleOutOpen = ref(false)
async function saveVehicleOut() {
  const ok = await act('out', () => jobBillingApi.vehicleOut(props.jobCardId))
  if (ok) vehicleOutOpen.value = false
}

const canOverrideGatePass = computed(
  () =>
    can.value.override_gate_pass &&
    !data.value?.gate_pass &&
    !!bill.value?.payable &&
    ['ready_for_delivery', 'invoiced'].includes(props.jobStatus) &&
    !paidInFull.value &&
    refundDue.value <= 0
)

onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <Card v-if="data">
    <template #header>
      <div class="flex items-center justify-between gap-2">
        <h3 class="text-lg font-semibold">Billing &amp; Delivery</h3>
        <Button v-if="can.pay && !closed" size="sm" @click="openPay">
          <PlusIcon class="h-4 w-4 mr-1" />
          Record payment
        </Button>
      </div>
    </template>

    <!-- Money at a glance -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
      <div class="rounded-lg bg-gray-50 p-3">
        <p class="text-gray-500">{{ data.bill_total != null ? 'Bill total' : 'Estimate' }}</p>
        <p class="font-semibold">₹ {{ money(data.bill_total ?? data.estimate_total, 0) }}</p>
      </div>
      <div class="rounded-lg bg-gray-50 p-3">
        <p class="text-gray-500">Paid</p>
        <p class="font-semibold">₹ {{ money(data.paid, 0) }}</p>
      </div>
      <div
        class="rounded-lg p-3"
        :class="data.bill_total == null ? 'bg-gray-50' : refundDue > 0 ? 'bg-red-50' : data.balance > 0 ? 'bg-amber-50' : 'bg-green-50'"
      >
        <p class="text-gray-500">{{ refundDue > 0 ? 'Refund due' : 'Balance' }}</p>
        <p class="font-semibold" :class="refundDue > 0 ? 'text-red-700' : ''">
          {{ data.bill_total == null ? '—' : `₹ ${money(refundDue > 0 ? refundDue : Math.max(0, data.balance), 0)}` }}
        </p>
      </div>
      <div class="rounded-lg p-3" :class="data.gate_pass ? 'bg-green-50' : 'bg-gray-50'">
        <p class="text-gray-500">Gate pass</p>
        <p class="font-semibold">{{ data.gate_pass ? data.gate_pass.number : 'Not issued' }}</p>
      </div>
    </div>

    <!-- Paid beyond the bill: must be refunded or kept as credit before the gate pass -->
    <div v-if="refundDue > 0" class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm">
      <p class="font-semibold text-red-800">₹ {{ money(refundDue, 2) }} paid beyond the bill</p>
      <p class="mt-0.5 text-red-700">
        The bill is less than what the customer paid (lines removed after payment). Refund the difference or keep it as the
        customer's credit; the gate pass waits until this is settled.
      </p>
      <Button v-if="can.settle" class="mt-2" size="sm" @click="openSettle">Refund or keep as credit…</Button>
      <p v-else class="mt-2 text-xs text-red-700">Back office settles this.</p>
    </div>
    <p v-if="data.customer_credit > 0 && refundDue <= 0" class="mt-3 text-xs text-gray-600">
      Customer credit available: ₹ {{ money(data.customer_credit, 0) }} — choose “Customer credit” when recording a payment.
    </p>

    <!-- Payments -->
    <div v-if="data.payments.length" class="mt-4">
      <h4 class="text-sm font-semibold text-gray-700 mb-1">Payments</h4>
      <ul class="divide-y divide-gray-100 text-sm">
        <li v-for="p in data.payments" :key="p.id" class="py-2 flex flex-wrap items-center gap-x-3 gap-y-1">
          <span class="font-medium" :class="p.amount < 0 ? 'text-red-700' : ''">{{ p.amount < 0 ? '−' : '' }}₹ {{ money(Math.abs(p.amount), 0) }}</span>
          <Badge size="sm" :variant="p.stage === 'final' ? 'success' : p.amount < 0 ? 'danger' : 'default'">{{ STAGES[p.stage ?? ''] ?? 'Payment' }}</Badge>
          <span class="text-gray-600">
            <template v-if="p.parts.length > 1">
              {{ p.parts.map((x) => `${METHODS[x.method] ?? x.method} ₹ ${money(Math.abs(x.amount), 0)}${x.reference_number ? ` (${x.reference_number})` : ''}`).join(' + ') }}
            </template>
            <template v-else>{{ METHODS[p.method] ?? p.method }}<template v-if="p.reference_number"> · {{ p.reference_number }}</template></template>
          </span>
          <span class="text-gray-500">{{ p.payment_date }} · {{ p.number }}<template v-if="p.received_by"> · {{ p.received_by }}</template></span>
          <button type="button" class="ml-auto text-primary-600 hover:underline text-xs" @click="pdf(() => jobBillingApi.receiptPdf(jobCardId, p.id))">
            Receipt
          </button>
        </li>
      </ul>
    </div>

    <!-- The bill -->
    <div class="mt-4 border-t border-gray-100 pt-4">
      <template v-if="!bill">
        <p class="text-sm text-gray-600">
          No bill yet.
          <template v-if="data.proforma_from">{{ data.proforma_from }}</template>
          <template v-else>The final bill (proforma) is made from the approved estimate.</template>
        </p>
        <Button
          v-if="can.manage && !data.proforma_from"
          class="mt-2"
          size="sm"
          :loading="busy === 'proforma'"
          @click="act('proforma', () => jobBillingApi.createProforma(jobCardId))"
        >
          Make proforma from estimate
        </Button>
      </template>

      <template v-else>
        <div class="flex flex-wrap items-center gap-2">
          <h4 class="text-sm font-semibold text-gray-800">
            {{ bill.kind === 'proforma' ? 'Proforma' : 'Tax invoice' }} {{ bill.number }}
          </h4>
          <Badge size="sm" :variant="STATUS[bill.status]?.variant ?? 'default'">{{ STATUS[bill.status]?.label ?? bill.status }}</Badge>
          <span v-if="bill.proforma_number" class="text-xs text-gray-500">from proforma {{ bill.proforma_number }}</span>
          <span v-if="bill.customer_gstin" class="text-xs text-gray-500">GSTIN {{ bill.customer_gstin }}</span>
          <button type="button" class="ml-auto inline-flex items-center gap-1 text-xs text-primary-600 hover:underline" @click="pdf(() => jobBillingApi.billPdf(jobCardId, bill!.id))">
            <DocumentArrowDownIcon class="h-4 w-4" /> PDF
          </button>
        </div>

        <p v-if="bill.estimate_changed" class="mt-2 rounded bg-amber-50 border border-amber-200 p-2 text-xs text-amber-800">
          The estimate was sent again after this draft was made.
          <button v-if="can.manage" type="button" class="underline font-medium" @click="act('refresh', () => jobBillingApi.refresh(jobCardId, bill!.id))">
            Rebuild from the latest estimate
          </button>
        </p>

        <div class="mt-2 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="text-left text-xs text-gray-500 border-b">
                <th class="py-1 pr-2">Item</th>
                <th class="py-1 px-2 text-right">Qty × Rate</th>
                <th class="py-1 px-2 text-right">Disc.</th>
                <th class="py-1 px-2 text-right">Taxable</th>
                <th class="py-1 px-2 text-right">GST</th>
                <th class="py-1 pl-2 text-right">Amount</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(l, i) in bill.lines" :key="l.id">
              <tr v-if="showSection(i)" class="bg-gray-50">
                <td colspan="6" class="py-1 px-1 text-xs font-semibold uppercase tracking-wide text-gray-600">{{ SECTIONS[l.section] ?? '' }}</td>
              </tr>
              <tr class="border-b border-gray-50">
                <td class="py-1.5 pr-2">
                  {{ l.description }}
                  <span class="block text-xs text-gray-400">
                    <template v-if="hsnEditing === l.id">
                      HSN/SAC
                      <input v-model="hsnValue" class="input inline-block w-24 py-0 px-1 text-xs" maxlength="8" @keyup.enter="saveHsn(l)" @keyup.esc="hsnEditing = null" />
                      <button type="button" class="text-primary-600 font-medium" @click="saveHsn(l)">Save</button>
                    </template>
                    <template v-else>
                      HSN/SAC {{ l.hsn_code ?? '—' }}
                      <button v-if="bill.editable && can.manage" type="button" class="text-primary-600 hover:underline" @click="editHsn(l)">edit</button>
                    </template>
                    · {{ l.gst_inclusive ? 'price incl. GST' : l.gst_applicable ? 'GST added' : 'no GST' }}
                  </span>
                  <button
                    v-if="bill.editable && can.manage && bill.lines.length > 1"
                    type="button"
                    class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-red-600 hover:underline disabled:opacity-40"
                    :disabled="busy === `rm-${l.id}`"
                    @click="removing = l"
                  >
                    <TrashIcon class="h-3.5 w-3.5" /> Remove from bill
                  </button>
                </td>
                <td class="py-1.5 px-2 text-right whitespace-nowrap">{{ Number(l.quantity) }} × {{ money(l.unit_price) }}</td>
                <td class="py-1.5 px-2 text-right">{{ l.discount > 0 ? money(l.discount) : '' }}</td>
                <td class="py-1.5 px-2 text-right">{{ money(l.taxable_amount) }}</td>
                <td class="py-1.5 px-2 text-right whitespace-nowrap">{{ l.tax_rate > 0 ? `${money(l.tax)} (${Number(l.tax_rate)}%)` : 'Nil' }}</td>
                <td class="py-1.5 pl-2 text-right font-medium">{{ money(l.total) }}</td>
              </tr>
              </template>
            </tbody>
          </table>
        </div>

        <dl class="mt-2 ml-auto max-w-xs text-sm space-y-0.5">
          <template v-if="bill.discount >= 0.01">
            <div class="flex justify-between"><dt class="text-gray-500">Value before discount</dt><dd>{{ money(bill.value_before_discount) }}</dd></div>
            <div class="flex justify-between text-green-700"><dt>Less: Discount</dt><dd>-{{ money(bill.discount) }}</dd></div>
          </template>
          <div class="flex justify-between"><dt class="text-gray-500">Taxable value</dt><dd>{{ money(bill.taxable_amount) }}</dd></div>
          <template v-if="bill.is_igst">
            <div class="flex justify-between"><dt class="text-gray-500">IGST</dt><dd>{{ money(bill.igst) }}</dd></div>
          </template>
          <template v-else>
            <div class="flex justify-between"><dt class="text-gray-500">CGST</dt><dd>{{ money(bill.cgst) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">SGST</dt><dd>{{ money(bill.sgst) }}</dd></div>
          </template>
          <div v-if="Math.abs(bill.round_off) >= 0.01" class="flex justify-between"><dt class="text-gray-500">Round off</dt><dd>{{ money(bill.round_off) }}</dd></div>
          <div class="flex justify-between font-semibold border-t pt-1"><dt>Total</dt><dd>₹ {{ money(bill.total, 0) }}</dd></div>
        </dl>

        <!-- Bill actions -->
        <div class="mt-3 flex flex-wrap gap-2">
          <Button v-if="bill.editable && can.manage" size="sm" variant="secondary" @click="openItem">
            <PlusIcon class="h-4 w-4 mr-1" /> Other item
          </Button>
          <Button
            v-if="bill.status === 'draft' && can.manage"
            size="sm"
            variant="ghost"
            :loading="busy === 'discard'"
            @click="act('discard', () => jobBillingApi.discard(jobCardId, bill!.id), 'Draft discarded')"
          >
            Discard draft
          </Button>
          <Button
            v-if="bill.status === 'draft' && can.approve"
            size="sm"
            :loading="busy === 'approve'"
            @click="act('approve', () => jobBillingApi.approve(jobCardId, bill!.id))"
          >
            Approve bill
          </Button>
        </div>

        <!-- Finish billing: tax invoice, or close on the paid proforma -->
        <div v-if="bill.kind === 'proforma' && bill.status === 'approved' && can.approve" class="mt-3 rounded-lg border border-primary-200 bg-primary-50 p-3">
          <p class="text-sm font-semibold text-gray-900">Finish billing</p>
          <p class="text-xs text-gray-600 mt-0.5">Remove any lines first (or untick them in the tax invoice step).</p>
          <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2">
            <div>
              <Button block size="sm" @click="openConvert">Convert to GST tax invoice</Button>
              <p class="mt-1 text-xs text-gray-500">Issues a tax invoice (INV…) with CGST/SGST.</p>
            </div>
            <div>
              <Button block size="sm" variant="secondary" :disabled="!paidInFull" @click="closeOpen = true">Close on proforma (no tax invoice)</Button>
              <p class="mt-1 text-xs" :class="paidInFull ? 'text-gray-500' : 'text-amber-700'">
                {{ paidInFull ? 'Paid in full: billing ends on this proforma.' : `Available once paid in full (₹ ${money(Math.max(0, data.balance), 0)} to pay).` }}
              </p>
            </div>
          </div>
        </div>
        <p v-if="bill.status === 'draft'" class="mt-2 text-xs text-gray-500">
          Back office approves the bill; the job moves to Invoiced once the car is ready and the customer gets the bill on WhatsApp.
        </p>
      </template>
    </div>

    <!-- Gate pass & delivery -->
    <div class="mt-4 border-t border-gray-100 pt-4 text-sm">
      <p v-if="!data.gate_pass && beforeReady && paidInFull" class="mb-2 rounded bg-blue-50 border border-blue-100 p-2 text-blue-800">
        Paid in full. The gate pass is issued by itself as soon as the car reaches Ready for Delivery (after QC and the pre-delivery checklist); then "Vehicle out" closes the job.
      </p>
      <template v-if="data.gate_pass">
        <div class="flex flex-wrap items-center gap-2">
          <ShieldCheckIcon class="h-5 w-5 text-green-600" />
          <span class="font-medium">Gate pass {{ data.gate_pass.number }}</span>
          <span class="text-gray-500">issued by {{ data.gate_pass.issued_by }}</span>
          <Badge v-if="data.gate_pass.override" size="sm" variant="warning">Before full payment (₹ {{ money(data.gate_pass.amount_due, 0) }} due)</Badge>
          <button type="button" class="ml-auto text-xs text-primary-600 hover:underline" @click="pdf(() => jobBillingApi.gatePassPdf(jobCardId))">Print</button>
        </div>
        <p v-if="data.gate_pass.override_reason" class="mt-1 text-xs text-gray-600">Reason: {{ data.gate_pass.override_reason }}</p>
        <p v-if="data.gate_pass.vehicle_out_at" class="mt-2 text-green-700">
          Vehicle out {{ new Date(data.gate_pass.vehicle_out_at).toLocaleString('en-IN') }} ({{ data.gate_pass.vehicle_out_by }}) — delivered.
        </p>
        <Button v-else-if="can.vehicle_out" class="mt-2" size="sm" @click="vehicleOutOpen = true">
          <TruckIcon class="h-4 w-4 mr-1" /> Vehicle out
        </Button>
      </template>
      <template v-else>
        <p class="text-gray-600">
          <template v-if="refundDue > 0">No gate pass yet: settle the ₹ {{ money(refundDue, 0) }} paid beyond the bill first (refund or credit).</template>
          <template v-else>No gate pass: it is issued by itself once the car is ready, the bill is approved and paid in full. Without it the car cannot leave.</template>
        </p>
        <Button v-if="canOverrideGatePass" class="mt-2" size="sm" variant="secondary" @click="overrideOpen = true">
          Issue gate pass before full payment…
        </Button>
      </template>
    </div>

    <!-- Record payment -->
    <Modal :open="payOpen" title="Record payment" @close="payOpen = false">
      <div class="space-y-3">
        <p class="text-sm text-gray-600">
          <template v-if="data.bill_total == null">No approved bill yet: this is recorded as an advance.</template>
          <template v-else>Balance: ₹ {{ money(Math.max(0, data.balance), 0) }}</template>
        </p>
        <!-- One block per way of paying (split payment) -->
        <div v-for="(part, i) in payParts" :key="i" class="rounded-xl border border-gray-200 p-3 space-y-2">
          <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ payParts.length > 1 ? `Part ${i + 1}` : 'Payment' }}</p>
            <button v-if="payParts.length > 1" type="button" class="p-1 text-gray-400 hover:text-red-600" aria-label="Remove part" @click="payParts.splice(i, 1)">
              <TrashIcon class="h-4 w-4" />
            </button>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <Input v-model="part.amount" type="number" label="Amount (₹)" required />
            <Select v-model="part.method" label="Mode" :options="payMethods" />
          </div>
          <Input v-if="part.method === 'cheque'" v-model="part.cheque_number" label="Cheque no." required />
          <Input
            v-if="part.method !== 'cash' && part.method !== data.credit_method"
            v-model="part.reference_number"
            :label="part.method === 'upi' ? 'UPI reference' : part.method === 'card' ? 'Card / approval no.' : 'Reference'"
          />
        </div>
        <button
          v-if="payParts.length < 6"
          type="button"
          class="w-full rounded-xl border border-dashed border-gray-300 py-2.5 text-sm font-medium text-primary-700 hover:bg-primary-50"
          @click="addPart"
        >
          + Split: add another way of paying
        </button>
        <div class="rounded-lg bg-gray-50 p-3 text-sm flex flex-wrap justify-between gap-2">
          <span>Total of this receipt: <b>₹ {{ money(payTotal, 2) }}</b></span>
          <span v-if="data.bill_total != null" :class="payLeft < 0 ? 'text-red-700 font-medium' : 'text-gray-600'">
            {{ payLeft < 0 ? `₹ ${money(-payLeft, 2)} more than the balance` : `Still to pay after this: ₹ ${money(payLeft, 2)}` }}
          </span>
        </div>
        <Textarea v-model="payNotes" label="Note" :rows="2" />
      </div>
      <template #footer>
        <Button variant="secondary" @click="payOpen = false">Cancel</Button>
        <Button :loading="busy === 'pay'" :disabled="!payValid" @click="savePayment">Save payment</Button>
      </template>
    </Modal>

    <!-- Paid beyond the bill: refund or keep as credit -->
    <Modal :open="settleOpen" title="Paid beyond the bill" @close="settleOpen = false">
      <div class="space-y-3">
        <p class="text-sm text-gray-600">
          The customer paid ₹ {{ money(refundDue, 2) }} more than the bill. Record how it is settled; a refund voucher or credit
          note is printed for the customer.
        </p>
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="m in [{ v: 'refund', l: 'Refund to customer' }, { v: 'credit', l: 'Keep as credit' }]"
            :key="m.v"
            type="button"
            class="rounded-xl border px-3 py-3 text-sm font-semibold"
            :class="settle.mode === m.v ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-gray-300 text-gray-700'"
            @click="settle.mode = m.v as 'refund' | 'credit'"
          >
            {{ m.l }}
          </button>
        </div>
        <Input v-model="settle.amount" type="number" label="Amount (₹)" required />
        <template v-if="settle.mode === 'refund'">
          <Select v-model="settle.method" label="Paid back by" :options="data.methods.map((m) => ({ value: m, label: METHODS[m] ?? m }))" />
          <Input v-if="settle.method !== 'cash'" v-model="settle.reference_number" label="Reference (UPI / bank / cheque no.)" />
        </template>
        <p v-else class="text-xs text-gray-500">The credit stays on the customer's account and can pay a later job (“Customer credit” in Record payment).</p>
        <Textarea v-model="settle.notes" label="Reason / note" :rows="2" placeholder="e.g. Part returned, line removed from the bill" />
      </div>
      <template #footer>
        <Button variant="secondary" @click="settleOpen = false">Cancel</Button>
        <Button
          :loading="busy === 'settle'"
          :disabled="!(Number(settle.amount) > 0) || Number(settle.amount) > refundDue + 0.001"
          @click="saveSettle"
        >
          {{ settle.mode === 'refund' ? 'Record refund' : 'Keep as credit' }}
        </Button>
      </template>
    </Modal>

    <!-- Other item -->
    <Modal :open="itemOpen" title="Add other item" @close="itemOpen = false">
      <div class="space-y-3">
        <Input v-model="item.description" label="Description" required />
        <div class="grid grid-cols-2 gap-3">
          <Input v-model="item.quantity" type="number" label="Qty" required />
          <Input v-model="item.unit_price" type="number" label="Rate before GST (₹)" required />
        </div>
        <div class="grid grid-cols-2 gap-3">
          <Select v-model="item.gst" label="GST" :options="[{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }]" />
          <Select
            v-if="item.gst === 'yes'"
            v-model="item.tax_rate"
            label="GST %"
            :options="data.gst_rates.filter((r) => r > 0).map((r) => ({ value: r, label: `${r}%` }))"
          />
        </div>
        <p class="text-sm text-gray-600">
          ₹ {{ money(itemPreview.base) }} + GST ₹ {{ money(itemPreview.tax) }} = <b>₹ {{ money(itemPreview.total) }}</b>
        </p>
      </div>
      <template #footer>
        <Button variant="secondary" @click="itemOpen = false">Cancel</Button>
        <Button :loading="busy === 'item'" :disabled="!item.description.trim() || !(Number(item.quantity) > 0) || item.unit_price === ''" @click="saveItem">
          Add to bill
        </Button>
      </template>
    </Modal>

    <!-- Remove line -->
    <Modal :open="!!removing" title="Remove line from the bill" @close="removing = null">
      <p class="text-sm text-gray-700">Remove <b>{{ removing?.description }}</b> (₹ {{ money(removing?.total) }}) from this bill? The estimate is not changed.</p>
      <template #footer>
        <Button variant="secondary" @click="removing = null">Keep</Button>
        <Button variant="danger" @click="removeLine">Remove</Button>
      </template>
    </Modal>

    <!-- Convert -->
    <Modal :open="convertOpen" title="Convert to GST tax invoice" @close="convertOpen = false">
      <div class="space-y-3">
        <div>
          <p class="text-sm font-medium text-gray-700 mb-1">Lines on the tax invoice (untick to leave a line out)</p>
          <ul class="max-h-56 overflow-y-auto divide-y divide-gray-100 rounded border border-gray-200 text-sm">
            <li v-for="l in bill?.lines ?? []" :key="l.id" class="flex items-center gap-2 px-2 py-1.5">
              <input v-model="keepIds" type="checkbox" :value="l.id" class="h-4 w-4 rounded border-gray-300" />
              <span class="flex-1" :class="keepIds.includes(l.id) ? '' : 'line-through text-gray-400'">{{ l.description }}</span>
              <span class="whitespace-nowrap">₹ {{ money(l.total) }}</span>
            </li>
          </ul>
          <p class="mt-1 text-sm">Tax invoice total: <b>₹ {{ money(convertTotal, 0) }}</b></p>
          <p v-if="keepIds.length < (bill?.lines.length ?? 0)" class="text-xs text-amber-700">Unticked lines are removed from the proforma too.</p>
        </div>
        <Input v-model="convert.customer_gstin" label="Customer GSTIN (business customers)" placeholder="27ABCDE1234F1Z5" />
        <Select
          v-model="convert.is_igst"
          label="Supply"
          :options="[{ value: 'no', label: 'Within the state (CGST + SGST)' }, { value: 'yes', label: 'Other state (IGST)' }]"
        />
      </div>
      <template #footer>
        <Button variant="secondary" @click="convertOpen = false">Cancel</Button>
        <Button :loading="busy === 'convert'" :disabled="!keepIds.length" @click="saveConvert">Issue tax invoice</Button>
      </template>
    </Modal>

    <!-- Close on proforma -->
    <Modal :open="closeOpen" title="Close on proforma" @close="closeOpen = false">
      <p class="text-sm text-gray-600 mb-3">Billing ends on the paid proforma; no tax invoice is issued.</p>
      <Textarea v-model="closeNote" label="Note (optional)" :rows="2" />
      <template #footer>
        <Button variant="secondary" @click="closeOpen = false">Cancel</Button>
        <Button :loading="busy === 'close'" @click="saveClose">Close bill</Button>
      </template>
    </Modal>

    <!-- Gate pass override -->
    <Modal :open="overrideOpen" title="Gate pass before full payment" @close="overrideOpen = false">
      <p class="text-sm text-gray-600 mb-3">₹ {{ money(Math.max(0, data.balance), 0) }} is still due. The reason is printed on the gate pass and logged.</p>
      <Textarea v-model="overrideReason" label="Reason" required :rows="3" />
      <template #footer>
        <Button variant="secondary" @click="overrideOpen = false">Cancel</Button>
        <Button :loading="busy === 'override'" :disabled="overrideReason.trim().length < 5" @click="saveOverride">Issue gate pass</Button>
      </template>
    </Modal>

    <!-- Vehicle out -->
    <Modal :open="vehicleOutOpen" title="Vehicle out" @close="vehicleOutOpen = false">
      <p class="text-sm text-gray-700">Record that the car has left the workshop? The job becomes Delivered.</p>
      <template #footer>
        <Button variant="secondary" @click="vehicleOutOpen = false">Cancel</Button>
        <Button :loading="busy === 'out'" @click="saveVehicleOut">Vehicle out</Button>
      </template>
    </Modal>
  </Card>
</template>
