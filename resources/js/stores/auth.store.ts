import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi } from '@/api/auth.api'
import type { User, Branch, LoginRequest } from '@/api/types'

const TOKEN_KEY = 'eaerp_token'
const USER_KEY = 'eaerp_user'

export const useAuthStore = defineStore('auth', () => {
  // State
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY))
  const user = ref<User | null>(JSON.parse(localStorage.getItem(USER_KEY) || 'null'))
  // Restore from the persisted user so guards work after a page reload
  const permissions = ref<string[]>(user.value?.permissions || [])
  const branches = ref<Branch[]>(user.value?.branches || [])
  const loading = ref(false)
  const error = ref<string | null>(null)

  // Getters
  const isAuthenticated = computed(() => !!token.value && !!user.value)
  const roleName = computed(() => user.value?.roles?.[0] || '')
  const roleDisplayName = computed(() =>
    roleName.value.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
  )
  const userName = computed(() => user.value?.name || '')
  const userEmail = computed(() => user.value?.email || '')
  /** Optional module switched on (e.g. 'purchases': GRN, stock, supplier dues) */
  const hasFeature = (name: 'purchases' | 'accounts' | 'crm' | 'hr' | 'customer_app') => !!user.value?.features?.[name]

  // Actions
  async function login(credentials: LoginRequest) {
    loading.value = true
    error.value = null

    try {
      const response = await authApi.login(credentials)
      const data = response.data.data

      token.value = data.token
      user.value = data.user
      permissions.value = data.user.permissions || []
      branches.value = data.user.branches || []

      // Persist to localStorage
      localStorage.setItem(TOKEN_KEY, data.token)
      localStorage.setItem(USER_KEY, JSON.stringify(data.user))

      return data
    } catch (err: any) {
      error.value = err.response?.data?.message || 'Login failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    try {
      if (token.value) {
        await authApi.logout()
      }
    } catch {
      // Ignore errors during logout
    } finally {
      clearAuth()
    }
  }

  function clearAuth() {
    // Location sharing ends with the session (loaded lazily: the tracker uses the API client)
    void import('@/composables/useLocationTracker').then((m) => m.reset())
    token.value = null
    user.value = null
    permissions.value = []
    branches.value = []
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USER_KEY)
  }

  async function fetchUser() {
    if (!token.value) return

    loading.value = true
    try {
      const response = await authApi.me()
      user.value = response.data.data
      permissions.value = user.value.permissions || []
      branches.value = user.value.branches || []
      localStorage.setItem(USER_KEY, JSON.stringify(user.value))
    } catch (err: any) {
      // Signed out only when the token is no longer valid, not on a flaky mobile connection
      if (!err?.response || err.response.status === 401) {
        if (err?.response?.status === 401 || !user.value) clearAuth()
      }
    } finally {
      loading.value = false
    }
  }

  function hasPermission(permission: string): boolean {
    if (!user.value) return false

    // Owner has all permissions
    if (hasRole('owner')) return true

    return permissions.value.includes(permission)
  }

  function hasAnyPermission(permissionList: string[]): boolean {
    return permissionList.some((p) => hasPermission(p))
  }

  function hasAllPermissions(permissionList: string[]): boolean {
    return permissionList.every((p) => hasPermission(p))
  }

  function hasRole(role: string): boolean {
    return user.value?.roles?.includes(role) ?? false
  }

  function hasAnyRole(roles: string[]): boolean {
    return roles.some((r) => hasRole(r))
  }

  function hasBranchAccess(branchId: number): boolean {
    if (!user.value) return false

    // Owner has access to all branches
    if (hasRole('owner')) return true

    return branches.value.some((b) => b.id === branchId)
  }

  // Initialize - fetch user if token exists
  async function initialize() {
    if (token.value && !user.value) {
      await fetchUser()
    } else if (token.value) {
      // Refresh in the background: new permissions and modules switched on show without signing in again
      fetchUser()
    }
  }

  return {
    // State
    token,
    user,
    permissions,
    branches,
    loading,
    error,

    // Getters
    isAuthenticated,
    roleName,
    roleDisplayName,
    userName,
    userEmail,

    // Actions
    login,
    logout,
    clearAuth,
    fetchUser,
    hasPermission,
    hasAnyPermission,
    hasAllPermissions,
    hasRole,
    hasAnyRole,
    hasBranchAccess,
    hasFeature,
    initialize,
  }
})
