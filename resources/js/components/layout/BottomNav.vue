<script setup lang="ts">
/**
 * Phone tab bar: up to four everyday screens for the user's role, plus "More"
 * (every other screen, the Masters and the account).
 */
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Squares2X2Icon } from '@heroicons/vue/24/outline'
import { useUiStore } from '@/stores/ui.store'
import { useNavigation } from './navigation'

const route = useRoute()
const uiStore = useUiStore()
const { primary, groups, isActive } = useNavigation()

// "More" is highlighted when the current screen is not one of the tabs
const moreActive = computed(
  () => !primary.value.some((i) => isActive(i.to)) && groups.value.some((g) => g.items.some((i) => isActive(i.to)))
)
</script>

<template>
  <nav
    v-if="!route.meta.hideTabBar"
    class="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-white/95 backdrop-blur border-t border-gray-200 pb-safe"
    aria-label="Main"
  >
    <div class="grid h-16" :style="{ gridTemplateColumns: `repeat(${primary.length + 1}, minmax(0, 1fr))` }">
      <RouterLink
        v-for="item in primary"
        :key="item.to"
        :to="item.to"
        class="relative flex flex-col items-center justify-center gap-0.5 text-[11px] font-medium"
        :class="isActive(item.to) ? 'text-primary-600' : 'text-gray-500'"
      >
        <span v-if="isActive(item.to)" class="absolute top-0 h-[3px] w-10 rounded-b bg-primary-600" />
        <component :is="item.icon" class="h-6 w-6" :stroke-width="isActive(item.to) ? 2 : 1.6" />
        <span class="truncate max-w-full px-1">{{ item.short ?? item.name }}</span>
      </RouterLink>
      <button
        type="button"
        class="relative flex flex-col items-center justify-center gap-0.5 text-[11px] font-medium"
        :class="moreActive || uiStore.sidebarMobileOpen ? 'text-primary-600' : 'text-gray-500'"
        @click="uiStore.toggleMobileSidebar()"
      >
        <span v-if="moreActive" class="absolute top-0 h-[3px] w-10 rounded-b bg-primary-600" />
        <Squares2X2Icon class="h-6 w-6" :stroke-width="moreActive ? 2 : 1.6" />
        <span>More</span>
      </button>
    </div>
  </nav>
</template>
