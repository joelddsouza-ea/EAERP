<script setup lang="ts">
/**
 * A WhatsApp message ready for the customer: read it, open WhatsApp with it (the tap also marks
 * it sent) or skip it. Used in the bell (updates staff asked for), on the bill after approval and
 * by advisors' own "Send update".
 */
import { ref } from 'vue'
import { customerUpdatesApi } from '@/api/outsideWork.api'
import { useToast } from '@/composables/useToast'
import Button from '@/components/ui/Button.vue'

const props = defineProps<{ messageId: number; body: string; shareUrl: string | null; compact?: boolean }>()
const emit = defineEmits<{ done: [status: 'sent' | 'dismissed'] }>()
const toast = useToast()
const busy = ref(false)

async function markSent() {
  busy.value = true
  try {
    await customerUpdatesApi.sent(props.messageId)
    emit('done', 'sent')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function skip() {
  busy.value = true
  try {
    await customerUpdatesApi.skip(props.messageId)
    emit('done', 'dismissed')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <p class="whitespace-pre-line rounded-lg bg-green-50 p-2 text-sm text-gray-800" :class="compact ? 'text-xs' : ''">{{ body }}</p>
    <div class="mt-2 flex flex-wrap gap-2">
      <!-- The link opens WhatsApp with the text; tapping it also records the message as sent -->
      <a
        v-if="shareUrl"
        :href="shareUrl"
        target="_blank"
        rel="noopener"
        class="inline-flex items-center rounded-lg bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700"
        @click="markSent"
      >
        Send on WhatsApp
      </a>
      <Button v-else size="sm" :loading="busy" @click="markSent">Mark sent</Button>
      <Button size="sm" variant="ghost" :disabled="busy" @click="skip">Skip</Button>
    </div>
  </div>
</template>
