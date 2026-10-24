import apiClient from './client'
import type { ApiResponse } from './types'

// Fleets (cab owners) and their drivers: customer page › Fleet, Workshop › Fleet reports

export interface FleetDriver {
  id: number
  name: string
  phone: string
  is_active: boolean
  notes: string | null
  created_via: 'staff' | 'owner_app'
  vehicles: { id: number; registration_no: string }[]
  vehicle_ids: number[]
  last_seen_at: string | null
}

export interface FleetReport {
  id: number
  number: string
  kind: string
  kind_label: string
  description: string
  vehicle: { id: number; registration_no: string | null }
  driver: { id: number; name: string; phone: string } | null
  odometer_km: number | null
  lat: number | null
  lng: number | null
  location_text: string | null
  maps_url: string | null
  status: 'open' | 'booked' | 'job_opened' | 'closed'
  status_label: string
  staff_note: string | null
  booking: { number: string; date: string | null; slot: string | null; mode: string } | null
  job: { id: number; job_number: string; status: string } | null
  media: { id: number; media_type: 'photo' | 'video'; url: string }[]
  created_at: string | null
  customer: { id: number; name: string; phone: string | null }
  branch_id: number | null
  handled_by: string | null
  handled_at: string | null
}

export interface FleetState {
  is_fleet: boolean
  fleet_driver_limit: number | null
  default_limit: number
  limit: number
  drivers: FleetDriver[]
  cabs: { id: number; registration_no: string; km: number | null }[]
  reports: FleetReport[]
}

/** Masters › Fleet Cabs */
export interface FleetSettings {
  driver_limit: number
  service_due_km_warning: number
}

export interface DriverInput {
  name?: string
  phone?: string
  notes?: string | null
  vehicle_ids?: number[]
  is_active?: boolean
}

export const fleetApi = {
  settings: () => apiClient.get<ApiResponse<FleetSettings>>('/fleet/settings'),
  saveSettings: (data: Partial<FleetSettings>) => apiClient.put<ApiResponse<FleetSettings>>('/fleet/settings', data),
  show: (customerId: number) => apiClient.get<ApiResponse<FleetState>>(`/fleet/customers/${customerId}`),
  update: (customerId: number, data: { is_fleet?: boolean; fleet_driver_limit?: number | null }) =>
    apiClient.put<ApiResponse<FleetState>>(`/fleet/customers/${customerId}`, data),
  addDriver: (customerId: number, data: DriverInput) => apiClient.post<ApiResponse<FleetDriver>>(`/fleet/customers/${customerId}/drivers`, data),
  updateDriver: (customerId: number, driverId: number, data: DriverInput) =>
    apiClient.put<ApiResponse<FleetDriver>>(`/fleet/customers/${customerId}/drivers/${driverId}`, data),
  signOutDriver: (customerId: number, driverId: number) => apiClient.post(`/fleet/customers/${customerId}/drivers/${driverId}/sign-out`),
  driversForJob: (customerId: number, vehicleId?: number | null) =>
    apiClient.get<ApiResponse<{ id: number; name: string; phone: string; drives_this_cab: boolean }[]>>(`/fleet/customers/${customerId}/drivers`, { params: { vehicle_id: vehicleId || undefined } }),

  reports: (status = 'active', customerId?: number) =>
    apiClient.get<ApiResponse<{ reports: FleetReport[]; counts: { open: number } }>>('/fleet/reports', { params: { status, customer_id: customerId } }),
  book: (reportId: number, data: { booking_date: string; slot_from?: string | null; service_mode: string; address?: string | null; maps_url?: string | null; note?: string | null }) =>
    apiClient.post<ApiResponse<FleetReport>>(`/fleet/reports/${reportId}/book`, data),
  close: (reportId: number, note: string) => apiClient.post<ApiResponse<FleetReport>>(`/fleet/reports/${reportId}/close`, { note }),
}
