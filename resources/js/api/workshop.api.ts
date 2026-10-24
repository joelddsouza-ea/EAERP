import apiClient from './client'
import type {
  ApiResponse,
  PaginatedResponse,
  Customer,
  Vehicle,
  JobCard,
  JobCardFilters,
  Bay,
  DashboardStats,
  FormPayload,
  JobCardOptions,
  JobCardAssignments,
  JobCardStatus,
  Payment,
  User,
  VehicleMake,
  VehicleModel,
  VehicleCategory,
  StatusTransitions,
} from './types'

// Customers
export const customersApi = {
  list(params?: { search?: string; page?: number; per_page?: number }) {
    return apiClient.get<PaginatedResponse<Customer>>('/workshop/customers', { params })
  },

  get(id: number) {
    return apiClient.get<ApiResponse<Customer>>(`/workshop/customers/${id}`)
  },

  create(data: FormPayload<Customer>) {
    return apiClient.post<ApiResponse<Customer>>('/workshop/customers', data)
  },

  update(id: number, data: FormPayload<Customer>) {
    return apiClient.put<ApiResponse<Customer>>(`/workshop/customers/${id}`, data)
  },

  delete(id: number) {
    return apiClient.delete(`/workshop/customers/${id}`)
  },

  vehicles(id: number) {
    return apiClient.get<ApiResponse<Vehicle[]>>(`/workshop/customers/${id}/vehicles`)
  },

  // Backend has no free-text search endpoint; the index quick-searches name/phone
  search(query: string) {
    return apiClient.get<PaginatedResponse<Customer>>('/workshop/customers', {
      params: { search: query, per_page: 10 },
    })
  },
}

// Vehicles
export const vehiclesApi = {
  list(params?: { customer_id?: number; search?: string; page?: number; per_page?: number }) {
    return apiClient.get<PaginatedResponse<Vehicle>>('/workshop/vehicles', { params })
  },

  get(id: number) {
    return apiClient.get<ApiResponse<Vehicle>>(`/workshop/vehicles/${id}`)
  },

  create(data: FormPayload<Vehicle>) {
    return apiClient.post<ApiResponse<Vehicle>>('/workshop/vehicles', data)
  },

  update(id: number, data: FormPayload<Vehicle>) {
    return apiClient.put<ApiResponse<Vehicle>>(`/workshop/vehicles/${id}`, data)
  },

  delete(id: number) {
    return apiClient.delete(`/workshop/vehicles/${id}`)
  },

  search(query: string) {
    return apiClient.get<PaginatedResponse<Vehicle>>('/workshop/vehicles', {
      params: { search: query, per_page: 10 },
    })
  },
}

// Master data
export const masterDataApi = {
  vehicleMakes() {
    return apiClient.get<ApiResponse<VehicleMake[]>>('/workshop/vehicle-makes')
  },

  vehicleModels(makeId: number) {
    return apiClient.get<ApiResponse<VehicleModel[]>>(`/workshop/vehicle-makes/${makeId}/models`)
  },

  vehicleCategories() {
    return apiClient.get<ApiResponse<VehicleCategory[]>>('/workshop/vehicle-categories')
  },

  /** Active service plans */
  servicePackages() {
    return apiClient.get<ApiResponse<{ id: number; name: string; slug: string }[]>>('/workshop/service-packages')
  },
}

