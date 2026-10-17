<script setup lang="ts">
/**
 * Accounts: profit & loss, balance sheet, trial balance, day book, cash & bank book,
 * ledgers, who owes whom, the GST summary for the CA, hand-made vouchers and a voucher
 * export. Everything else is posted by itself from bills, receipts, GRNs, expenses and payroll.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  accountsApi, inr,
  type Account, type ProfitLoss, type BalanceSheet, type TrialBalance, type Ledger, type Entry, type PartyBalances, type GstSummary,
} from '@/api/accounts.api'
import { localDate } from '@/api/hr.api'
import { useBranchStore } from '@/stores/branch.store'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

type Tab = 'pl' | 'bs' | 'tb' | 'day' | 'cash' | 'ledger' | 'parties' | 'gst'
const TABS: [Tab, string][] = [
  ['pl', 'Profit & loss'], ['bs', 'Balance sheet'], ['cash', 'Cash & bank'], ['parties', 'Who owes'],
  ['gst', 'GST'], ['day', 'Day book'], ['ledger', 'Ledger'], ['tb', 'Trial balance'],
]

const route = useRoute()
const router = useRouter()
const branchStore = useBranchStore()
const { can } = usePermissions()
const toast = useToast()

const tab = ref<Tab>((route.query.tab as Tab) || 'pl')
const today = localDate()
const from = ref((route.query.from as string) || today.slice(0, 8) + '01')
const to = ref((route.query.to as string) || today)
const branchId = computed(() => branchStore.currentBranchId)
const loading = ref(false)

const accounts = ref<Account[]>([])
const bankAccounts = computed(() => accounts.value.filter((a) => a.sub_type === 'bank' || a.sub_type === 'cash'))

const pl = ref<ProfitLoss | null>(null)
const bs = ref<BalanceSheet | null>(null)
const tb = ref<TrialBalance | null>(null)
const day = ref<Entry[]>([])
const ledger = ref<Ledger | null>(null)
const cashAccountId = ref<number | null>(null)
const ledgerAccountId = ref<number | null>(Number(route.query.account_id) || null)
const partiesOf = ref<'debtors' | 'creditors' | 'staff_custody' | 'salary_advances' | 'salary_payable'>('debtors')
const parties = ref<PartyBalances | null>(null)
const gst = ref<GstSummary | null>(null)

const PARTY_LABELS = {
  debtors: 'Customers who owe',
  creditors: 'Suppliers we owe',
  staff_custody: 'Customer money with staff',
  salary_advances: 'Salary advances',
  salary_payable: 'Salaries to pay',
} as const

async function load() {
  router.replace({ query: { tab: tab.value, from: from.value, to: to.value, ...(ledgerAccountId.value ? { account_id: String(ledgerAccountId.value) } : {}) } })
  loading.value = true
  const p = { from: from.value, to: to.value, branch_id: branchId.value }
  try {
    if (tab.value === 'pl') pl.value = (await accountsApi.profitLoss(p)).data.data
    if (tab.value === 'bs') bs.value = (await accountsApi.balanceSheet(p)).data.data
    if (tab.value === 'tb') tb.value = (await accountsApi.trialBalance(p)).data.data
    if (tab.value === 'day') day.value = (await accountsApi.dayBook(p)).data.data.entries
    if (tab.value === 'cash') ledger.value = (await accountsApi.cashBook({ ...p, account_id: cashAccountId.value ?? undefined })).data.data
    if (tab.value === 'ledger' && ledgerAccountId.value) ledger.value = (await accountsApi.ledger({ ...p, account_id: ledgerAccountId.value })).data.data
    if (tab.value === 'parties') parties.value = (await accountsApi.partyBalances({ ...p, of: partiesOf.value })).data.data
    if (tab.value === 'gst') gst.value = (await accountsApi.gst(p)).data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}
watch([tab, from, to, branchId, cashAccountId, ledgerAccountId, partiesOf], () => {
  if (tab.value !== 'ledger' && tab.value !== 'cash') ledger.value = null
  load()
})

onMounted(async () => {
  try {
    accounts.value = (await accountsApi.masters()).data.data.accounts
  } catch {
    // only the ledger picker needs them
  }
  load()
})

function preset(which: 'today' | 'month' | 'last' | 'fy') {
  const d = new Date()
  if (which === 'today') {
    from.value = to.value = today
  } else if (which === 'month') {
    from.value = today.slice(0, 8) + '01'
    to.value = today
  } else if (which === 'last') {
    const first = new Date(d.getFullYear(), d.getMonth() - 1, 1)
    const last = new Date(d.getFullYear(), d.getMonth(), 0)
    from.value = localDate(first)
    to.value = localDate(last)
  } else {
    const y = d.getMonth() >= 3 ? d.getFullYear() : d.getFullYear() - 1
    from.value = `${y}-04-01`
    to.value = today
  }
}

function openLedger(accountId: number | null) {
  if (!accountId) return
  ledgerAccountId.value = accountId
  tab.value = 'ledger'
}

// ------------------------------------------------------------ one voucher
const entry = ref<Entry | null>(null)
async function openEntry(id: number) {
  try {
    entry.value = (await accountsApi.entry(id)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}
const reverseReason = ref('')
const busy = ref(false)
async function reverse() {
  if (!entry.value) return
  busy.value = true
  try {
    const res = await accountsApi.reverseEntry(entry.value.id, reverseReason.value)
    toast.success(res.data.message ?? 'Reversed')
    entry.value = null
    reverseReason.value = ''
    load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

// ----------------------------------------------------------- new voucher
const voucherOpen = ref(false)
type VLine = { account_id: number | null; debit: number | string; credit: number | string; memo: string }
const voucher = ref({ voucher_type: 'journal' as 'journal' | 'contra' | 'opening', entry_date: today, narration: '', lines: [] as VLine[] })
const vDebit = computed(() => voucher.value.lines.reduce((s, l) => s + (Number(l.debit) || 0), 0))
const vCredit = computed(() => voucher.value.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0))
const vBalanced = computed(() => vDebit.value > 0 && Math.abs(vDebit.value - vCredit.value) < 0.005)
function openVoucher(type: 'journal' | 'contra' | 'opening' = 'journal') {
  voucher.value = {
    voucher_type: type,
    entry_date: today,
    narration: type === 'contra' ? 'Cash deposited in the bank' : '',
    lines: [
      { account_id: null, debit: '', credit: '', memo: '' },
      { account_id: null, debit: '', credit: '', memo: '' },
    ],
  }
  voucherOpen.value = true
}
async function postVoucher() {
  busy.value = true
  try {
    const res = await accountsApi.postEntry({
      ...voucher.value,
      branch_id: branchId.value,
      lines: voucher.value.lines
        .filter((l) => l.account_id && (Number(l.debit) || Number(l.credit)))
        .map((l) => ({ account_id: l.account_id!, debit: Number(l.debit) || 0, credit: Number(l.credit) || 0, memo: l.memo || null })),
    })
    toast.success(res.data.message ?? 'Posted')
    voucherOpen.value = false
    load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function exportCsv() {
  try {
    const res = await accountsApi.vouchersCsv({ from: from.value, to: to.value, branch_id: branchId.value })
    const url = URL.createObjectURL(res.data as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `vouchers-${from.value}-to-${to.value}.csv`
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 30000)
  } catch (err) {
    toast.apiError(err)
  }
}
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Accounts</h1>
        <p class="text-sm text-gray-500">{{ branchStore.currentBranch?.name ?? 'All branches' }} · posted automatically from bills, receipts, GRNs, expenses and payroll</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <Button v-if="can('accounts.journals.create')" variant="secondary" size="sm" @click="openVoucher('contra')">Cash ↔ bank</Button>
        <Button v-if="can('accounts.journals.create')" variant="secondary" size="sm" @click="openVoucher('journal')">Journal voucher</Button>
        <Button variant="ghost" size="sm" @click="exportCsv">Export CSV</Button>
      </div>
    </div>

    <div class="mb-3 flex gap-1 overflow-x-auto scrollbar-none rounded-xl bg-gray-100 p-1 text-sm font-medium">
      <button
        v-for="t in TABS"
        :key="t[0]"
        type="button"
        class="whitespace-nowrap rounded-lg px-3 py-1.5"
        :class="tab === t[0] ? 'bg-white shadow-sm' : 'text-gray-600'"
        @click="tab = t[0]"
      >{{ t[1] }}</button>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
      <template v-if="!['bs', 'tb', 'parties'].includes(tab)">
        <input v-model="from" type="date" class="input w-auto" />
        <span class="text-gray-400">→</span>
      </template>
      <span v-else class="text-gray-500">As of</span>
      <input v-model="to" type="date" class="input w-auto" />
      <button v-for="p in ([['today', 'Today'], ['month', 'This month'], ['last', 'Last month'], ['fy', 'This year']] as const)" :key="p[0]" type="button" class="rounded-full bg-white px-3 py-1 ring-1 ring-gray-200 text-xs" @click="preset(p[0])">{{ p[1] }}</button>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><Spinner size="lg" /></div>

    <template v-else>
      <!-- P&L -->
      <template v-if="tab === 'pl' && pl">
        <div class="mb-4 grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm">
          <div class="rounded-xl bg-white ring-1 ring-gray-200 p-3"><p class="text-xs text-gray-500">Income</p><p class="font-semibold">{{ inr(pl.total_income) }}</p></div>
          <div class="rounded-xl bg-white ring-1 ring-gray-200 p-3"><p class="text-xs text-gray-500">Gross profit</p><p class="font-semibold">{{ inr(pl.gross_profit) }}</p></div>
          <div class="rounded-xl bg-white ring-1 ring-gray-200 p-3"><p class="text-xs text-gray-500">Expenses</p><p class="font-semibold">{{ inr(pl.total_expenses) }}</p></div>
          <div class="rounded-xl p-3" :class="pl.net_profit >= 0 ? 'bg-green-50' : 'bg-red-50'"><p class="text-xs text-gray-500">Net {{ pl.net_profit >= 0 ? 'profit' : 'loss' }}</p><p class="font-semibold">{{ inr(Math.abs(pl.net_profit)) }}</p></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Card v-for="side in (['income', 'expenses'] as const)" :key="side" :padding="false">
            <template #header><h3 class="font-semibold">{{ side === 'income' ? 'Income' : 'Expenses' }}</h3></template>
            <div v-for="g in pl[side]" :key="g.group" class="px-4 py-2">
              <div class="flex justify-between text-sm font-semibold text-gray-700"><span>{{ g.group }}</span><span>{{ inr(g.total) }}</span></div>
              <button v-for="i in g.items" :key="i.code" type="button" class="flex w-full justify-between py-0.5 text-sm text-gray-600 hover:text-primary-700" @click="openLedger(i.account_id)">
                <span>{{ i.name }}</span><span>{{ inr(i.amount) }}</span>
              </button>
            </div>
            <p v-if="!pl[side].length" class="px-4 py-3 text-sm text-gray-500">Nothing in these dates.</p>
          </Card>
        </div>
      </template>

      <!-- Balance sheet -->
      <template v-if="tab === 'bs' && bs">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Card :padding="false">
            <template #header><div class="flex justify-between font-semibold"><span>Assets</span><span>{{ inr(bs.total_assets) }}</span></div></template>
            <div v-for="g in bs.assets" :key="g.group" class="px-4 py-2">
              <div class="flex justify-between text-sm font-semibold text-gray-700"><span>{{ g.group }}</span><span>{{ inr(g.total) }}</span></div>
              <button v-for="i in g.items" :key="i.code" type="button" class="flex w-full justify-between py-0.5 text-sm text-gray-600 hover:text-primary-700" @click="openLedger(i.account_id)"><span>{{ i.name }}</span><span>{{ inr(i.amount) }}</span></button>
            </div>
          </Card>
          <Card :padding="false">
            <template #header><div class="flex justify-between font-semibold"><span>Liabilities &amp; capital</span><span>{{ inr(bs.total_liabilities_equity) }}</span></div></template>
            <div v-for="g in [...bs.liabilities, ...bs.equity]" :key="g.group + g.total" class="px-4 py-2">
              <div class="flex justify-between text-sm font-semibold text-gray-700"><span>{{ g.group }}</span><span>{{ inr(g.total) }}</span></div>
              <button v-for="i in g.items" :key="i.name" type="button" class="flex w-full justify-between py-0.5 text-sm text-gray-600 hover:text-primary-700" @click="openLedger(i.account_id)"><span>{{ i.name }}</span><span>{{ inr(i.amount) }}</span></button>
            </div>
          </Card>
        </div>
        <p v-if="Math.abs(bs.total_assets - bs.total_liabilities_equity) > 0.5" class="mt-2 text-sm text-red-700">The two sides differ by {{ inr(bs.total_assets - bs.total_liabilities_equity, 2) }}.</p>
        <p class="mt-2 text-xs text-gray-500">Add opening balances (cash, bank, capital, loans, assets) with a journal voucher of type “Opening balance”.</p>
      </template>

      <!-- Trial balance -->
      <Card v-if="tab === 'tb' && tb" :padding="false">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500"><tr><th class="px-3 py-2 text-left">Account</th><th class="px-3 py-2 text-right">Debit</th><th class="px-3 py-2 text-right">Credit</th></tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in tb.rows" :key="r.account_id" class="cursor-pointer hover:bg-gray-50" @click="openLedger(r.account_id)">
              <td class="px-3 py-1.5"><span class="text-gray-400 mr-2">{{ r.code }}</span>{{ r.name }}</td>
              <td class="px-3 py-1.5 text-right">{{ r.debit ? inr(r.debit, 2) : '' }}</td>
              <td class="px-3 py-1.5 text-right">{{ r.credit ? inr(r.credit, 2) : '' }}</td>
            </tr>
          </tbody>
          <tfoot class="font-semibold"><tr><td class="px-3 py-2">Total</td><td class="px-3 py-2 text-right">{{ inr(tb.total_debit, 2) }}</td><td class="px-3 py-2 text-right">{{ inr(tb.total_credit, 2) }}</td></tr></tfoot>
        </table>
      </Card>

      <!-- Day book -->
      <Card v-if="tab === 'day'" :padding="false">
        <p v-if="!day.length" class="p-4 text-sm text-gray-500">No entries in these dates.</p>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="e in day" :key="e.id">
            <button type="button" class="w-full px-4 py-2 text-left flex gap-3 hover:bg-gray-50" :class="e.status === 'reversed' ? 'opacity-50' : ''" @click="entry = e; reverseReason = ''">
              <span class="w-20 shrink-0 text-xs text-gray-500">{{ e.date }}<span class="block">{{ e.number }}</span></span>
              <span class="flex-1 min-w-0">
                <span class="block font-medium truncate">{{ e.narration }}</span>
                <span class="block text-xs text-gray-500">{{ e.voucher_label }}<template v-if="e.branch"> · {{ e.branch }}</template></span>
              </span>
              <span class="font-semibold">{{ inr(e.amount, 2) }}</span>
            </button>
          </li>
        </ul>
      </Card>

      <!-- Cash & bank / ledger -->
      <template v-if="tab === 'cash' || tab === 'ledger'">
        <div class="mb-3">
          <select v-if="tab === 'cash'" v-model="cashAccountId" class="input w-auto">
            <option :value="null">Cash in hand</option>
            <option v-for="a in bankAccounts.filter((x) => x.sub_type === 'bank')" :key="a.id" :value="a.id">{{ a.name }}</option>
          </select>
          <select v-else v-model="ledgerAccountId" class="input w-auto max-w-full">
            <option :value="null" disabled>Choose an account</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
          </select>
        </div>
        <Card v-if="ledger" :padding="false">
          <template #header>
            <div class="flex flex-wrap justify-between gap-2 text-sm">
              <span class="font-semibold">{{ ledger.account.name }}</span>
              <span>Opening <b>{{ inr(ledger.opening * ledger.sign, 2) }}</b> · Closing <b>{{ inr(ledger.closing * ledger.sign, 2) }}</b></span>
            </div>
          </template>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 text-xs text-gray-500">
                <tr><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-left">Particulars</th><th class="px-3 py-2 text-right">{{ ledger.sign > 0 ? 'In / Dr' : 'Dr' }}</th><th class="px-3 py-2 text-right">{{ ledger.sign > 0 ? 'Out / Cr' : 'Cr' }}</th><th class="px-3 py-2 text-right">Balance</th></tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(r, i) in ledger.rows" :key="i" class="cursor-pointer hover:bg-gray-50" @click="openEntry(r.entry_id)">
                  <td class="px-3 py-1.5 whitespace-nowrap text-gray-500">{{ r.date }}</td>
                  <td class="px-3 py-1.5">{{ r.narration }}<span v-if="r.party" class="block text-xs text-gray-500">{{ r.party }}</span></td>
                  <td class="px-3 py-1.5 text-right">{{ r.debit ? inr(r.debit, 2) : '' }}</td>
                  <td class="px-3 py-1.5 text-right">{{ r.credit ? inr(r.credit, 2) : '' }}</td>
                  <td class="px-3 py-1.5 text-right font-medium">{{ inr(r.balance * ledger.sign, 2) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="!ledger.rows.length" class="px-4 py-3 text-sm text-gray-500">No movements in these dates.</p>
        </Card>
      </template>

      <!-- Who owes -->
      <template v-if="tab === 'parties'">
        <div class="mb-3 flex gap-2 overflow-x-auto scrollbar-none">
          <button v-for="(label, k) in PARTY_LABELS" :key="k" type="button" class="shrink-0 rounded-full px-3 py-1 text-xs ring-1" :class="partiesOf === k ? 'bg-gray-900 text-white ring-gray-900' : 'bg-white ring-gray-200'" @click="partiesOf = k">{{ label }}</button>
        </div>
        <Card v-if="parties" :padding="false">
          <template #header><div class="flex justify-between font-semibold text-sm"><span>{{ PARTY_LABELS[partiesOf] }}</span><span>{{ inr(parties.total) }}</span></div></template>
          <ul class="divide-y divide-gray-100 text-sm">
            <li v-for="p in parties.rows" :key="p.party_type + p.party_id" class="px-4 py-2 flex justify-between">
              <span>{{ p.name }}</span>
              <span class="font-medium" :class="p.balance < 0 ? 'text-green-700' : ''">{{ inr(p.balance, 2) }}<span v-if="p.balance < 0 && partiesOf === 'debtors'" class="text-xs"> advance</span></span>
            </li>
          </ul>
          <p v-if="!parties.rows.length" class="px-4 py-3 text-sm text-gray-500">Nothing outstanding.</p>
        </Card>
      </template>

      <!-- GST -->
      <template v-if="tab === 'gst' && gst">
        <div class="mb-4 grid grid-cols-1 sm:grid-cols-3 gap-2 text-sm">
          <div class="rounded-xl bg-white ring-1 ring-gray-200 p-3"><p class="text-xs text-gray-500">Output GST ({{ gst.output.invoices }} bills)</p><p class="text-lg font-semibold">{{ inr(gst.output.tax, 2) }}</p><p class="text-xs text-gray-500">CGST {{ inr(gst.output.cgst, 2) }} · SGST {{ inr(gst.output.sgst, 2) }}<template v-if="gst.output.igst"> · IGST {{ inr(gst.output.igst, 2) }}</template></p></div>
          <div class="rounded-xl bg-white ring-1 ring-gray-200 p-3"><p class="text-xs text-gray-500">Input GST (claimable)</p><p class="text-lg font-semibold">{{ inr(gst.input.tax, 2) }}</p><p class="text-xs text-gray-500">Purchases {{ inr(gst.input.purchases, 2) }} · Expenses {{ inr(gst.input.expenses, 2) }}</p></div>
          <div class="rounded-xl p-3" :class="gst.net_payable > 0 ? 'bg-amber-50' : 'bg-green-50'"><p class="text-xs text-gray-500">Net GST payable</p><p class="text-lg font-semibold">{{ inr(gst.net_payable, 2) }}</p></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Card :padding="false">
            <template #header><h3 class="font-semibold">Sales by rate</h3></template>
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 text-xs text-gray-500"><tr><th class="px-3 py-2 text-left">Rate</th><th class="px-3 py-2 text-right">Taxable</th><th class="px-3 py-2 text-right">Tax</th></tr></thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="r in gst.by_rate" :key="r.rate"><td class="px-3 py-1.5">{{ r.rate }}%</td><td class="px-3 py-1.5 text-right">{{ inr(r.taxable, 2) }}</td><td class="px-3 py-1.5 text-right">{{ inr(r.cgst + r.sgst + r.igst, 2) }}</td></tr>
              </tbody>
            </table>
            <p class="px-3 py-2 text-xs text-gray-500">B2C (no GSTIN) taxable: {{ inr(gst.b2c_taxable, 2) }}</p>
          </Card>
          <Card :padding="false">
            <template #header><h3 class="font-semibold">HSN / SAC summary</h3></template>
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 text-xs text-gray-500"><tr><th class="px-3 py-2 text-left">HSN/SAC</th><th class="px-3 py-2 text-right">Qty</th><th class="px-3 py-2 text-right">Taxable</th><th class="px-3 py-2 text-right">Tax</th></tr></thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="h in gst.by_hsn" :key="h.hsn + h.rate"><td class="px-3 py-1.5">{{ h.hsn }} <span class="text-xs text-gray-400">{{ h.rate }}%</span></td><td class="px-3 py-1.5 text-right">{{ h.quantity }}</td><td class="px-3 py-1.5 text-right">{{ inr(h.taxable, 2) }}</td><td class="px-3 py-1.5 text-right">{{ inr(h.tax, 2) }}</td></tr>
              </tbody>
            </table>
          </Card>
          <Card v-if="gst.b2b.length" :padding="false" class="md:col-span-2">
            <template #header><h3 class="font-semibold">B2B invoices (customer GSTIN)</h3></template>
            <ul class="divide-y divide-gray-100 text-sm">
              <li v-for="b in gst.b2b" :key="b.number" class="px-4 py-2 flex flex-wrap gap-x-3">
                <span class="font-medium">{{ b.number }}</span><span class="text-gray-500">{{ b.date }}</span><span>{{ b.customer }} · {{ b.gstin }}</span>
                <span class="ml-auto">{{ inr(b.taxable, 2) }} + {{ inr(b.tax, 2) }}</span>
              </li>
            </ul>
          </Card>
        </div>
        <p class="mt-3 text-xs text-gray-500">Figures for the CA's GSTR-1 / 3B. Input GST is only from purchases on a GST bill and expenses marked as a GST bill.</p>
      </template>
    </template>

    <!-- Voucher -->
    <Modal :open="!!entry" :title="entry ? `${entry.number} · ${entry.voucher_label}` : ''" size="lg" @close="entry = null">
      <div v-if="entry" class="space-y-3 text-sm">
        <p>{{ entry.date }} · {{ entry.narration }} <Badge v-if="entry.status === 'reversed'" size="sm" variant="danger">Reversed</Badge></p>
        <table class="min-w-full">
          <thead class="text-xs text-gray-500"><tr><th class="text-left py-1">Account</th><th class="text-right py-1">Debit</th><th class="text-right py-1">Credit</th></tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(l, i) in entry.lines" :key="i">
              <td class="py-1">{{ l.account }}<span v-if="l.party" class="text-gray-500"> — {{ l.party }}</span><span v-if="l.memo" class="block text-xs text-gray-400">{{ l.memo }}</span></td>
              <td class="py-1 text-right">{{ l.debit ? inr(l.debit, 2) : '' }}</td>
              <td class="py-1 text-right">{{ l.credit ? inr(l.credit, 2) : '' }}</td>
            </tr>
          </tbody>
        </table>
        <p class="text-xs text-gray-500">{{ entry.source_type === 'manual' ? 'Entered by ' + (entry.by ?? '—') : 'Posted automatically' }}</p>
        <div v-if="entry.source_type === 'manual' && entry.status === 'posted' && entry.voucher_type !== 'reversal' && can('accounts.journals.create')" class="border-t pt-3">
          <input v-model="reverseReason" class="input" placeholder="Reason to reverse" />
          <Button class="mt-2" size="sm" variant="danger" :loading="busy" :disabled="!reverseReason.trim()" @click="reverse">Reverse this voucher</Button>
        </div>
      </div>
    </Modal>

    <Modal :open="voucherOpen" :title="{ journal: 'Journal voucher', contra: 'Cash ↔ bank (contra)', opening: 'Opening balances' }[voucher.voucher_type]" size="lg" @close="voucherOpen = false">
      <div class="space-y-3 text-sm">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">Type</label>
            <select v-model="voucher.voucher_type" class="input"><option value="journal">Journal</option><option value="contra">Contra (cash ↔ bank)</option><option value="opening">Opening balance</option></select>
          </div>
          <div><label class="label">Date</label><input v-model="voucher.entry_date" type="date" class="input" /></div>
        </div>
        <div><label class="label">Narration</label><input v-model="voucher.narration" class="input" /></div>
        <div v-for="(l, i) in voucher.lines" :key="i" class="grid grid-cols-12 gap-2">
          <select v-model="l.account_id" class="input col-span-6">
            <option :value="null">Account…</option>
            <option v-for="a in (voucher.voucher_type === 'contra' ? bankAccounts : accounts)" :key="a.id" :value="a.id">{{ a.code }} {{ a.name }}</option>
          </select>
          <input v-model="l.debit" type="number" min="0" step="0.01" class="input col-span-3" placeholder="Debit" @input="l.credit = ''" />
          <input v-model="l.credit" type="number" min="0" step="0.01" class="input col-span-3" placeholder="Credit" @input="l.debit = ''" />
        </div>
        <button type="button" class="text-xs font-medium text-primary-700" @click="voucher.lines.push({ account_id: null, debit: '', credit: '', memo: '' })">+ Add line</button>
        <p :class="vBalanced ? 'text-green-700' : 'text-red-700'">Debit {{ inr(vDebit, 2) }} · Credit {{ inr(vCredit, 2) }}{{ vBalanced ? '' : ' — must be equal' }}</p>
        <p class="text-xs text-gray-500">Debit what comes in / what is spent; credit where it came from. Bills, receipts, GRNs, expenses and payroll post themselves — don't enter them here.</p>
      </div>
      <template #footer>
        <Button variant="secondary" @click="voucherOpen = false">Cancel</Button>
        <Button :loading="busy" :disabled="!vBalanced || !voucher.narration.trim()" @click="postVoucher">Post</Button>
      </template>
    </Modal>
  </div>
</template>
