/**
 * Purchases module (optional): GRNs, the technician's part check, consumables stock,
 * plan kits, suppliers' dues and the module switch.
 */
import apiClient from './client'
import type { ApiResponse } from './types'

export type GstMode = 'incl' | 'excl' | 'cash'
export type BillType = 'gst_invoice' | 'challan' | 'no_bill'
export type GrnLineKind = 'job_part' | 'stock' | 'third_party'

export interface GrnPhoto {
  id: number
  line_id: number | null
  url: string
  thumbnail_url: string
  is_pdf: boolean
}

export interface ReceivablePart {
  id: number
  job_card_id: number
  job_number: string | null
  car: string | null
  customer: string | null
  part_name: string
  part_number: string | null
  quantity: number
  unit: string | null
  status: string
  supplier_id: number | null
  supplier: string | null
  unit_cost: number | null
  gst_mode: GstMode | null
  gst_rate: number | null
}

export interface Receivables {
  parts: ReceivablePart[]
  jobs: { id: number; job_number: string; car: string | null }[]
  third_party_lines: { job_card_id: number; estimate_line_key: string; description: string }[]
  suppliers: { id: number; name: string; phone: string | null; gstin: string | null }[]
  min_part_photos: number
}

export interface GrnLineInput {
  kind: GrnLineKind
  spare_part_request_id?: number | null
  stock_item_id?: number | null
  job_card_id?: number | null
  estimate_line_key?: string | null
  description?: string | null
  part_number?: string | null
  brand?: string | null
  quantity: number
  unit?: string | null
  mrp?: number | null
  unit_cost: number
  gst_mode: GstMode
  gst_rate?: number | null
}

export interface GrnInput {
  supplier_id?: number | null
  supplier_name?: string | null
  supplier_phone?: string | null
  supplier_bill_no?: string | null
  bill_date: string
  bill_type: BillType
  payment_mode: 'cash' | 'credit'
  notes?: string | null
  lines: GrnLineInput[]
}

export interface GrnSummary {
  id: number
  grn_number: string
  supplier: string | null
  supplier_gstin: string | null
  supplier_bill_no: string | null
  bill_date: string
  bill_type: BillType
  payment_mode: 'cash' | 'credit'
  taxable_amount: number
  gst_amount: number
  total_amount: number
  status: 'posted' | 'cancelled'
  lines_count: number | null
  received_by: string | null
}

export interface GrnLine {
  id: number
  kind: GrnLineKind
  job_card_id: number | null
  job_number: string | null
  registration_no: string | null
  stock_item: string | null
  description: string
  part_number: string | null
  brand: string | null
  quantity: number
  unit: string
  mrp: number | null
  unit_cost: number
  gst_mode: GstMode
  gst_rate: number
  taxable_amount: number
  gst_amount: number
  total: number
  verification: 'pending' | 'verified' | 'wrong_part' | null
  verification_note: string | null
  verified_by: string | null
  returned: boolean
  return_reason: string | null
  photos: GrnPhoto[]
}

export interface Grn extends GrnSummary {
  notes: string | null
  cancel_reason: string | null
  cancelled_by: string | null
  cancelled_at: string | null
  bill_photos: GrnPhoto[]
  lines: GrnLine[]
}

export interface PurchaseRegister {
  from: string
  to: string
  grns: GrnSummary[]
  totals: { count: number; taxable: number; gst: number; total: number; with_gst_bill: number; without_gst_bill: number; credit: number }
}

export interface StockRow {
  id: number
  name: string
  category: string
  unit: string
  is_active: boolean
  quantity: number
  avg_cost: number
  value: number
  reorder_level: number
  low: boolean
}

export interface StockItemMaster {
  id: number
  name: string
  category: string
  unit: string
  oil_type_id: number | null
  oil_type?: { id: number; name: string } | null
  hsn_code: string | null
  gst_rate: string | number
  reorder_level: string | number
  is_active: boolean
}

export interface KitLine {
  id?: number
  package_id: number
  category_id: number | null
  stock_item_id: number
  item?: string
  unit?: string
  quantity: number
}

export interface JobStock {
  kit_issued_at: string | null
  /** What the automatic plan kit at final QC couldn't issue (short in stock) */
  kit_shortfall: { stock_item_id: number; name: string; unit: string; quantity: number }[]
  suggested_kit: { stock_item_id: number; name: string; unit: string; quantity: number; in_stock: number }[]
  issued: { stock_item_id: number; name: string; unit: string; quantity: number; cost: number }[]
  items: { id: number; name: string; unit: string; category: string }[]
  cost: {
    parts: number
    third_party: number
    consumables: number
    total: number
    lines: { grn_number: string | null; kind: GrnLineKind; description: string; quantity: number; cost: number; returned: boolean; verification: string | null }[]
  } | null
}

export interface SupplierDue {
  id: number
  name: string
  contact_person: string | null
  phone: string | null
  gstin: string | null
  is_active: boolean
  purchases_on_credit: number
  paid: number
  due: number
}

export interface SupplierLedger {
  supplier: { id: number; name: string; contact_person: string | null; phone: string | null; gstin: string | null }
  balance: { purchases: number; paid: number; due: number }
  grns: (GrnSummary & { cost_after_returns: number })[]
  payments: { id: number; amount: number; method: string; reference_number: string | null; paid_on: string; notes: string | null; by: string | null }[]
}

const base = '/workshop/purchases'

