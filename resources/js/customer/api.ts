/**
 * The customer app's API client: its own session token (never a staff login).
 * A 401 means the session ended: back to sign-in.
 */
import axios, { type AxiosError } from 'axios'
import { session, signOutLocally, type Viewer } from './session'
import router from './router'

const api = axios.create({
  baseURL: '/api/v1/customer',
  headers: { Accept: 'application/json' },
  timeout: 60000,
})

api.interceptors.request.use((config) => {
  if (session.token) config.headers.Authorization = `Bearer ${session.token}`
  return config
})

api.interceptors.response.use(
  (r) => r,
  (error: AxiosError) => {
    if (error.response?.status === 401 && session.token) {
      signOutLocally()
      router.push({ name: 'login' })
    }
    return Promise.reject(error)
  },
)

export default api

export function message(err: any, fallback = 'Something went wrong. Please try again or call us.'): string {
  const data = err?.response?.data
  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (Array.isArray(first) && first[0] && data.message?.length < 12) return String(first[0])
  }
  return data?.message ?? (err?.code === 'ECONNABORTED' ? 'That took too long. Check your internet and try again.' : fallback)
}

export function money(n: number | null | undefined, decimals = 0): string {
  if (n === null || n === undefined) return '-'
  return '₹ ' + new Intl.NumberFormat('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(n)
}

export function date(d: string | null | undefined, withYear = true): string {
  if (!d) return '-'
  return new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}) })
}

// ------------------------------------------------------------------ shapes

export interface JobCardSummary {
  id: number
  job_number: string
  status: string
  status_label: string
  car: string
  registration_no: string | null
  plan: string | null
  created_at: string | null
  approvals_pending: number
  balance: number | null
  driver_on_the_way: boolean
  /** A fleet driver: what they may still approve on this job */
  driver_limit_left: number | null
}

export interface VehicleCard {
  id: number
  car: string
  registration_no: string
  fuel_type: string | null
  year: number | null
  next_service_due: string | null
  next_service_due_km: number | null
  insurance_expiry: string | null
  puc_expiry: string | null
}

export interface Home {
  customer: { id: number; name: string; phone: string }
  viewer: Viewer
  active_jobs: JobCardSummary[]
  vehicles: VehicleCard[]
  unread: number
  credit: number
  support_phone: string | null
  assistant: { enabled: boolean; left_today: number; paused: boolean }
  push_key: string | null
}

export interface Driver {
  kind: string
  label: string
  name: string
  phone: string | null
  lat: number | null
  lng: number | null
  updated_at: string | null
  destination: { lat: number; lng: number } | null
  distance_km: number | null
  eta_minutes: number | null
}

export interface PaymentClaimRow {
  id: number
  amount: number
  utr: string
  status: 'pending' | 'confirmed' | 'rejected'
  reason: string | null
  created_at: string | null
}

// ------------------------------------------------------------------ fleets

export interface FleetReport {
  id: number
  number: string
  kind: string
  kind_label: string
  description: string
  vehicle: { id: number; registration_no: string | null }
  driver: { id: number; name: string; phone: string } | null
  odometer_km: number | null
  maps_url: string | null
  location_text: string | null
  status: 'open' | 'booked' | 'job_opened' | 'closed'
  status_label: string
  staff_note: string | null
  booking: { number: string; date: string | null; slot: string | null; mode: string } | null
  job: { id: number; job_number: string; status: string } | null
  media: { id: number; media_type: 'photo' | 'video'; url: string }[]
  created_at: string | null
}

export interface FleetCab {
  id: number
  registration_no: string
  car: string
  drivers: { id: number; name: string; phone: string }[]
  state: 'workshop' | 'on_road'
  job: (JobCardSummary & { driver: string | null }) | null
  km: number | null
  km_at: string | null
  next_service_due_km: number | null
  km_to_service: number | null
  service_due_soon: boolean
  next_service_due: string | null
  open_reports: number
  spend_year: number
}

export interface FleetDashboard {
  fleet: { name: string; driver_limit: number }
  totals: {
    cabs: number
    at_workshop: number
    approvals_waiting: number
    balance_due: number
    open_reports: number
    service_due_soon: number
    spend_month: number
    spend_year: number
  }
  cabs: FleetCab[]
}

export interface FleetDriverRow {
  id: number
  name: string
  phone: string
  is_active: boolean
  notes: string | null
  created_via: string
  vehicles: { id: number; registration_no: string }[]
  vehicle_ids: number[]
  last_seen_at: string | null
}

export interface JobScreen {
  job: JobCardSummary
  page_token: string
  payment: {
    bill_total: number | null
    paid: number
    balance: number | null
    upi_id: string | null
    upi_name: string
    claims: PaymentClaimRow[]
  }
  driver: Driver | null
}
