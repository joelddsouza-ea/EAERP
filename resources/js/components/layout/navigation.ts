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

    const main: NavItem[] = [{ name: 'Dashboard', short: 'Home', to: '/', icon: HomeIcon }]
    if (isTechnician) main.push({ name: 'My Jobs', short: 'My Jobs', to: '/my-jobs', icon: WrenchScrewdriverIcon })
    if (can('workshop.job-cards.view')) main.push({ name: 'Job Cards', short: 'Jobs', to: '/job-cards', icon: ClipboardDocumentListIcon })
    if (can('workshop.customers.view')) main.push({ name: 'Customers', short: 'Customers', to: '/customers', icon: UsersIcon })
    if (can('workshop.vehicles.view')) main.push({ name: 'Vehicles', short: 'Vehicles', to: '/vehicles', icon: TruckIcon })

    const operations: NavItem[] = []
    if (can('workshop.spare-parts.view')) operations.push({ name: 'Parts Desk', short: 'Parts', to: '/parts-desk', icon: CubeIcon })

    const billing: NavItem[] = []
    if (can('billing.invoices.view')) billing.push({ name: 'Invoices', short: 'Invoices', to: '/invoices', icon: DocumentTextIcon })
    if (can('billing.payments.view')) billing.push({ name: 'Payments', short: 'Payments', to: '/payments', icon: BanknotesIcon })
    if (can('billing.reports.view')) billing.push({ name: 'Reports', short: 'Reports', to: '/reports', icon: ChartBarIcon })

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
    if (can('workshop.bays.view')) masters.push({ name: 'Bays', to: '/bays', icon: HomeModernIcon })

    const admin: NavItem[] = []
    if (can('user-admin.users.view')) admin.push({ name: 'Users', to: '/users', icon: UserGroupIcon })
    if (can('user-admin.branches.view')) admin.push({ name: 'Branches', to: '/branches', icon: BuildingOffice2Icon })
    if (can('user-admin.roles.view')) admin.push({ name: 'Roles & Access', to: '/roles', icon: ShieldCheckIcon })

    return [
      { key: 'main', label: 'Workshop', items: main },
      { key: 'operations', label: 'Operations', items: operations },
      { key: 'billing', label: 'Billing', items: billing },
      { key: 'masters', label: 'Masters', icon: Squares2X2Icon, items: masters, collapsible: true },
      { key: 'admin', label: 'Administration', items: admin },
    ].filter((g) => g.items.length)
  })

  /** Up to four everyday screens for the mobile bottom bar (the fifth slot is "More") */
  const primary = computed<NavItem[]>(() => {
    const all = groups.value.flatMap((g) => (g.key === 'masters' || g.key === 'admin' ? [] : g.items))
    const order = ['/', '/my-jobs', '/job-cards', '/parts-desk', '/customers', '/payments', '/invoices', '/vehicles', '/reports']
    return order.map((to) => all.find((i) => i.to === to)).filter((i): i is NavItem => !!i).slice(0, 4)
  })

  function isActive(to: string): boolean {
    const [path, query] = to.split('?')
    if (path === '/') return route.path === '/'
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
