<script setup lang="ts">
/**
 * Top app bar. Phones: back button on inner screens (logo on top-level ones), the
 * screen title, notifications and the account menu. Desktop: sidebar toggle, branch.
 */
import { computed } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue'
import {
  ChevronLeftIcon,
  ArrowLeftIcon,
  ArrowRightOnRectangleIcon,
  KeyIcon,
} from '@heroicons/vue/24/outline'
import { useUiStore } from '@/stores/ui.store'
import { useAuthStore } from '@/stores/auth.store'
import { useAuth } from '@/composables/useAuth'
import BranchSelector from './BranchSelector.vue'
import NotificationBell from './NotificationBell.vue'
import { useNavigation } from './navigation'
import GlobalSearch from './GlobalSearch.vue'

const route = useRoute()
const router = useRouter()
const uiStore = useUiStore()
const authStore = useAuthStore()
const { logout } = useAuth()
const { groups } = useNavigation()

const title = computed(() => (route.meta.title as string | undefined) ?? 'Express Autocare')

// Menu screens show the logo; anything deeper (a job card, a form) gets a back arrow
const isTopLevel = computed(() =>
  groups.value.some((g) => g.items.some((i) => i.to.split('?')[0] === route.path))
)

function back() {
  if (window.history.state?.back) router.back()
  else router.push('/')
}
</script>

<template>
  <header
    :class="[
      'fixed top-0 right-0 left-0 z-30 bg-white/95 backdrop-blur border-b border-gray-200 transition-all duration-300 pt-safe',
      uiStore.sidebarCollapsed ? 'lg:left-20' : 'lg:left-64',
    ]"
  >
    <span class="lg:hidden absolute inset-x-0 top-0 h-[3px] bg-primary-600" />
    <div class="flex items-center justify-between h-14 lg:h-16 px-2 sm:px-4">
      <!-- Left -->
      <div class="flex items-center gap-1 sm:gap-3 min-w-0">
        <!-- Phones -->
        <button
          v-if="!isTopLevel"
          type="button"
          class="lg:hidden p-2 -ml-1 rounded-full text-gray-700 active:bg-gray-100"
          aria-label="Back"
          @click="back"
        >
          <ArrowLeftIcon class="h-6 w-6" />
        </button>
        <RouterLink v-else to="/" class="lg:hidden shrink-0 pl-1">
          <img src="/images/logo-mark.png" alt="Express Autocare" class="h-10 w-10 object-contain" />
        </RouterLink>
        <h1 class="lg:hidden truncate text-[17px] font-bold text-gray-900">{{ title }}</h1>

        <!-- Desktop -->
        <button
          type="button"
          class="hidden lg:flex p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg"
          aria-label="Collapse menu"
          @click="uiStore.toggleSidebar()"
        >
          <ChevronLeftIcon :class="['h-5 w-5 transition-transform duration-300', uiStore.sidebarCollapsed ? 'rotate-180' : '']" />
        </button>
        <div class="hidden lg:block w-56">
          <BranchSelector />
        </div>
      </div>

      <!-- Right -->
      <div class="flex items-center gap-1 sm:gap-3 shrink-0">
        <GlobalSearch v-if="!authStore.hasRole('service-partner')" />
        <NotificationBell />

        <Menu as="div" class="relative">
          <MenuButton class="flex items-center gap-2 p-1.5 rounded-full lg:rounded-lg text-gray-700 hover:bg-gray-100">
            <div class="flex items-center justify-center w-8 h-8 rounded-full bg-gray-700 text-white text-sm font-semibold">
              {{ authStore.userName?.charAt(0)?.toUpperCase() || 'U' }}
            </div>
            <span class="hidden lg:block text-sm font-medium pr-1">{{ authStore.userName }}</span>
          </MenuButton>

          <transition
            enter-active-class="transition ease-out duration-100"
            enter-from-class="transform opacity-0 scale-95"
            enter-to-class="transform opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="transform opacity-100 scale-100"
            leave-to-class="transform opacity-0 scale-95"
          >
            <MenuItems
              class="absolute right-0 mt-2 w-60 origin-top-right divide-y divide-gray-100 rounded-xl bg-white shadow-lg ring-1 ring-black/5 focus:outline-none"
            >
              <div class="px-4 py-3">
                <p class="text-sm font-semibold text-gray-900">{{ authStore.userName }}</p>
                <p class="text-sm text-gray-500 truncate">{{ authStore.userEmail }}</p>
                <p class="mt-1 inline-block rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                  {{ authStore.roleDisplayName }}
                </p>
              </div>
              <div class="py-1">
                <MenuItem v-slot="{ active }">
                  <RouterLink to="/change-password" :class="[active ? 'bg-gray-50' : '', 'flex items-center px-4 py-2.5 text-sm text-gray-700']">
                    <KeyIcon class="h-5 w-5 mr-3 text-gray-400" />
                    Change password
                  </RouterLink>
                </MenuItem>
              </div>
              <div class="py-1">
                <MenuItem v-slot="{ active }">
                  <button
                    type="button"
                    :class="[active ? 'bg-gray-50' : '', 'flex w-full items-center px-4 py-2.5 text-sm text-primary-700']"
                    @click="logout"
                  >
                    <ArrowRightOnRectangleIcon class="h-5 w-5 mr-3" />
                    Sign out
                  </button>
                </MenuItem>
              </div>
            </MenuItems>
          </transition>
        </Menu>
      </div>
    </div>
  </header>
</template>
