import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'

// Lazy load views
const Login = () => import('@/views/auth/Login.vue')
const ChangePassword = () => import('@/views/auth/ChangePassword.vue')
const AppLayout = () => import('@/components/layout/AppLayout.vue')
const Dashboard = () => import('@/views/dashboard/Dashboard.vue')

// Workshop views
const CustomerList = () => import('@/views/workshop/CustomerList.vue')
const CustomerForm = () => import('@/views/workshop/CustomerForm.vue')
const CustomerDetail = () => import('@/views/workshop/CustomerDetail.vue')
const VehicleList = () => import('@/views/workshop/VehicleList.vue')
const VehicleForm = () => import('@/views/workshop/VehicleForm.vue')
const JobCardList = () => import('@/views/workshop/JobCardList.vue')
const JobCardForm = () => import('@/views/workshop/JobCardForm.vue')
const JobCardDetail = () => import('@/views/workshop/JobCardDetail.vue')
const JobSheet = () => import('@/views/workshop/JobSheet.vue')
const JobSheetMasters = () => import('@/views/workshop/masters/JobSheetMasters.vue')
const PricingMasters = () => import('@/views/workshop/masters/PricingMasters.vue')
const PartsDesk = () => import('@/views/workshop/PartsDesk.vue')
const EstimateEditor = () => import('@/views/workshop/EstimateEditor.vue')
const CustomerJob = () => import('@/views/public/CustomerJob.vue')
const BayList = () => import('@/views/workshop/BayList.vue')
const MyJobs = () => import('@/views/workshop/MyJobs.vue')

// Billing views
const InvoiceList = () => import('@/views/billing/InvoiceList.vue')
const InvoiceDetail = () => import('@/views/billing/InvoiceDetail.vue')
const InvoiceForm = () => import('@/views/billing/InvoiceForm.vue')
const PaymentList = () => import('@/views/billing/PaymentList.vue')
const PaymentForm = () => import('@/views/billing/PaymentForm.vue')
const Reports = () => import('@/views/billing/Reports.vue')

// Admin views
const UserList = () => import('@/views/admin/UserList.vue')
const UserForm = () => import('@/views/admin/UserForm.vue')
const RoleList = () => import('@/views/admin/RoleList.vue')
const BranchList = () => import('@/views/admin/BranchList.vue')
const BranchForm = () => import('@/views/admin/BranchForm.vue')

// Error views
const NotFound = () => import('@/views/errors/NotFound.vue')
const Forbidden = () => import('@/views/errors/Forbidden.vue')

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    permission?: string
    permissions?: string[]
    roles?: string[]
    title?: string
    /** Phones: hide the bottom tab bar (the screen has its own action bar) */
    hideTabBar?: boolean
  }
}

