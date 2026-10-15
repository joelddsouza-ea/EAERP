import axios from 'axios'
import apiClient from './client'
import type {
  ApiResponse,
  Estimate,
  EstimateOverview,
  EstimateLineKind,
  EstimateSuggestion,
  EstimateDecisionLog,
  CustomerMessage,
  CustomerLinkInfo,
  ServiceReport,
  PublicJobPage,
  PricingMasters,
  PlanMaster,
  LabourServiceMaster,
  FuelGroup,
  LabourCharge,
  SparePartRequest,
  SparePartQuote,
  GstMode,
  Supplier,
  IntakeInfo,
  StaffNotification,
  JobCardMedia,
} from './types'

const jc = (jobCardId: number) => `/workshop/job-cards/${jobCardId}`

export interface EstimateHeaderUpdate {
  package_id?: number | null
  oil_type_id?: number | null
  plan_discount_pct?: number
  plan_discount_flat?: number
  extras_discount_pct?: number
  extras_discount_flat?: number
  next_service_km?: number | null
  next_service_date?: string | null
  payment_mode?: string | null
  notes?: string | null
}

export interface EstimateLineInput {
  kind?: EstimateLineKind
  description?: string
  quantity?: number
  rate?: number
  media_ids?: number[]
  job_card_additional_job_id?: number | null
  job_card_issue_id?: number | null
  labour_service_id?: number | null
  /** Parts priced from a supplier quote: a new margin reprices the line */
  margin_pct?: number | null
}

export interface Decision {
  line_key: string
  decision: 'approved' | 'declined'
}

// Advisor side: drafts, versions, sending, decisions on the customer's behalf
export const estimatesApi = {
  overview(jobCardId: number) {
    return apiClient.get<ApiResponse<EstimateOverview>>(`${jc(jobCardId)}/estimates`)
  },

  /** The draft to edit (built from the masters, or a new version of the sent one) */
  draft(jobCardId: number) {
    return apiClient.post<ApiResponse<Estimate>>(`${jc(jobCardId)}/estimates`)
  },

  get(jobCardId: number, estimateId: number) {
    return apiClient.get<ApiResponse<Estimate>>(`${jc(jobCardId)}/estimates/${estimateId}`)
  },

  update(jobCardId: number, estimateId: number, data: EstimateHeaderUpdate) {
    return apiClient.put<ApiResponse<Estimate>>(`${jc(jobCardId)}/estimates/${estimateId}`, data)
  },

  refreshPlan(jobCardId: number, estimateId: number) {
    return apiClient.post<ApiResponse<Estimate>>(`${jc(jobCardId)}/estimates/${estimateId}/refresh-plan`)
  },

  addLine(jobCardId: number, estimateId: number, data: EstimateLineInput) {
    return apiClient.post<ApiResponse<Estimate>>(`${jc(jobCardId)}/estimates/${estimateId}/lines`, data)
  },

  updateLine(jobCardId: number, estimateId: number, lineId: number, data: EstimateLineInput) {
    return apiClient.put<ApiResponse<Estimate>>(`${jc(jobCardId)}/estimates/${estimateId}/lines/${lineId}`, data)
  },

  removeLine(jobCardId: number, estimateId: number, lineId: number) {
    return apiClient.delete<ApiResponse<Estimate>>(`${jc(jobCardId)}/estimates/${estimateId}/lines/${lineId}`)
  },

  send(jobCardId: number, estimateId: number) {
    return apiClient.post<ApiResponse<{ estimate: Estimate; message: CustomerMessage; moved_to: string | null }>>(
      `${jc(jobCardId)}/estimates/${estimateId}/send`
    )
  },

  comment(jobCardId: number, estimateId: number, body: string) {
    return apiClient.post<ApiResponse<Estimate>>(`${jc(jobCardId)}/estimates/${estimateId}/comments`, { body })
  },

  decideOnBehalf(jobCardId: number, decisions: Decision[], note: string) {
    return apiClient.post<
      ApiResponse<{ changes: unknown[]; estimate: Estimate; message: CustomerMessage | null; job_status: string }>
    >(`${jc(jobCardId)}/estimate-decisions`, { decisions, note })
  },

  decisionLog(jobCardId: number) {
    return apiClient.get<ApiResponse<EstimateDecisionLog[]>>(`${jc(jobCardId)}/estimate-decisions`)
  },

  suggestions(q: string, categoryId?: number | null, jobCardId?: number) {
    return apiClient.get<ApiResponse<EstimateSuggestion[]>>('/workshop/estimate-suggestions', {
      params: { q, category_id: categoryId ?? undefined, job_card_id: jobCardId },
    })
  },

  /** All photos/videos on the job card, to attach to lines */
  mediaLibrary(jobCardId: number) {
    return apiClient.get<ApiResponse<JobCardMedia[]>>(`${jc(jobCardId)}/media-library`)
  },

  /** PDFs need the bearer token, so they are fetched as blobs */
  pdf(jobCardId: number, estimateId: number) {
    return apiClient.get<Blob>(`${jc(jobCardId)}/estimates/${estimateId}/pdf`, { responseType: 'blob' })
  },
}

