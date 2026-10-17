/**
 * The app's menu, in one place for the desktop sidebar, the mobile bottom bar and
 * the mobile "More" sheet. Items are filtered by the signed-in user's role and
 * permissions; the Masters group lists every master screen (deep-linked to its tab).
 */
import { computed, type Component } from 'vue'
import { useRoute } from 'vue-router'
import {
  HomeIcon,
  UsersIcon,
  TruckIcon,
  ClipboardDocumentListIcon,
  BanknotesIcon,
  DocumentTextIcon,
  ChartBarIcon,
  ShieldCheckIcon,
  BuildingOffice2Icon,
  UserGroupIcon,
  WrenchScrewdriverIcon,
  CubeIcon,
  Squares2X2Icon,
  RectangleStackIcon,
  ChatBubbleBottomCenterTextIcon,
  ArrowPathIcon,
  SparklesIcon,
  CurrencyRupeeIcon,
  ListBulletIcon,
  BeakerIcon,
  CalculatorIcon,
  TagIcon,
  HomeModernIcon,
  InboxArrowDownIcon,
  ArchiveBoxIcon,
  BuildingStorefrontIcon,
  ShoppingCartIcon,
  MapIcon,
  MapPinIcon,
  FingerPrintIcon,
  CalendarDaysIcon,
  IdentificationIcon,
  WalletIcon,
  ReceiptPercentIcon,
  BookOpenIcon,
  ClockIcon,
  ScaleIcon,
} from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth.store'

export interface NavItem {
  name: string
  to: string
  icon: Component
  /** Short label for the mobile bottom bar */
  short?: string
}

export interface NavGroup {
  key: string
  label: string
  icon?: Component
  items: NavItem[]
  /** Shown as one collapsible entry in the sidebar */
  collapsible?: boolean
}

const TECHNICIAN_ROLES = ['technician', 'body-shop-technician']

