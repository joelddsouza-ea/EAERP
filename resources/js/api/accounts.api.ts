import apiClient from './client'
import type { ApiResponse } from './types'

export interface Account {
  id: number
  code: string
  name: string
  type: 'asset' | 'liability' | 'equity' | 'income' | 'expense'
  sub_type: string | null
  group: string | null
  is_system: boolean
  is_expense_head: boolean
  is_active: boolean
}

export interface BankAccountRow {
  id: number
  account_id: number
  name: string
  account_number: string | null
  ifsc: string | null
  is_default: boolean
  is_active: boolean
  account?: { id: number; code: string; name: string }
}

export interface AccountsMasters {
  accounts: Account[]
  banks: BankAccountRow[]
  periods: { period: string; locked_by: string | null; locked_at: string | null }[]
  books_start_date: string | null
  types: string[]
  groups: string[]
}

export interface ExpenseRow {
  id: number
  number: string
  date: string
  account_id: number
  account: string | null
  paid_to: string
  supplier: string | null
  amount: number
  gst_bill: boolean
  gst_rate: number
  gst_amount: number
  bill_number: string | null
  paid_via: 'cash' | 'bank' | 'staff_custody' | 'credit'
  bank: string | null
  paid_by: string | null
  job_number: string | null
  job_card_id: number | null
  note: string | null
  status: 'posted' | 'cancelled'
  cancel_reason: string | null
  has_bill: boolean
  by: string | null
}

export interface ExpenseOptions {
  heads: { id: number; code: string; name: string; group: string | null }[]
  banks: { id: number; name: string; is_default: boolean }[]
  suppliers: { id: number; name: string }[]
  staff_holding: { user_id: number; name: string; held: number }[]
}

export const PAID_VIA: Record<ExpenseRow['paid_via'], string> = {
  cash: 'Cash',
  bank: 'Bank / UPI / card',
  staff_custody: 'Customer money held by staff',
  credit: 'On credit (supplier)',
}

export interface EntryLine {
  account: string | null
  code: string | null
  party: string | null
  debit: number
  credit: number
  memo: string | null
}

export interface Entry {
  id: number
  number: string
  date: string
  voucher_type: string
  voucher_label: string
  narration: string | null
  status: 'posted' | 'reversed'
  branch: string | null
  amount: number
  source_type: string | null
  source_id: number | null
  by: string | null
  lines: EntryLine[]
}

export interface StatementItem {
  account_id: number | null
  code: string
  name: string
  group: string | null
  amount: number
}

export interface StatementGroup {
  group: string
  total: number
  items: StatementItem[]
}

export interface ProfitLoss {
  from: string
  to: string
  income: StatementGroup[]
  expenses: StatementGroup[]
  total_income: number
  total_direct_costs: number
  gross_profit: number
  total_expenses: number
  net_profit: number
}

export interface BalanceSheet {
  as_of: string
  assets: StatementGroup[]
  liabilities: StatementGroup[]
  equity: StatementGroup[]
  total_assets: number
  total_liabilities_equity: number
}

export interface TrialBalance {
  to: string
  rows: { account_id: number; code: string; name: string; type: string; group: string | null; debit: number; credit: number }[]
  total_debit: number
  total_credit: number
}

export interface Ledger {
  account: { id: number; code: string; name: string; type: string }
  from: string
  to: string
  opening: number
  rows: { entry_id: number; date: string; number: string; voucher_type: string; narration: string | null; party: string | null; memo: string | null; debit: number; credit: number; balance: number }[]
  total_debit: number
  total_credit: number
  closing: number
  sign: 1 | -1
}

export interface PartyBalances {
  account_id: number
  rows: { party_type: string; party_id: number; name: string; debit: number; credit: number; balance: number }[]
  total: number
}

export interface GstSummary {
  from: string
  to: string
  output: { taxable: number; cgst: number; sgst: number; igst: number; tax: number; invoices: number }
  by_rate: { rate: number; taxable: number; cgst: number; sgst: number; igst: number }[]
  by_hsn: { hsn: string; rate: number; quantity: number; taxable: number; tax: number }[]
  b2b: { number: string; date: string; customer: string | null; gstin: string; taxable: number; tax: number; total: number }[]
  b2c_taxable: number
  input: { purchases: number; expenses: number; tax: number; purchase_bills: number }
  net_payable: number
}

