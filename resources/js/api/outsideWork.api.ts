import apiClient from './client'
import type { ApiResponse } from './types'

// Outside partners (washing, wheel alignment & balancing), the partner's login, and staff
// "Send update" requests that the advisor sends to the customer (owner feedback 2026-10-24)

export type OutsideKind = 'washing' | 'wheel_alignment'

export interface OutsideWork {
  id: number
  kind: OutsideKind
  kind_label: string
  status: 'sent' | 'done' | 'returned' | 'cancelled'
  note: string | null
  partner: { id: number; name: string; phone: string | null } | null
  partner_note: string | null
  sent_by: string | null
  sent_at: string | null
  done_at: string | null
  returned_by: string | null
  returned_at: string | null
  job: { id: number; job_number: string; registration_no: string | null; car: string } | null
  media: { id: number; media_type: 'photo' | 'video'; url: string; thumbnail_url: string | null }[]
  /** wa.me link with the job for the partner's phone (staff only) */
  partner_whatsapp_url: string | null
}

export interface OutsideWorkState {
  works: OutsideWork[]
  partners: Partial<Record<OutsideKind, { id: number; name: string }[]>>
  kinds: Record<OutsideKind, string>
}

export interface ServicePartner {
  id: number
  name: string
  phone: string | null
  kind: OutsideKind
  kind_label: string
  branch_id: number | null
  branch: string | null
  is_active: boolean
  notes: string | null
  login: { phone: string; is_active: boolean } | null
}

export interface ReadyMessage {
  id: number
  template: string
  body: string
  phone: string | null
  status: 'prepared' | 'sent' | 'failed' | 'dismissed'
  error: string | null
  share_url: string | null
}

function mediaForm(file: Blob, fileName: string, meta: { poster?: Blob | null; duration_seconds?: number | null; captured_at?: string } = {}) {
  const form = new FormData()
  form.append('file', file, fileName)
  if (meta.poster) form.append('poster', meta.poster, 'poster.jpg')
  if (meta.duration_seconds != null) form.append('duration_seconds', String(meta.duration_seconds))
  form.append('captured_at', meta.captured_at ?? new Date().toISOString())
  return form
}

const upload = { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 300000 }
const jc = (jobCardId: number) => `/workshop/job-cards/${jobCardId}`

export const outsideWorkApi = {
  list: (jobCardId: number) => apiClient.get<ApiResponse<OutsideWorkState>>(`${jc(jobCardId)}/outside-works`),
  send: (jobCardId: number, data: { kind: OutsideKind; service_partner_id: number; note?: string | null }) =>
    apiClient.post<ApiResponse<OutsideWork>>(`${jc(jobCardId)}/outside-works`, data),
  returned: (jobCardId: number, workId: number) => apiClient.post<ApiResponse<OutsideWork>>(`${jc(jobCardId)}/outside-works/${workId}/returned`),
  cancel: (jobCardId: number, workId: number) => apiClient.post<ApiResponse<OutsideWork>>(`${jc(jobCardId)}/outside-works/${workId}/cancel`),
  /** The technician adds what the partner sent on WhatsApp (gallery is fine) */
  uploadMedia: (jobCardId: number, workId: number, file: Blob, fileName: string, meta = {}) =>
    apiClient.post<ApiResponse<OutsideWork>>(`${jc(jobCardId)}/outside-works/${workId}/media`, mediaForm(file, fileName, meta), upload),
}

export const partnerApi = {
  works: () => apiClient.get<ApiResponse<OutsideWork[]>>('/partner/works'),
  work: (id: number) => apiClient.get<ApiResponse<OutsideWork>>(`/partner/works/${id}`),
  uploadMedia: (id: number, file: Blob, fileName: string, meta = {}) =>
    apiClient.post<ApiResponse<OutsideWork>>(`/partner/works/${id}/media`, mediaForm(file, fileName, meta), upload),
  done: (id: number, note?: string | null) => apiClient.post<ApiResponse<OutsideWork>>(`/partner/works/${id}/done`, { note }),
}

export const servicePartnersApi = {
  options: (kind: OutsideKind) => apiClient.get<ApiResponse<{ id: number; name: string }[]>>('/workshop/service-partners/options', { params: { kind } }),
  list: () => apiClient.get<ApiResponse<{ partners: ServicePartner[]; kinds: Record<OutsideKind, string> }>>('/workshop/service-partners'),
  create: (data: Partial<ServicePartner>) => apiClient.post<ApiResponse<ServicePartner>>('/workshop/service-partners', data),
  update: (id: number, data: Partial<ServicePartner>) => apiClient.put<ApiResponse<ServicePartner>>(`/workshop/service-partners/${id}`, data),
  createLogin: (id: number, data: { phone: string; password: string }) =>
    apiClient.post<ApiResponse<ServicePartner>>(`/workshop/service-partners/${id}/login`, data),
}

export const customerUpdatesApi = {
  /** Staff finished a step: the advisor gets the message ready to send */
  request: (jobCardId: number, data: { kind: 'section' | 'stage'; sheet_id?: number; section?: string }) =>
    apiClient.post<ApiResponse<{ message: ReadyMessage; to_advisor: boolean }>>(`${jc(jobCardId)}/customer-updates`, data),
  get: (messageId: number) => apiClient.get<ApiResponse<ReadyMessage>>(`/workshop/customer-updates/${messageId}`),
  sent: (messageId: number) => apiClient.post<ApiResponse<ReadyMessage>>(`/workshop/customer-updates/${messageId}/sent`),
  skip: (messageId: number) => apiClient.post<ApiResponse<ReadyMessage>>(`/workshop/customer-updates/${messageId}/skip`),
  settings: () => apiClient.get<ApiResponse<{ section_message: string; stage_message: string }>>('/workshop/customer-update-settings'),
  saveSettings: (data: { section_message?: string; stage_message?: string }) =>
    apiClient.put<ApiResponse<{ section_message: string; stage_message: string }>>('/workshop/customer-update-settings', data),
}
