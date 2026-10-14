import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth.store'

export function usePermissions() {
  const authStore = useAuthStore()

  const permissions = computed(() => authStore.permissions)
  const role = computed(() => authStore.roleName)

  function can(permission: string): boolean {
    return authStore.hasPermission(permission)
  }

  function canAny(permissionList: string[]): boolean {
    return authStore.hasAnyPermission(permissionList)
  }

  function canAll(permissionList: string[]): boolean {
    return authStore.hasAllPermissions(permissionList)
  }

  function hasRole(roleName: string): boolean {
    return authStore.hasRole(roleName)
  }

  function hasAnyRole(roles: string[]): boolean {
    return authStore.hasAnyRole(roles)
  }

  // Common permission checks
  const canManageUsers = computed(() => can('user-admin.users.view') || can('user-admin.users.create'))
  const canManageBranches = computed(() => can('user-admin.branches.view') || can('user-admin.branches.create'))
  const canManageCustomers = computed(() => can('workshop.customers.view'))
  const canManageVehicles = computed(() => can('workshop.vehicles.view'))
  const canManageJobCards = computed(() => can('workshop.job-cards.view'))
  const canManageInvoices = computed(() => can('billing.invoices.view'))
  const canManagePayments = computed(() => can('billing.payments.view'))
  const canViewReports = computed(() => can('billing.reports.view'))

  // Role checks
  const isOwner = computed(() => hasRole('owner'))
  const isManager = computed(() => hasRole('manager'))
  const isServiceAdvisor = computed(() => hasRole('service-advisor'))
  const isTechnician = computed(() => hasRole('technician'))
  const isBackoffice = computed(() => hasRole('backoffice'))
  const isBodyshopManager = computed(() => hasRole('body-shop-manager'))
  const isBodyshopAdvisor = computed(() => hasRole('body-shop-advisor'))
  const isBodyshopTechnician = computed(() => hasRole('body-shop-technician'))

  return {
    permissions,
    role,
    can,
    canAny,
    canAll,
    hasRole,
    hasAnyRole,

    // Permission checks
    canManageUsers,
    canManageBranches,
    canManageCustomers,
    canManageVehicles,
    canManageJobCards,
    canManageInvoices,
    canManagePayments,
    canViewReports,

    // Role checks
    isOwner,
    isManager,
    isServiceAdvisor,
    isTechnician,
    isBackoffice,
    isBodyshopManager,
    isBodyshopAdvisor,
    isBodyshopTechnician,
  }
}
