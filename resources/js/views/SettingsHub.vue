<script setup lang="ts">
/**
 * Settings: every master in one place, grouped by module, with search — instead of a
 * long list of master links in the menu.
 */
import { ref, computed } from 'vue'
import { ChevronRightIcon } from '@heroicons/vue/20/solid'
import { useNavigation } from '@/components/layout/navigation'

const { settingsGroups } = useNavigation()
const q = ref('')

// Inside the EA Workshop Android app: go back to its server-address screen
type Cap = { isNativePlatform?: () => boolean; Plugins?: Record<string, any>; registerPlugin?: (n: string) => any }
const cap = (window as unknown as { Capacitor?: Cap }).Capacitor
const inApp = !!cap?.isNativePlatform?.()
function changeServer() {
  if (!confirmChange.value) {
    confirmChange.value = true
    return
  }
  const plugin = cap?.Plugins?.ServerConfig ?? cap?.registerPlugin?.('ServerConfig')
  plugin?.reset()
}
const confirmChange = ref(false)

const shown = computed(() => {
  const t = q.value.trim().toLowerCase()
  return settingsGroups.value
    .map((g) => ({ ...g, items: g.items.filter((i) => !t || i.name.toLowerCase().includes(t) || (i.hint ?? '').toLowerCase().includes(t) || g.label.toLowerCase().includes(t)) }))
    .filter((g) => g.items.length)
})
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Settings</h1>
        <p class="text-sm text-gray-500">Masters for every module. Changes are saved with the Save bar on each page.</p>
      </div>
      <input v-model="q" class="input w-64" placeholder="Find a setting…" />
    </div>

    <div v-if="inApp" class="mb-4 flex items-center justify-between gap-3 rounded-2xl bg-white px-4 py-3 ring-1 ring-gray-200/80 text-sm">
      <span class="text-gray-600">This phone's server address</span>
      <button type="button" class="font-medium text-red-700" @click="changeServer">{{ confirmChange ? 'Tap again to change' : 'Change server address' }}</button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <section v-for="g in shown" :key="g.key" class="rounded-2xl bg-white ring-1 ring-gray-200/80">
        <h2 class="px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">{{ g.label }}</h2>
        <ul class="divide-y divide-gray-100">
          <li v-for="i in g.items" :key="i.to">
            <RouterLink :to="i.to" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50">
              <component :is="i.icon" class="h-5 w-5 shrink-0 text-gray-400" />
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-gray-900">{{ i.name }}</span>
                <span v-if="i.hint" class="block truncate text-xs text-gray-500">{{ i.hint }}</span>
              </span>
              <ChevronRightIcon class="h-4 w-4 text-gray-300" />
            </RouterLink>
          </li>
        </ul>
      </section>
    </div>
    <p v-if="!shown.length" class="text-sm text-gray-500">Nothing matches.</p>
  </div>
</template>
