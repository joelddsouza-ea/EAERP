/**
 * The customer app's API client: its own session token (never a staff login).
 * A 401 means the session ended: back to sign-in.
 */
import axios, { type AxiosError } from 'axios'
import { session, signOutLocally } from './session'
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
