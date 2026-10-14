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

export type MediaRequirement = 'none' | 'photo' | 'video' | 'photo_or_video'
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
  item_id: number
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
  source: 'manual' | 'activity' | 'pre_delivery'
  source_item_id: number | null
  text: string
  customer_visible: boolean
  created_by?: { id: number; name: string } | null
  created_at: string
}

export interface JobCardIssue {
  id: number
  job_card_id: number
  description: string
  priority: 'critical' | 'high' | 'normal'
  status: 'open' | 'in_progress' | 'resolved' | 'deferred'
  resolution_notes: string | null
  resolved_at: string | null
  additional_jobs?: JobCardAdditionalJob[]
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
  requires_notes: boolean
  can_override: boolean
  blocked_by: GuardFailureInfo[]
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