const routes: RouteRecordRaw[] = [
  // Auth routes (no layout)
  {
    path: '/login',
    name: 'login',
    component: Login,
    meta: { title: 'Login' },
  },
  {
    path: '/change-password',
    name: 'change-password',
    component: ChangePassword,
    meta: { requiresAuth: true, title: 'Change Password' },
  },

  // App routes (with layout)
  {
    path: '/',
    component: AppLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'dashboard',
        component: Dashboard,
        meta: { title: 'Dashboard' },
      },

      // Workshop routes
      {
        path: 'customers',
        name: 'customers',
        component: CustomerList,
        meta: { title: 'Customers', permission: 'workshop.customers.view' },
      },
      {
        path: 'customers/create',
        name: 'customer-create',
        component: CustomerForm,
        meta: { title: 'New Customer', permission: 'workshop.customers.create' },
      },
      {
        path: 'customers/:id',
        name: 'customer-detail',
        component: CustomerDetail,
        meta: { title: 'Customer Details', permission: 'workshop.customers.view' },
      },
      {
        path: 'customers/:id/edit',
        name: 'customer-edit',
        component: CustomerForm,
        meta: { title: 'Edit Customer', permission: 'workshop.customers.update' },
      },

      {
        path: 'vehicles',
        name: 'vehicles',
        component: VehicleList,
        meta: { title: 'Vehicles', permission: 'workshop.vehicles.view' },
      },
      {
        path: 'vehicles/create',
        name: 'vehicle-create',
        component: VehicleForm,
        meta: { title: 'New Vehicle', permission: 'workshop.vehicles.create' },
      },
      {
        path: 'vehicles/:id/edit',
        name: 'vehicle-edit',
        component: VehicleForm,
        meta: { title: 'Edit Vehicle', permission: 'workshop.vehicles.update' },
      },

      {
        path: 'job-cards',
        name: 'job-cards',
        component: JobCardList,
        meta: { title: 'Job Cards', permission: 'workshop.job-cards.view' },
      },
      {
        path: 'job-cards/create',
        name: 'job-card-create',
        component: JobCardForm,
        meta: { title: 'New Job Card', permission: 'workshop.job-cards.create' },
      },
      {
        path: 'job-cards/:id',
        name: 'job-card-detail',
        component: JobCardDetail,
        meta: { title: 'Job Card Details', permission: 'workshop.job-cards.view' },
      },
      {
        path: 'job-cards/:id/sheet',
        name: 'job-card-sheet',
        component: JobSheet,
        meta: { title: 'Job Sheet', permission: 'workshop.job-sheets.view', hideTabBar: true },
      },
      {
        path: 'job-cards/:id/estimate',
        name: 'job-card-estimate',
        component: EstimateEditor,
        meta: { title: 'Estimate', permission: 'workshop.estimates.view' },
      },
      {
        path: 'job-cards/:id/edit',
        name: 'job-card-edit',
        component: JobCardForm,
        meta: { title: 'Edit Job Card', permission: 'workshop.job-cards.update' },
      },

      {
        path: 'job-sheet-masters',
        name: 'job-sheet-masters',
        component: JobSheetMasters,
        meta: { title: 'Job Sheet Masters', permission: 'workshop.job-sheet-masters.view' },
      },

      {
        path: 'pricing-masters',
        name: 'pricing-masters',
        component: PricingMasters,
        meta: { title: 'Price Masters', permission: 'workshop.pricing-masters.view' },
      },

      {
        path: 'parts-desk',
        name: 'parts-desk',
        component: PartsDesk,
        meta: { title: 'Parts Desk', permission: 'workshop.spare-parts.view' },
      },

      {
        path: 'bays',
        name: 'bays',
        component: BayList,
        meta: { title: 'Bays', permission: 'workshop.bays.view' },
      },

      {
        path: 'my-jobs',
        name: 'my-jobs',
        component: MyJobs,
        meta: { title: 'My Jobs', roles: ['technician', 'body-shop-technician'] },
      },

      // Billing routes
      {
        path: 'invoices',
        name: 'invoices',
        component: InvoiceList,
        meta: { title: 'Invoices', permission: 'billing.invoices.view' },
      },
      {
        path: 'invoices/create',
        name: 'invoice-create',
        component: InvoiceForm,
        meta: { title: 'New Invoice', permission: 'billing.invoices.create' },
      },
      {
        path: 'invoices/:id',
        name: 'invoice-detail',
        component: InvoiceDetail,
        meta: { title: 'Invoice Details', permission: 'billing.invoices.view' },
      },

      {
        path: 'payments',
        name: 'payments',
        component: PaymentList,
        meta: { title: 'Payments', permission: 'billing.payments.view' },
      },
      {
        path: 'payments/create',
        name: 'payment-create',
        component: PaymentForm,
        meta: { title: 'Record Payment', permission: 'billing.payments.create' },
      },

      {
        path: 'reports',
        name: 'reports',
        component: Reports,
        meta: { title: 'Reports', permission: 'billing.reports.view' },
      },

      // Admin routes
      {
        path: 'users',
        name: 'users',
        component: UserList,
        meta: { title: 'Users', permission: 'user-admin.users.view' },
      },
      {
        path: 'users/create',
        name: 'user-create',
        component: UserForm,
        meta: { title: 'New User', permission: 'user-admin.users.create' },
      },
      {
        path: 'users/:id/edit',
        name: 'user-edit',
        component: UserForm,
        meta: { title: 'Edit User', permission: 'user-admin.users.update' },
      },

      {
        path: 'roles',
        name: 'roles',
        component: RoleList,
        meta: { title: 'Roles', permission: 'user-admin.roles.view' },
      },

      {
        path: 'branches',
        name: 'branches',
        component: BranchList,
        meta: { title: 'Branches', permission: 'user-admin.branches.view' },
      },
      {
        path: 'branches/create',
        name: 'branch-create',
        component: BranchForm,
        meta: { title: 'New Branch', permission: 'user-admin.branches.create' },
      },
      {
        path: 'branches/:id/edit',
        name: 'branch-edit',
        component: BranchForm,
        meta: { title: 'Edit Branch', permission: 'user-admin.branches.update' },
      },
    ],
  },

  // Customer's login-free job page (token in the URL is the credential)
  {
    path: '/j/:token',
    name: 'customer-job',
    component: CustomerJob,
    meta: { title: 'Your car' },
  },

  // Error routes
  {
    path: '/403',
    name: 'forbidden',
    component: Forbidden,
    meta: { title: 'Access Denied' },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: NotFound,
    meta: { title: 'Page Not Found' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior(_to, _from, savedPosition) {
    if (savedPosition) {
      return savedPosition
    }
    return { top: 0 }
  },
})

// Navigation guards
router.beforeEach(async (to, _from, next) => {
  const authStore = useAuthStore()

  // Update document title
  document.title = to.meta.title ? `${to.meta.title} | Express Autocare` : 'Express Autocare'

  // Initialize auth if needed
  if (authStore.token && !authStore.user) {
    await authStore.initialize()
  }

  // Check if route requires authentication
  if (to.meta.requiresAuth !== false && to.matched.some((r) => r.meta.requiresAuth)) {
    if (!authStore.isAuthenticated) {
      return next({ name: 'login', query: { redirect: to.fullPath } })
    }
  }

  // Redirect authenticated users away from login
  if (to.name === 'login' && authStore.isAuthenticated) {
    return next({ name: 'dashboard' })
  }

  // Check role-based access
  if (to.meta.roles && to.meta.roles.length > 0) {
    if (!authStore.hasAnyRole(to.meta.roles)) {
      return next({ name: 'forbidden' })
    }
  }

  // Check permission-based access
  if (to.meta.permission) {
    if (!authStore.hasPermission(to.meta.permission)) {
      return next({ name: 'forbidden' })
    }
  }

  if (to.meta.permissions && to.meta.permissions.length > 0) {
    if (!authStore.hasAnyPermission(to.meta.permissions)) {
      return next({ name: 'forbidden' })
    }
  }

  next()
})

export default router
