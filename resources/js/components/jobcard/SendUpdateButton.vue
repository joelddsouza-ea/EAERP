<script setup lang="ts">
/**
 * "Send update" after finishing a step: technicians, drivers and managers hand a ready WhatsApp
 * message to the job's advisor (it appears in their bell); advisors get it to send at once.
 */
import { ref } from 'vue'
import { ChatBubbleLeftRightIcon } from '@heroicons/vue/24/outline'
import { customerUpdatesApi, type ReadyMessage as Ready } from '@/api/outsideWork.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Button from '@/components/ui/Button.vue'
import Modal from '@/components/ui/Modal.vue'
import ReadyMessage from '@/components/whatsapp/ReadyMessage.vue'

const props = withDefaults(
  defineProps<{ jobCardId: number; kind: 'section' | 'stage'; sheetId?: number; section?: string; label?: string; size?: 'sm' | 'md' }>(),
  { label: 'Send update', size: 'sm' }
)
const { can } = usePermissions()
const toast = useToast()

const busy = ref(false)
const asked = ref(false)
const ready = ref<Ready | null>(null)

async function send() {
  busy.value = true
  try {
    const res = (await customerUpdatesApi.request(props.jobCardId, { kind: props.kind, sheet_id: props.sheetId, section: props.section })).data
    asked.value = true
    if (res.data.to_advisor) {
      toast.success('Sent to the advisor', 'They will send it to the customer on WhatsApp.')
    } else {
      ready.value = res.data.message
    }
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <span v-if="can('workshop.customer-updates.request')">
    <Button :size="size" variant="secondary" :loading="busy" :disabled="asked && !ready" @click.stop="send">
      <ChatBubbleLeftRightIcon class="mr-1 h-4 w-4" />
      {{ asked && !ready ? 'Sent to advisor' : label }}
    </Button>
    <Modal :open="!!ready" title="Update for the customer" @close="ready = null">
      <ReadyMessage v-if="ready" :message-id="ready.id" :body="ready.body" :share-url="ready.share_url" @done="ready = null" />
    </Modal>
  </span>
</template>
