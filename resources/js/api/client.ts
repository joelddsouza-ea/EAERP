import axios, { AxiosError, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios'
import { useAuthStore } from '@/stores/auth.store'
import { useBranchStore } from '@/stores/branch.store'
import router from '@/router'

const apiClient: AxiosInstance = axios.create({
  baseURL: '/api/v1',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  timeout: 30000,
})

// Request interceptor
apiClient.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    const authStore = useAuthStore()
    const branchStore = useBranchStore()

    // Add auth token
    if (authStore.token) {
      config.headers.Authorization = `Bearer ${authStore.token}`
    }

    // Add branch context
    if (branchStore.currentBranchId) {
      config.headers['X-Branch-Id'] = branchStore.currentBranchId.toString()
    }

    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor
apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response) {
      const status = error.response.status

      switch (status) {
        case 401:
          // Unauthorized - clear auth and redirect to login
          const authStore = useAuthStore()
          authStore.logout()
          router.push({ name: 'login' })
          break

        case 403:
          // Forbidden - redirect to forbidden page
          router.push({ name: 'forbidden' })
          break

        case 404:
          // Not found
          break

        case 422:
          // Validation error - let the caller handle it
          break

        case 500:
          // Server error
          console.error('Server error:', error.response.data)
          break
      }
    } else if (error.request) {
      // Network error
      console.error('Network error:', error.message)
    }

    return Promise.reject(error)
  }
)

export default apiClient
