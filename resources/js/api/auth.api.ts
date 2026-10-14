import apiClient from './client'
import type { ApiResponse, LoginRequest, LoginResponse, User } from './types'

export const authApi = {
  login(credentials: LoginRequest) {
    return apiClient.post<ApiResponse<LoginResponse>>('/auth/login', credentials)
  },

  logout() {
    return apiClient.post('/auth/logout')
  },

  me() {
    return apiClient.get<ApiResponse<User>>('/auth/me')
  },

  changePassword(data: { current_password: string; password: string; password_confirmation: string }) {
    return apiClient.post('/auth/change-password', data)
  },
}
