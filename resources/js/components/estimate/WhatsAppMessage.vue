<script setup lang="ts">
/**
 * One prepared customer message: open it in WhatsApp on this phone/PC (wa.me),
 * then mark it sent. Messages telling the customer about decisions recorded on
 * their behalf cannot be dismissed.
 */
import { ref } from 'vue'
import { ChatBubbleLeftEllipsisIcon, CheckIcon, XMarkIcon } from '@heroicons/vue/20/solid'
import { customerChannelApi } from '@/api/estimates.api'
import { useToast } from '@/composables/useToast'
import type { CustomerMessage } from '@/api/types'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'

const props = defineProps<{ jobCardId: number; message: CustomerMessage; compact?: boolean }>()
const emit = defineEmits<{ updated: [message: CustomerMessage] }>()

const toast = useToast()
const busy = ref(false)
const opened = ref(false)

const TEMPLATE_LABELS: Record<string, string> = {
  car_received: 'Car received',
  work_started: 'Work started',
  estimate_ready: 'Estimate',
  approval_reminder: 'Approval reminder',
  decision_recorded: 'Decision recorded on behalf',
  media_shared: 'New photos / videos',
  ready_for_delivery: 'Ready for delivery',
  report_ready: 'Service report',
  thank_you: 'Thank you',
}

function openWhatsApp() {
  if (!props.message.share_url) return
  window.open(props.message.share_url, '_blank', 'noopener')
  opened.value = true
}

async function markSent() {
  busy.value = true
  try {
    const res = await customerChannelApi.markSent(props.jobCardId, props.message.id)
    emit('updated', res.data.data)
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function dismiss() {
  busy.value = true
  try {
    const res = await customerChannelApi.dismiss(props.jobCardId, props.message.id)
    emit('updated', res.data.data)
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="rounded-lg border border-gray-200 p-3">
    <div class="flex items-center justify-between gap-2 mb-1">
      <span class="text-sm font-medium text-gray-900">{{ TEMPLATE_LABELS[message.template] ?? message.template }}</span>
      <Badge
        size="sm"
        :variant="message.status === 'sent' ? 'success' : message.status === 'failed' ? 'danger' : message.status === 'dismissed' ? 'default' : 'warning'"
      >
        {{ message.status === 'prepared' ? 'To send' : message.status }}
      </Badge>
    </div>

    <p v-if="!compact" class="text-xs text-gray-600 whitespace-pre-wrap break-words">{{ message.body }}</p>
    <p v-if="message.error" class="text-xs text-red-600 mt-1">{{ message.error }}</p>
    <p v-if="message.status === 'sent' && message.sent_by" class="text-xs text-gray-400 mt-1">
      Sent by {{ message.sent_by.name }}
    </p>

    <div v-if="message.status === 'prepared' || message.status === 'failed'" class="flex flex-wrap gap-2 mt-2">
      <Button v-if="message.share_url" size="sm" @click="openWhatsApp">
        <ChatBubbleLeftEllipsisIcon class="h-4 w-4 mr-1" />
        Open WhatsApp
      </Button>
      <Button size="sm" :variant="opened ? 'primary' : 'secondary'" :loading="busy" @click="markSent">
        <CheckIcon class="h-4 w-4 mr-1" />
        Mark sent
      </Button>
      <Button v-if="message.template !== 'decision_recorded'" size="sm" variant="ghost" :disabled="busy" @click="dismiss">
        <XMarkIcon class="h-4 w-4 mr-1" />
        Skip
      </Button>
    </div>
  </div>
</template>
