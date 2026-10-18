<script setup lang="ts">
/**
 * Inbox: job updates, payments, reminders (service due, insurance, PUC, documents) and offers.
 * Opening it marks everything read.
 */
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { BellAlertIcon, WrenchScrewdriverIcon, TruckIcon, BanknotesIcon, CalendarDaysIcon, ShieldCheckIcon, DocumentTextIcon, ExclamationCircleIcon } from '@heroicons/vue/24/outline'
import api, { message } from '../api'
import { ui, toast, enablePush } from '../ui'

interface Item { id: number; kind: string; title: string; body: string | null; link: string | null; read: boolean; created_at: string }

const router = useRouter()
const items = ref<Item[]>([])
const loading = ref(true)
const pushState = ref<'unknown' | 'on' | 'off' | 'unsupported'>('unknown')

const icons: Record<string, unknown> = {
  stage: WrenchScrewdriverIcon, approval: ExclamationCircleIcon, driver: TruckIcon, payment: BanknotesIcon,
  service_due: CalendarDaysIcon, insurance_due: ShieldCheckIcon, puc_due: ShieldCheckIcon, document_due: DocumentTextIcon, pending_work: WrenchScrewdriverIcon,
}

function ago(iso: string): string {
  const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (mins < 60) return `${Math.max(1, mins)} min ago`
  if (mins < 1440) return `${Math.round(mins / 60)} h ago`
  return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
}

function open(item: Item) {
  if (!item.link) return
  router.push(item.link.replace(/^\/app/, '') || '/')
}

async function turnOnPush() {
  try {
    const key = (await api.get('/home')).data.data.push_key
    pushState.value = (await enablePush(key, (sub) => api.post('/push-subscriptions', sub))) ? 'on' : 'off'
    if (pushState.value === 'off') toast('Allow notifications for this app in your phone or browser settings.', 'error')
  } catch (err) {
    toast(message(err), 'error')
  }
}

onMounted(async () => {
  pushState.value = !('Notification' in window) || !('PushManager' in window) ? 'unsupported' : Notification.permission === 'granted' ? 'on' : 'off'
  try {
    items.value = (await api.get('/notifications')).data.data
    if (items.value.some((i) => !i.read)) {
      await api.post('/notifications/read')
      ui.unread = 0
    }
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <h1 class="text-xl font-bold text-gray-900">Inbox</h1>

    <button v-if="pushState === 'off'" type="button" class="mt-3 flex w-full items-center gap-3 rounded-2xl bg-primary-50 p-3 text-left text-sm text-primary-900 ring-1 ring-primary-200" @click="turnOnPush">
      <BellAlertIcon class="h-6 w-6 shrink-0" /> Get these on your phone: turn on notifications
    </button>

    <p v-if="loading" class="mt-8 text-center text-gray-500">Loading…</p>
    <p v-else-if="!items.length" class="mt-4 rounded-2xl bg-white p-4 text-sm text-gray-500 ring-1 ring-gray-200/80">Nothing yet. Job updates and reminders will show here.</p>
    <ul class="mt-4 space-y-2">
      <li v-for="i in items" :key="i.id">
        <button type="button" class="flex w-full gap-3 rounded-2xl bg-white p-3 text-left shadow-sm ring-1" :class="i.read ? 'ring-gray-200/80' : 'ring-primary-300'" @click="open(i)">
          <component :is="icons[i.kind] ?? BellAlertIcon" class="mt-0.5 h-6 w-6 shrink-0 text-primary-600" />
          <span class="min-w-0 flex-1">
            <span class="block font-medium text-gray-900">{{ i.title }}</span>
            <span v-if="i.body" class="block text-sm text-gray-600">{{ i.body }}</span>
            <span class="block text-xs text-gray-400">{{ ago(i.created_at) }}</span>
          </span>
        </button>
      </li>
    </ul>
  </div>
</template>
