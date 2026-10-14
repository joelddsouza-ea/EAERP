import apiClient from './client'
import type { ApiResponse, PaginatedResponse, Invoice, InvoiceFilters, Payment, FormPayload } from './types'

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
