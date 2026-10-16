// Common types
export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    from: number
    last_page: number
    per_page: number
    to: number
    total: number
  }
  links: {
    first: string
    last: string
    prev: string | null
    next: string | null
  }
}

export interface ApiResponse<T> {
  data: T
  message?: string
}

// Auth types
export interface User {
  id: number
  name: string
  email: string
  phone: string | null
  roles: string[]
  branches: Branch[]
  primary_branch_id?: number | null
  permissions: string[]
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface Role {
  id: number
  name: string
  slug: string
  description: string | null
  is_system?: boolean
  users_count?: number
  permissions_count?: number
}

// User as returned by the user-admin endpoints (auth/me returns role slugs instead)
export interface AdminUser {
  id: number
  name: string
  email: string
  phone: string | null
  is_active: boolean
  last_login_at: string | null
  roles: Pick<Role, 'id' | 'name' | 'slug'>[]
  branches: Pick<Branch, 'id' | 'name' | 'code' | 'is_primary'>[]
  primary_branch_id: number | null
  created_at: string
  updated_at: string
}

export interface Permission {
  id: number
  name: string
  slug: string
  description: string | null
}

export interface Branch {
  id: number
  name: string
  code: string
  address: string | null
  phone: string | null
  email: string | null
  is_active: boolean
  is_primary?: boolean
}

export interface LoginRequest {
  email: string
  password: string
}

export interface LoginResponse {
  token: string
  token_type: string
  user: User
}

// Workshop types
export interface Customer {
  id: number
  name: string
  email: string | null
  phone: string
  alt_phone: string | null
  address: string | null
  city: string | null
  state: string | null
  pincode: string | null
  gstin: string | null
  customer_type: 'individual' | 'corporate'
  company_name: string | null
  notes: string | null
  vehicles_count?: number
  job_cards_count?: number
  created_at: string
  updated_at: string
}

export interface Vehicle {
  id: number
  customer_id: number
  customer?: Pick<Customer, 'id' | 'name' | 'phone'> & Partial<Customer>
  full_name?: string
  registration_no: string
  make: { id: number; name: string } | null
  model: { id: number; name: string } | null
  category: { id: number; name: string; slug: string } | null
  year: number | string | null
  color: string | null
  vin: string | null
  engine_no: string | null
  chassis_no: string | null
  fuel_type: 'petrol' | 'diesel' | 'cng' | 'electric' | 'hybrid'
  transmission: 'manual' | 'automatic' | null
  insurance_company: string | null
  insurance_policy_no: string | null
  insurance_expiry: string | null
  notes: string | null
  is_active?: boolean
  created_at: string
  updated_at: string
}

export interface VehicleMake {
  id: number
  name: string
  logo: string | null
}

export interface VehicleModel {
  id: number
  name: string
  default_category_id: number | null
}

export interface VehicleCategory {
  id: number
  name: string
  slug: string
  description?: string | null
}

export type ServiceType =
  | 'scheduled'
  | 'general_repair'
  | 'ac_service'
  | 'body_shop'
  | 'electrical'
  | 'detailing'
  | 'home_service'

export type FuelLevel = 'empty' | 'quarter' | 'half' | 'three_quarter' | 'full'

export interface JobCard {
  id: number
  job_number: string
  branch_id: number
  branch?: Branch
  customer_id: number
  customer?: Customer
  vehicle_id: number
  vehicle?: Vehicle
  service_advisor_id: number
  service_advisor?: { id: number; name: string } | null
  technician_id: number | null
  technician?: { id: number; name: string } | null
  bay_id: number | null
  bay?: Bay | null
  service_type: ServiceType | null
  status: JobCardStatus
  priority: 'low' | 'normal' | 'high' | 'urgent'
  package_id?: number | null
  oil_type_id?: number | null
  /** Lead + co-technicians (all may fill the job sheets) */
  technician_ids?: number[]
  package?: { id: number; name: string; activity_points: number } | null
  oil_type?: { id: number; name: string } | null
  package_price?: number | string
  km_reading: number | null
  fuel_level: FuelLevel | null
  customer_complaints: string | null
  advisor_observations: string | null
  internal_notes: string | null
  estimated_amount: number | string
  labor_total: number | string
  parts_total: number | string
  discount: number | string
  total_amount: number | string
  estimated_delivery: string | null
  time_in: string | null
  work_started_at: string | null
  work_completed_at: string | null
  qc_completed_at: string | null
  time_out: string | null
  items: JobCardItem[]
  status_changed_at?: string | null
  cancel_reason?: string | null
  status_history?: StatusHistoryEntry[]
  created_at: string
  updated_at: string
}

export type JobCardStatus =
  | 'pickup_scheduled'
  | 'pickup_en_route'
  | 'vehicle_collected'
  | 'intake_pending'
  | 'intake_in_progress'
  | 'awaiting_approval'
  | 'work_in_progress'
  | 'awaiting_parts'
  | 'post_work_trial'
  | 'wash_pending'
  | 'qc_pending'
  | 'ready_for_delivery'
  | 'invoiced'
  | 'gate_pass_generated'
  | 'delivered'
  | 'cancelled'

export interface JobCardItem {
  id: number
  job_card_id: number
  type: 'labor' | 'part' | 'service' | 'third_party'
  description: string
  part_number: string | null
  quantity: number | string
  unit: string | null
  unit_price: number | string
  discount: number | string
  tax_rate: number | string
  tax_amount: number | string
  total: number | string
  notes: string | null
}

// ---- Job sheets ----

export type SheetKind =
  | 'service_activities'
  | 'ac_service'
  | 'general_repair'
  | 'body_shop'
  | 'observation_report'
  | 'pre_delivery'

export type MediaRequirement = 'none' | 'photo' | 'video' | 'photo_or_video' | 'photo_and_video'
export type JobSheetItemStatus = 'pending' | 'done' | 'not_applicable' | 'not_done'
export type SheetInputType = 'tick' | 'yes_no' | 'repair_replace' | 'panel_matrix' | 'text' | 'number' | 'select'

export interface JobCardMedia {
  id: number
  media_type: 'photo' | 'video'
  url: string
  thumbnail_url: string | null
  mime: string
  size_bytes: number
  duration_seconds: number | string | null
  description: string | null
  customer_visible: boolean
  uploaded_by: number | null
  captured_at: string | null
}

export interface PanelFlags {
  repair: boolean
  replace: boolean
  partial_paint: boolean
  full_paint: boolean
}

export interface JobSheetItem {
  /** Pre-delivery QC spot check: shown only after the point is marked */
  qc_evidence?: { required: boolean; marked_at: string; deadline: string; satisfied: boolean } | null
  id: number
  section_name: string | null
  item_name: string | null
  input_type: SheetInputType
  status: JobSheetItemStatus
  needs_attention: boolean
  remarks: string | null
  // yes_no: 'yes' | 'no'; repair_replace: { action }; panel_matrix: PanelFlags
  value: string | { action: 'ok' | 'repair' | 'replace' } | PanelFlags | null
  media_requirement: MediaRequirement
  min_media_count: number
  na_allowed: boolean
  expected_answer: 'yes' | 'no' | null
  is_required: boolean
  is_adhoc: boolean
  media_shortfall: string | null
  media: JobCardMedia[]
  completed_by?: { id: number; name: string } | null
  completed_at: string | null
}

export interface SheetProblem {
  /** null for an unfinished Additional Issues line (see issue_id) */
  item_id: number | null
  issue_id?: number
  item_name: string | null
  section_name: string | null
  code: string
  message: string
  sheet_id?: number
  sheet_name?: string | null
}

export interface JobSheet {
  id: number
  template_id: number | null
  sheet_kind: SheetKind
  name: string | null
  status: 'pending' | 'in_progress' | 'completed'
  completion_rule: 'all_resolved' | 'all_answered' | 'optional'
  filled_by: 'technician' | 'advisor'
  has_issues_tab: boolean
  has_observations: boolean
  progress: { total: number; resolved: number; needs_media: number; not_done: number; needs_attention: number } | null
  submittable: boolean
  problems: SheetProblem[]
  sections: { name: string; items: JobSheetItem[] }[]
  started_at: string | null
  completed_at: string | null
  completed_by?: { id: number; name: string } | null
  override: boolean
  reopened_at: string | null
  reopen_notes: string | null
}

export interface JobCardObservation {
  id: number
  job_card_id: number
  /** manual / activity (⚠) / pre_delivery / estimate_declined (customer not approved) / issue_deferred */
  source: 'manual' | 'activity' | 'pre_delivery' | 'estimate_declined' | 'issue_deferred'
  source_item_id: number | null
  text: string
  customer_visible: boolean
  remark_id: number | null
  remark_days: number | null
  /** The remark as printed, e.g. "Fix within 30 days" */
  remark_text?: string | null
  created_by?: { id: number; name: string } | null
  created_at: string
}

export interface ObservationRemark {
  id: number
  code: string
  label: string
  needs_days: boolean
  sort_order?: number
  is_active?: boolean
}

/** A line under Additional Issues: a customer complaint (intake), the technician's finding, or a flagged activity */
export interface JobCardIssue {
  id: number
  job_card_id: number
  source: 'customer' | 'technician' | 'activity'
  description: string
  priority: 'critical' | 'high' | 'normal'
  status: 'open' | 'in_progress' | 'resolved' | 'deferred'
  resolution_notes: string | null
  reported_by?: string | null
  resolved_by?: { id: number; name: string } | null
  resolved_at: string | null
  media?: (JobCardMedia & { tag?: string | null })[]
  /** Photos/videos on the flagged activity the line came from (count as evidence) */
  activity_media?: JobCardMedia[]
  /** Evidence of the faulty part is there: parts can be listed */
  can_list_parts?: boolean
  /** Approved parts not here yet */
  parts_awaited?: boolean
  /** New part arrived: photo/video of it fitted, then Done */
  parts_arrived?: boolean
  part_requests?: { id: number; part_name: string; part_number: string | null; quantity: number; status: SparePartStatus }[]
  /** Parts were approved: a photo/video of the fitted part is needed before Done */
  needs_fitted_media?: boolean
  /** The customer declined all its parts: it is listed under Observations */
  declined_by_customer?: boolean
  /** Why it can't be marked Done yet, or null */
  done_shortfall?: string | null
}

export interface JobCardAdditionalJob {
  id: number
  job_card_id: number
  job_card_issue_id: number | null
  description: string
  parts_used: string | null
  cost: number | string
  done_by?: { id: number; name: string } | null
  created_at: string
  /** Parts the technician asked for to do this job */
  part_requests?: {
    id: number
    part_name: string
    part_number: string | null
    quantity: number | string
    status: SparePartStatus
  }[]
}

export interface GuardFailureInfo {
  code: string
  message: string
  details: { items?: SheetProblem[]; fields?: string[]; issues?: { id: number; description: string }[] }
}

export interface StatusTransitionOption {
  status: JobCardStatus
  label: string
  allowed: boolean
  permitted: boolean
  // A move into a stage the system enters by itself: owner/manager only, with a reason
  manual: boolean
  requires_notes: boolean
  can_override: boolean
  blocked_by: GuardFailureInfo[]
}

export interface StatusTransitions {
  options: StatusTransitionOption[]
  stage: {
    status: JobCardStatus
    waiting_for: string | null
    // Sent back (e.g. QC failed): people move it on again
    sent_back: boolean
  }
}

/** Job stages master row */
export interface WorkflowStageRow {
  id: number
  status: JobCardStatus
  label: string
  waiting_for: string | null
  auto: boolean
  auto_capable: boolean
  entered_by: string | null
  notify_customer: 'off' | 'first_entry' | 'always'
  message: string | null
  // Tell the customer about new photos/videos on entering this stage
  gallery_update: boolean
  customized_at: string | null
}

export interface BillLine {
  id: number
  type: string
  hsn_code: string | null
  description: string
  quantity: number
  unit_price: number
  discount: number
  taxable_amount: number
  tax_rate: number
  gst_inclusive: boolean
  gst_applicable: boolean
  tax: number
  total: number
  from_estimate: boolean
  section: 'plan' | 'additional' | 'other'
}

export interface JobBill {
  id: number
  number: string
  kind: 'proforma' | 'tax'
  status: 'draft' | 'approved' | 'converted' | 'closed' | 'issued' | 'partial' | 'paid' | 'cancelled'
  date: string
  editable: boolean
  payable: boolean
  is_igst: boolean
  customer_gstin: string | null
  place_of_supply: string | null
  lines: BillLine[]
  value_before_discount: number
  discount: number
  taxable_amount: number
  cgst: number
  sgst: number
  igst: number
  total_tax: number
  round_off: number
  total: number
  approved_by: string | null
  approved_at: string | null
  closed_at: string | null
  proforma_number: string | null
  estimate_changed: boolean
}

export interface JobPayment {
  id: number
  number: string
  amount: number
  stage: 'advance' | 'part' | 'final' | 'refund' | 'credit' | null
  /** "split" when paid several ways; see parts */
  method: string
  parts: { method: string; amount: number; reference_number: string | null }[]
  reference_number: string | null
  payment_date: string
  notes: string | null
  received_by: string | null
  created_at: string
}

export interface JobGatePass {
  id: number
  number: string
  override: boolean
  override_reason: string | null
  amount_due: number
  issued_by: string
  issued_at: string
  vehicle_out_at: string | null
  vehicle_out_by: string | null
}

export interface JobBillingSummary {
  estimate_total: number | null
  bill: JobBill | null
  bill_total: number | null
  paid: number
  balance: number
  /** Paid beyond the approved bill: to refund or keep as the customer's credit */
  refund_due: number
  /** The customer's credit balance (usable as a payment method) */
  customer_credit: number
  credit_method: string
  payments: JobPayment[]
  gate_pass: JobGatePass | null
  proforma_from: string | null
  methods: string[]
  gst_rates: number[]
  can: { manage: boolean; approve: boolean; pay: boolean; settle: boolean; override_gate_pass: boolean; vehicle_out: boolean } | null
}

export interface StatusHistoryEntry {
  from_status: JobCardStatus | null
  to_status: JobCardStatus
  notes: string | null
  override: boolean
  changed_by: string | null
  changed_at: string
}

// ---- Job-sheet masters ----

export interface ChecklistMasterItem {
  id: number
  section_id: number
  code: string | null
  item_name: string
  description: string | null
  input_type: SheetInputType
  media_requirement: MediaRequirement
  min_media_count: number
  na_allowed: boolean
  is_required: boolean
  is_active: boolean
  expected_answer: 'yes' | 'no' | null
  applies_to_package_ids: number[] | null
  source_marker: string | null
  rules_customized_at: string | null
  sort_order: number
}

export interface ChecklistMasterSection {
  id: number
  template_id: number
  name: string
  sort_order: number
  items: ChecklistMasterItem[]
}

export interface ChecklistMasterTemplate {
  id: number
  name: string
  slug: string
  sheet_kind: SheetKind
  service_type: string | null
  applies_to_all: boolean
  filled_by: 'technician' | 'advisor'
  has_issues_tab: boolean
  has_observations: boolean
  is_active: boolean
  sort_order: number
  items_count?: number
  packages: { id: number; name: string; slug: string }[]
  sections?: ChecklistMasterSection[]
}

export interface Bay {
  id: number
  branch_id?: number
  name: string
  code: string
  type: string
  description?: string | null
  is_active: boolean
  is_occupied?: boolean
  current_job?: JobCard | null
}

// Billing types
export interface Invoice {
  id: number
  invoice_number: string
  branch_id: number
  branch?: Branch
  job_card_id: number | null
  job_card?: JobCard | null
  customer_id: number
  customer?: Customer
  invoice_date: string
  due_date: string | null
  subtotal: number | string
  discount: number | string
  taxable_amount: number | string
  cgst: number | string
  sgst: number | string
  igst: number | string
  total_tax: number | string
  total_amount: number | string
  paid_amount: number | string
  balance_amount: number | string
  status: 'draft' | 'issued' | 'partial' | 'paid' | 'cancelled'
  is_igst: boolean
  items: InvoiceItem[]
  payments: Payment[]
  notes: string | null
  cancellation_reason?: string | null
  created_at: string
  updated_at: string
}

export interface InvoiceItem {
  id: number
  invoice_id: number
  type: 'package' | 'labor' | 'part' | 'service' | 'addon' | 'other' | null
  description: string
  hsn_code: string | null
  quantity: number | string
  unit: string | null
  unit_price: number | string
  discount: number | string
  tax_rate: number | string
  total: number | string
}

export type PaymentMethod = 'cash' | 'card' | 'upi' | 'bank_transfer' | 'cheque' | 'wallet'

export interface Payment {
  id: number
  payment_number: string
  branch_id: number
  invoice_id: number
  invoice?: Invoice
  amount: number | string
  payment_date: string
  method: PaymentMethod
  reference_number: string | null
  cheque_number: string | null
  cheque_date: string | null
  bank_name: string | null
  notes: string | null
  status: string
  // Laravel serialises the receivedBy relation over the received_by id
  received_by?: number | { id: number; name: string } | null
  created_at: string
}

// Dashboard types
export interface DashboardStats {
  jobs_in_progress: number
  daily_collection: number
  billing_till_date: number
  total_outstanding: number
  jobs_by_status: Record<JobCardStatus, number>
  recent_job_cards: JobCard[]
  recent_payments: Payment[]
}

// Filter types
export interface JobCardFilters {
  status?: JobCardStatus | JobCardStatus[]
  service_type?: ServiceType
  technician_id?: number
  service_advisor_id?: number
  bay_id?: number
  date_from?: string
  date_to?: string
  search?: string
  page?: number
  per_page?: number
}

export interface InvoiceFilters {
  status?: string | string[]
  date_from?: string
  date_to?: string
  search?: string
  page?: number
  per_page?: number
}

// Form payloads may send null for cleared optional fields
export type FormPayload<T> = { [K in keyof T]?: T[K] | null }

// ---- Estimates & customer approval (Phase 2) ----

export type EstimateStatus = 'draft' | 'sent' | 'superseded'
export type EstimateLineKind = 'oil' | 'plan_item' | 'plan_extra' | 'labour_balance' | 'part' | 'labor' | 'third_party'
export type ApprovalStatus = 'included' | 'pending' | 'approved' | 'declined'

export interface EstimateLineMedia {
  id: number
  media_type: 'photo' | 'video'
  description: string | null
  url: string
  thumbnail_url: string | null
}

export interface EstimateLine {
  id: number
  line_key: string
  section: 'plan' | 'additional'
  kind: EstimateLineKind
  description: string
  quantity: number
  rate: number
  amount: number
  approval_status: ApprovalStatus
  approval_label: string
  decided_via: 'customer_link' | 'advisor_on_behalf' | null
  decided_by: string | null
  decided_at: string | null
  media: EstimateLineMedia[]
  job_card_additional_job_id?: number | null
  job_card_issue_id?: number | null
  /** Workshop-only fields (never sent to the customer) */
  labour_service_id?: number | null
  spare_part_request_id?: number | null
  unit_cost?: number | null
  margin_pct?: number | null
}

export interface EstimateComment {
  id: number
  author_type: 'customer' | 'staff'
  author: string | null
  body: string
  created_at: string
}

export interface Estimate {
  id: number
  job_card_id: number
  version: number
  status: EstimateStatus
  package_id: number | null
  category_id: number | null
  oil_type_id: number | null
  package_name: string | null
  category_name: string | null
  oil_type_name: string | null
  package_price: number
  plan_lines: EstimateLine[]
  additional_lines: EstimateLine[]
  plan_discount_pct: number
  plan_discount_flat: number
  plan_discount: number
  plan_total: number
  extras_discount_pct: number
  extras_discount_flat: number
  extras_subtotal: number
  extras_discount: number
  extras_total: number
  grand_total: number
  grand_total_words: string
  pending_count: number
  next_service_km: number | null
  next_service_date: string | null
  payment_mode: string | null
  notes: string | null
  sent_at: string | null
  customer_viewed_at: string | null
  responded_at: string | null
  declined_label: string
  /** Observations & recommendations with remarks (work not approved is listed here) */
  observations?: { id: number; text: string; source: string; remark: string | null; estimate_line_key: string | null }[]
  comments: EstimateComment[]
  sent_by?: { id: number; name: string } | null
  warnings?: string[]
}

export interface EstimateVersionSummary {
  id: number
  version: number
  status: EstimateStatus
  grand_total: number
  sent_at: string | null
  sent_by: string | null
  responded_at: string | null
}

export interface EstimateOverview {
  versions: EstimateVersionSummary[]
  draft: Estimate | null
  current: Estimate | null
  decisions_open: boolean
}

export interface CustomerMessage {
  id: number
  template: string
  body: string
  phone: string | null
  channel: string
  status: 'prepared' | 'sent' | 'failed' | 'dismissed'
  error: string | null
  share_url: string | null
  sent_at: string | null
  sent_by: { id: number; name: string } | null
  created_at: string
}

export interface CustomerLinkInfo {
  url: string
  expires_at: string | null
  last_viewed_at: string | null
  created_at: string
}

export interface EstimateSuggestion {
  description: string
  kind: EstimateLineKind
  /** null for a labour-chart service not offered for this car */
  last_rate: number | null
  median_rate: number | null
  times_used: number
  source: 'labour' | 'history' | 'addon'
  labour_service_id?: number
  unit?: 'job' | 'hour'
}

export interface EstimateDecisionLog {
  id: number
  line_key: string
  description: string
  from_status: ApprovalStatus
  to_status: ApprovalStatus
  decided_via: 'customer_link' | 'advisor_on_behalf'
  user: { id: number; name: string } | null
  note: string | null
  customer_message: { id: number; status: string; sent_at: string | null } | null
  created_at: string
}

export interface ServiceReport {
  title: string
  job_number: string
  date: string
  customer: string | null
  car: string
  registration_no: string | null
  km_reading: number | null
  advisor: string | null
  activities_carried_out: number
  sections: { name: string; items: { name: string; mark: string }[] }[]
  additional_jobs: string[]
  observations: { text: string; severity: 'minor' | 'medium' | 'major' | null }[]
  not_approved: string[]
  not_approved_label: string
  next_service_km: number | null
  next_service_date: string | null
  advisor_notes: string | null
  released_at: string | null
  sends_to_customer: boolean
  include: { activities: boolean; additional: boolean; observations: boolean }
}

export interface CustomerGalleryItem {
  id: number
  media_type: 'photo' | 'video'
  caption: string | null
  url: string
  thumbnail_url: string | null
  captured_at: string | null
}

export interface CustomerGalleryArea {
  key: string
  title: string
  count: number
  groups: { title: string | null; items: CustomerGalleryItem[] }[]
}

export interface PublicJobPage {
  workshop: { name: string; phone: string | null }
  job: {
    job_number: string
    status: string
    status_label: string
    customer_name: string | null
    car: string
    registration_no: string | null
    plan: string | null
    advisor: string | null
    advisor_phone: string | null
    timeline: { status: string; label: string; at: string }[]
  }
  estimate: Estimate | null
  decisions_open: boolean
  // Section-wise: arrival, job sheets by section, additional work before/after (final QC is internal)
  gallery: CustomerGalleryArea[]
  // Changes whenever anything on the page changes (the page polls it)
  version: string
  report: ServiceReport | null
  // Once back office approves the final bill
  bill: { number: string; kind: 'proforma' | 'tax'; total: number; paid: number; balance: number; refund_due: number; gate_pass: string | null } | null
}

export interface PlanMaster {
  id: number
  name: string
  slug: string
  description: string | null
  features: string[]
  activity_points: number
  home_service_available: boolean
  send_customer_report: boolean
  next_service_km: number | null
  next_service_months: number | null
  is_active: boolean
  sort_order: number
  prices_count: number
  sheets_count: number
}

export type FuelGroup = 'petrol' | 'diesel'

export interface LabourServiceMaster {
  id: number
  name: string
  unit: 'job' | 'hour'
  gst_rate: number
  sort_order: number
  is_active: boolean
  /** rates[fuel][categoryId]; null or missing = not offered */
  rates: Record<FuelGroup, Record<number, number | null>>
}

export interface PricingMasters {
  packages: PlanMaster[]
  categories: { id: number; name: string; slug: string }[]
  oil_types: { id: number; name: string; slug: string; product_name: string | null; rate_per_litre: string }[]
  prices: { package_id: number; category_id: number; oil_type_id: number; price: string }[]
  litres: { package_id: number; category_id: number; litres: string }[]
  line_items: {
    id: number
    package_id: number
    description: string
    sort_order: number
    is_active: boolean
    prices: Record<number, number>
  }[]
  labour_services: LabourServiceMaster[]
  parts_default_margin_pct: number
}

// ---- Labour charges for a job's car

export interface LabourCharge {
  labour_service_id: number
  description: string
  unit: 'job' | 'hour'
  /** null = not on the chart for this car type / fuel */
  rate: number | null
}

// ---- Parts pricing loop (advisor ↔ spare parts coordinator)

export type SparePartStatus =
  | 'draft'
  | 'proposed'
  | 'requested'
  | 'quoted'
  | 'approved'
  | 'declined'
  | 'ordered'
  | 'issued'
  | 'received'
  | 'returned'
  | 'cancelled'

export type GstMode = 'incl' | 'excl' | 'cash'

export interface SparePartQuote {
  id: number
  supplier_id: number
  supplier: string | null
  unit_cost: number
  gst_mode: GstMode
  gst_rate: number
  landed_unit_cost: number
  availability: string | null
}

export interface SparePartRequest {
  id: number
  job_card_id: number
  job_number: string | null
  job_status: string | null
  vehicle: string | null
  advisor: string | null
  part_name: string
  part_number: string | null
  quantity: number
  unit: string
  note: string | null
  status: SparePartStatus
  estimate_line_key: string | null
  job_card_additional_job_id: number | null
  additional_job: string | null
  received_at: string | null
  requested_by: string | null
  requested_at: string | null
  quoted_by: string | null
  quoted_at: string | null
  ordered_by: string | null
  ordered_at: string | null
  selected_quote_id: number | null
  quotes: SparePartQuote[]
}

export interface Supplier {
  id: number
  name: string
  contact_person: string | null
  phone: string | null
  gstin: string | null
  is_active: boolean
}

// ---- Intake (trial drive + inventory media)

export interface IntakeInfo {
  editable: boolean
  trial: { status: 'done' | 'skipped' | null; notes: string | null; by: string | null; at: string | null }
  km_reading: number | null
  fuel_level: string | null
  requirements: { min_photos: number; min_videos: number }
  counts: { photo: number; video: number }
  media: JobCardMedia[]
}

// ---- In-app staff notifications

export interface StaffNotification {
  id: string
  kind: 'parts_price_requested' | 'parts_quoted' | 'parts_to_procure' | 'estimate_answered' | string
  title: string
  body: string
  link: string
  job_card_id: number | null
  read: boolean
  created_at: string
}

// ---- Create / Edit Job Card choices

export interface JobCardPlanOption {
  id: number
  name: string
  /** Oil grades this plan is priced for on the car's type, with the package price */
  oil_options: { id: number; name: string; price: number }[]
  sheets: string[]
}

export interface JobCardOptions {
  car_type: { id: number; name: string } | null
  plans: JobCardPlanOption[]
  service_types: { value: ServiceType; label: string; sheets: string[] }[]
  oil_types: { id: number; name: string }[]
  /** Set when an estimate has been sent: plan and oil grade change on the estimate instead */
  lock: { reason: string } | null
}

// ---- Job card assignments (several technicians, bay moves)

export interface JobCardAssignments {
  technicians: { id: number; name: string; is_lead: boolean }[]
  bay: { id: number; name: string } | null
  bay_moves: { from: string | null; to: string | null; notes: string | null; by: string | null; at: string | null }[]
  candidates: {
    technicians: { id: number; name: string }[]
    bays: { id: number; name: string; code: string; type: string; occupied_by: string | null }[]
  }
}

// ---- Car classes & models master

export interface CarClassMasters {
  classes: { id: number; name: string; slug: string; description: string | null; sort_order: number; is_active: boolean; vehicles_count: number; has_prices: boolean }[]
  makes: { id: number; name: string; is_active: boolean }[]
  models: { id: number; make_id: number; make: string | null; name: string; class_id: number | null; is_active: boolean }[]
}

