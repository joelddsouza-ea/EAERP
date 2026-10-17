<script setup lang="ts">
/**
 * "Messages to send": journey creatives, quotes and booking messages waiting for a
 * one-tap WhatsApp send from the workshop phone. Tap → WhatsApp opens with the text →
 * send → come back → the next one is ready. (With the WhatsApp Business API later,
 * these go by themselves.)
 */
import { ref, computed, onMounted } from 'vue'
import { crmApi, TEMP_COLORS, fmtDateTime, type QueueMessage } from '@/api/crm.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'

const { can } = usePermissions()
const toast = useToast()

const rows = ref<QueueMessage[]>([])
const total = ref(0)
const sentToday = ref(0)
const everyone = ref(false)
const loading = ref(true)
const expanded = ref<number | null>(null)

async function load() {
  loading.value = true
  try {
    const data = (await crmApi.queue(everyone.value)).data.data
    rows.value = data.rows
    total.value = data.total
    sentToday.value = data.sent_today
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}
onMounted(load)

const next = computed(() => rows.value.find((r) => r.share_url))

async function send(m: QueueMessage) {
  if (m.share_url) window.open(m.share_url, '_blank')
  try {
    await crmApi.markSent(m.id)
    rows.value = rows.value.filter((r) => r.id !== m.id)
    total.value--
    sentToday.value++
  } catch (err) {
    toast.apiError(err)
  }
}
async function skip(m: QueueMessage) {
  try {
    await crmApi.dismiss(m.id)
    rows.value = rows.value.filter((r) => r.id !== m.id)
    total.value--
  } catch (err) {
    toast.apiError(err)
  }
}
const kind = (t: string) => (t.startsWith('crm.journey') ? 'Nurture' : t === 'crm.quote' ? 'Quote' : t.startsWith('crm.booking') ? 'Booking' : 'Message')
</script>

<template>
  <div class="max-w-3xl mx-auto">
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Messages to send</h1>
        <p class="text-sm text-gray-500">{{ total }} waiting · {{ sentToday }} sent by you today</p>
      </div>
      <label v-if="can('crm.leads.manage')" class="flex items-center gap-2 text-sm"><input v-model="everyone" type="checkbox" class="h-4 w-4 rounded" @change="load" /> Everyone's</label>
    </div>

    <Card v-if="next" class="mb-4 ring-2 ring-primary-200">
      <p class="text-xs font-semibold uppercase tracking-wide text-primary-700">Next</p>
      <p class="mt-1 font-semibold">{{ next.lead?.name ?? next.customer?.name }} <span class="text-sm font-normal text-gray-500">· {{ kind(next.template) }}</span></p>
      <p class="mt-1 whitespace-pre-line text-sm text-gray-700 line-clamp-4">{{ next.body }}</p>
      <div class="mt-3 flex gap-2">
        <Button block size="lg" @click="send(next)">Send on WhatsApp</Button>
        <Button variant="ghost" @click="skip(next)">Skip</Button>
      </div>
    </Card>

    <div v-if="loading" class="flex justify-center py-10"><Spinner size="lg" /></div>
    <p v-else-if="!rows.length" class="rounded-xl bg-green-50 p-4 text-sm text-green-800">Nothing to send right now.</p>
    <Card v-else :padding="false">
      <ul class="divide-y divide-gray-100">
        <li v-for="m in rows" :key="m.id" class="px-4 py-3 text-sm">
          <div class="flex items-center gap-2">
            <span v-if="m.lead" class="h-2 w-2 rounded-full" :class="TEMP_COLORS[m.lead.temperature]" />
            <RouterLink v-if="m.lead" :to="`/crm/leads/${m.lead.id}`" class="font-medium">{{ m.lead.name }}</RouterLink>
            <RouterLink v-else-if="m.customer" :to="`/customers/${m.customer.id}`" class="font-medium">{{ m.customer.name }}</RouterLink>
            <span class="text-xs text-gray-500">{{ kind(m.template) }} · {{ fmtDateTime(m.scheduled_at) }}</span>
            <span v-if="m.has_media" class="text-xs">🖼️</span>
            <span class="ml-auto flex gap-1">
              <Button size="sm" :disabled="!m.share_url" @click="send(m)">Send</Button>
              <Button size="sm" variant="ghost" @click="skip(m)">Skip</Button>
            </span>
          </div>
          <button type="button" class="mt-1 block w-full text-left text-gray-600" :class="expanded === m.id ? 'whitespace-pre-line' : 'truncate'" @click="expanded = expanded === m.id ? null : m.id">{{ m.body }}</button>
          <p v-if="m.error" class="text-xs text-red-600">{{ m.error }}</p>
        </li>
      </ul>
    </Card>
  </div>
</template>
