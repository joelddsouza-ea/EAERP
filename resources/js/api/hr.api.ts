import apiClient from './client'
import type { ApiResponse } from './types'

// ------------------------------------------------------------------ types

export type DayStatus = 'present' | 'half_day' | 'absent' | 'weekly_off' | 'holiday' | 'leave_paid' | 'leave_unpaid' | 'incomplete'

export const DAY_LABELS: Record<DayStatus, string> = {
  present: 'Present',
  half_day: 'Half day',
  absent: 'Absent',
  weekly_off: 'Weekly off',
  holiday: 'Holiday',
  leave_paid: 'Paid leave',
  leave_unpaid: 'Leave without pay',
  incomplete: 'Out-punch missing',
}

/** One letter per status for the month grid */
export const DAY_SHORT: Record<DayStatus, string> = {
  present: 'P', half_day: '½', absent: 'A', weekly_off: 'W', holiday: 'H', leave_paid: 'L', leave_unpaid: 'LW', incomplete: '!',
}

export const DAY_COLORS: Record<DayStatus, string> = {
  present: 'bg-green-100 text-green-800',
  half_day: 'bg-amber-100 text-amber-800',
  absent: 'bg-red-100 text-red-700',
  weekly_off: 'bg-gray-100 text-gray-500',
  holiday: 'bg-blue-50 text-blue-700',
  leave_paid: 'bg-purple-100 text-purple-700',
  leave_unpaid: 'bg-purple-50 text-purple-500',
  incomplete: 'bg-orange-100 text-orange-800',
}

export const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

export interface Punch {
  id: number
  type: 'in' | 'out'
  at: string
  time: string
  source: 'selfie' | 'trip' | 'manual'
  distance_m: number | null
  accuracy_m: number | null
  lat: number | null
  lng: number | null
  reason: string | null
  branch: string | null
  by: string | null
  selfie_url: string | null
  selfie_thumb_url: string | null
  voided: boolean
}

export interface AttendanceDayRow {
  date: string
  status: DayStatus
  label: string
  first_in: string | null
  last_out: string | null
  worked_minutes: number
  late_minutes: number
  ot_minutes: number
  worked_off_day: boolean
  regularised: boolean
}

export interface PunchBranch {
  id: number
  name: string
  lat: number | null
  lng: number | null
  radius_m: number
}

export interface Today {
  date: string
  next: 'in' | 'out'
  punches: Punch[]
  worked_minutes: number
  status: DayStatus | null
  shift: { name: string; start: string; end: string } | null
  branches: PunchBranch[]
  trip: { id: number; label: string; status: string } | null
  max_accuracy_m: number
  selfie_window_seconds: number
  tracking: TrackingConfig
}

/** Live location: what the phone should do (from punch in to punch out) */
export interface TrackingConfig {
  required: boolean
  enabled: boolean
  interval_seconds: number
  distance_filter_m: number
  consent: boolean
  retention_days: number
}

export interface LocationPoint {
  lat: number
  lng: number
  accuracy: number | null
  speed: number | null
  heading: number | null
  recorded_at: string
  battery?: number | null
  charging?: boolean | null
}

export type LiveState = 'moving' | 'still' | 'no_signal' | 'waiting' | 'not_tracked' | 'punched_out'

export const LIVE_STATE_LABELS: Record<LiveState, string> = {
  moving: 'Moving',
  still: 'Not moving',
  no_signal: 'No signal',
  waiting: 'Waiting for phone',
  not_tracked: 'Not tracked',
  punched_out: 'Punched out',
}

export const LIVE_STATE_COLORS: Record<LiveState, string> = {
  moving: '#16a34a',
  still: '#2563eb',
  no_signal: '#dc2626',
  waiting: '#d97706',
  not_tracked: '#6b7280',
  punched_out: '#9ca3af',
}

export const GAP_LABELS: Record<string, string> = {
  location_off: 'Location (GPS) is off on the phone',
  permission_denied: 'Location permission refused on the phone',
  app_closed: 'App closed',
}

