<script setup lang="ts">
/**
 * CRM › WhatsApp Inbox: customers' and leads' WhatsApp chats through Interakt.
 * Executives see their leads' chats, advisors their open jobs' customers, and chats nobody
 * has yet; managers see all. New numbers writing in become leads by themselves (setting).
 * Phones: the list, then the chat full screen.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronLeftIcon, MagnifyingGlassIcon, MegaphoneIcon } from '@heroicons/vue/24/outline'
import { crmApi, fmtDateTime, type WaConversation, type WaThread } from '@/api/crm.api'
import { useToast } from '@/composables/useToast'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'
import WhatsAppChat from '@/components/crm/WhatsAppChat.vue'
import { usePermissions } from '@/composables/usePermissions'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const { can } = usePermissions()
// Managers start with every chat, everyone else with theirs
const filter = ref<'mine' | 'unassigned' | 'all' | 'unread'>(can('crm.leads.manage') || can('crm.whatsapp.manage') ? 'all' : 'mine')
const status = ref<'open' | 'closed'>('open')
const search = ref('')
const list = ref<WaConversation[]>([])
const seesAll = ref(false)
const apiOn = ref(true)
const loading = ref(true)
const selected = ref<number | null>(Number(route.query.c) || null)
const thread = ref<WaThread | null>(null)
const people = ref<{ id: number; name: string }[]>([])

type Filter = 'mine' | 'unassigned' | 'all' | 'unread'
const filters = computed<{ k: Filter; l: string }[]>(() => [
  { k: 'mine', l: 'Mine' },
  { k: 'unread', l: 'Unread' },
  { k: 'unassigned', l: 'Nobody\x27s' },
  ...(seesAll.value ? [{ k: 'all' as Filter, l: 'All' }] : []),
])

async function loadList() {
  try {
    const res = (await crmApi.whatsappConversations({ filter: filter.value, status: status.value, search: search.value || undefined })).data.data
    list.value = res.conversations
    seesAll.value = res.sees_all
    apiOn.value = res.api_on
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

async function open(id: number) {
  selected.value = id
  router.replace({ query: { ...route.query, c: id } })
  try {
    thread.value = (await crmApi.whatsappThread(id)).data.data
    const row = list.value.find((c) => c.id === id)
    if (row) row.unread = 0
  } catch (err) {
    toast.apiError(err)
    selected.value = null
  }
}

async function refreshThread() {
  if (selected.value) thread.value = (await crmApi.whatsappThread(selected.value)).data.data
}

async function sent() {
  await Promise.all([refreshThread(), loadList()])
}

async function assign(userId: number) {
  if (!thread.value) return
  try {
    const res = await crmApi.whatsappAssign(thread.value.conversation.id, userId)
    toast.success(res.data.message ?? 'Assigned')
    await sent()
  } catch (err) {
    toast.apiError(err)
  }
}

async function setStatus(s: 'open' | 'closed') {
  if (!thread.value) return
  try {
    await crmApi.whatsappStatus(thread.value.conversation.id, s)
    await sent()
  } catch (err) {
    toast.apiError(err)
  }
}

async function createLead() {
  if (!thread.value) return
  try {
    const res = await crmApi.whatsappCreateLead(thread.value.conversation.id)
    toast.success('Lead created')
    router.push(`/crm/leads/${res.data.data.lead_id}`)
  } catch (err) {
    toast.apiError(err)
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadList, 300)
})
watch([filter, status], loadList)

// New messages: the list every 15 s, the open chat too
let poll: ReturnType<typeof setInterval> | undefined
onMounted(async () => {
  await loadList()
  if (selected.value) await open(selected.value)
  try {
    people.value = (await crmApi.whatsappPeople()).data.data
  } catch {
    /* "Give to" stays empty */
  }
  poll = setInterval(() => {
    if (document.visibilityState !== 'visible') return
    loadList()
    refreshThread().catch(() => {})
  }, 15_000)
})
onBeforeUnmount(() => clearInterval(poll))
</script>

