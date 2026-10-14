<script setup lang="ts">
import { Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue'
import {
  Bars3Icon,
  ChevronLeftIcon,
  ArrowRightOnRectangleIcon,
  KeyIcon,
} from '@heroicons/vue/24/outline'
import { useUiStore } from '@/stores/ui.store'
import { useAuthStore } from '@/stores/auth.store'
import { useAuth } from '@/composables/useAuth'
import BranchSelector from './BranchSelector.vue'

const uiStore = useUiStore()
const authStore = useAuthStore()
const { logout } = useAuth()

function toggleSidebar() {
  uiStore.toggleSidebar()
}

function toggleMobileSidebar() {
  uiStore.toggleMobileSidebar()
}
</script>

<template>
  <header
    :class="[
      'fixed top-0 right-0 z-30 h-16 bg-white border-b border-gray-200 transition-all duration-300',
      uiStore.sidebarCollapsed ? 'left-20' : 'left-64',
    ]"
  >
    <div class="flex items-center justify-between h-full px-4">
      <!-- Left side -->
      <div class="flex items-center gap-4">
        <!-- Mobile menu button -->
        <button
          type="button"
          class="lg:hidden p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg"
          @click="toggleMobileSidebar"
        >
          <Bars3Icon class="h-6 w-6" />
        </button>

        <!-- Toggle sidebar button (desktop) -->
        <button
          type="button"
          class="hidden lg:flex p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg"
          @click="toggleSidebar"
        >
          <ChevronLeftIcon
            :class="[
              'h-5 w-5 transition-transform duration-300',
              uiStore.sidebarCollapsed ? 'rotate-180' : '',
            ]"
          />
        </button>

        <!-- Branch selector -->
        <div class="hidden sm:block w-48">
          <BranchSelector />
        </div>
      </div>

      <!-- Right side -->
      <div class="flex items-center gap-4">
        <!-- User menu -->
        <Menu as="div" class="relative">
          <MenuButton
            class="flex items-center gap-2 p-2 text-gray-700 hover:bg-gray-100 rounded-lg"
          >
            <div
              class="flex items-center justify-center w-8 h-8 rounded-full bg-primary-100 text-primary-600 text-sm font-medium"
            >
              {{ authStore.userName?.charAt(0)?.toUpperCase() || 'U' }}
            </div>
            <span class="hidden sm:block text-sm font-medium">
              {{ authStore.userName }}
            </span>
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
              class="absolute right-0 mt-2 w-56 origin-top-right divide-y divide-gray-100 rounded-lg bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none"
            >
              <div class="px-4 py-3">
                <p class="text-sm font-medium text-gray-900">{{ authStore.userName }}</p>
                <p class="text-sm text-gray-500 truncate">{{ authStore.userEmail }}</p>
                <p class="text-xs text-gray-400 mt-1">{{ authStore.roleDisplayName }}</p>
              </div>

              <div class="py-1">
                <MenuItem v-slot="{ active }">
                  <RouterLink
                    to="/change-password"
                    :class="[
                      active ? 'bg-gray-100' : '',
                      'flex items-center px-4 py-2 text-sm text-gray-700',
                    ]"
                  >
                    <KeyIcon class="h-5 w-5 mr-3 text-gray-400" />
                    Change Password
                  </RouterLink>
                </MenuItem>
              </div>

              <div class="py-1">
                <MenuItem v-slot="{ active }">
                  <button
                    type="button"
                    :class="[
                      active ? 'bg-gray-100' : '',
                      'flex w-full items-center px-4 py-2 text-sm text-gray-700',
                    ]"
                    @click="logout"
                  >
                    <ArrowRightOnRectangleIcon class="h-5 w-5 mr-3 text-gray-400" />
                    Logout
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
