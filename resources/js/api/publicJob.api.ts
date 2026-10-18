import axios from 'axios'
import type { ApiResponse, PublicJobPage } from './types'
import type { Decision } from './estimates.api'

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

  /** Cheap check the page polls; a new value means something changed */
  version(token: string) {
    return publicClient.get<ApiResponse<{ version: string }>>(`/j/${token}/version`)
  },

  estimatePdfUrl(token: string) {
    return `/api/v1/public/j/${token}/estimate.pdf`
  },

  reportPdfUrl(token: string) {
    return `/api/v1/public/j/${token}/report.pdf`
  },

  billPdfUrl(token: string) {
    return `/api/v1/public/j/${token}/bill.pdf`
  },
}