<template>
  <div class="-mx-4 -my-4 flex h-[calc(100dvh-8.5rem)] min-h-[28rem] overflow-hidden bg-white sm:mx-0 sm:my-0 sm:h-[calc(100dvh-7rem)] sm:rounded-xl sm:ring-1 sm:ring-gray-200 lg:h-[calc(100dvh-6rem)]">
    <!-- Conversation list -->
    <aside class="flex w-full flex-col border-r border-gray-200 md:w-80 md:shrink-0" :class="selected ? 'hidden md:flex' : 'flex'">
      <div class="space-y-2 border-b border-gray-200 p-3">
        <div class="flex items-center justify-between">
          <h1 class="text-lg font-bold text-gray-900">WhatsApp</h1>
          <select v-model="status" class="rounded-lg border-gray-300 py-1 text-xs">
            <option value="open">Open</option>
            <option value="closed">Closed</option>
          </select>
        </div>
        <div class="relative">
          <MagnifyingGlassIcon class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-gray-400" />
          <input v-model="search" class="input pl-8" placeholder="Name, number or text" />
        </div>
        <div class="flex gap-1 text-xs font-medium">
          <button v-for="f in filters" :key="f.k" type="button" class="rounded-full px-2.5 py-1" :class="filter === f.k ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700'" @click="filter = f.k">{{ f.l }}</button>
        </div>
        <p v-if="!apiOn" class="rounded bg-amber-50 p-2 text-xs text-amber-800">Interakt is off: no chats come in. CRM › WhatsApp settings.</p>
      </div>
      <div v-if="loading" class="flex justify-center py-8"><Spinner /></div>
      <p v-else-if="!list.length" class="p-4 text-center text-sm text-gray-500">No chats here.</p>
      <ul v-else class="min-h-0 flex-1 divide-y divide-gray-100 overflow-y-auto">
        <li v-for="c in list" :key="c.id">
          <button type="button" class="w-full px-3 py-2.5 text-left hover:bg-gray-50" :class="selected === c.id ? 'bg-primary-50' : ''" @click="open(c.id)">
            <div class="flex items-center justify-between gap-2">
              <span class="truncate font-medium text-gray-900">{{ c.name || c.phone }}</span>
              <span class="shrink-0 text-[11px] text-gray-400">{{ fmtDateTime(c.last_message_at) }}</span>
            </div>
            <div class="flex items-center justify-between gap-2">
              <span class="truncate text-sm text-gray-500">{{ c.last_preview }}</span>
              <span v-if="c.unread" class="shrink-0 rounded-full bg-emerald-500 px-1.5 text-[11px] font-bold text-white">{{ c.unread }}</span>
            </div>
            <div class="mt-0.5 flex flex-wrap gap-1 text-[10px]">
              <span v-if="c.lead" class="rounded bg-blue-50 px-1.5 text-blue-700">Lead</span>
              <span v-if="c.customer" class="rounded bg-gray-100 px-1.5 text-gray-700">Customer</span>
              <span v-if="c.from_ad" class="rounded bg-purple-50 px-1.5 text-purple-700">From ad</span>
              <span v-if="c.window_open" class="rounded bg-emerald-50 px-1.5 text-emerald-700">Can reply</span>
              <span v-if="c.assigned_to" class="text-gray-400">· {{ c.assigned_to.name }}</span>
            </div>
          </button>
        </li>
      </ul>
    </aside>

    <!-- Chat -->
    <section class="min-w-0 flex-1 flex-col" :class="selected ? 'flex' : 'hidden md:flex'">
      <div v-if="!thread" class="m-auto p-6 text-center text-sm text-gray-500">Choose a chat.</div>
      <template v-else>
        <header class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-3 py-2">
          <button type="button" class="rounded p-1 text-gray-500 md:hidden" aria-label="Back" @click="selected = null; thread = null"><ChevronLeftIcon class="h-5 w-5" /></button>
          <div class="min-w-0 flex-1">
            <p class="truncate font-semibold text-gray-900">{{ thread.conversation.name || thread.conversation.phone }}</p>
            <p class="truncate text-xs text-gray-500">
              +{{ thread.conversation.phone }}
              <RouterLink v-if="thread.conversation.lead" :to="`/crm/leads/${thread.conversation.lead.id}`" class="ml-1 text-primary-700">· Lead ({{ thread.conversation.lead.status }})</RouterLink>
              <RouterLink v-if="thread.conversation.customer" :to="`/customers/${thread.conversation.customer.id}`" class="ml-1 text-primary-700">· Customer</RouterLink>
              <RouterLink v-if="thread.conversation.job" :to="`/job-cards/${thread.conversation.job.id}`" class="ml-1 text-primary-700">· Job {{ thread.conversation.job.job_number }}</RouterLink>
            </p>
          </div>
          <Button v-if="!thread.conversation.lead" size="sm" variant="secondary" @click="createLead"><MegaphoneIcon class="mr-1 h-4 w-4" /> Make lead</Button>
          <select v-if="people.length" class="rounded-lg border-gray-300 py-1 text-xs" :value="thread.conversation.assigned_to?.id ?? ''" @change="assign(Number(($event.target as HTMLSelectElement).value))">
            <option value="" disabled>Give to…</option>
            <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <Button size="sm" variant="ghost" @click="setStatus(thread.conversation.status === 'open' ? 'closed' : 'open')">{{ thread.conversation.status === 'open' ? 'Close' : 'Reopen' }}</Button>
        </header>
        <div v-if="thread.conversation.referral" class="border-b border-purple-100 bg-purple-50 px-3 py-1 text-xs text-purple-800">
          Started from an ad<template v-if="thread.conversation.first_text">: "{{ thread.conversation.first_text }}"</template>
        </div>
        <Badge v-if="thread.conversation.opted_out" variant="warning" class="m-2">No marketing messages</Badge>
        <WhatsAppChat :thread="thread" class="min-h-0 flex-1" @sent="sent" />
      </template>
    </section>
  </div>
</template>
