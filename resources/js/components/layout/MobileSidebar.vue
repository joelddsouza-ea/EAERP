<script setup lang="ts">
/**
 * Phone "More" sheet: every screen grouped as in the sidebar (Masters included),
 * the branch, and the account. Slides up from the bottom tab bar.
 */
import { watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Dialog, DialogPanel, TransitionRoot, TransitionChild } from '@headlessui/vue'
import { XMarkIcon, KeyIcon, ArrowRightOnRectangleIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth.store'
import { useUiStore } from '@/stores/ui.store'
import { useAuth } from '@/composables/useAuth'
import BranchSelector from './BranchSelector.vue'
import { useNavigation } from './navigation'

const route = useRoute()
const authStore = useAuthStore()
const uiStore = useUiStore()
const { logout } = useAuth()
const { groups, isActive } = useNavigation()

function close() {
  uiStore.closeMobileSidebar()
}

watch(() => route.fullPath, close)
</script>

<template>
  <TransitionRoot :show="uiStore.sidebarMobileOpen" as="template">
    <Dialog as="div" class="relative z-50 lg:hidden" @close="close">
      <TransitionChild
        as="template"
        enter="transition-opacity ease-out duration-200"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="transition-opacity ease-in duration-150"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-gray-900/50 backdrop-blur-[2px]" />
      </TransitionChild>

      <div class="fixed inset-0 flex items-end">
        <TransitionChild
          as="template"
          enter="transition ease-out duration-300 transform"
          enter-from="translate-y-full"
          enter-to="translate-y-0"
          leave="transition ease-in duration-200 transform"
          leave-from="translate-y-0"
          leave-to="translate-y-full"
        >
          <DialogPanel class="w-full max-h-[88vh] flex flex-col rounded-t-3xl bg-gray-50 shadow-2xl">
            <!-- Header -->
            <div class="shrink-0 rounded-t-3xl bg-white px-4 pt-2 pb-3 border-b border-gray-100">
              <div class="flex justify-center"><span class="h-1 w-10 rounded-full bg-gray-300" /></div>
              <div class="mt-2 flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-full bg-gray-700 text-white font-semibold">
                  {{ authStore.userName?.charAt(0)?.toUpperCase() || 'U' }}
                </div>
                <div class="min-w-0 flex-1">
                  <p class="font-semibold text-gray-900 truncate">{{ authStore.userName }}</p>
                  <p class="text-xs text-gray-500 truncate">{{ authStore.roleDisplayName }}</p>
                </div>
                <button type="button" class="p-2 -mr-2 rounded-full text-gray-500 active:bg-gray-100" aria-label="Close" @click="close">
                  <XMarkIcon class="h-6 w-6" />
                </button>
              </div>
              <div class="mt-3"><BranchSelector /></div>
            </div>

            <!-- Menu -->
            <nav class="flex-1 overflow-y-auto px-4 py-3 space-y-4">
              <section v-for="group in groups" :key="group.key">
                <p class="px-1 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-gray-400">{{ group.label }}</p>
                <div
                  :class="[
                    'rounded-2xl bg-white ring-1 ring-gray-200/80',
                    group.key === 'settings' ? 'divide-y divide-gray-100' : 'grid grid-cols-3 gap-1 p-2',
                  ]"
                >
                  <template v-if="group.key === 'settings'">
                    <RouterLink
                      v-for="item in group.items"
                      :key="item.to"
                      :to="item.to"
                      class="flex items-center gap-3 px-4 py-3 text-sm"
                      :class="isActive(item.to) ? 'text-primary-700 font-semibold' : 'text-gray-800'"
                    >
                      <component :is="item.icon" class="h-5 w-5 shrink-0" :class="isActive(item.to) ? 'text-primary-600' : 'text-gray-400'" />
                      <span class="flex-1">{{ item.name }}</span>
                      <span v-if="isActive(item.to)" class="h-2 w-2 rounded-full bg-primary-600" />
                    </RouterLink>
                  </template>
                  <template v-else>
                    <RouterLink
                      v-for="item in group.items"
                      :key="item.to"
                      :to="item.to"
                      class="flex flex-col items-center gap-1.5 rounded-xl px-1 py-3 text-center text-xs font-medium"
                      :class="isActive(item.to) ? 'bg-primary-50 text-primary-700' : 'text-gray-700 active:bg-gray-50'"
                    >
                      <span
                        class="flex h-10 w-10 items-center justify-center rounded-xl"
                        :class="isActive(item.to) ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-600'"
                      >
                        <component :is="item.icon" class="h-5 w-5" />
                      </span>
                      <span class="leading-tight">{{ item.name }}</span>
                    </RouterLink>
                  </template>
                </div>
              </section>

              <section>
                <p class="px-1 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-gray-400">Account</p>
                <div class="rounded-2xl bg-white ring-1 ring-gray-200/80 divide-y divide-gray-100">
                  <RouterLink to="/change-password" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-800">
                    <KeyIcon class="h-5 w-5 text-gray-400" /> Change password
                  </RouterLink>
                  <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-sm text-primary-700" @click="close(); logout()">
                    <ArrowRightOnRectangleIcon class="h-5 w-5" /> Sign out
                  </button>
                </div>
              </section>
              <p class="pb-[max(1rem,env(safe-area-inset-bottom))] text-center text-[11px] text-gray-400">Express Autocare · Multi-brand car service</p>
            </nav>
          </DialogPanel>
        </TransitionChild>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