// Customer link and WhatsApp messages
export const customerChannelApi = {
  link(jobCardId: number) {
    return apiClient.get<ApiResponse<CustomerLinkInfo | null>>(`${jc(jobCardId)}/customer-link`)
  },

  regenerateLink(jobCardId: number) {
    return apiClient.post<ApiResponse<CustomerLinkInfo>>(`${jc(jobCardId)}/customer-link`)
  },

  revokeLink(jobCardId: number) {
    return apiClient.delete<ApiResponse<null>>(`${jc(jobCardId)}/customer-link`)
  },

  messages(jobCardId: number) {
    return apiClient.get<ApiResponse<CustomerMessage[]>>(`${jc(jobCardId)}/customer-messages`)
  },

  markSent(jobCardId: number, messageId: number) {
    return apiClient.post<ApiResponse<CustomerMessage>>(`${jc(jobCardId)}/customer-messages/${messageId}/sent`)
  },

  dismiss(jobCardId: number, messageId: number) {
    return apiClient.post<ApiResponse<CustomerMessage>>(`${jc(jobCardId)}/customer-messages/${messageId}/dismiss`)
  },

  remind(jobCardId: number) {
    return apiClient.post<ApiResponse<CustomerMessage>>(`${jc(jobCardId)}/customer-messages/approval-reminder`)
  },

  shareMedia(jobCardId: number, mediaIds: number[]) {
    return apiClient.post<ApiResponse<CustomerMessage>>(`${jc(jobCardId)}/customer-messages/share-media`, {
      media_ids: mediaIds,
    })
  },
}

// Service report (360 / ELITE)
export const reportsApi = {
  get(jobCardId: number) {
    return apiClient.get<ApiResponse<ServiceReport>>(`${jc(jobCardId)}/report`)
  },

  update(
    jobCardId: number,
    data: Partial<{
      include_activities: boolean
      include_additional: boolean
      include_observations: boolean
      advisor_notes: string | null
    }>
  ) {
    return apiClient.put<ApiResponse<ServiceReport>>(`${jc(jobCardId)}/report`, data)
  },

  release(jobCardId: number) {
    return apiClient.post<ApiResponse<{ report: ServiceReport; message: CustomerMessage }>>(
      `${jc(jobCardId)}/report/release`
    )
  },

  pdf(jobCardId: number) {
    return apiClient.get<Blob>(`${jc(jobCardId)}/report/pdf`, { responseType: 'blob' })
  },
}