export interface LivePerson {
  user_id: number
  name: string
  role: string | null
  designation: string | null
  branch: string | null
  state: LiveState
  gap_reason: string | null
  punched_in_at: string | null
  punched_out_at: string | null
  lat: number | null
  lng: number | null
  accuracy_m: number | null
  last_seen_at: string | null
  minutes_ago: number | null
  battery_pct: number | null
  source: 'web' | 'android' | null
  at_workshop: string | null
  trip: { id: number; label: string; status: string; job_number: string | null; job_card_id: number } | null
}

export interface LiveMap {
  people: LivePerson[]
  branches: { id: number; name: string; lat: number; lng: number; radius_m: number }[]
  stale_minutes: number
  tracking_enabled: boolean
  generated_at: string
}

export interface Trail {
  user: { id: number; name: string }
  date: string
  points: { t: string; lat: number; lng: number; acc: number | null; spd: number | null; bat: number | null; trip: number | null }[]
  punches: { type: 'in' | 'out'; time: string; lat: number | null; lng: number | null; branch: string | null; source: string }[]
  km: number
  stops: { lat: number; lng: number; from: string; to: string; minutes: number }[]
  gaps: { from: string; to: string; minutes: number }[]
}

export interface MonthAttendance {
  period: string
  days: AttendanceDayRow[]
  summary: {
    present: number
    half_days: number
    absent: number
    leave_paid: number
    leave_unpaid: number
    weekly_off: number
    holidays: number
    incomplete: number
    late_days: number
    ot_hours: number
    off_days_worked: number
  }
}

export interface LeaveBalance {
  leave_type_id: number
  name: string
  code: string
  is_paid: boolean
  allotted: number | null
  carried: number | null
  taken: number
  pending: number
  available: number | null
}

export interface LeaveRequestRow {
  id: number
  user_id: number
  employee: string | null
  leave_type: string | null
  leave_type_id: number
  is_paid: boolean | null
  from_date: string
  to_date: string
  half_day: boolean
  days: number
  unpaid_days: number
  reason: string | null
  status: 'pending' | 'approved' | 'rejected' | 'cancelled'
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string | null
}

export interface PayslipLineRow {
  id: number
  code: string
  label: string
  kind: 'earning' | 'deduction' | 'employer'
  amount: number
  edited_note: string | null
}

export interface Payslip {
  id: number
  run_id: number
  period: string | null
  period_label: string | null
  run_status: string | null
  user_id: number
  employee: string | null
  employee_code: string | null
  designation: string | null
  days_in_month: number
  paid_days: number
  lop_days: number
  present_days: number
  leave_days: number
  off_day_works: number
  ot_hours: number
  monthly_salary: number
  gross: number
  deductions: number
  employer_cost: number
  net: number
  has_edits: boolean
  pay_status: 'unpaid' | 'paid'
  paid_on: string | null
  pay_method: string | null
  pay_reference: string | null
  lines: PayslipLineRow[]
}

export interface PayrollRunRow {
  id: number
  period: string
  label: string
  branch: string
  branch_id: number | null
  status: 'draft' | 'finalised' | 'paid'
  gross: number
  deductions: number
  net: number
  employer_cost: number
  employees: number
  unpaid: number
  calculated_at: string | null
  finalised_by: string | null
  finalised_at: string | null
  month_over: boolean
  payslips: Payslip[] | null
}

export interface Advance {
  id: number
  user_id: number
  employee: string | null
  amount: number
  given_on: string
  method: string
  reference_number: string | null
  recover_per_month: number
  recovered: number
  balance: number
  status: 'open' | 'closed' | 'cancelled'
  note: string | null
  by: string | null
}

export interface CustodyEntry {
  id: number
  user_id: number
  employee: string | null
  date: string
  kind: 'collected' | 'handed_over' | 'spent' | 'salary_recovery'
  amount: number
  method: string | null
  reference_number: string | null
  note: string | null
  by: string | null
}

export interface CustodySummaryRow {
  user_id: number
  name: string
  collected: number
  handed_over: number
  spent: number
  recovered: number
  balance: number
}

