<script setup lang="ts">
/**
 * Staff notifications (parts prices requested / ready, parts to procure, customer
 * answered an estimate). Polls every minute; tapping one opens the related screen.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/vue'
import { BellIcon } from '@heroicons/vue/24/outline'
import { formatDistanceToNow } from 'date-fns'
import { notificationsApi } from '@/api/estimates.api'
import type { StaffNotification } from '@/api/types'

const router = useRouter()

const items = ref<StaffNotification[]>([])
const unread = ref(0)
let timer: ReturnType<typeof setInterval> | undefined

async function load() {
  try {
    const data = (await notificationsApi.list()).data.data
    items.value = data.items
    unread.value = data.unread
  } catch {
    // The bell is not worth an error toast; try again on the next poll
  }
}

async function open(n: StaffNotification, close: () => void) {
  close()
  if (!n.read) {
    n.read = true
    unread.value = Math.max(0, unread.value - 1)
    notificationsApi.markRead(n.id).catch(() => undefined)
  }
  router.push(n.link)
}

async function markAllRead() {
  await notificationsApi.markAllRead().catch(() => undefined)
  items.value.forEach((n) => (n.read = true))
  unread.value = 0
}

function ago(date: string): string {
  return formatDistanceToNow(new Date(date), { addSuffix: true })
}

onMounted(() => {
  load()
  timer = setInterval(load, 60_000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <Popover class="relative">
    <PopoverButton
      class="relative p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg"
      :aria-label="unread ? `${unread} unread notifications` : 'Notifications'"
      @click="load"
    >
      <BellIcon class="h-6 w-6" />
      <span
        v-if="unread"
        class="absolute top-1 right-1 min-w-[1.1rem] h-[1.1rem] px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold flex items-center justify-center"
      >
        {{ unread > 9 ? '9+' : unread }}
      </span>
    </PopoverButton>

    <PopoverPanel
      v-slot="{ close }"
      class="absolute right-0 z-40 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-lg bg-white shadow-lg ring-1 ring-black ring-opacity-5"
    >
      <div class="flex items-center justify-between px-4 py-2 border-b border-gray-100">
        <p class="text-sm font-semibold text-gray-900">Notifications</p>
        <button v-if="unread" class="text-xs text-primary-600 hover:underline" @click="markAllRead">Mark all read</button>
      </div>
      <ul class="max-h-96 overflow-auto divide-y divide-gray-100">
        <li v-for="n in items" :key="n.id">
          <button class="w-full px-4 py-3 text-left hover:bg-gray-50" @click="open(n, close)">
            <p class="text-sm" :class="n.read ? 'text-gray-600' : 'font-semibold text-gray-900'">{{ n.title }}</p>
            <p class="text-xs text-gray-500 mt-0.5">{{ n.body }}</p>
            <p class="text-[11px] text-gray-400 mt-1">{{ ago(n.created_at) }}</p>
          </button>
        </li>
        <li v-if="!items.length" class="px-4 py-6 text-center text-sm text-gray-500">No notifications.</li>
      </ul>
    </PopoverPanel>
  </Popover>
</template>