// Owner price masters
export const pricingApi = {
  get() {
    return apiClient.get<ApiResponse<PricingMasters>>('/workshop/pricing-masters')
  },

  setPrice(data: { package_id: number; category_id: number; oil_type_id: number; price: number | null }) {
    return apiClient.put<ApiResponse<unknown>>('/workshop/pricing-masters/prices', data)
  },

  setLitres(data: { package_id: number; category_id: number; litres: number }) {
    return apiClient.put<ApiResponse<unknown>>('/workshop/pricing-masters/litres', data)
  },

  updateOilType(id: number, data: { product_name?: string | null; rate_per_litre?: number }) {
    return apiClient.put<ApiResponse<unknown>>(`/workshop/pricing-masters/oil-types/${id}`, data)
  },

  createPackage(data: PlanInput & { name: string }) {
    return apiClient.post<ApiResponse<PlanMaster>>('/workshop/pricing-masters/packages', data)
  },

  updatePackage(id: number, data: PlanInput) {
    return apiClient.put<ApiResponse<unknown>>(`/workshop/pricing-masters/packages/${id}`, data)
  },

  addLineItem(data: { package_id: number; description: string }) {
    return apiClient.post<ApiResponse<unknown>>('/workshop/pricing-masters/line-items', data)
  },

  updateLineItem(
    id: number,
    data: { description?: string; is_active?: boolean; sort_order?: number; prices?: Record<number, number> }
  ) {
    return apiClient.put<ApiResponse<unknown>>(`/workshop/pricing-masters/line-items/${id}`, data)
  },

  deleteLineItem(id: number) {
    return apiClient.delete<ApiResponse<null>>(`/workshop/pricing-masters/line-items/${id}`)
  },

  addLabourService(data: { name: string; unit?: 'job' | 'hour' }) {
    return apiClient.post<ApiResponse<LabourServiceMaster>>('/workshop/pricing-masters/labour-services', data)
  },

  updateLabourService(
    id: number,
    data: {
      name?: string
      unit?: 'job' | 'hour'
      is_active?: boolean
      sort_order?: number
      rates?: Partial<Record<FuelGroup, Record<number, number | null>>>
    }
  ) {
    return apiClient.put<ApiResponse<LabourServiceMaster>>(`/workshop/pricing-masters/labour-services/${id}`, data)
  },

  setPartsMargin(defaultMarginPct: number) {
    return apiClient.put<ApiResponse<{ parts_default_margin_pct: number }>>('/workshop/pricing-masters/parts-margin', {
      default_margin_pct: defaultMarginPct,
    })
  },
}

export interface PlanInput {
  name?: string
  description?: string | null
  features?: string[]
  activity_points?: number
  home_service_available?: boolean
  send_customer_report?: boolean
  next_service_km?: number | null
  next_service_months?: number | null
  is_active?: boolean
  sort_order?: number
}

export interface PartRequestInput {
  part_name: string
  part_number?: string | null
  quantity?: number
  unit?: string | null
  note?: string | null
}

export interface QuoteInput {
  supplier_id?: number | null
  supplier_name?: string | null
  supplier_phone?: string | null
  unit_cost: number
  gst_mode: GstMode
  gst_rate?: number
  availability?: string | null
}

// Parts pricing loop: advisor asks, the spare parts coordinator quotes (Parts Desk)
export const partsApi = {
  forJobCard(jobCardId: number) {
    return apiClient.get<ApiResponse<SparePartRequest[]>>(`${jc(jobCardId)}/part-requests`)
  },

  requestPrices(jobCardId: number, parts: PartRequestInput[]) {
    return apiClient.post<ApiResponse<unknown>>(`${jc(jobCardId)}/part-requests`, { parts })
  },

  cancel(jobCardId: number, requestId: number) {
    return apiClient.post<ApiResponse<unknown>>(`${jc(jobCardId)}/part-requests/${requestId}/cancel`)
  },

  addToEstimate(jobCardId: number, estimateId: number, requestId: number, marginPct?: number | null) {
    return apiClient.post<ApiResponse<Estimate>>(`${jc(jobCardId)}/estimates/${estimateId}/parts`, {
      spare_part_request_id: requestId,
      margin_pct: marginPct ?? undefined,
    })
  },

  labourCharges(jobCardId: number, q?: string) {
    return apiClient.get<ApiResponse<LabourCharge[]>>(`${jc(jobCardId)}/labour-charges`, { params: { q: q || undefined } })
  },

  desk(tab: 'requests' | 'procure') {
    return apiClient.get<ApiResponse<{ tab: string; requests: SparePartRequest[] }>>('/workshop/parts-desk', {
      params: { tab },
    })
  },

  addQuote(requestId: number, data: QuoteInput) {
    return apiClient.post<ApiResponse<SparePartQuote>>(`/workshop/parts-desk/requests/${requestId}/quotes`, data)
  },

  removeQuote(requestId: number, quoteId: number) {
    return apiClient.delete<ApiResponse<null>>(`/workshop/parts-desk/requests/${requestId}/quotes/${quoteId}`)
  },

  submit(selections: Record<number, number>) {
    return apiClient.post<ApiResponse<{ submitted: number }>>('/workshop/parts-desk/submit', { selections })
  },

  markOrdered(requestId: number) {
    return apiClient.post<ApiResponse<unknown>>(`/workshop/parts-desk/requests/${requestId}/ordered`)
  },

  suppliers() {
    return apiClient.get<ApiResponse<Supplier[]>>('/workshop/suppliers')
  },
}

