<script setup lang="ts">
/**
 * The customer's link and the WhatsApp messages waiting to be sent from the
 * workshop phone.
 */
import { ref, computed, onMounted } from 'vue'
import { ClipboardDocumentIcon, ArrowPathIcon, BellAlertIcon } from '@heroicons/vue/20/solid'
import { customerChannelApi } from '@/api/estimates.api'
import { useToast } from '@/composables/useToast'
import type { CustomerLinkInfo, CustomerMessage } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import WhatsAppMessage from './WhatsAppMessage.vue'

const props = defineProps<{ jobCardId: number; canRemind?: boolean }>()
const toast = useToast()

const link = ref<CustomerLinkInfo | null>(null)
const messages = ref<CustomerMessage[]>([])
const showAll = ref(false)
const busy = ref(false)

const toSend = computed(() => messages.value.filter((m) => m.status === 'prepared' || m.status === 'failed'))
const history = computed(() => messages.value.filter((m) => m.status === 'sent' || m.status === 'dismissed'))

async function load() {
  try {
    const [l, m] = await Promise.all([customerChannelApi.link(props.jobCardId), customerChannelApi.messages(props.jobCardId)])
    link.value = l.data.data
    messages.value = m.data.data
  } catch (err) {
    toast.apiError(err)
  }
}

async function copyLink() {
  if (!link.value) return
  try {
    await navigator.clipboard.writeText(link.value.url)
    toast.success('Link copied')
  } catch {
    window.prompt('Copy the customer link', link.value.url)
  }
}

async function newLink() {
  if (link.value && !window.confirm('The current link will stop working. Create a new one?')) return
  busy.value = true
  try {
    link.value = (await customerChannelApi.regenerateLink(props.jobCardId)).data.data
    toast.success('New customer link ready')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function remind() {
  busy.value = true
  try {
    const msg = (await customerChannelApi.remind(props.jobCardId)).data.data
    messages.value = [msg, ...messages.value]
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

function replace(updated: CustomerMessage) {
  messages.value = messages.value.map((m) => (m.id === updated.id ? updated : m))
}

defineExpose({ reload: load })
onMounted(load)
</script>

<template>
  <Card>
    <template #header>
      <h3 class="text-lg font-semibold">Customer</h3>
    </template>

    <div class="mb-4">
      <p class="text-sm text-gray-500 mb-1">Customer page (no login)</p>
      <div v-if="link" class="flex items-center gap-2">
        <a :href="link.url" target="_blank" class="text-sm text-primary-600 truncate hover:underline">{{ link.url }}</a>
        <Button size="sm" variant="ghost" title="Copy" @click="copyLink"><ClipboardDocumentIcon class="h-4 w-4" /></Button>
        <Button size="sm" variant="ghost" title="New link" :disabled="busy" @click="newLink"><ArrowPathIcon class="h-4 w-4" /></Button>
      </div>
      <Button v-else size="sm" variant="secondary" :loading="busy" @click="newLink">Create link</Button>
      <p v-if="link?.last_viewed_at" class="text-xs text-gray-400 mt-1">Last opened {{ new Date(link.last_viewed_at).toLocaleString() }}</p>
    </div>

    <div class="space-y-2">
      <div class="flex items-center justify-between">
        <p class="text-sm font-medium text-gray-700">WhatsApp to send <span v-if="toSend.length" class="text-amber-600">({{ toSend.length }})</span></p>
        <Button v-if="canRemind" size="sm" variant="ghost" :disabled="busy" title="Remind about pending approvals" @click="remind">
          <BellAlertIcon class="h-4 w-4" />
        </Button>
      </div>
      <WhatsAppMessage v-for="m in toSend" :key="m.id" :job-card-id="jobCardId" :message="m" @updated="replace" />
      <p v-if="!toSend.length" class="text-xs text-gray-500">Nothing waiting.</p>

      <button v-if="history.length" class="text-xs text-gray-500 hover:underline" @click="showAll = !showAll">
        {{ showAll ? 'Hide' : 'Show' }} sent messages ({{ history.length }})
      </button>
      <template v-if="showAll">
        <WhatsAppMessage v-for="m in history" :key="m.id" :job-card-id="jobCardId" :message="m" compact />
      </template>
    </div>
  </Card>
</template>
