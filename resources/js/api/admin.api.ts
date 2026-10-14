import apiClient from './client'
import type { ApiResponse, PaginatedResponse, AdminUser, Role, Permission, Branch } from './types'

// Roles are sent as slugs and branches as ids
interface UserPayload {
  name: string
  email: string
  phone: string | null
  roles: string[]
  branches: number[]
  primary_branch_id: number
  is_active: boolean
}

// Users
export const usersApi = {
  list(params?: { role?: string; branch_id?: number; is_active?: boolean; search?: string; page?: number; per_page?: number }) {
    return apiClient.get<PaginatedResponse<AdminUser>>('/user-admin/users', { params })
  },

  get(id: number) {
    return apiClient.get<ApiResponse<AdminUser>>(`/user-admin/users/${id}`)
  },

  create(data: UserPayload & { password: string; password_confirmation: string }) {
    return apiClient.post<ApiResponse<AdminUser>>('/user-admin/users', data)
  },

  update(id: number, data: Partial<UserPayload>) {
    return apiClient.put<ApiResponse<AdminUser>>(`/user-admin/users/${id}`, data)
  },

  delete(id: number) {
    return apiClient.delete(`/user-admin/users/${id}`)
  },

  activate(id: number) {
    return apiClient.post<ApiResponse<AdminUser>>(`/user-admin/users/${id}/activate`)
  },

  deactivate(id: number) {
    return apiClient.post<ApiResponse<AdminUser>>(`/user-admin/users/${id}/deactivate`)
  },

  resetPassword(id: number, password: string, passwordConfirmation: string) {
    return apiClient.post(`/user-admin/users/${id}/reset-password`, {
      password,
      password_confirmation: passwordConfirmation,
    })
  },
}

// Roles
export const rolesApi = {
  list() {
    return apiClient.get<ApiResponse<Role[]>>('/user-admin/roles')
  },

  get(id: number) {
    return apiClient.get<ApiResponse<Role>>(`/user-admin/roles/${id}`)
  },

  create(data: { name: string; slug?: string; description?: string; permissions?: string[] }) {
    return apiClient.post<ApiResponse<Role>>('/user-admin/roles', data)
  },

  update(id: number, data: { name?: string; slug?: string; description?: string; permissions?: string[] }) {
    return apiClient.put<ApiResponse<Role>>(`/user-admin/roles/${id}`, data)
  },

  delete(id: number) {
    return apiClient.delete(`/user-admin/roles/${id}`)
  },

  permissions(id: number) {
    return apiClient.get<ApiResponse<{ role: Pick<Role, 'id' | 'name' | 'slug'>; permissions: string[] }>>(
      `/user-admin/roles/${id}/permissions`
    )
  },
}

// Permissions
export const permissionsApi = {
  list() {
    return apiClient.get<ApiResponse<Permission[]>>('/user-admin/permissions')
  },

  byModule() {
    return apiClient.get<ApiResponse<Record<string, Permission[]>>>('/user-admin/permissions')
  },
}

// Branches
export const branchesApi = {
  list(params?: { is_active?: boolean }) {
    return apiClient.get<ApiResponse<Branch[]>>('/user-admin/branches', { params })
  },

  get(id: number) {
    return apiClient.get<ApiResponse<Branch>>(`/user-admin/branches/${id}`)
  },

  create(data: Partial<Branch>) {
    return apiClient.post<ApiResponse<Branch>>('/user-admin/branches', data)
  },

  update(id: number, data: Partial<Branch>) {
    return apiClient.put<ApiResponse<Branch>>(`/user-admin/branches/${id}`, data)
  },

  delete(id: number) {
    return apiClient.delete(`/user-admin/branches/${id}`)
  },

  activate(id: number) {
    return apiClient.post<ApiResponse<Branch>>(`/user-admin/branches/${id}/activate`)
  },

  deactivate(id: number) {
    return apiClient.post<ApiResponse<Branch>>(`/user-admin/branches/${id}/deactivate`)
  },
}
