import apiClient from './client'
import type { ApiResponse, JobCardMedia } from './types'

export type TripKind = 'pickup' | 'drop' | 'home_visit'
export type TripStatus = 'unassigned' | 'scheduled' | 'en_route' | 'collected' | 'arrived' | 'done' | 'cancelled'
export type ServiceMode = 'drive_in' | 'pickup_drop' | 'home'
export type FuelLevel = 'empty' | 'quarter' | 'half' | 'three_quarter' | 'full'

export interface TripPerson {
  id: number
  name: string
  phone: string | null
  roles: string[]
  is_driver: boolean
}

export interface Trip {
  id: number
  kind: TripKind
  label: string
  status: TripStatus
  assigned_to: number | null
  assignee: { id: number; name: string; phone: string | null } | null
  address: string | null
  landmark: string | null
  maps_url: string | null
  lat: number | null
  lng: number | null
  navigate_url: string | null
  slot_date: string | null
  slot_from: string | null
  slot_to: string | null
  charge: number
  started_at: string | null
  arrived_at: string | null
  completed_at: string | null
  handover_name: string | null
  notes: string | null
  cancel_reason: string | null
  job: {
    id: number
    job_number: string
    status: string
    status_label: string
    service_mode: ServiceMode
    customer: { name: string; phone: string | null; whatsapp_phone: string | null } | null
    vehicle: { registration_no: string; name: string; color: string | null } | null
  } | null
}

export interface TripDetail extends Trip {
  can_drive: boolean
  can_manage: boolean
  intake: {
    km_reading: number | null
    fuel_level: FuelLevel | null
    counts: { photo: number; video: number }
    min_photos: number
    min_videos: number
    editable: boolean
    media: (JobCardMedia & { tag?: string | null })[]
  } | null
  handover: {
    min_photos: number
    photos: number
    sides: string[]
    media: (JobCardMedia & { tag?: string | null })[]
  } | null
  gate_pass: { number: string; vehicle_out_at: string | null } | null
  bill: { total: number | null; paid: number; balance: number; methods: string[] } | null
  job_sheet_url: string | null
}

export interface CustomerAddress {
  id: number
  label: string | null
  address: string
  landmark: string | null
  maps_url: string | null
  lat: number | null
  lng: number | null
}

export interface JobTrips {
  service_mode: ServiceMode
  trips: Trip[]
  people: TripPerson[]
  charges: Record<TripKind, number>
  addresses: CustomerAddress[]
}

export interface TripSettings {
  pickup_charge: number
  drop_charge: number
  home_visit_charge: number
  policy_text: string
  min_drop_photos: number
  min_home_close_photos: number
}

/** Booking / editing fields of a trip */
export interface TripForm {
  assigned_to?: number | null
  address?: string | null
  landmark?: string | null
  maps_url?: string | null
  lat?: number | null
  lng?: number | null
  slot_date?: string | null
  slot_from?: string | null
  slot_to?: string | null
  notes?: string | null
  with_charge?: boolean
  charge?: number | null
  save_address?: boolean
}

const t = (id: number) => `/workshop/trips/${id}`

export const tripsApi = {
  mine() {
    return apiClient.get<ApiResponse<Trip[]>>('/workshop/trips/mine')
  },

  board(params: { date?: string; assigned_to?: number } = {}) {
    return apiClient.get<ApiResponse<{ date: string; trips: Trip[]; people: TripPerson[] }>>('/workshop/trips/board', { params })
  },

  forJob(jobCardId: number) {
    return apiClient.get<ApiResponse<JobTrips>>(`/workshop/job-cards/${jobCardId}/trips`)
  },

  get(id: number) {
    return apiClient.get<ApiResponse<TripDetail>>(t(id))
  },

  create(jobCardId: number, kind: TripKind, data: TripForm) {
    return apiClient.post<ApiResponse<TripDetail>>(`/workshop/job-cards/${jobCardId}/trips`, { kind, ...data })
  },

  update(id: number, data: TripForm) {
    return apiClient.patch<ApiResponse<TripDetail>>(t(id), data)
  },

  cancel(id: number, reason: string) {
    return apiClient.post<ApiResponse<TripDetail>>(`${t(id)}/cancel`, { reason })
  },

  /** Driver / home technician steps */
  step(id: number, step: 'start' | 'reach-workshop' | 'arrive', data: Record<string, unknown> = {}) {
    return apiClient.post<ApiResponse<TripDetail>>(`${t(id)}/${step}`, data)
  },

  collect(id: number, data: { km_reading: number; fuel_level: FuelLevel; notes?: string | null; handover_name?: string | null }) {
    return apiClient.post<ApiResponse<TripDetail>>(`${t(id)}/collect`, data)
  },

  finish(id: number, step: 'deliver' | 'close', data: { notes?: string | null; handover_name?: string | null }) {
    return apiClient.post<ApiResponse<TripDetail>>(`${t(id)}/${step}`, data)
  },

  turnBack(id: number, reason: string) {
    return apiClient.post<ApiResponse<TripDetail>>(`${t(id)}/turn-back`, { reason })
  },

  readings(id: number, data: { km_reading: number; fuel_level: FuelLevel }) {
    return apiClient.post<ApiResponse<TripDetail>>(`${t(id)}/readings`, data)
  },

  payment(id: number, data: { method: string; amount: number; reference_number?: string | null }) {
    return apiClient.post<ApiResponse<TripDetail>>(`${t(id)}/payment`, data)
  },

  upload(id: number, form: FormData, onProgress?: (pct: number) => void) {
    return apiClient.post<ApiResponse<{ media: JobCardMedia; trip: TripDetail }>>(`${t(id)}/media`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 300000,
      onUploadProgress: (e) => onProgress?.(e.total ? Math.round((e.loaded / e.total) * 100) : 0),
    })
  },

  removeMedia(id: number, mediaId: number) {
    return apiClient.delete<ApiResponse<TripDetail>>(`${t(id)}/media/${mediaId}`)
  },

  settings() {
    return apiClient.get<ApiResponse<TripSettings>>('/workshop/trip-settings')
  },

  saveSettings(data: Partial<TripSettings>) {
    return apiClient.put<ApiResponse<TripSettings>>('/workshop/trip-settings', data)
  },

  addresses(customerId: number) {
    return apiClient.get<ApiResponse<CustomerAddress[]>>(`/workshop/customers/${customerId}/addresses`)
  },
}

export const TRIP_STATUS_LABELS: Record<TripStatus, string> = {
  unassigned: 'Not assigned',
  scheduled: 'Scheduled',
  en_route: 'On the way',
  collected: 'Car collected',
  arrived: 'At the customer',
  done: 'Done',
  cancelled: 'Cancelled',
}

export const FUEL_LEVELS: { value: FuelLevel; label: string }[] = [
  { value: 'empty', label: 'Empty' },
  { value: 'quarter', label: '1/4' },
  { value: 'half', label: '1/2' },
  { value: 'three_quarter', label: '3/4' },
  { value: 'full', label: 'Full' },
]

export function slotText(trip: Pick<Trip, 'slot_date' | 'slot_from' | 'slot_to'>): string {
  if (!trip.slot_date) return 'No slot yet'
  const date = new Date(`${trip.slot_date}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' })
  if (!trip.slot_from) return date
  return `${date}, ${trip.slot_from}${trip.slot_to ? `–${trip.slot_to}` : ''}`
}
