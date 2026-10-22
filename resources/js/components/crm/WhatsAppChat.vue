<script setup lang="ts">
/**
 * One WhatsApp chat (CRM inbox and the lead page): the messages both ways with ticks, and the
 * reply box. Inside 24 hours of the customer's last message any text or file can be sent;
 * after that WhatsApp only allows an approved template, so the box offers those instead.
 */
import { ref, computed, watch, nextTick } from 'vue'
import { PaperClipIcon, PaperAirplaneIcon, DocumentIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { crmApi, fmtDateTime, type WaThread, type WaMessage } from '@/api/crm.api'
import { useToast } from '@/composables/useToast'
import Button from '@/components/ui/Button.vue'

const props = defineProps<{ thread: WaThread; compact?: boolean }>()
const emit = defineEmits<{ sent: [message: WaMessage] }>()
const toast = useToast()

const body = ref('')
const file = ref<File | null>(null)
const busy = ref(false)
const templateKey = ref('')
const scroller = ref<HTMLElement | null>(null)

const conv = computed(() => props.thread.conversation)
const canWrite = computed(() => props.thread.api_on && conv.value.window_open)
const closesIn = computed(() => {
  if (!conv.value.window_closes_at) return null
  const mins = Math.max(0, Math.round((new Date(conv.value.window_closes_at).getTime() - Date.now()) / 60000))
  return mins >= 60 ? `${Math.floor(mins / 60)} h ${mins % 60} min` : `${mins} min`
})

watch(() => props.thread.messages.length, async () => {
  await nextTick()
  scroller.value?.scrollTo({ top: scroller.value.scrollHeight })
}, { immediate: true })

const ticks = (m: WaMessage) => ({ queued: '🕓', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '⚠', received: '' })[m.status] ?? ''

async function send() {
  if (!body.value.trim() && !file.value) return
  busy.value = true
  try {
    const res = await crmApi.whatsappReply(conv.value.id, body.value.trim(), file.value)
    if (res.data.data.status === 'failed') toast.error('Not sent', res.data.data.error ?? '')
    body.value = ''
    file.value = null
    emit('sent', res.data.data)
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function sendTemplate() {
  if (!templateKey.value) return
  busy.value = true
  try {
    const res = await crmApi.whatsappTemplate(conv.value.id, templateKey.value)
    if (res.data.data.status === 'failed') toast.error('Not sent', res.data.data.error ?? '')
    else toast.success('Template sent')
    templateKey.value = ''
    emit('sent', res.data.data)
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-0 flex-col">
    <div ref="scroller" class="min-h-0 flex-1 space-y-2 overflow-y-auto bg-[#efeae2] p-3" :class="compact ? 'max-h-96' : ''">
      <p v-if="!thread.messages.length" class="py-6 text-center text-sm text-gray-500">No messages yet.</p>
      <div v-for="m in thread.messages" :key="m.id" class="flex" :class="m.direction === 'out' ? 'justify-end' : 'justify-start'">
        <div class="max-w-[85%] rounded-lg px-3 py-1.5 text-sm shadow-sm" :class="m.direction === 'out' ? 'bg-[#d9fdd3]' : m.kind === 'button' ? 'bg-amber-50 ring-1 ring-amber-200' : 'bg-white'">
          <p v-if="m.kind === 'template'" class="mb-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500">Template</p>
          <a v-if="m.media_url && m.kind === 'image'" :href="m.media_url" target="_blank" rel="noopener"><img :src="m.media_url" class="mb-1 max-h-60 rounded" alt="" /></a>
          <a v-else-if="m.media_url" :href="m.media_url" target="_blank" rel="noopener" class="mb-1 flex items-center gap-1 text-primary-700 underline">
            <DocumentIcon class="h-4 w-4" /> {{ m.file_name || (m.kind === 'audio' ? 'Voice note' : m.kind === 'video' ? 'Video' : 'File') }}
          </a>
          <p v-if="m.body" class="whitespace-pre-wrap break-words text-gray-900">{{ m.body }}</p>
          <p class="mt-0.5 text-right text-[10px] text-gray-500">
            <template v-if="m.by">{{ m.by }} · </template>{{ fmtDateTime(m.at) }}
            <span v-if="m.direction === 'out'" class="ml-1" :class="m.status === 'read' ? 'text-sky-600' : m.status === 'failed' ? 'text-red-600' : ''" :title="m.error ?? m.status">{{ ticks(m) }}</span>
          </p>
          <p v-if="m.status === 'failed' && m.error" class="text-[11px] text-red-700">{{ m.error }}</p>
        </div>
      </div>
    </div>

    <div class="border-t border-gray-200 bg-white p-2">
      <p v-if="!thread.api_on" class="px-1 py-2 text-sm text-gray-600">Interakt is off: reply with the WhatsApp button (workshop phone). Switch it on in CRM › WhatsApp settings.</p>
      <p v-else-if="conv.opted_out" class="px-1 pb-1 text-xs text-amber-700">This number asked for no marketing messages: reply to what they ask only.</p>

      <template v-if="canWrite">
        <p class="px-1 pb-1 text-[11px] text-gray-500">Free reply possible for {{ closesIn }} more (24 h after their last message).</p>
        <div v-if="file" class="mb-1 flex items-center gap-2 rounded bg-gray-100 px-2 py-1 text-xs">
          <PaperClipIcon class="h-4 w-4" /> <span class="flex-1 truncate">{{ file.name }}</span>
          <button type="button" aria-label="Remove file" @click="file = null"><XMarkIcon class="h-4 w-4" /></button>
        </div>
        <form class="flex items-end gap-2" @submit.prevent="send">
          <label class="cursor-pointer rounded-full p-2 text-gray-500 hover:bg-gray-100" aria-label="Attach">
            <PaperClipIcon class="h-5 w-5" />
            <input type="file" class="hidden" accept="image/*,application/pdf,video/mp4,audio/*,.doc,.docx,.xls,.xlsx" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
          </label>
          <textarea v-model="body" rows="1" maxlength="4096" class="input min-h-[2.5rem] flex-1 resize-y" placeholder="Type a message" @keydown.enter.exact.prevent="send" />
          <Button type="submit" :loading="busy" :disabled="!body.trim() && !file" aria-label="Send"><PaperAirplaneIcon class="h-5 w-5" /></Button>
        </form>
      </template>
      <div v-else-if="thread.api_on" class="space-y-1">
        <p class="px-1 text-xs text-gray-600">
          {{ conv.last_message_at && thread.messages.some((m) => m.direction === 'in') ? 'Over 24 hours since their last message' : 'They haven\x27t written yet' }}:
          WhatsApp only allows an approved template. Their reply opens the chat again.
        </p>
        <div class="flex gap-2">
          <select v-model="templateKey" class="input flex-1">
            <option value="">Choose a template…</option>
            <option v-for="t in thread.templates" :key="t.key" :value="t.key">{{ t.label }}</option>
          </select>
          <Button :loading="busy" :disabled="!templateKey" @click="sendTemplate">Send</Button>
        </div>
        <p v-if="!thread.templates.length" class="px-1 text-[11px] text-gray-500">No template without extra values is set up: add one (e.g. "Hello :name, please reply to continue") in CRM › WhatsApp settings › Templates.</p>
      </div>
    </div>
  </div>
</template>
