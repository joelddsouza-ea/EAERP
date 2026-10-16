<script setup lang="ts">
/**
 * Desktop sidebar (lg and up): brand logo, grouped menu with a collapsible
 * Masters group, and the signed-in user. Collapses to icons.
 */
import { ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ChevronDownIcon } from '@heroicons/vue/20/solid'
import { useAuthStore } from '@/stores/auth.store'
import { useUiStore } from '@/stores/ui.store'
import { useNavigation } from './navigation'

const route = useRoute()
const authStore = useAuthStore()
const uiStore = useUiStore()
const { groups, isActive } = useNavigation()

// Masters stays open while one of its screens is shown
const mastersOpen = ref(false)
watch(
  () => route.fullPath,
  () => {
    const masters = groups.value.find((g) => g.key === 'masters')
    if (masters?.items.some((i) => isActive(i.to))) mastersOpen.value = true
  },
  { immediate: true }
)
</script>

<template>
  <aside
    :class="[
      'fixed inset-y-0 left-0 z-40 flex flex-col bg-white border-r border-gray-200 transition-all duration-300',
      uiStore.sidebarCollapsed ? 'w-20' : 'w-64',
    ]"
  >
    <!-- Brand -->
    <div class="relative flex items-center h-16 px-4 border-b border-gray-100">
      <span class="absolute inset-x-0 top-0 h-1 bg-primary-600" />
      <RouterLink to="/" class="flex items-center w-full" :title="'Express Autocare'">
        <img
          v-if="!uiStore.sidebarCollapsed"
          src="/images/logo.png"
          alt="Express Autocare"
          class="h-11 w-auto object-contain"
        />
        <img v-else src="/images/logo-mark.png" alt="Express Autocare" class="mx-auto h-12 w-12 object-contain" />
      </RouterLink>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 px-3 py-4 overflow-y-auto scrollbar-thin">
      <div v-for="group in groups" :key="group.key" class="mb-4">
        <p
          v-if="!uiStore.sidebarCollapsed && !group.collapsible"
          class="px-3 mb-1 text-[11px] font-semibold uppercase tracking-wider text-gray-400"
        >
          {{ group.label }}
        </p>

        <!-- Collapsible group (Masters) -->
        <template v-if="group.collapsible">
          <button
            type="button"
            :class="[
              'w-full flex items-center px-3 py-2 rounded-lg text-sm font-semibold transition-colors',
              group.items.some((i) => isActive(i.to)) ? 'text-primary-700' : 'text-gray-700 hover:bg-gray-100',
              uiStore.sidebarCollapsed ? 'justify-center' : '',
            ]"
            :title="group.label"
            @click="mastersOpen = !mastersOpen; uiStore.sidebarCollapsed && uiStore.toggleSidebar()"
          >
            <component :is="group.icon" class="h-5 w-5 shrink-0" :class="group.items.some((i) => isActive(i.to)) ? 'text-primary-600' : 'text-gray-400'" />
            <template v-if="!uiStore.sidebarCollapsed">
              <span class="ml-3 flex-1 text-left">{{ group.label }}</span>
              <ChevronDownIcon :class="['h-4 w-4 text-gray-400 transition-transform', mastersOpen ? 'rotate-180' : '']" />
            </template>
          </button>
          <div v-if="mastersOpen && !uiStore.sidebarCollapsed" class="mt-1 ml-5 border-l border-gray-200 pl-2 space-y-0.5">
            <RouterLink
              v-for="item in group.items"
              :key="item.to"
              :to="item.to"
              :class="[
                'block px-3 py-1.5 rounded-md text-[13px] transition-colors',
                isActive(item.to) ? 'bg-primary-50 text-primary-700 font-semibold' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
              ]"
            >
              {{ item.name }}
            </RouterLink>
          </div>
        </template>

        <template v-else>
          <RouterLink
            v-for="item in group.items"
            :key="item.to"
            :to="item.to"
            :title="uiStore.sidebarCollapsed ? item.name : undefined"
            :class="[
              'relative flex items-center px-3 py-2 rounded-lg text-sm font-medium transition-colors',
              isActive(item.to) ? 'bg-primary-50 text-primary-700' : 'text-gray-700 hover:bg-gray-100',
              uiStore.sidebarCollapsed ? 'justify-center' : '',
            ]"
          >
            <span v-if="isActive(item.to)" class="absolute left-0 top-1.5 bottom-1.5 w-1 rounded-r bg-primary-600" />
            <component
              :is="item.icon"
              :class="['shrink-0 h-5 w-5', isActive(item.to) ? 'text-primary-600' : 'text-gray-400']"
            />
            <span v-if="!uiStore.sidebarCollapsed" class="ml-3">{{ item.name }}</span>
          </RouterLink>
        </template>
      </div>
    </nav>

    <!-- User -->
    <div class="p-3 border-t border-gray-100">
      <div :class="['flex items-center rounded-xl bg-gray-50 p-2', uiStore.sidebarCollapsed ? 'justify-center' : '']">
        <div class="flex items-center justify-center w-9 h-9 rounded-full bg-gray-700 text-white text-sm font-semibold shrink-0">
          {{ authStore.userName?.charAt(0)?.toUpperCase() || 'U' }}
        </div>
        <div v-if="!uiStore.sidebarCollapsed" class="ml-3 overflow-hidden">
          <p class="text-sm font-semibold text-gray-900 truncate">{{ authStore.userName }}</p>
          <p class="text-xs text-gray-500 truncate">{{ authStore.roleDisplayName }}</p>
        </div>
      </div>
    </div>
  </aside>
</template>
