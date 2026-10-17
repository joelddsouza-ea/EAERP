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
const MyTrips = () => import('@/views/workshop/MyTrips.vue')
const TripDetail = () => import('@/views/workshop/TripDetail.vue')
const TripBoard = () => import('@/views/workshop/TripBoard.vue')
const TripSettings = () => import('@/views/workshop/masters/TripSettings.vue')

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
    /** Only while this optional module is switched on */
    feature?: 'purchases' | 'accounts' | 'crm' | 'hr'
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
        // Drivers work from My Trips
        beforeEnter: () => {
          const auth = useAuthStore()
          return auth.hasPermission('workshop.trips.drive') && !auth.hasPermission('workshop.job-cards.view') ? { name: 'my-trips' } : true
        },
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

      // Purchases module (optional; feature-gated)
      {
        path: 'purchases/grns',
        name: 'grns',
        component: () => import('@/views/workshop/purchases/GoodsReceiptList.vue'),
        meta: { title: 'GRNs', permission: 'purchases.grn.view', feature: 'purchases' },
      },
      {
        path: 'purchases/grns/new',
        name: 'grn-new',
        component: () => import('@/views/workshop/purchases/GoodsReceiptForm.vue'),
        meta: { title: 'Receive Goods', permission: 'purchases.grn.create', feature: 'purchases' },
      },
      {
        path: 'purchases/grns/:id',
        name: 'grn-detail',
        component: () => import('@/views/workshop/purchases/GoodsReceiptDetail.vue'),
        meta: { title: 'GRN', permission: 'purchases.grn.view', feature: 'purchases' },
      },
      {
        path: 'stock',
        name: 'stock',
        component: () => import('@/views/workshop/purchases/StockList.vue'),
        meta: { title: 'Stock', permission: 'purchases.stock.view', feature: 'purchases' },
      },
      {
        path: 'suppliers',
        name: 'suppliers',
        component: () => import('@/views/workshop/purchases/SupplierList.vue'),
        meta: { title: 'Suppliers', permissions: ['purchases.grn.view', 'purchases.suppliers.pay'], feature: 'purchases' },
      },
      {
        path: 'stock-masters',
        name: 'stock-masters',
        component: () => import('@/views/workshop/masters/StockMasters.vue'),
        meta: { title: 'Stock Items & Plan Kits', permission: 'purchases.stock.view', feature: 'purchases' },
      },
      {
        path: 'purchases-settings',
        name: 'purchases-settings',
        component: () => import('@/views/workshop/masters/PurchasesSettings.vue'),
        meta: { title: 'Purchases & Stock', permission: 'purchases.settings.manage' },
      },

      {
        path: 'bays',
        name: 'bays',
        component: BayList,
        meta: { title: 'Bays', permission: 'workshop.bays.view' },
      },

      // Pickup / drop / home visit
      {
        path: 'trips',
        name: 'my-trips',
        component: MyTrips,
        meta: { title: 'My Trips', permission: 'workshop.trips.drive' },
      },
      {
        path: 'trips/board',
        name: 'trip-board',
        component: TripBoard,
        meta: { title: 'Pickups & Drops', permissions: ['workshop.trips.view', 'workshop.trips.manage'] },
      },
      {
        path: 'trips/:id(\d+)',
        name: 'trip-detail',
        component: TripDetail,
        meta: { title: 'Trip', permissions: ['workshop.trips.drive', 'workshop.trips.manage'] },
      },
      {
        path: 'trip-settings',
        name: 'trip-settings',
        component: TripSettings,
        meta: { title: 'Pickup, Drop & Home Service', permissions: ['workshop.trips.settings', 'workshop.trips.manage'] },
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

      // Home: what to do today, and every master in one place
      {
        path: 'my-day',
        name: 'my-day',
        component: () => import('@/views/MyDay.vue'),
        meta: { title: 'My Day' },
      },
      {
        path: 'settings/modules',
        name: 'settings-modules',
        component: () => import('@/views/ModulesSettings.vue'),
        meta: { title: 'Modules', roles: ['owner'] },
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('@/views/SettingsHub.vue'),
        meta: { title: 'Settings' },
      },

      // CRM (Phase 7)
      {
        path: 'crm/leads',
        name: 'crm-leads',
        component: () => import('@/views/crm/Leads.vue'),
        meta: { feature: 'crm',  title: 'Leads', permissions: ['crm.leads.view', 'crm.leads.work'] },
      },
      {
        path: 'crm/leads/:id(\d+)',
        name: 'crm-lead',
        component: () => import('@/views/crm/LeadDetail.vue'),
        meta: { feature: 'crm',  title: 'Lead', permissions: ['crm.leads.view', 'crm.leads.work', 'crm.bookings.manage'] },
      },
      {
        path: 'crm/bookings',
        name: 'crm-bookings',
        component: () => import('@/views/crm/Bookings.vue'),
        meta: { feature: 'crm',  title: 'Bookings', permission: 'crm.bookings.manage' },
      },
      {
        path: 'crm/messages',
        name: 'crm-messages',
        component: () => import('@/views/crm/MessageQueue.vue'),
        meta: { feature: 'crm',  title: 'Messages to send', permission: 'crm.messages.send' },
      },
      {
        path: 'crm/insights',
        name: 'crm-insights',
        component: () => import('@/views/crm/Insights.vue'),
        meta: { feature: 'crm',  title: 'CRM insights', permissions: ['crm.reports.view', 'crm.leads.manage'] },
      },
      { path: 'crm/feedback', redirect: { path: '/crm/insights', query: { tab: 'feedback' } } },
      {
        path: 'crm-settings',
        name: 'crm-settings',
        component: () => import('@/views/crm/CrmMasters.vue'),
        meta: { feature: 'crm',  title: 'CRM settings', permissions: ['crm.settings.manage', 'crm.leads.manage'] },
      },

      // People: attendance, leave, payroll (Phase 6)
      {
        path: 'attendance',
        name: 'my-attendance',
        component: () => import('@/views/hr/MyAttendance.vue'),
        meta: { feature: 'hr',  title: 'Attendance', permission: 'hr.self' },
      },
      {
        path: 'hr/attendance',
        name: 'attendance-register',
        component: () => import('@/views/hr/AttendanceRegister.vue'),
        meta: { feature: 'hr',  title: 'Attendance register', permission: 'hr.attendance.view' },
      },
      {
        path: 'hr/leave',
        name: 'leave-requests',
        component: () => import('@/views/hr/LeaveRequests.vue'),
        meta: { feature: 'hr',  title: 'Leave requests', permission: 'hr.leave.approve' },
      },
      {
        path: 'hr/employees',
        name: 'employees',
        component: () => import('@/views/hr/Employees.vue'),
        meta: { feature: 'hr',  title: 'Employees', permission: 'hr.employees.manage' },
      },
      {
        path: 'hr/payroll',
        name: 'payroll',
        component: () => import('@/views/hr/PayrollRuns.vue'),
        meta: { feature: 'hr',  title: 'Payroll', permission: 'hr.payroll.prepare' },
      },
      {
        path: 'hr/payroll/:id(\d+)',
        name: 'payroll-run',
        component: () => import('@/views/hr/PayrollRunDetail.vue'),
        meta: { feature: 'hr',  title: 'Payroll', permission: 'hr.payroll.prepare' },
      },
      {
        path: 'hr/staff-money',
        name: 'staff-money',
        component: () => import('@/views/hr/StaffMoney.vue'),
        meta: { feature: 'hr',  title: 'Staff money', permissions: ['hr.custody.manage', 'hr.advances.manage'] },
      },
      {
        path: 'hr-masters',
        name: 'hr-masters',
        component: () => import('@/views/hr/HrMasters.vue'),
        meta: { feature: 'hr',  title: 'HR & Payroll masters', permissions: ['hr.settings.manage', 'hr.payroll.prepare'] },
      },

      // Accounts (Phase 6)
      {
        path: 'accounts',
        name: 'accounts',
        component: () => import('@/views/accounts/AccountsBooks.vue'),
        meta: { feature: 'accounts',  title: 'Accounts', permission: 'accounts.reports.view' },
      },
      {
        path: 'accounts/expenses',
        name: 'expenses',
        component: () => import('@/views/accounts/Expenses.vue'),
        meta: { feature: 'accounts',  title: 'Expenses', permissions: ['accounts.expenses.create', 'accounts.view'] },
      },
      {
        path: 'accounts-masters',
        name: 'accounts-masters',
        component: () => import('@/views/accounts/AccountsMasters.vue'),
        meta: { feature: 'accounts',  title: 'Accounts masters', permissions: ['accounts.settings.manage', 'accounts.view'] },
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

  // Customer's CRM link: book a slot, rate the service, refer a friend, view a creative
  {
    path: '/c/:token',
    name: 'crm-link',
    component: () => import('@/views/public/CrmLinkPage.vue'),
    meta: { title: 'Express Autocare' },
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

  if (to.meta.feature && !authStore.hasFeature(to.meta.feature)) {
    return next({ name: 'not-found' })
  }

  if (to.meta.permissions && to.meta.permissions.length > 0) {
    if (!authStore.hasAnyPermission(to.meta.permissions)) {
      return next({ name: 'forbidden' })
    }
  }

  next()
})

export default router