export function useNavigation() {
  const auth = useAuthStore()
  const route = useRoute()

  const can = (permission: string) => auth.hasPermission(permission)

  const groups = computed<NavGroup[]>(() => {
    const isTechnician = auth.hasAnyRole(TECHNICIAN_ROLES)

    // Drivers work from My Trips only (the dashboard sends them there)
    const driverOnly = can('workshop.trips.drive') && !can('workshop.job-cards.view')
    const main: NavItem[] = driverOnly ? [] : [{ name: 'Dashboard', short: 'Home', to: '/', icon: HomeIcon }]
    if (isTechnician) main.push({ name: 'My Jobs', short: 'My Jobs', to: '/my-jobs', icon: WrenchScrewdriverIcon })
    if (can('workshop.trips.drive')) main.push({ name: 'My Trips', short: 'Trips', to: '/trips', icon: MapPinIcon })
    if (can('workshop.job-cards.view')) main.push({ name: 'Job Cards', short: 'Jobs', to: '/job-cards', icon: ClipboardDocumentListIcon })
    if (can('workshop.customers.view')) main.push({ name: 'Customers', short: 'Customers', to: '/customers', icon: UsersIcon })
    if (can('workshop.vehicles.view')) main.push({ name: 'Vehicles', short: 'Vehicles', to: '/vehicles', icon: TruckIcon })
    if (can('hr.self')) main.push({ name: 'My Attendance', short: 'Attendance', to: '/attendance', icon: FingerPrintIcon })

    const operations: NavItem[] = []
    if (can('workshop.trips.view') || can('workshop.trips.manage')) {
      operations.push({ name: 'Pickups & Drops', short: 'Pickups', to: '/trips/board', icon: MapIcon })
    }
    if (can('workshop.spare-parts.view')) operations.push({ name: 'Parts Desk', short: 'Parts', to: '/parts-desk', icon: CubeIcon })
    // Purchases module (optional)
    const purchases = auth.hasFeature('purchases')
    if (purchases && can('purchases.grn.view')) operations.push({ name: 'GRNs', short: 'GRNs', to: '/purchases/grns', icon: InboxArrowDownIcon })
    if (purchases && can('purchases.stock.view')) operations.push({ name: 'Stock', short: 'Stock', to: '/stock', icon: ArchiveBoxIcon })
    if (purchases && (can('purchases.grn.view') || can('purchases.suppliers.pay'))) {
      operations.push({ name: 'Suppliers', short: 'Suppliers', to: '/suppliers', icon: BuildingStorefrontIcon })
    }

    const billing: NavItem[] = []
    if (can('billing.invoices.view')) billing.push({ name: 'Invoices', short: 'Invoices', to: '/invoices', icon: DocumentTextIcon })
    if (can('billing.payments.view')) billing.push({ name: 'Payments', short: 'Payments', to: '/payments', icon: BanknotesIcon })
    if (can('billing.reports.view')) billing.push({ name: 'Reports', short: 'Reports', to: '/reports', icon: ChartBarIcon })

    const people: NavItem[] = []
    if (can('hr.attendance.view')) people.push({ name: 'Attendance Register', short: 'Register', to: '/hr/attendance', icon: ClockIcon })
    if (can('hr.leave.approve')) people.push({ name: 'Leave Requests', short: 'Leave', to: '/hr/leave', icon: CalendarDaysIcon })
    if (can('hr.employees.manage')) people.push({ name: 'Employees', short: 'Staff', to: '/hr/employees', icon: IdentificationIcon })
    if (can('hr.payroll.prepare')) people.push({ name: 'Payroll', short: 'Payroll', to: '/hr/payroll', icon: BanknotesIcon })
    if (can('hr.custody.manage') || can('hr.advances.manage')) people.push({ name: 'Staff Money & Advances', short: 'Staff money', to: '/hr/staff-money', icon: WalletIcon })

    const accounts: NavItem[] = []
    if (can('accounts.reports.view')) accounts.push({ name: 'Accounts & Reports', short: 'Accounts', to: '/accounts', icon: BookOpenIcon })
    if (can('accounts.expenses.create') || can('accounts.view')) accounts.push({ name: 'Expenses', short: 'Expenses', to: '/accounts/expenses', icon: ReceiptPercentIcon })

    const masters: NavItem[] = []
    if (can('workshop.job-sheet-masters.view')) {
      masters.push(
        { name: 'Job Sheets & Activities', to: '/job-sheet-masters?tab=sheets', icon: RectangleStackIcon },
        { name: 'Job Stages & Messages', to: '/job-sheet-masters?tab=stages', icon: ArrowPathIcon },
        { name: 'Observation Remarks', to: '/job-sheet-masters?tab=remarks', icon: ChatBubbleBottomCenterTextIcon },
      )
    }
    if (can('workshop.pricing-masters.view')) {
      masters.push(
        { name: 'Service Plans', to: '/pricing-masters?tab=plans', icon: SparklesIcon },
        { name: 'Package Prices', to: '/pricing-masters?tab=prices', icon: CurrencyRupeeIcon },
        { name: 'Plan Items', to: '/pricing-masters?tab=items', icon: ListBulletIcon },
        { name: 'Engine Oil', to: '/pricing-masters?tab=oil', icon: BeakerIcon },
        { name: 'Labour Charges & Parts Margin', to: '/pricing-masters?tab=labour', icon: CalculatorIcon },
        { name: 'Car Classes & Models', to: '/pricing-masters?tab=classes', icon: TagIcon },
      )
    }
    if (can('workshop.trips.settings')) masters.push({ name: 'Pickup, Drop & Home Service', to: '/trip-settings', icon: TruckIcon })
    if (can('workshop.bays.view')) masters.push({ name: 'Bays', to: '/bays', icon: HomeModernIcon })
    if (purchases && can('purchases.stock.view')) masters.push({ name: 'Stock Items & Plan Kits', to: '/stock-masters', icon: ShoppingCartIcon })
    if (can('purchases.settings.manage')) masters.push({ name: 'Purchases & Stock (module)', to: '/purchases-settings', icon: InboxArrowDownIcon })
    if (can('hr.settings.manage') || can('hr.payroll.prepare')) masters.push({ name: 'HR & Payroll', to: '/hr-masters', icon: CalendarDaysIcon })
    if (can('accounts.settings.manage') || can('accounts.view')) masters.push({ name: 'Chart of Accounts & Banks', to: '/accounts-masters', icon: ScaleIcon })

    const admin: NavItem[] = []
    if (can('user-admin.users.view')) admin.push({ name: 'Users', to: '/users', icon: UserGroupIcon })
    if (can('user-admin.branches.view')) admin.push({ name: 'Branches', to: '/branches', icon: BuildingOffice2Icon })
    if (can('user-admin.roles.view')) admin.push({ name: 'Roles & Access', to: '/roles', icon: ShieldCheckIcon })

    return [
      { key: 'main', label: 'Workshop', items: main },
      { key: 'operations', label: 'Operations', items: operations },
      { key: 'billing', label: 'Billing', items: billing },
      { key: 'people', label: 'People', items: people },
      { key: 'accounts', label: 'Accounts', items: accounts },
      { key: 'masters', label: 'Masters', icon: Squares2X2Icon, items: masters, collapsible: true },
      { key: 'admin', label: 'Administration', items: admin },
    ].filter((g) => g.items.length)
  })

  /** Up to four everyday screens for the mobile bottom bar (the fifth slot is "More") */
  const primary = computed<NavItem[]>(() => {
    const all = groups.value.flatMap((g) => (g.key === 'masters' || g.key === 'admin' ? [] : g.items))
    // Technicians and drivers punch every day: Attendance is one of their tabs
    const fieldStaff = auth.hasAnyRole([...TECHNICIAN_ROLES, 'driver', 'employee'])
    const order = fieldStaff
      ? ['/', '/my-jobs', '/trips', '/attendance', '/job-cards', '/customers']
      : ['/', '/job-cards', '/parts-desk', '/customers', '/payments', '/invoices', '/accounts/expenses', '/vehicles', '/reports', '/attendance']
    return order.map((to) => all.find((i) => i.to === to)).filter((i): i is NavItem => !!i).slice(0, 4)
  })

  function isActive(to: string): boolean {
    const [path, query] = to.split('?')
    if (path === '/') return route.path === '/'
    // My Trips is not the board; Accounts is not its Expenses
    if (path === '/trips') return route.path === '/trips' || /^\/trips\/\d+/.test(route.path)
    if (path === '/accounts') return route.path === '/accounts'
    if (!route.path.startsWith(path)) return false
    if (!query) return true
    const tab = new URLSearchParams(query).get('tab')
    // A master's first tab is also the page without ?tab
    const current = (route.query.tab as string | undefined) ?? defaultTab(path)
    return current === tab
  }

  return { groups, primary, isActive }
}

export function defaultTab(path: string): string | undefined {
  return path === '/job-sheet-masters' ? 'sheets' : path === '/pricing-masters' ? 'plans' : undefined
}