// Job Cards
export const jobCardsApi = {
  list(params?: JobCardFilters) {
    return apiClient.get<PaginatedResponse<JobCard>>('/workshop/job-cards', { params })
  },

  get(id: number) {
    return apiClient.get<ApiResponse<JobCard>>(`/workshop/job-cards/${id}`)
  },

  /** trip: pickup / home-visit booking for a pickup & drop or home-service job */
  create(data: FormPayload<JobCard> & { complaints?: string[]; trip?: Record<string, unknown>; booking_id?: number | null }) {
    return apiClient.post<ApiResponse<JobCard>>('/workshop/job-cards', data)
  },

  update(id: number, data: FormPayload<JobCard>) {
    return apiClient.put<ApiResponse<JobCard>>(`/workshop/job-cards/${id}`, data)
  },

  /** Technicians (lead + others) and bay, with the candidates to choose from */
  assignments(id: number) {
    return apiClient.get<ApiResponse<JobCardAssignments>>(`/workshop/job-cards/${id}/assignments`)
  },
  addTechnician(id: number, userId: number, isLead = false) {
    return apiClient.post<ApiResponse<JobCardAssignments>>(`/workshop/job-cards/${id}/technicians`, { user_id: userId, is_lead: isLead })
  },
  removeTechnician(id: number, userId: number) {
    return apiClient.delete<ApiResponse<JobCardAssignments>>(`/workshop/job-cards/${id}/technicians/${userId}`)
  },
  moveBay(id: number, bayId: number, notes?: string | null) {
    return apiClient.post<ApiResponse<JobCardAssignments>>(`/workshop/job-cards/${id}/bay`, { bay_id: bayId, notes })
  },

  /** Plans (with oil grades and prices for this car), repair types and the job sheets each brings */
  formOptions(vehicleId: number | null, jobCardId?: number | null) {
    return apiClient.get<ApiResponse<JobCardOptions>>('/workshop/job-card-options', {
      params: { vehicle_id: vehicleId ?? undefined, job_card_id: jobCardId ?? undefined },
    })
  },

  delete(id: number) {
    return apiClient.delete(`/workshop/job-cards/${id}`)
  },

  updateStatus(id: number, status: string, notes?: string, override = false) {
    return apiClient.patch<ApiResponse<JobCard>>(`/workshop/job-cards/${id}/status`, {
      status,
      notes: notes || undefined,
      override: override || undefined,
    })
  },

  // Next statuses from the current one, with any checks blocking them
  transitions(id: number) {
    return apiClient.get<ApiResponse<StatusTransitions>>(`/workshop/job-cards/${id}/transitions`)
  },

  assignTechnician(id: number, technicianId: number) {
    return apiClient.post<ApiResponse<JobCard>>(`/workshop/job-cards/${id}/assign-technician`, {
      technician_id: technicianId,
    })
  },

  assignBay(id: number, bayId: number) {
    return apiClient.post<ApiResponse<JobCard>>(`/workshop/job-cards/${id}/assign-bay`, {
      bay_id: bayId,
    })
  },

  addItem(id: number, item: Partial<JobCard['items'][0]>) {
    return apiClient.post<ApiResponse<JobCard>>(`/workshop/job-cards/${id}/items`, item)
  },

  updateItem(id: number, itemId: number, item: Partial<JobCard['items'][0]>) {
    return apiClient.put<ApiResponse<JobCard>>(`/workshop/job-cards/${id}/items/${itemId}`, item)
  },

  removeItem(id: number, itemId: number) {
    return apiClient.delete(`/workshop/job-cards/${id}/items/${itemId}`)
  },

  updateChecklistItem(
    id: number,
    checklistId: number,
    itemId: number,
    data: { is_completed?: boolean; value?: string; remarks?: string }
  ) {
    return apiClient.patch(`/workshop/job-cards/${id}/checklists/${checklistId}/items/${itemId}`, data)
  },

  // No dedicated my-jobs endpoint; filter the index by the logged-in technician
  myJobs(technicianId: number, params?: { status?: string; page?: number; per_page?: number }) {
    return apiClient.get<PaginatedResponse<JobCard>>('/workshop/job-cards', {
      params: { ...params, technician_id: technicianId },
    })
  },
}

