<script setup lang="ts">
import { computed, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Dialog, DialogPanel, TransitionRoot, TransitionChild } from '@headlessui/vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
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
import { useAuthStore } from '@/stores/auth.store'
import { useUiStore } from '@/stores/ui.store'

const route = useRoute()
const authStore = useAuthStore()
const uiStore = useUiStore()

interface NavItem {
  name: string
  to: string
  icon: any
}

const navigation = computed<NavItem[]>(() => {
  const items: NavItem[] = [{ name: 'Dashboard', to: '/', icon: HomeIcon }]

  if (authStore.hasAnyRole(['technician', 'body-shop-technician'])) {
    items.push({ name: 'My Jobs', to: '/my-jobs', icon: WrenchScrewdriverIcon })
  }

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
  if (authStore.hasPermission('billing.invoices.view')) {
    items.push({ name: 'Invoices', to: '/invoices', icon: DocumentTextIcon })
  }
  if (authStore.hasPermission('billing.payments.view')) {
    items.push({ name: 'Payments', to: '/payments', icon: CurrencyDollarIcon })
  }
  if (authStore.hasPermission('billing.reports.view')) {
    items.push({ name: 'Reports', to: '/reports', icon: ChartBarIcon })
  }
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

function close() {
  uiStore.closeMobileSidebar()
}

// Close on route change
watch(() => route.path, close)
</script>

<template>
  <TransitionRoot :show="uiStore.sidebarMobileOpen" as="template">
    <Dialog as="div" class="relative z-50 lg:hidden" @close="close">
      <!-- Backdrop -->
      <TransitionChild
        as="template"
        enter="transition-opacity ease-linear duration-300"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="transition-opacity ease-linear duration-300"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-gray-900/80" />
      </TransitionChild>

      <div class="fixed inset-0 flex">
        <TransitionChild
          as="template"
          enter="transition ease-in-out duration-300 transform"
          enter-from="-translate-x-full"
          enter-to="translate-x-0"
          leave="transition ease-in-out duration-300 transform"
          leave-from="translate-x-0"
          leave-to="-translate-x-full"
        >
          <DialogPanel class="relative mr-16 flex w-full max-w-xs flex-1">
            <!-- Close button -->
            <TransitionChild
              as="template"
              enter="ease-in-out duration-300"
              enter-from="opacity-0"
              enter-to="opacity-100"
              leave="ease-in-out duration-300"
              leave-from="opacity-100"
              leave-to="opacity-0"
            >
              <div class="absolute left-full top-0 flex w-16 justify-center pt-5">
                <button type="button" class="-m-2.5 p-2.5" @click="close">
                  <XMarkIcon class="h-6 w-6 text-white" />
                </button>
              </div>
            </TransitionChild>

            <!-- Sidebar content -->
            <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-white px-6 pb-4">
              <!-- Logo -->
              <div class="flex h-16 shrink-0 items-center">
                <RouterLink to="/" class="flex items-center" @click="close">
                  <div
                    class="flex items-center justify-center w-10 h-10 rounded-lg bg-primary-600 text-white font-bold text-lg"
                  >
                    EA
                  </div>
                  <span class="ml-3 text-xl font-bold text-gray-900">EAERP</span>
                </RouterLink>
              </div>

              <!-- Navigation -->
              <nav class="flex flex-1 flex-col">
                <ul role="list" class="flex flex-1 flex-col gap-y-1">
                  <li v-for="item in navigation" :key="item.to">
                    <RouterLink
                      :to="item.to"
                      :class="[
                        'flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium',
                        isActive(item.to)
                          ? 'bg-primary-50 text-primary-700'
                          : 'text-gray-700 hover:bg-gray-100',
                      ]"
                      @click="close"
                    >
                      <component
                        :is="item.icon"
                        :class="[
                          'h-5 w-5 shrink-0',
                          isActive(item.to) ? 'text-primary-600' : 'text-gray-400',
                        ]"
                      />
                      {{ item.name }}
                    </RouterLink>
                  </li>
                </ul>
              </nav>
            </div>
          </DialogPanel>
        </TransitionChild>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
