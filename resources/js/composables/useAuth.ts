import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { useBranchStore } from '@/stores/branch.store'
import type { LoginRequest } from '@/api/types'

export function useAuth() {
  const router = useRouter()
  const authStore = useAuthStore()
  const branchStore = useBranchStore()

  const isAuthenticated = computed(() => authStore.isAuthenticated)
  const user = computed(() => authStore.user)
  const role = computed(() => authStore.roleName)
  const loading = computed(() => authStore.loading)
  const error = computed(() => authStore.error)

  async function login(credentials: LoginRequest, redirectTo?: string) {
    await authStore.login(credentials)

    // Initialize branch after login
    branchStore.initializeBranch()

    // Redirect to intended page or role-based dashboard
    const redirect = redirectTo || getDashboardRoute()
    router.push(redirect)
  }

  async function logout() {
    await authStore.logout()
    branchStore.clearBranch()
    router.push({ name: 'login' })
  }

  function getDashboardRoute(): string {
    const roleName = authStore.roleName

    switch (roleName) {
      case 'technician':
      case 'body-shop-technician':
        return '/my-jobs'
      default:
        return '/'
    }
  }

  return {
    isAuthenticated,
    user,
    role,
    loading,
    error,
    login,
    logout,
    getDashboardRoute,
  }
}