export interface EmployeeProfile {
  branch_id: number | null
  employee_code: string | null
  designation: string | null
  gender: 'male' | 'female' | 'other' | null
  date_joined: string | null
  date_left: string | null
  shift_id: number | null
  shift?: string | null
  weekly_off_day: number | null
  attendance_required: boolean
  track_location?: boolean
  location_consent_at?: string | null
  // Only for those who prepare payroll
  monthly_salary?: number
  basic_salary?: number | null
  ot_hourly_rate?: number | null
  offday_work_amount?: number | null
  pt_enabled?: boolean
  pf_enabled?: boolean
  esi_enabled?: boolean
  uan?: string | null
  esic_no?: string | null
  pan?: string | null
  bank_name?: string | null
  bank_account?: string | null
  bank_ifsc?: string | null
}

export interface EmployeeRow {
  id: number
  name: string
  phone: string | null
  email: string | null
  is_active: boolean
  roles: string[]
  branches: string[]
  profile: EmployeeProfile | null
  custody_held: number
  advance_due: number | null
}

export interface Shift {
  id: number
  name: string
  start_time: string
  end_time: string
  break_minutes: number
  late_grace_minutes: number
  half_day_minutes: number
  is_active: boolean
}

export interface Holiday {
  id: number
  date: string
  name: string
  branch_id: number | null
}

export interface LeaveType {
  id: number
  name: string
  code: string
  is_paid: boolean
  annual_quota: number
  carry_forward: boolean
  max_carry: number
  is_active: boolean
  sort_order: number
}

export interface IncentiveRule {
  id: number
  name: string
  roles: string[]
  basis: string
  package_id: number | null
  value: number
  min_monthly_billing: number
  is_active: boolean
}

export interface HrMasters {
  shifts: Shift[]
  holidays: Holiday[]
  leave_types: LeaveType[]
  incentive_rules: IncentiveRule[]
  incentive_bases: Record<string, string>
  roles: { name: string; slug: string }[]
  plans: { id: number; name: string }[]
  settings: Record<string, any>
}

export interface Adjustment {
  id: number
  user_id: number
  employee: string | null
  period: string
  kind: 'incentive' | 'bonus' | 'reimbursement' | 'deduction' | 'penalty'
  amount: number
  note: string | null
  by: string | null
}

export const ADJUSTMENT_KINDS: Record<Adjustment['kind'], string> = {
  incentive: 'Incentive',
  bonus: 'Bonus',
  reimbursement: 'Reimbursement',
  deduction: 'Deduction',
  penalty: 'Penalty',
}

export const PAY_METHODS: Record<string, string> = { cash: 'Cash', bank_transfer: 'Bank transfer', upi: 'UPI', cheque: 'Cheque' }

export const hours = (minutes: number) => {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m ? `${h}h ${m}m` : `${h}h`
}

// -------------------------------------------------------------------- api

type R<T> = Promise<{ data: ApiResponse<T> }>

