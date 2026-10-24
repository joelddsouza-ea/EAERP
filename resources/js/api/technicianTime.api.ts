import apiClient from './client'
import type { ApiResponse } from './types'

// Workshop › Technician Time: working time on jobs (minutes), in shift hours

export interface JobTime {
  id: number
  job_number: string
  registration_no: string | null
  service: string | null
  status: string
  technicians: string[]
  work_started_at: string | null
  work_completed_at: string | null
  gross_minutes: number
  approval_minutes: number
  parts_minutes: number
  outside_minutes: number
  working_minutes: number
  per_technician: { id: number; name: string; minutes: number }[]
}

export interface TechnicianTimeReport {
  from: string
  to: string
  technicians: { id: number; name: string; jobs: number; working_minutes: number; average_minutes: number }[]
  jobs: JobTime[]
  technician_list: { id: number; name: string }[]
}

type Params = { from: string; to: string; technician_id?: number | null }

export const technicianTimeApi = {
  report: (p: Params) => apiClient.get<ApiResponse<TechnicianTimeReport>>('/workshop/reports/technician-time', { params: p }),
  csv: (p: Params) => apiClient.get('/workshop/reports/technician-time.csv', { params: p, responseType: 'blob' }),
  job: (jobCardId: number) => apiClient.get<ApiResponse<JobTime | null>>(`/workshop/job-cards/${jobCardId}/working-time`),
}

/** 125 → "2 h 05 m" */
export function hm(minutes: number): string {
  const m = Math.max(0, Math.round(minutes))
  return m < 60 ? `${m} m` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')} m`
}
