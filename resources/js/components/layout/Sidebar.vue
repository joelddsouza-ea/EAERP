<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { useUiStore } from '@/stores/ui.store'
import {
  HomeIcon,
  UsersIcon,
  TruckIcon,
  ClipboardDocumentListIcon,
  CurrencyDollarIcon,
  DocumentTextIcon,
  ChartBarIcon,
  Cog6ToothIcon,
  BuildingOfficeIcon,
  UserGroupIcon,
  WrenchScrewdriverIcon,
  AdjustmentsHorizontalIcon,
} from '@heroicons/vue/24/outline'

const route = useRoute()
const authStore = useAuthStore()
const uiStore = useUiStore()

interface NavItem {
  name: string
  to: string
  icon: any
  permission?: string
  roles?: string[]
  children?: NavItem[]
}

const navigation = computed<NavItem[]>(() => {
  const items: NavItem[] = [
    { name: 'Dashboard', to: '/', icon: HomeIcon },
  ]

  // Technician-specific
  if (authStore.hasAnyRole(['technician', 'body-shop-technician'])) {
    items.push({ name: 'My Jobs', to: '/my-jobs', icon: WrenchScrewdriverIcon })
  }

  // Workshop items
  if (authStore.hasPermission('workshop.customers.view')) {
    items.push({ name: 'Customers', to: '/customers', icon: UsersIcon })
  }
  if (authStore.hasPermission('workshop.vehicles.view')) {
    items.push({ name: 'Vehicles', to: '/vehicles', icon: TruckIcon })
  }
  if (authStore.hasPermission('workshop.job-cards.view')) {
    items.push({ name: 'Job Cards', to: '/job-cards', icon: ClipboardDocumentListIcon })
  }
  if (authStore.hasPermission('workshop.bays.view')) {
    items.push({ name: 'Bays', to: '/bays', icon: BuildingOfficeIcon })
  }
  if (authStore.hasPermission('workshop.job-sheet-masters.view')) {
    items.push({ name: 'Job Sheet Masters', to: '/job-sheet-masters', icon: AdjustmentsHorizontalIcon })
  }

  // Billing items
  if (authStore.hasPermission('billing.invoices.view')) {
    items.push({ name: 'Invoices', to: '/invoices', icon: DocumentTextIcon })
  }
  if (authStore.hasPermission('billing.payments.view')) {
    items.push({ name: 'Payments', to: '/payments', icon: CurrencyDollarIcon })
  }
  if (authStore.hasPermission('billing.reports.view')) {
    items.push({ name: 'Reports', to: '/reports', icon: ChartBarIcon })
  }

  // Admin items
  if (authStore.hasPermission('user-admin.users.view')) {
    items.push({ name: 'Users', to: '/users', icon: UserGroupIcon })
  }
  if (authStore.hasPermission('user-admin.branches.view')) {
    items.push({ name: 'Branches', to: '/branches', icon: BuildingOfficeIcon })
  }
  if (authStore.hasPermission('user-admin.roles.view')) {
    items.push({ name: 'Roles', to: '/roles', icon: Cog6ToothIcon })
  }

  return items
})

function isActive(path: string): boolean {
  if (path === '/') {
    return route.path === '/'
  }
  return route.path.startsWith(path)
}

function closeMobileSidebar() {
  uiStore.closeMobileSidebar()
}
</script>

<template>
  <aside
    :class="[
      'fixed inset-y-0 left-0 z-40 flex flex-col bg-white border-r border-gray-200 transition-all duration-300',
      uiStore.sidebarCollapsed ? 'w-20' : 'w-64',
    ]"
  >
    <!-- Logo -->
    <div class="flex items-center h-16 px-4 border-b border-gray-200">
      <RouterLink to="/" class="flex items-center">
        <div
          class="flex items-center justify-center w-10 h-10 rounded-lg bg-primary-600 text-white font-bold text-lg"
        >
          EA
        </div>
        <span
          v-if="!uiStore.sidebarCollapsed"
          class="ml-3 text-xl font-bold text-gray-900"
        >
          EAERP
        </span>
      </RouterLink>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto scrollbar-thin">
      <RouterLink
        v-for="item in navigation"
        :key="item.to"
        :to="item.to"
        :class="[
          'flex items-center px-3 py-2 rounded-lg text-sm font-medium transition-colors duration-200',
          isActive(item.to)
            ? 'bg-primary-50 text-primary-700'
            : 'text-gray-700 hover:bg-gray-100',
          uiStore.sidebarCollapsed ? 'justify-center' : '',
        ]"
        @click="closeMobileSidebar"
      >
        <component
          :is="item.icon"
          :class="[
            'flex-shrink-0 w-5 h-5',
            isActive(item.to) ? 'text-primary-600' : 'text-gray-400',
          ]"
        />
        <span v-if="!uiStore.sidebarCollapsed" class="ml-3">{{ item.name }}</span>
      </RouterLink>
    </nav>

    <!-- User info -->
    <div class="p-4 border-t border-gray-200">
      <div
        :class="[
          'flex items-center',
          uiStore.sidebarCollapsed ? 'justify-center' : '',
        ]"
      >
        <div
          class="flex items-center justify-center w-8 h-8 rounded-full bg-primary-100 text-primary-600 text-sm font-medium"
        >
          {{ authStore.userName?.charAt(0)?.toUpperCase() || 'U' }}
        </div>
        <div v-if="!uiStore.sidebarCollapsed" class="ml-3 overflow-hidden">
          <p class="text-sm font-medium text-gray-900 truncate">
            {{ authStore.userName }}
          </p>
          <p class="text-xs text-gray-500 truncate">
            {{ authStore.roleDisplayName }}
          </p>
        </div>
      </div>
    </div>
  </aside>
</template>
