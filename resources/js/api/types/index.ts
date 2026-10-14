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
  checklists?: JobCardChecklist[]
  created_at: string
  updated_at: string
}

export type JobCardStatus =
  | 'draft'
  | 'received'
  | 'in_progress'
  | 'qc'
  | 'ready'
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

export interface JobCardChecklist {
  id: number
  job_card_id: number
  checklist_template_id: number
  name: string
  items: ChecklistItem[]
  completed_at: string | null
  completed_by: number | null
}

export interface ChecklistItem {
  id: number
  name: string
  is_checked: boolean
  notes: string | null
  checked_at: string | null
  checked_by: number | null
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
