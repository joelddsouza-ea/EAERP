<script setup lang="ts">
/**
 * One search box for any record: phone, car number, job / invoice / booking number or a
 * name. Ctrl+K (⌘K) opens it from anywhere; Enter opens the first hit.
 */
import { ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import apiClient from '@/api/client'

interface Hit {
  type: string
  label: string
  sub: string | null
  to: string
}

const router = useRouter()
const open = ref(false)
const q = ref('')
const hits = ref<Hit[]>([])
const active = ref(0)
const input = ref<HTMLInputElement | null>(null)
let timer: number | undefined

const TYPES: Record<string, string> = { customer: 'Customer', vehicle: 'Car', job: 'Job', invoice: 'Bill', lead: 'Lead', booking: 'Booking' }

watch(q, (value) => {
  window.clearTimeout(timer)
  if (value.trim().length < 2) {
    hits.value = []
    return
  }
  timer = window.setTimeout(async () => {
    try {
      hits.value = (await apiClient.get('/search', { params: { q: value } })).data.data
      active.value = 0
    } catch {
      hits.value = []
    }
  }, 250)
})

async function show() {
  open.value = true
  await nextTick()
  input.value?.focus()
}
function go(hit?: Hit) {
  if (!hit) return
  open.value = false
  q.value = ''
  router.push(hit.to)
}
function onKey(e: KeyboardEvent) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    show()
  }
  if (e.key === 'Escape') open.value = false
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <button type="button" class="flex items-center gap-2 rounded-full p-2 text-gray-500 hover:bg-gray-100 lg:rounded-lg lg:bg-gray-100 lg:px-3 lg:py-1.5 lg:text-sm" aria-label="Search" @click="show">
    <MagnifyingGlassIcon class="h-5 w-5" />
    <span class="hidden lg:inline">Search phone, car no., job…</span>
    <kbd class="hidden lg:inline rounded bg-white px-1.5 text-[10px] text-gray-400 ring-1 ring-gray-200">Ctrl K</kbd>
  </button>

  <teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center bg-black/30 p-4 pt-[10vh]" @click.self="open = false">
      <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-xl">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4">
          <MagnifyingGlassIcon class="h-5 w-5 text-gray-400" />
          <input
            ref="input"
            v-model="q"
            class="w-full border-0 py-3.5 text-base focus:ring-0"
            placeholder="Phone, car number, job / bill / booking no., name"
            @keydown.down.prevent="active = Math.min(active + 1, hits.length - 1)"
            @keydown.up.prevent="active = Math.max(active - 1, 0)"
            @keydown.enter.prevent="go(hits[active])"
          />
        </div>
        <ul class="max-h-[60vh] overflow-y-auto">
          <li v-for="(h, i) in hits" :key="h.type + h.to + i">
            <button type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm" :class="i === active ? 'bg-primary-50' : 'hover:bg-gray-50'" @click="go(h)" @mouseenter="active = i">
              <span class="w-16 shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-center text-[11px] font-medium text-gray-600">{{ TYPES[h.type] ?? h.type }}</span>
              <span class="min-w-0 flex-1">
                <span class="block truncate font-medium text-gray-900">{{ h.label }}</span>
                <span v-if="h.sub" class="block truncate text-xs text-gray-500">{{ h.sub }}</span>
              </span>
            </button>
          </li>
        </ul>
        <p v-if="q.trim().length >= 2 && !hits.length" class="px-4 py-4 text-sm text-gray-500">Nothing found.</p>
      </div>
    </div>
  </teleport>
</template>
