import apiClient from './client'
import type { ApiResponse } from './types'

export type LeadStatus = 'new' | 'contacted' | 'interested' | 'booked' | 'won' | 'lost' | 'unreachable'
export type Temperature = 'hot' | 'warm' | 'cold'

export const STATUS_LABELS: Record<LeadStatus, string> = {
  new: 'New',
  contacted: 'Contacted',
  interested: 'Interested',
  booked: 'Booked',
  won: 'Won',
  lost: 'Lost',
  unreachable: 'Not reachable',
}

export const STATUS_COLORS: Record<LeadStatus, string> = {
  new: 'bg-blue-100 text-blue-800',
  contacted: 'bg-indigo-100 text-indigo-800',
  interested: 'bg-amber-100 text-amber-800',
  booked: 'bg-purple-100 text-purple-800',
  won: 'bg-green-100 text-green-800',
  lost: 'bg-gray-100 text-gray-600',
  unreachable: 'bg-orange-100 text-orange-800',
}

export const TEMP_COLORS: Record<Temperature, string> = {
  hot: 'bg-red-500',
  warm: 'bg-amber-400',
  cold: 'bg-sky-400',
}

export const MODE_LABELS: Record<string, string> = { drive_in: 'Drive-in', pickup_drop: 'Pickup & drop', home: 'Home service' }

export interface LeadRow {
  id: number
  name: string
  phone: string
  branch_id: number | null
  source: string | null
  source_slug: string | null
  campaign: string | null
  car: string
  interest: string | null
  interest_label: string | null
  package: string | null
  package_id: number | null
  category_id: number | null
  category: string | null
  kind: string
  kind_label: string
  status: LeadStatus
  status_label: string
  temperature: Temperature
  score: number
  assigned_to: number | null
  assignee: string | null
  next_follow_up_at: string | null
  first_contact_due_at: string | null
  first_contacted_at: string | null
  overdue: boolean
  customer_id: number | null
  is_customer: boolean
  requirement: string | null
  lost_reason: string | null
  won_revenue: number
  opted_out: boolean
  created_at: string | null
}

export interface LeadActivityRow {
  id: number
  type: string
  outcome: string | null
  outcome_label: string | null
  body: string | null
  by: string | null
  at: string | null
}

export interface BookingRow {
  id: number
  number: string
  branch_id: number
  branch: string | null
  date: string
  slot_from: string | null
  slot_to: string | null
  name: string | null
  phone: string | null
  lead_id: number | null
  customer_id: number | null
  vehicle_id: number | null
  car: string | null
  service_type: string
  package_id: number | null
  oil_type_id: number | null
  package: string | null
  service_mode: string
  address: string | null
  notes: string | null
  status: string
  status_label: string
  booked_via: string
  job_card_id: number | null
  job_number: string | null
  cancel_reason: string | null
}

export interface LeadDetail extends LeadRow {
  email: string | null
  alt_phone: string | null
  city: string | null
  car_text: string | null
  registration_no: string | null
  make_id: number | null
  model_id: number | null
  fuel_type: string | null
  vehicle_id: number | null
  referrer: string | null
  tracking: Record<string, string> | null
  lost_note: string | null
  unanswered_calls: number
  call_url: string
  whatsapp_url: string
  activities: LeadActivityRow[]
  bookings: BookingRow[]
}

export interface LeadOptions {
  sources: { id: number; name: string; slug: string }[]
  lost_reasons: { id: number; name: string; nurture: boolean }[]
  plans: { id: number; name: string; home_service_available: boolean }[]
  categories: { id: number; name: string }[]
  oil_types: { id: number; name: string }[]
  interests: Record<string, string>
  statuses: Record<string, string>
  kinds: Record<string, string>
  outcomes: Record<string, string>
  executives: { id: number; name: string }[]
}

export interface Slot {
  from: string
  to: string
  capacity: number
  booked: number
  free: number
}

export interface QueueMessage {
  id: number
  template: string
  body: string
  phone: string | null
  status: string
  error: string | null
  scheduled_at: string | null
  sent_at: string | null
  share_url: string | null
  lead: { id: number; name: string; status: string; temperature: Temperature } | null
  customer: { id: number; name: string } | null
  assignee: string | null
  has_media: boolean
}

export interface Funnel {
  leads: number
  contacted: number
  booked: number
  arrived: number
  won: number
  revenue: number
  spend: number
  cost_per_lead: number | null
  cost_per_booking: number | null
  roi: number | null
  conversion: number
}

export interface Insights {
  from: string
  to: string
  funnel: Funnel
  by_source: (Funnel & { name: string })[]
  by_campaign: (Funnel & { name: string; source: string | null })[]
  by_executive: (Omit<Funnel, 'spend' | 'cost_per_lead' | 'cost_per_booking' | 'roi'> & { name: string })[]
  speed: { median_minutes: number | null; sla_missed: number }
  lost_reasons: { name: string; count: number }[]
  retention: {
    service_due: number
    service_due_booked: number
    delivered: number
    repeat_customers: number
    repeat_pct: number
    feedback_count: number
    avg_rating: number
    low_ratings_open: number
    referrals: number
    referrals_rewarded: number
  }
}