// Walk-in intake: trial drive and inventory photos/videos
export const intakeApi = {
  get(jobCardId: number) {
    return apiClient.get<ApiResponse<IntakeInfo>>(`${jc(jobCardId)}/intake`)
  },

  setTrial(jobCardId: number, status: 'done' | 'skipped', notes?: string | null) {
    return apiClient.put<ApiResponse<IntakeInfo>>(`${jc(jobCardId)}/intake/trial`, { status, notes })
  },

  upload(jobCardId: number, form: FormData, onProgress?: (pct: number) => void) {
    return apiClient.post<ApiResponse<{ media: JobCardMedia; intake: IntakeInfo }>>(`${jc(jobCardId)}/intake/media`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 300000,
      onUploadProgress: (e) => onProgress?.(e.total ? Math.round((e.loaded / e.total) * 100) : 0),
    })
  },

  remove(jobCardId: number, mediaId: number) {
    return apiClient.delete<ApiResponse<IntakeInfo>>(`${jc(jobCardId)}/intake/media/${mediaId}`)
  },

  requirements() {
    return apiClient.get<ApiResponse<{ min_photos: number; min_videos: number }>>('/workshop/intake-requirements')
  },

  setRequirements(data: { min_photos: number; min_videos: number }) {
    return apiClient.put<ApiResponse<{ min_photos: number; min_videos: number }>>('/workshop/intake-requirements', data)
  },
}

// In-app staff notifications (header bell)
export const notificationsApi = {
  list() {
    return apiClient.get<ApiResponse<{ unread: number; items: StaffNotification[] }>>('/notifications')
  },

  markRead(id: string) {
    return apiClient.post<ApiResponse<null>>(`/notifications/${id}/read`)
  },

  markAllRead() {
    return apiClient.post<ApiResponse<null>>('/notifications/read-all')
  },
}

/**
 * The customer's page has no login: its own client, without the staff token or
 * the 401/403 redirects of apiClient.
 */
const publicClient = axios.create({
  baseURL: '/api/v1/public',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  timeout: 30000,
})

export const publicJobApi = {
  get(token: string) {
    return publicClient.get<ApiResponse<PublicJobPage>>(`/j/${token}`)
  },

  decide(token: string, decisions: Decision[], comment?: string) {
    return publicClient.post<ApiResponse<PublicJobPage>>(`/j/${token}/decisions`, { decisions, comment })
  },

  comment(token: string, body: string) {
    return publicClient.post<ApiResponse<PublicJobPage>>(`/j/${token}/comments`, { body })
  },

  estimatePdfUrl(token: string) {
    return `/api/v1/public/j/${token}/estimate.pdf`
  },

  reportPdfUrl(token: string) {
    return `/api/v1/public/j/${token}/report.pdf`
  },
}

/**
 * Open a PDF fetched as a blob in a new tab.
 */
export function openPdfBlob(blob: Blob) {
  const url = URL.createObjectURL(blob)
  window.open(url, '_blank')
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}
