import apiClient from './client'
import type { ApiResponse, PaginatedResponse, Invoice, InvoiceFilters, Payment, FormPayload, JobBillingSummary, WorkflowStageRow } from './types'

// Invoices
export const invoicesApi = {
  list(params?: InvoiceFilters) {
    return apiClient.get<PaginatedResponse<Invoice>>('/billing/invoices', { params })
  },

  get(id: number) {
    return apiClient.get<ApiResponse<Invoice>>(`/billing/invoices/${id}`)
  },

  create(data: FormPayload<Invoice>) {
    return apiClient.post<ApiResponse<Invoice>>('/billing/invoices', data)
  },

  createFromJobCard(jobCardId: number) {
    return apiClient.post<ApiResponse<Invoice>>(`/billing/invoices/from-job-card/${jobCardId}`)
  },

  update(id: number, data: FormPayload<Invoice>) {
    return apiClient.put<ApiResponse<Invoice>>(`/billing/invoices/${id}`, data)
  },

  delete(id: number) {
    return apiClient.delete(`/billing/invoices/${id}`)
  },

  issue(id: number) {
    return apiClient.post<ApiResponse<Invoice>>(`/billing/invoices/${id}/issue`)
  },

  cancel(id: number, reason: string) {
    return apiClient.post<ApiResponse<Invoice>>(`/billing/invoices/${id}/cancel`, { reason })
  },

  download(id: number) {
    return apiClient.get(`/billing/invoices/${id}/pdf`, { responseType: 'blob' })
  },
}

// Payments
export const paymentsApi = {
  list(params?: { invoice_id?: number; date_from?: string; date_to?: string; page?: number; per_page?: number }) {
    return apiClient.get<PaginatedResponse<Payment>>('/billing/payments', { params })
  },

  get(id: number) {
    return apiClient.get<ApiResponse<Payment>>(`/billing/payments/${id}`)
  },

  // Payments are recorded against an invoice; POST /billing/payments has no handler
  create(invoiceId: number, data: FormPayload<Payment>) {
    return apiClient.post<ApiResponse<{ payment: Payment; invoice: Invoice }>>(
      `/billing/invoices/${invoiceId}/payments`,
      data
    )
  },

  receipt(id: number) {
    return apiClient.get(`/billing/payments/${id}/receipt`, { responseType: 'blob' })
  },
}

// Reports
export const reportsApi = {
  dailyCollection(params: { date: string; branch_id?: number }) {
    return apiClient.get<ApiResponse<{
      date: string
      total: number
      by_method: Record<string, number>
      payments: Payment[]
    }>>('/billing/reports/daily-collection', { params })
  },

  outstanding(params?: { branch_id?: number; customer_id?: number }) {
    return apiClient.get<ApiResponse<{
      total: number
      by_customer: { customer: any; amount: number }[]
      invoices: Invoice[]
    }>>('/billing/reports/outstanding', { params })
  },

  invoiceRegister(params: { from_date: string; to_date: string; branch_id?: number; status?: string }) {
    return apiClient.get<ApiResponse<{
      summary: { total: number; paid: number; outstanding: number; count: number }
      invoices: Invoice[]
    }>>('/billing/reports/invoice-register', { params })
  },
}

// A job's Billing & Delivery: payments, proforma → tax invoice / close, gate pass, vehicle out
const jb = (jobCardId: number) => `/workshop/job-cards/${jobCardId}/billing`

export const jobBillingApi = {
  get(jobCardId: number) {
    return apiClient.get<ApiResponse<JobBillingSummary>>(jb(jobCardId))
  },

  /** One receipt: a single method (amount + method) or split over several (parts) */
  recordPayment(
    jobCardId: number,
    data: {
      amount?: number
      method?: string
      parts?: { method: string; amount: number; reference_number?: string | null; cheque_number?: string | null }[]
      reference_number?: string | null
      payment_date?: string
      notes?: string | null
      cheque_number?: string | null
    }
  ) {
    return apiClient.post<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/payments`, data)
  },

  /** Money paid beyond the bill: refund it or keep it as the customer's credit */
  settleExcess(
    jobCardId: number,
    data: { mode: 'refund' | 'credit'; amount?: number | null; method?: string | null; reference_number?: string | null; notes?: string | null }
  ) {
    return apiClient.post<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/settle-excess`, data)
  },

  createProforma(jobCardId: number) {
    return apiClient.post<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/proforma`)
  },

  refresh(jobCardId: number, billId: number) {
    return apiClient.post<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/bills/${billId}/refresh`)
  },

  discard(jobCardId: number, billId: number) {
    return apiClient.delete<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/bills/${billId}`)
  },

  addItem(
    jobCardId: number,
    billId: number,
    data: { description: string; type?: string; quantity: number; unit_price: number; gst_applicable: boolean; tax_rate?: number | null; hsn_code?: string | null }
  ) {
    return apiClient.post<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/bills/${billId}/items`, data)
  },

  setHsn(jobCardId: number, billId: number, itemId: number, hsn_code: string) {
    return apiClient.patch<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/bills/${billId}/items/${itemId}`, { hsn_code })
  },

  removeItem(jobCardId: number, billId: number, itemId: number) {
    return apiClient.delete<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/bills/${billId}/items/${itemId}`)
  },

  approve(jobCardId: number, billId: number) {
    return apiClient.post<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/bills/${billId}/approve`)
  },

  convert(jobCardId: number, billId: number, data: { customer_gstin?: string | null; is_igst?: boolean; exclude_item_ids?: number[] }) {
    return apiClient.post<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/bills/${billId}/convert`, data)
  },

  close(jobCardId: number, billId: number, note?: string | null) {
    return apiClient.post<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/bills/${billId}/close`, { note })
  },

  overrideGatePass(jobCardId: number, reason: string) {
    return apiClient.post<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/gate-pass/override`, { reason })
  },

  vehicleOut(jobCardId: number) {
    return apiClient.post<ApiResponse<JobBillingSummary>>(`${jb(jobCardId)}/gate-pass/vehicle-out`)
  },

  /** PDFs need the bearer token, so they are fetched as blobs */
  billPdf(jobCardId: number, billId: number) {
    return apiClient.get<Blob>(`${jb(jobCardId)}/bills/${billId}/pdf`, { responseType: 'blob' })
  },

  receiptPdf(jobCardId: number, paymentId: number) {
    return apiClient.get<Blob>(`${jb(jobCardId)}/payments/${paymentId}/receipt`, { responseType: 'blob' })
  },

  gatePassPdf(jobCardId: number) {
    return apiClient.get<Blob>(`${jb(jobCardId)}/gate-pass/pdf`, { responseType: 'blob' })
  },
}

// Job stages master (Job Sheet Masters › Job stages)
export const workflowStagesApi = {
  list() {
    return apiClient.get<ApiResponse<{ stages: WorkflowStageRow[]; placeholders: string[] }>>('/workshop/job-sheet-masters/workflow-stages')
  },

  update(id: number, data: Partial<Pick<WorkflowStageRow, 'waiting_for' | 'auto' | 'notify_customer' | 'message' | 'gallery_update'>>) {
    return apiClient.put<ApiResponse<WorkflowStageRow>>(`/workshop/job-sheet-masters/workflow-stages/${id}`, data)
  },
}