export interface CrmMasters {
  sources: { id: number; name: string; slug: string; is_paid: boolean; is_active: boolean; webhook_url: string | null }[]
  campaigns: { id: number; name: string; external_ref: string | null; lead_source_id: number; source: string | null; is_active: boolean; spends: { period: string; amount: number }[] }[]
  lost_reasons: { id: number; name: string; nurture: boolean; is_active: boolean; sort_order: number }[]
  creatives: Creative[]
  journeys: JourneyRow[]
  slots: { id?: number; branch_id: number | null; weekday: number; start_time: string; end_time: string; capacity: number; is_active: boolean }[]
  triggers: Record<string, string>
  due_triggers: string[]
  actions: Record<string, string>
  plans: { id: number; name: string }[]
  settings: Record<string, any>
}

export interface Creative {
  id: number
  title: string
  kind: 'text' | 'image' | 'video' | 'pdf'
  caption: string
  package_id: number | null
  package: string | null
  wa_template: string | null
  wa_language: string | null
  is_active: boolean
  has_file: boolean
  mime: string | null
  file_url: string | null
}

export interface JourneyStepRow {
  id?: number
  day_offset: number
  send_at: string
  action: 'message' | 'task' | 'lead'
  creative_id: number | null
  creative?: string | null
  task_note: string | null
}

export interface JourneyRow {
  id: number
  name: string
  trigger: string
  branch_id: number | null
  is_active: boolean
  description: string | null
  steps: JourneyStepRow[]
}

export interface CustomerTimeline {
  summary: {
    visits: number
    spend: number
    last_visit: string | null
    avg_rating: number | null
    credit: number
    referrals: number
    referral_code: string | null
    opted_out: boolean
    open_lead_id: number | null
    active_journeys: string[]
  }
  vehicles: { id: number; registration_no: string | null; next_service_due_date: string | null; next_service_due_km: number | null; last_service_at: string | null; insurance_expiry: string | null }[]
  timeline: { at: string | null; type: string; title: string; detail: string | null; to: string | null }[]
}

type R<T> = Promise<{ data: ApiResponse<T> & { meta?: any } }>