export interface AccountsDashboard {
  cash_in_hand: number
  bank: number
  receivable: number
  payable: number
  staff_held: number
  expenses_today: number
  month_income: number
  month_expenses: number
  month_profit: number
}

type R<T> = Promise<{ data: ApiResponse<T> }>
type Range = { from?: string; to?: string; branch_id?: number | null }

export const accountsApi = {
  dashboard: (branchId?: number | null): R<AccountsDashboard> => apiClient.get('/accounts/dashboard', { params: { branch_id: branchId || undefined } }),

  masters: (): R<AccountsMasters> => apiClient.get('/accounts/masters'),
  saveAccount: (a: Partial<Account>): R<Account> => (a.id ? apiClient.put(`/accounts/accounts/${a.id}`, a) : apiClient.post('/accounts/accounts', a)),
  saveBank: (b: Partial<BankAccountRow>): R<BankAccountRow> => (b.id ? apiClient.put(`/accounts/banks/${b.id}`, b) : apiClient.post('/accounts/banks', b)),
  saveSettings: (data: { books_start_date: string | null }): R<AccountsMasters> => apiClient.put('/accounts/settings', data),
  lockPeriod: (period: string, locked: boolean): R<AccountsMasters> => apiClient.post('/accounts/periods/lock', { period, locked }),
  resync: () => apiClient.post('/accounts/resync', {}, { timeout: 300000 }),

  expenseOptions: (): R<ExpenseOptions> => apiClient.get('/accounts/expense-options'),
  expenses: (params: Range & { account_id?: number; paid_via?: string; q?: string }): R<{ from: string; to: string; rows: ExpenseRow[]; total: number; by_head: { account: string; total: number }[] }> =>
    apiClient.get('/accounts/expenses', { params }),
  addExpense: (form: FormData): R<ExpenseRow> => apiClient.post('/accounts/expenses', form, { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 120000 }),
  cancelExpense: (id: number, reason: string): R<ExpenseRow> => apiClient.post(`/accounts/expenses/${id}/cancel`, { reason }),
  expenseBillUrl: (id: number): R<{ url: string }> => apiClient.get(`/accounts/expenses/${id}/bill-url`),

  entry: (id: number): R<Entry> => apiClient.get(`/accounts/entries/${id}`),
  postEntry: (data: {
    voucher_type: 'journal' | 'contra' | 'opening'
    entry_date: string
    branch_id?: number | null
    narration: string
    lines: { account_id: number; debit?: number; credit?: number; party_type?: string | null; party_id?: number | null; memo?: string | null }[]
  }): R<Entry> => apiClient.post('/accounts/entries', data),
  reverseEntry: (id: number, reason: string): R<Entry> => apiClient.post(`/accounts/entries/${id}/reverse`, { reason }),
  parties: (type: string, q: string): R<{ id: number; name: string }[]> => apiClient.get('/accounts/parties', { params: { type, q } }),

  trialBalance: (p: Range): R<TrialBalance> => apiClient.get('/accounts/reports/trial-balance', { params: p }),
  profitLoss: (p: Range): R<ProfitLoss> => apiClient.get('/accounts/reports/profit-loss', { params: p }),
  balanceSheet: (p: Range): R<BalanceSheet> => apiClient.get('/accounts/reports/balance-sheet', { params: p }),
  ledger: (p: Range & { account_id: number; party_type?: string; party_id?: number }): R<Ledger> => apiClient.get('/accounts/reports/ledger', { params: p }),
  cashBook: (p: Range & { account_id?: number }): R<Ledger> => apiClient.get('/accounts/reports/cash-book', { params: p }),
  dayBook: (p: Range & { voucher_type?: string }): R<{ from: string; to: string; entries: Entry[] }> => apiClient.get('/accounts/reports/day-book', { params: p }),
  partyBalances: (p: Range & { of: 'debtors' | 'creditors' | 'staff_custody' | 'salary_advances' | 'salary_payable' }): R<PartyBalances> =>
    apiClient.get('/accounts/reports/parties', { params: p }),
  gst: (p: Range): R<GstSummary> => apiClient.get('/accounts/reports/gst', { params: p }),
  vouchersCsv: (p: Range) => apiClient.get('/accounts/reports/vouchers.csv', { params: p, responseType: 'blob' }),
}

export const inr = (n: number | null | undefined, digits = 0) =>
  n === null || n === undefined ? '—' : '₹ ' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: digits, maximumFractionDigits: digits })