export const purchasesApi = {
  settings() {
    return apiClient.get<ApiResponse<{ enabled: boolean; min_part_photos: number }>>(`${base}/settings`)
  },
  updateSettings(data: { enabled?: boolean; min_part_photos?: number }) {
    return apiClient.put<ApiResponse<{ enabled: boolean; min_part_photos: number }>>(`${base}/settings`, data)
  },

  receivables() {
    return apiClient.get<ApiResponse<Receivables>>(`${base}/receivables`)
  },
  /** Bill photos and photos per line index travel with the GRN in one request */
  post(grn: GrnInput, billPhotos: File[], linePhotos: Record<number, File[]>) {
    const form = new FormData()
    form.append('payload', JSON.stringify(grn))
    billPhotos.forEach((f) => form.append('bill_photos[]', f))
    Object.entries(linePhotos).forEach(([i, files]) => files.forEach((f) => form.append(`line_photos[${i}][]`, f)))
    return apiClient.post<ApiResponse<Grn>>(`${base}/grns`, form, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  list(params: { from?: string; to?: string; supplier_id?: number; status?: string } = {}) {
    return apiClient.get<ApiResponse<PurchaseRegister>>(`${base}/grns`, { params })
  },
  get(id: number) {
    return apiClient.get<ApiResponse<Grn>>(`${base}/grns/${id}`)
  },
  cancel(id: number, reason: string) {
    return apiClient.post<ApiResponse<Grn>>(`${base}/grns/${id}/cancel`, { reason })
  },
  returnLine(lineId: number, reason: string) {
    return apiClient.post<ApiResponse<Grn>>(`${base}/grn-lines/${lineId}/return`, { reason })
  },
  verify(jobCardId: number, lineId: number, right: boolean, note?: string | null) {
    return apiClient.post<ApiResponse<{ line_id: number; verification: string }>>(
      `/workshop/job-cards/${jobCardId}/grn-lines/${lineId}/verify`,
      { right, note }
    )
  },

  stock() {
    return apiClient.get<ApiResponse<{ items: StockRow[]; total_value: number; low_count: number }>>(`${base}/stock`)
  },
  movements(itemId: number) {
    return apiClient.get<ApiResponse<{ item: { id: number; name: string; unit: string }; movements: { id: number; type: string; quantity: number; unit_cost: number; value: number; job_number: string | null; note: string | null; by: string | null; at: string }[] }>>(
      `${base}/stock/${itemId}/movements`
    )
  },
  adjust(itemId: number, change: number, reason: string, unitCost?: number | null) {
    return apiClient.post<ApiResponse<{ items: StockRow[] }>>(`${base}/stock/${itemId}/adjust`, { change, reason, unit_cost: unitCost })
  },
  stockItems() {
    return apiClient.get<ApiResponse<{ items: StockItemMaster[]; categories: string[]; units: string[]; oil_types: { id: number; name: string }[] }>>(`${base}/stock-items`)
  },
  saveStockItem(item: Partial<StockItemMaster>) {
    return item.id
      ? apiClient.put<ApiResponse<StockItemMaster>>(`${base}/stock-items/${item.id}`, item)
      : apiClient.post<ApiResponse<StockItemMaster>>(`${base}/stock-items`, item)
  },
  kits() {
    return apiClient.get<ApiResponse<{ kits: KitLine[]; packages: { id: number; name: string }[]; categories: { id: number; name: string }[]; items: { id: number; name: string; unit: string }[] }>>(
      `${base}/plan-kits`
    )
  },
  saveKit(packageId: number, lines: { category_id: number | null; stock_item_id: number; quantity: number }[]) {
    return apiClient.put<ApiResponse<{ kits: KitLine[] }>>(`${base}/plan-kits/${packageId}`, { lines })
  },

  jobStock(jobCardId: number) {
    return apiClient.get<ApiResponse<JobStock>>(`/workshop/job-cards/${jobCardId}/stock`)
  },
  issue(jobCardId: number, lines: { stock_item_id: number; quantity: number }[], kit = false) {
    return apiClient.post<ApiResponse<JobStock>>(`/workshop/job-cards/${jobCardId}/stock/issue`, { lines, kit })
  },
  returnToStock(jobCardId: number, stockItemId: number, quantity: number, note?: string | null) {
    return apiClient.post<ApiResponse<JobStock>>(`/workshop/job-cards/${jobCardId}/stock/return`, { stock_item_id: stockItemId, quantity, note })
  },

  suppliers() {
    return apiClient.get<ApiResponse<{ suppliers: SupplierDue[]; total_due: number; methods: string[] }>>(`${base}/suppliers`)
  },
  supplier(id: number) {
    return apiClient.get<ApiResponse<SupplierLedger>>(`${base}/suppliers/${id}`)
  },
  paySupplier(id: number, data: { amount: number; method: string; reference_number?: string | null; paid_on?: string | null; notes?: string | null }) {
    return apiClient.post<ApiResponse<SupplierLedger>>(`${base}/suppliers/${id}/payments`, data)
  },
}

export const GST_MODE_LABELS: Record<GstMode, string> = {
  incl: 'GST included',
  excl: 'Plus GST',
  cash: 'No GST (cash)',
}

export const BILL_TYPE_LABELS: Record<BillType, string> = {
  gst_invoice: 'GST invoice',
  challan: 'Delivery challan',
  no_bill: 'No bill (cash purchase)',
}

export const money = (n: number | null | undefined, digits = 2) =>
  new Intl.NumberFormat('en-IN', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(n ?? 0))