export const crmApi = {
  leads: (params: Record<string, any>): R<any> => apiClient.get('/crm/leads', { params }),
  options: (): R<LeadOptions> => apiClient.get('/crm/lead-options'),
  lead: (id: number): R<LeadDetail> => apiClient.get(`/crm/leads/${id}`),
  addLead: (data: Record<string, any>): R<LeadDetail> => apiClient.post('/crm/leads', data),
  updateLead: (id: number, data: Record<string, any>): R<LeadDetail> => apiClient.put(`/crm/leads/${id}`, data),
  call: (id: number, data: { outcome: string; note?: string | null; call_back_at?: string | null; lost_reason_id?: number | null }): R<LeadDetail> =>
    apiClient.post(`/crm/leads/${id}/call`, data),
  note: (id: number, body: string, next?: string | null): R<LeadDetail> => apiClient.post(`/crm/leads/${id}/note`, { body, next_follow_up_at: next || null }),
  followUp: (id: number, at: string): R<LeadDetail> => apiClient.post(`/crm/leads/${id}/follow-up`, { next_follow_up_at: at }),
  assign: (id: number, userId: number): R<LeadDetail> => apiClient.post(`/crm/leads/${id}/assign`, { user_id: userId }),
  interested: (id: number): R<LeadDetail> => apiClient.post(`/crm/leads/${id}/interested`),
  lost: (id: number, reasonId: number, note?: string | null): R<LeadDetail> => apiClient.post(`/crm/leads/${id}/lost`, { lost_reason_id: reasonId, note }),
  reopen: (id: number): R<LeadDetail> => apiClient.post(`/crm/leads/${id}/reopen`),
  optOut: (id: number): R<LeadDetail> => apiClient.post(`/crm/leads/${id}/opt-out`),
  quote: (id: number, packageId: number, categoryId?: number | null): R<{ lead: LeadDetail; message: QueueMessage | null }> =>
    apiClient.post(`/crm/leads/${id}/quote`, { package_id: packageId, category_id: categoryId || null }),
  leadMessages: (id: number): R<QueueMessage[]> => apiClient.get(`/crm/leads/${id}/messages`),
  importLeads: (rows: Record<string, string>[], source: string, branchId?: number | null): R<{ added: number; updated: number; failed: { row: number; error: string }[] }> =>
    apiClient.post('/crm/leads-import', { rows, source, branch_id: branchId || null }, { timeout: 120000 }),

  bookings: (params: { from?: string; to?: string; branch_id?: number | null; status?: string }): R<{ from: string; to: string; branch_id: number | null; rows: BookingRow[]; slots: Slot[]; pending_confirmation: number }> =>
    apiClient.get('/crm/bookings', { params }),
  availability: (date: string, branchId?: number | null): R<Slot[]> => apiClient.get('/crm/booking-availability', { params: { date, branch_id: branchId || undefined } }),
  book: (data: Record<string, any>): R<BookingRow> => apiClient.post('/crm/bookings', data),
  confirm: (id: number): R<BookingRow> => apiClient.post(`/crm/bookings/${id}/confirm`),
  reschedule: (id: number, date: string, slot: string | null): R<BookingRow> => apiClient.post(`/crm/bookings/${id}/reschedule`, { booking_date: date, slot_from: slot }),
  cancelBooking: (id: number, reason: string): R<BookingRow> => apiClient.post(`/crm/bookings/${id}/cancel`, { reason }),
  noShow: (id: number): R<BookingRow> => apiClient.post(`/crm/bookings/${id}/no-show`),
  arrive: (id: number, car: Record<string, any>): R<{ booking_id: number; customer_id: number; vehicle_id: number; service_type: string; package_id: number | null; oil_type_id: number | null; complaints: string[] }> =>
    apiClient.post(`/crm/bookings/${id}/arrive`, car),

  queue: (everyone = false): R<{ rows: QueueMessage[]; total: number; sent_today: number }> => apiClient.get('/crm/messages', { params: { everyone: everyone ? 1 : undefined } }),
  markSent: (id: number): R<QueueMessage> => apiClient.post(`/crm/messages/${id}/sent`),
  dismiss: (id: number) => apiClient.post(`/crm/messages/${id}/dismiss`),

  insights: (params: { from?: string; to?: string; branch_id?: number | null }): R<Insights> => apiClient.get('/crm/insights', { params }),
  tiles: (branchId?: number | null): R<Record<string, number>> => apiClient.get('/crm/tiles', { params: { branch_id: branchId || undefined } }),
  feedback: (filter?: string): R<{ average: number; rows: any[] }> => apiClient.get('/crm/feedback', { params: { filter } }),
  resolveFeedback: (id: number, note: string) => apiClient.post(`/crm/feedback/${id}/resolve`, { note }),
  customerTimeline: (customerId: number): R<CustomerTimeline> => apiClient.get(`/crm/customers/${customerId}/timeline`),

  masters: (): R<CrmMasters> => apiClient.get('/crm/masters'),
  saveSource: (s: { id?: number; name: string; is_paid: boolean; is_active: boolean }) =>
    s.id ? apiClient.put(`/crm/masters/sources/${s.id}`, s) : apiClient.post('/crm/masters/sources', s),
  newWebhookKey: (id: number) => apiClient.post(`/crm/masters/sources/${id}/new-key`),
  saveCampaign: (c: Record<string, any>) => (c.id ? apiClient.put(`/crm/masters/campaigns/${c.id}`, c) : apiClient.post('/crm/masters/campaigns', c)),
  saveSpend: (campaignId: number, period: string, amount: number) => apiClient.post(`/crm/masters/campaigns/${campaignId}/spend`, { period, amount }),
  saveLostReason: (r: Record<string, any>) => (r.id ? apiClient.put(`/crm/masters/lost-reasons/${r.id}`, r) : apiClient.post('/crm/masters/lost-reasons', r)),
  saveCreative: (form: FormData, id?: number) =>
    apiClient.post(id ? `/crm/masters/creatives/${id}` : '/crm/masters/creatives', form, { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 120000 }),
  saveJourney: (j: Partial<JourneyRow>) => (j.id ? apiClient.put(`/crm/masters/journeys/${j.id}`, j) : apiClient.post('/crm/masters/journeys', j)),
  saveSlots: (branchId: number | null, slots: CrmMasters['slots']) => apiClient.put('/crm/masters/slots', { branch_id: branchId, slots }),
  saveSettings: (settings: Record<string, any>): R<CrmMasters> => apiClient.put('/crm/masters/settings', { settings }),
}

// Public (no login) — the customer's CRM link pages
export const publicCrmApi = {
  show: (token: string) => apiClient.get(`/public/c/${token}`),
  slots: (token: string, date: string) => apiClient.get(`/public/c/${token}/slots`, { params: { date } }),
  book: (token: string, data: Record<string, any>) => apiClient.post(`/public/c/${token}/book`, data),
  feedback: (token: string, rating: number, comments?: string) => apiClient.post(`/public/c/${token}/feedback`, { rating, comments }),
  reviewClicked: (token: string) => apiClient.post(`/public/c/${token}/review`),
  refer: (token: string, data: Record<string, any>) => apiClient.post(`/public/c/${token}/refer`, data),
  stop: (token: string) => apiClient.post(`/public/c/${token}/stop`),
}

/** "in 2 h", "3 days ago" */
export function relTime(iso: string | null): string {
  if (!iso) return ''
  const diff = (new Date(iso).getTime() - Date.now()) / 60000
  const abs = Math.abs(diff)
  const txt = abs < 60 ? `${Math.round(abs)} min` : abs < 1440 ? `${Math.round(abs / 60)} h` : `${Math.round(abs / 1440)} d`
  return diff >= 0 ? `in ${txt}` : `${txt} ago`
}

export const fmtDateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''
