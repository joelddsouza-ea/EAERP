<script setup lang="ts">
/**
 * Customer app shell: header with the inbox bell, the screen, and a bottom tab bar
 * (Home, Book, Assistant, Inbox, More). Sign-in screens show without the chrome.
 */
import { computed } from 'vue'
import { RouterView, RouterLink, useRoute } from 'vue-router'
import { HomeIcon, CalendarDaysIcon, SparklesIcon, BellIcon, Bars3Icon } from '@heroicons/vue/24/outline'
import { ui } from './ui'

const route = useRoute()
const chrome = computed(() => !route.meta.public)

const tabs = [
  { to: '/', label: 'Home', icon: HomeIcon, name: 'home' },
  { to: '/book', label: 'Book', icon: CalendarDaysIcon, name: 'book' },
  { to: '/assistant', label: 'Ask', icon: SparklesIcon, name: 'assistant' },
  { to: '/inbox', label: 'Inbox', icon: BellIcon, name: 'inbox' },
  { to: '/more', label: 'More', icon: Bars3Icon, name: 'more' },
]
const active = (name: string) => route.name === name || (name === 'home' && ['job', 'car', 'jobs', 'add-car'].includes(String(route.name)))
</script>

<template>
  <div class="min-h-screen bg-gray-100">
    <header v-if="chrome" class="sticky top-0 z-30 border-b border-gray-200 bg-white/95 pt-[env(safe-area-inset-top)] backdrop-blur">
      <div class="mx-auto flex h-14 max-w-xl items-center justify-between px-4">
        <RouterLink to="/" class="flex items-center gap-2">
          <img src="/images/logo.png" alt="Express Autocare" class="h-9 w-auto" />
        </RouterLink>
        <RouterLink to="/inbox" class="relative rounded-full p-2 text-gray-600" aria-label="Inbox">
          <BellIcon class="h-6 w-6" />
          <span v-if="ui.unread" class="absolute right-1 top-1 min-w-[1.1rem] rounded-full bg-primary-600 px-1 text-center text-[10px] font-bold leading-[1.1rem] text-white">{{ ui.unread > 9 ? '9+' : ui.unread }}</span>
        </RouterLink>
      </div>
    </header>

    <main :class="chrome ? 'mx-auto max-w-xl px-4 pb-[calc(5rem+env(safe-area-inset-bottom))] pt-4' : ''">
      <RouterView />
    </main>

    <nav v-if="chrome" class="fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white pb-[env(safe-area-inset-bottom)]">
      <div class="mx-auto grid h-16 max-w-xl grid-cols-5">
        <RouterLink v-for="t in tabs" :key="t.to" :to="t.to" class="flex flex-col items-center justify-center gap-0.5 text-[11px] font-medium" :class="active(t.name) ? 'text-primary-600' : 'text-gray-500'">
          <span class="relative">
            <component :is="t.icon" class="h-6 w-6" />
            <span v-if="t.name === 'inbox' && ui.unread" class="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full bg-primary-600"></span>
          </span>
          {{ t.label }}
        </RouterLink>
      </div>
    </nav>

    <transition name="fade">
      <div v-if="ui.toast" class="fixed inset-x-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-50 mx-auto max-w-md rounded-xl px-4 py-3 text-sm text-white shadow-lg" :class="ui.toast.kind === 'error' ? 'bg-red-700' : 'bg-gray-900'">
        {{ ui.toast.text }}
      </div>
    </transition>
  </div>
</template>

<style>
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