// Bays
export const baysApi = {
  list(params?: { branch_id?: number; type?: string; is_active?: boolean }) {
    return apiClient.get<ApiResponse<Bay[]>>('/workshop/bays', { params })
  },

  get(id: number) {
    return apiClient.get<ApiResponse<Bay>>(`/workshop/bays/${id}`)
  },

  create(data: FormPayload<Bay>) {
    return apiClient.post<ApiResponse<Bay>>('/workshop/bays', data)
  },

  update(id: number, data: FormPayload<Bay>) {
    return apiClient.put<ApiResponse<Bay>>(`/workshop/bays/${id}`, data)
  },

  delete(id: number) {
    return apiClient.delete(`/workshop/bays/${id}`)
  },

  // No available-bays endpoint; list active bays and drop occupied ones
  async available(branchId?: number) {
    const response = await apiClient.get<ApiResponse<Bay[]>>('/workshop/bays', {
      params: { branch_id: branchId, is_active: 1 },
    })
    response.data.data = response.data.data.filter((bay) => !bay.is_occupied)
    return response
  },
}

// Dashboard
interface WorkshopDashboardStats {
  jobs_in_progress: number
  by_status: Record<JobCardStatus, number>
  bills_to_make?: number
  bills_to_approve?: number
}

interface BillingDashboardStats {
  today: { collection: number }
  month_to_date: { total_billing: number }
  outstanding: { total: number }
}

export const dashboardApi = {
  // No single owner-dashboard endpoint exists; combine workshop and billing stats.
  // Only what the user's dashboard shows is fetched (Roles & Access › Dashboard), and one
  // call failing (no permission) doesn't blank the others.
  async stats(branchId: number | undefined, show: (block: string) => boolean) {
    const params = { branch_id: branchId }
    const need = {
      workshop: show('jobs_in_progress') || show('status_chart') || show('bill_queues'),
      billing: show('todays_collection') || show('billing_till_date') || show('total_outstanding'),
      jobs: show('recent_jobs'),
      payments: show('recent_payments'),
    }
    const none = Promise.resolve(null)
    const [workshop, billing, jobCards, payments] = await Promise.allSettled([
      need.workshop ? apiClient.get<ApiResponse<WorkshopDashboardStats>>('/workshop/dashboard/stats', { params }) : none,
      need.billing ? apiClient.get<ApiResponse<BillingDashboardStats>>('/billing/dashboard', { params }) : none,
      need.jobs ? apiClient.get<PaginatedResponse<JobCard>>('/workshop/job-cards', { params: { ...params, per_page: 5 } }) : none,
      need.payments ? apiClient.get<PaginatedResponse<Payment>>('/billing/payments', { params: { ...params, per_page: 5 } }) : none,
    ])
    const ok = <T,>(r: PromiseSettledResult<T | null>): T | null => (r.status === 'fulfilled' ? r.value : null)
    const w = ok(workshop)?.data.data
    const b = ok(billing)?.data.data

    const stats: DashboardStats = {
      jobs_in_progress: w?.jobs_in_progress ?? 0,
      daily_collection: Number(b?.today.collection ?? 0),
      billing_till_date: Number(b?.month_to_date.total_billing ?? 0),
      total_outstanding: Number(b?.outstanding.total ?? 0),
      jobs_by_status: w?.by_status ?? ({} as DashboardStats['jobs_by_status']),
      bills_to_make: w?.bills_to_make,
      bills_to_approve: w?.bills_to_approve,
      recent_job_cards: ok(jobCards)?.data.data ?? [],
      recent_payments: ok(payments)?.data.data ?? [],
    }
    return stats
  },

  dailyCollection(date?: string, branchId?: number) {
    return apiClient.get<ApiResponse<{ total: number; payments: any[] }>>('/billing/reports/daily-collection', {
      params: { date, branch_id: branchId },
    })
  },
}

// Technicians: users holding a technician role (workshop endpoint, so advisors
// don't need the user-admin permission)
export const techniciansApi = {
  list(branchId?: number) {
    return apiClient.get<ApiResponse<User[]>>('/workshop/technicians', { params: { branch_id: branchId } })
  },

  available(branchId?: number) {
    return techniciansApi.list(branchId)
  },
}
