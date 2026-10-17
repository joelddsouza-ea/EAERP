/**
 * The app's menu, in one place for the desktop sidebar, the mobile bottom bar and the
 * mobile "More" sheet — grouped by module (Home, CRM, Workshop, Customers, Parts & Stock,
 * Billing, Accounts, People) and filtered by the signed-in user's permissions.
 *
 * Every master lives on one Settings page (grouped by module, searchable) instead of the
 * menu: see settingsGroups.
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
  Cog6ToothIcon,
  SunIcon,
  MegaphoneIcon,
  ChatBubbleLeftRightIcon,
  PresentationChartLineIcon,
  PhotoIcon,
  GlobeAltIcon,
  CalendarIcon,
  XCircleIcon,
  AdjustmentsHorizontalIcon,
  GiftIcon,
} from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth.store'

export interface NavItem {
  name: string
  to: string
  icon: Component
  /** Short label for the mobile bottom bar */
  short?: string
  /** One line on the Settings page */
  hint?: string
}

export interface NavGroup {
  key: string
  label: string
  icon?: Component
  items: NavItem[]
}

const TECHNICIAN_ROLES = ['technician', 'body-shop-technician']

export function useNavigation() {
  const auth = useAuthStore()
  const route = useRoute()

  const can = (permission: string) => auth.hasPermission(permission)
  const any = (...permissions: string[]) => permissions.some(can)
  // Optional modules switched off in Settings › Modules drop out of the menu
  const on = (module: 'accounts' | 'crm' | 'hr') => auth.hasFeature(module)

  /** Every master, by module, for the Settings page */
  const settingsGroups = computed<NavGroup[]>(() => {
    const purchases = auth.hasFeature('purchases')
    const workshop: NavItem[] = []
    if (can('workshop.job-sheet-masters.view')) {
      workshop.push(
        { name: 'Job Sheets & Activities', to: '/job-sheet-masters?tab=sheets', icon: RectangleStackIcon, hint: 'Activities per plan and repair type, media required' },
        { name: 'Job Stages & Messages', to: '/job-sheet-masters?tab=stages', icon: ArrowPathIcon, hint: 'Automatic stages and the WhatsApp message at each' },
        { name: 'Observation Remarks', to: '/job-sheet-masters?tab=remarks', icon: ChatBubbleBottomCenterTextIcon },
      )
    }
    if (can('workshop.bays.view')) workshop.push({ name: 'Bays', to: '/bays', icon: HomeModernIcon })
    if (can('workshop.trips.settings')) workshop.push({ name: 'Pickup, Drop & Home Service', to: '/trip-settings', icon: TruckIcon, hint: 'Charges, policy, photos' })

    const pricing: NavItem[] = []
    if (can('workshop.pricing-masters.view')) {
      pricing.push(
        { name: 'Service Plans', to: '/pricing-masters?tab=plans', icon: SparklesIcon },
        { name: 'Package Prices', to: '/pricing-masters?tab=prices', icon: CurrencyRupeeIcon },
        { name: 'Plan Items', to: '/pricing-masters?tab=items', icon: ListBulletIcon },
        { name: 'Engine Oil', to: '/pricing-masters?tab=oil', icon: BeakerIcon },
        { name: 'Labour Charges & Parts Margin', to: '/pricing-masters?tab=labour', icon: CalculatorIcon },
        { name: 'Car Classes & Models', to: '/pricing-masters?tab=classes', icon: TagIcon },
      )
    }

    const crm: NavItem[] = []
    if (on('crm') && any('crm.settings.manage', 'crm.leads.manage')) {
      crm.push(
        { name: 'Journeys', to: '/crm-settings?tab=journeys', icon: PresentationChartLineIcon, hint: 'Which creative goes when (nurturing, service due, win-back…)' },
        { name: 'Creatives', to: '/crm-settings?tab=creatives', icon: PhotoIcon, hint: 'Images, videos, PDFs and their captions' },
        { name: 'Lead Sources & Webhooks', to: '/crm-settings?tab=sources', icon: GlobeAltIcon, hint: 'Links for Google / Meta / website leads' },
        { name: 'Campaigns & Ad Spend', to: '/crm-settings?tab=campaigns', icon: MegaphoneIcon },
        { name: 'Booking Slots', to: '/crm-settings?tab=slots', icon: CalendarIcon },
        { name: 'Lost Reasons', to: '/crm-settings?tab=reasons', icon: XCircleIcon },
        { name: 'CRM Rules', to: '/crm-settings?tab=settings', icon: AdjustmentsHorizontalIcon, hint: 'Call-back SLA, sending hours, referral reward, messages' },
      )
    }

    const parts: NavItem[] = []
    if (purchases && can('purchases.stock.view')) parts.push({ name: 'Stock Items & Plan Kits', to: '/stock-masters', icon: ShoppingCartIcon })
    if (can('purchases.settings.manage')) parts.push({ name: 'Purchases & Stock (module)', to: '/purchases-settings', icon: InboxArrowDownIcon, hint: 'Switch GRN, part check and stock on or off' })

    const hr: NavItem[] = []
    if (on('hr') && any('hr.settings.manage', 'hr.payroll.prepare')) {
      hr.push(
        { name: 'Pay, OT & Statutory', to: '/hr-masters?tab=settings', icon: CalendarDaysIcon },
        { name: 'Shifts', to: '/hr-masters?tab=shifts', icon: ClockIcon },
        { name: 'Holidays', to: '/hr-masters?tab=holidays', icon: SunIcon },
        { name: 'Leave Types', to: '/hr-masters?tab=leave', icon: CalendarIcon },
        { name: 'Incentive Rules', to: '/hr-masters?tab=incentives', icon: GiftIcon },
      )
    }

    const accounts: NavItem[] = []
    if (on('accounts') && any('accounts.settings.manage', 'accounts.view')) accounts.push({ name: 'Chart of Accounts, Banks & Month Locks', to: '/accounts-masters', icon: ScaleIcon })

    const company: NavItem[] = []
    if (auth.hasRole('owner')) company.push({ name: 'Modules (switch on / off)', to: '/settings/modules', icon: AdjustmentsHorizontalIcon, hint: 'Purchases & stock, Accounts, CRM, Attendance & payroll' })
    if (can('user-admin.users.view')) company.push({ name: 'Users & App Logins', to: '/users', icon: UserGroupIcon })
    if (can('user-admin.branches.view')) company.push({ name: 'Branches (location, weekly off)', to: '/branches', icon: BuildingOffice2Icon })
    if (can('user-admin.roles.view')) company.push({ name: 'Roles & Access', to: '/roles', icon: ShieldCheckIcon })

    return [
      { key: 'workshop', label: 'Workshop & job sheets', items: workshop },
      { key: 'pricing', label: 'Pricing & service plans', items: pricing },
      { key: 'crm', label: 'CRM & messaging', items: crm },
      { key: 'parts', label: 'Parts & purchases', items: parts },
      { key: 'hr', label: 'HR & payroll', items: hr },
      { key: 'accounts', label: 'Accounts', items: accounts },
      { key: 'company', label: 'Company & access', items: company },
    ].filter((g) => g.items.length)
  })

  const groups = computed<NavGroup[]>(() => {
    const isTechnician = auth.hasAnyRole(TECHNICIAN_ROLES)
    // Drivers work from My Trips only (the dashboard sends them there)
    const driverOnly = can('workshop.trips.drive') && !can('workshop.job-cards.view')

    const home: NavItem[] = driverOnly ? [] : [{ name: 'Dashboard', short: 'Home', to: '/', icon: HomeIcon }]
    home.push({ name: 'My Day', short: 'My Day', to: '/my-day', icon: SunIcon })
    if (on('hr') && can('hr.self')) home.push({ name: 'My Attendance', short: 'Attendance', to: '/attendance', icon: FingerPrintIcon })
    if (isTechnician) home.push({ name: 'My Jobs', short: 'My Jobs', to: '/my-jobs', icon: WrenchScrewdriverIcon })
    if (can('workshop.trips.drive')) home.push({ name: 'My Trips', short: 'Trips', to: '/trips', icon: MapPinIcon })

    const crm: NavItem[] = []
    const crmOn = on('crm')
    if (crmOn && any('crm.leads.view', 'crm.leads.work')) crm.push({ name: 'Leads & Follow-ups', short: 'Leads', to: '/crm/leads', icon: MegaphoneIcon })
    if (crmOn && can('crm.bookings.manage')) crm.push({ name: 'Bookings', short: 'Bookings', to: '/crm/bookings', icon: CalendarDaysIcon })
    if (crmOn && can('crm.messages.send')) crm.push({ name: 'Messages to Send', short: 'Messages', to: '/crm/messages', icon: ChatBubbleLeftRightIcon })
    if (crmOn && any('crm.reports.view')) crm.push({ name: 'Insights & Feedback', short: 'Insights', to: '/crm/insights', icon: PresentationChartLineIcon })

    const workshop: NavItem[] = []
    if (can('workshop.job-cards.view')) workshop.push({ name: 'Job Cards', short: 'Jobs', to: '/job-cards', icon: ClipboardDocumentListIcon })
    if (any('workshop.trips.view', 'workshop.trips.manage')) workshop.push({ name: 'Pickups & Drops', short: 'Pickups', to: '/trips/board', icon: MapIcon })

    const customers: NavItem[] = []
    if (can('workshop.customers.view')) customers.push({ name: 'Customers', short: 'Customers', to: '/customers', icon: UsersIcon })
    if (can('workshop.vehicles.view')) customers.push({ name: 'Vehicles', short: 'Vehicles', to: '/vehicles', icon: TruckIcon })

    const parts: NavItem[] = []
    const purchases = auth.hasFeature('purchases')
    if (can('workshop.spare-parts.view')) parts.push({ name: 'Parts Desk', short: 'Parts', to: '/parts-desk', icon: CubeIcon })
    if (purchases && can('purchases.grn.view')) parts.push({ name: 'GRNs', short: 'GRNs', to: '/purchases/grns', icon: InboxArrowDownIcon })
    if (purchases && can('purchases.stock.view')) parts.push({ name: 'Stock', short: 'Stock', to: '/stock', icon: ArchiveBoxIcon })
    if (purchases && any('purchases.grn.view', 'purchases.suppliers.pay')) parts.push({ name: 'Suppliers', short: 'Suppliers', to: '/suppliers', icon: BuildingStorefrontIcon })

    const billing: NavItem[] = []
    if (can('billing.invoices.view')) billing.push({ name: 'Invoices', short: 'Invoices', to: '/invoices', icon: DocumentTextIcon })
    if (can('billing.payments.view')) billing.push({ name: 'Payments', short: 'Payments', to: '/payments', icon: BanknotesIcon })
    if (can('billing.reports.view')) billing.push({ name: 'Billing Reports', short: 'Reports', to: '/reports', icon: ChartBarIcon })

    const accounts: NavItem[] = []
    if (on('accounts') && can('accounts.reports.view')) accounts.push({ name: 'Accounts & Reports', short: 'Accounts', to: '/accounts', icon: BookOpenIcon })
    if (on('accounts') && any('accounts.expenses.create', 'accounts.view')) accounts.push({ name: 'Expenses', short: 'Expenses', to: '/accounts/expenses', icon: ReceiptPercentIcon })

    const people: NavItem[] = []
    const hrOn = on('hr')
    if (hrOn && can('hr.attendance.view')) people.push({ name: 'Attendance Register', short: 'Register', to: '/hr/attendance', icon: ClockIcon })
    if (hrOn && can('hr.leave.approve')) people.push({ name: 'Leave Requests', short: 'Leave', to: '/hr/leave', icon: CalendarDaysIcon })
    if (hrOn && can('hr.employees.manage')) people.push({ name: 'Employees', short: 'Staff', to: '/hr/employees', icon: IdentificationIcon })
    if (hrOn && can('hr.payroll.prepare')) people.push({ name: 'Payroll', short: 'Payroll', to: '/hr/payroll', icon: BanknotesIcon })
    if (hrOn && any('hr.custody.manage', 'hr.advances.manage')) people.push({ name: 'Staff Money & Advances', short: 'Staff money', to: '/hr/staff-money', icon: WalletIcon })

    const settings: NavItem[] = settingsGroups.value.length ? [{ name: 'Settings', short: 'Settings', to: '/settings', icon: Cog6ToothIcon }] : []

    return [
      { key: 'home', label: 'Home', icon: HomeIcon, items: home },
      { key: 'crm', label: 'CRM', icon: MegaphoneIcon, items: crm },
      { key: 'workshop', label: 'Workshop', icon: WrenchScrewdriverIcon, items: workshop },
      { key: 'customers', label: 'Customers', icon: UsersIcon, items: customers },
      { key: 'parts', label: 'Parts & Stock', icon: CubeIcon, items: parts },
      { key: 'billing', label: 'Billing', icon: DocumentTextIcon, items: billing },
      { key: 'accounts', label: 'Accounts', icon: BookOpenIcon, items: accounts },
      { key: 'people', label: 'People', icon: IdentificationIcon, items: people },
      { key: 'settings', label: 'Settings', icon: Cog6ToothIcon, items: settings },
    ].filter((g) => g.items.length)
  })

  /** Up to four everyday screens for the mobile bottom bar (the fifth slot is "More") */
  const primary = computed<NavItem[]>(() => {
    const all = groups.value.flatMap((g) => (g.key === 'settings' ? [] : g.items))
    const fieldStaff = auth.hasAnyRole([...TECHNICIAN_ROLES, 'driver', 'employee'])
    const marketing = auth.hasRole('marketing')
    const order = fieldStaff
      ? ['/my-day', '/my-jobs', '/trips', '/attendance', '/job-cards']
      : marketing
        ? ['/crm/leads', '/crm/bookings', '/crm/messages', '/my-day', '/attendance']
        : ['/', '/job-cards', '/crm/leads', '/crm/bookings', '/parts-desk', '/customers', '/payments', '/invoices', '/accounts/expenses', '/my-day']
    return order.map((to) => all.find((i) => i.to === to)).filter((i): i is NavItem => !!i).slice(0, 4)
  })

  function isActive(to: string): boolean {
    const [path, query] = to.split('?')
    if (path === '/') return route.path === '/'
    // My Trips is not the board; Accounts is not its Expenses
    if (path === '/trips') return route.path === '/trips' || /^\/trips\/\d+/.test(route.path)
    if (path === '/accounts') return route.path === '/accounts'
    // Settings is active on any master page
    if (path === '/settings') {
      return route.path === '/settings' || settingsGroups.value.some((g) => g.items.some((i) => route.path.startsWith(i.to.split('?')[0])))
    }
    if (!route.path.startsWith(path)) return false
    if (!query) return true
    const tab = new URLSearchParams(query).get('tab')
    // A master's first tab is also the page without ?tab
    const current = (route.query.tab as string | undefined) ?? defaultTab(path)
    return current === tab
  }

  return { groups, primary, settingsGroups, isActive }
}

export function defaultTab(path: string): string | undefined {
  return {
    '/job-sheet-masters': 'sheets',
    '/pricing-masters': 'plans',
    '/crm-settings': 'journeys',
    '/hr-masters': 'settings',
  }[path]
}