export const hrApi = {
  // Self
  today: (): R<Today> => apiClient.get('/hr/me/today'),
  punch: (form: FormData): R<Today> => apiClient.post('/hr/me/punch', form, { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 120000 }),
  sendLocations: (points: LocationPoint[], source: 'web' | 'android', gap?: string | null): R<{ accepted: number; skipped: number; tracking: boolean }> =>
    apiClient.post('/hr/me/locations', { points, source, gap: gap ?? null }, { timeout: 20000 }),
  locationConsent: (): R<TrackingConfig> => apiClient.post('/hr/me/location-consent'),
  liveMap: (branchId?: number | null): R<LiveMap> => apiClient.get('/hr/tracking/live', { params: branchId ? { branch_id: branchId } : {} }),
  trail: (userId: number, date: string): R<Trail> => apiClient.get(`/hr/tracking/users/${userId}/trail`, { params: { date } }),
  myAttendance: (period: string): R<MonthAttendance> => apiClient.get('/hr/me/attendance', { params: { period } }),
  myLeave: (): R<{ balances: LeaveBalance[]; requests: LeaveRequestRow[] }> => apiClient.get('/hr/me/leave'),
  applyLeave: (data: { leave_type_id: number; from_date: string; to_date?: string | null; half_day?: boolean; reason?: string | null }): R<LeaveRequestRow> =>
    apiClient.post('/hr/me/leave', data),
  cancelMyLeave: (id: number): R<LeaveRequestRow> => apiClient.post(`/hr/me/leave/${id}/cancel`),
  myPayslips: (): R<Payslip[]> => apiClient.get('/hr/me/payslips'),
  myMoney: (): R<{ advances: Advance[]; custody_balance: number; custody_entries: CustodyEntry[] }> => apiClient.get('/hr/me/money'),
  payslipPdf: (id: number) => apiClient.get(`/hr/payslips/${id}/pdf`, { responseType: 'blob' }),

  staffDirectory: (): R<{ id: number; name: string }[]> => apiClient.get('/hr/staff-directory'),

  // Register
  day: (date: string, branchId?: number | null): R<{ date: string; rows: { user_id: number; name: string; designation: string | null; branch: string | null; day: AttendanceDayRow | null; punches: Punch[] }[]; counts: Record<string, number> }> =>
    apiClient.get('/hr/attendance/day', { params: { date, branch_id: branchId || undefined } }),
  month: (period: string, branchId?: number | null): R<{ period: string; days_in_month: number; rows: { user_id: number; name: string; days: Record<number, { status: DayStatus; ot: number; late: number; off_work: boolean; regularised: boolean }> }[] }> =>
    apiClient.get('/hr/attendance/month', { params: { period, branch_id: branchId || undefined } }),
  punches: (userId: number, date: string): R<{ employee: string; date: string; day: AttendanceDayRow | null; punches: Punch[] }> =>
    apiClient.get(`/hr/attendance/users/${userId}/punches`, { params: { date } }),
  addPunch: (userId: number, data: { date: string; type: 'in' | 'out'; time: string; reason: string }) => apiClient.post(`/hr/attendance/users/${userId}/punches`, data),
  voidPunch: (punchId: number, reason: string) => apiClient.post(`/hr/attendance/punches/${punchId}/void`, { reason }),

  // Leave approvals
  leaveRequests: (status = 'pending'): R<LeaveRequestRow[]> => apiClient.get('/hr/leave-requests', { params: { status } }),
  pendingLeave: (): R<{ pending: number }> => apiClient.get('/hr/leave-requests/pending-count'),
  decideLeave: (id: number, approve: boolean, note?: string | null): R<LeaveRequestRow> => apiClient.post(`/hr/leave-requests/${id}/decide`, { approve, note }),
  cancelLeave: (id: number): R<LeaveRequestRow> => apiClient.post(`/hr/leave-requests/${id}/cancel`),
  leaveBalances: (userId: number): R<LeaveBalance[]> => apiClient.get(`/hr/leave-balances/${userId}`),

  // Employees
  employees: (includeInactive = false): R<EmployeeRow[]> => apiClient.get('/hr/employees', { params: { include_inactive: includeInactive ? 1 : undefined } }),
  saveProfile: (userId: number, data: Partial<EmployeeProfile>): R<EmployeeProfile> => apiClient.put(`/hr/employees/${userId}/profile`, data),

  // Masters
  masters: (): R<HrMasters> => apiClient.get('/hr/masters'),
  saveShift: (s: Partial<Shift>) => (s.id ? apiClient.put(`/hr/masters/shifts/${s.id}`, s) : apiClient.post('/hr/masters/shifts', s)),
  saveHoliday: (h: Partial<Holiday>) => (h.id ? apiClient.put(`/hr/masters/holidays/${h.id}`, h) : apiClient.post('/hr/masters/holidays', h)),
  deleteHoliday: (id: number) => apiClient.delete(`/hr/masters/holidays/${id}`),
  saveLeaveType: (t: Partial<LeaveType>) => (t.id ? apiClient.put(`/hr/masters/leave-types/${t.id}`, t) : apiClient.post('/hr/masters/leave-types', t)),
  saveIncentiveRule: (r: Partial<IncentiveRule>) => (r.id ? apiClient.put(`/hr/masters/incentive-rules/${r.id}`, r) : apiClient.post('/hr/masters/incentive-rules', r)),
  deleteIncentiveRule: (id: number) => apiClient.delete(`/hr/masters/incentive-rules/${id}`),
  saveSettings: (settings: Record<string, any>): R<HrMasters> => apiClient.put('/hr/masters/settings', { settings }),

  // Payroll
  runs: (): R<PayrollRunRow[]> => apiClient.get('/hr/payroll-runs'),
  createRun: (period: string, branchId?: number | null): R<PayrollRunRow> => apiClient.post('/hr/payroll-runs', { period, branch_id: branchId || null }, { timeout: 120000 }),
  run: (id: number): R<PayrollRunRow> => apiClient.get(`/hr/payroll-runs/${id}`),
  recalculate: (id: number): R<PayrollRunRow> => apiClient.post(`/hr/payroll-runs/${id}/calculate`, {}, { timeout: 120000 }),
  deleteRun: (id: number) => apiClient.delete(`/hr/payroll-runs/${id}`),
  editLine: (lineId: number, amount: number, note: string): R<PayrollRunRow> => apiClient.put(`/hr/payslip-lines/${lineId}`, { amount, note }),
  finalise: (id: number, force = false): R<PayrollRunRow> => apiClient.post(`/hr/payroll-runs/${id}/finalise`, { force }, { timeout: 120000 }),
  reopen: (id: number): R<PayrollRunRow> => apiClient.post(`/hr/payroll-runs/${id}/reopen`),
  pay: (id: number, data: { payslip_ids?: number[]; method: string; paid_on?: string | null; reference?: string | null }): R<PayrollRunRow> =>
    apiClient.post(`/hr/payroll-runs/${id}/pay`, data),
  adjustments: (period: string): R<Adjustment[]> => apiClient.get('/hr/adjustments', { params: { period } }),
  addAdjustment: (data: { user_id: number; period: string; kind: string; amount: number; note?: string | null }) => apiClient.post('/hr/adjustments', data),
  deleteAdjustment: (id: number) => apiClient.delete(`/hr/adjustments/${id}`),

  // Advances & staff-held customer money
  advances: (status = 'open'): R<Advance[]> => apiClient.get('/hr/advances', { params: { status } }),
  giveAdvance: (data: { user_id: number; amount: number; given_on?: string; method: string; reference_number?: string | null; recover_per_month?: number; note?: string | null }): R<Advance> =>
    apiClient.post('/hr/advances', data),
  updateAdvance: (id: number, data: { recover_per_month: number; note?: string | null }): R<Advance> => apiClient.put(`/hr/advances/${id}`, data),
  cancelAdvance: (id: number, reason: string): R<Advance> => apiClient.post(`/hr/advances/${id}/cancel`, { reason }),
  custody: (userId?: number | null): R<{ summary: CustodySummaryRow[]; entries: CustodyEntry[] }> => apiClient.get('/hr/custody', { params: { user_id: userId || undefined } }),
  handOver: (data: { user_id: number; amount: number; method: 'cash' | 'bank'; entry_date?: string; reference_number?: string | null; note?: string | null }): R<CustodyEntry> =>
    apiClient.post('/hr/custody/hand-over', data),
}

/** Opens a PDF blob response in a new tab (or downloads it on phones that block pop-ups). */
export function openBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const win = window.open(url, '_blank')
  if (!win) {
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
  }
  setTimeout(() => URL.revokeObjectURL(url), 60000)
}

export const monthLabel = (period: string) => {
  const [y, m] = period.split('-').map(Number)
  return new Date(y, m - 1, 1).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })
}

export const thisMonth = () => localDate().slice(0, 7)

export const lastMonth = () => {
  const d = new Date()
  d.setDate(1)
  d.setMonth(d.getMonth() - 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

export const localDate = (d = new Date()) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
