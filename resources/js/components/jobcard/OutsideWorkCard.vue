<script setup lang="ts">
/**
 * Outside work on a job card: wheel alignment & balancing (the advisor ticks it and picks the
 * partner) and the washing the technician sent from the job sheet — status, the partner's
 * photos/videos, WhatsApp to the partner, "Car is back". Also the per-job switch for the
 * customer's progress list.
 */
import { ref, computed, onMounted } from 'vue'
import { outsideWorkApi, type OutsideWorkState, type OutsideWork } from '@/api/outsideWork.api'
import { jobCardsApi } from '@/api/workshop.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'

const props = defineProps<{ jobCardId: number; jobClosed: boolean; showTimeline: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { can } = usePermissions()
const toast = useToast()

const state = ref<OutsideWorkState | null>(null)
const partnerId = ref<number | null>(null)
const busy = ref(false)
const timeline = ref(props.showTimeline)

const alignmentPartners = computed(() => state.value?.partners.wheel_alignment ?? [])
const openAlignment = computed(() => state.value?.works.find((w) => w.kind === 'wheel_alignment' && ['sent', 'done'].includes(w.status)) ?? null)
const canSend = computed(() => can('workshop.outside-work.send') && !props.jobClosed)

async function load() {
  try {
    state.value = (await outsideWorkApi.list(props.jobCardId)).data.data
    partnerId.value = alignmentPartners.value[0]?.id ?? null
  } catch {
    state.value = null
  }
}

async function run(call: () => Promise<unknown>, message: string) {
  busy.value = true
  try {
    await call()
    toast.success(message)
    await load()
    emit('changed')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function saveTimeline() {
  try {
    await jobCardsApi.update(props.jobCardId, { show_customer_timeline: timeline.value })
    toast.success(timeline.value ? 'The customer sees the progress list' : 'Progress list hidden from the customer')
  } catch (err) {
    timeline.value = !timeline.value
    toast.apiError(err)
  }
}

const STATUS: Record<OutsideWork['status'], { label: string; variant: 'warning' | 'info' | 'success' | 'default' }> = {
  sent: { label: 'At the partner', variant: 'warning' },
  done: { label: 'Partner done', variant: 'info' },
  returned: { label: 'Back', variant: 'success' },
  cancelled: { label: 'Cancelled', variant: 'default' },
}

onMounted(load)
</script>

<template>
  <Card v-if="state">
    <template #header><h3 class="text-lg font-semibold">Outside work</h3></template>

    <ul v-if="state.works.length" class="divide-y divide-gray-100 text-sm">
      <li v-for="w in state.works" :key="w.id" class="py-2">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-medium text-gray-900">{{ w.kind_label }}</span>
          <span class="text-gray-600">· {{ w.partner?.name }}</span>
          <Badge size="sm" :variant="STATUS[w.status].variant">{{ STATUS[w.status].label }}</Badge>
          <a v-if="w.partner_whatsapp_url && w.status === 'sent'" :href="w.partner_whatsapp_url" target="_blank" rel="noopener" class="text-xs text-green-700 underline">WhatsApp the partner</a>
        </div>
        <p v-if="w.note || w.partner_note" class="mt-0.5 text-xs text-gray-500">{{ [w.note, w.partner_note && `Partner: ${w.partner_note}`].filter(Boolean).join(' · ') }}</p>
        <div v-if="w.media.length" class="mt-1 flex flex-wrap gap-1.5">
          <a v-for="m in w.media" :key="m.id" :href="m.url" target="_blank" class="block h-12 w-12 overflow-hidden rounded ring-1 ring-gray-200">
            <img v-if="m.thumbnail_url" :src="m.thumbnail_url" class="h-full w-full object-cover" alt="Partner's photo" />
            <span v-else class="flex h-full w-full items-center justify-center text-[10px] text-gray-500">video</span>
          </a>
        </div>
        <div v-if="canSend && ['sent', 'done'].includes(w.status)" class="mt-1 flex gap-3 text-xs">
          <button type="button" class="font-medium text-primary-700 hover:underline" :disabled="busy" @click="run(() => outsideWorkApi.returned(jobCardId, w.id), 'Car is back')">Car is back</button>
          <button v-if="w.status === 'sent'" type="button" class="text-gray-600 hover:underline" :disabled="busy" @click="run(() => outsideWorkApi.cancel(jobCardId, w.id), 'Cancelled')">Cancel</button>
        </div>
      </li>
    </ul>
    <p v-else class="text-sm text-gray-500">Nothing sent outside. Washing is sent from the technician's job sheet.</p>

    <!-- Wheel alignment & balancing: the advisor picks the partner -->
    <div v-if="canSend && !openAlignment" class="mt-3 rounded-lg bg-gray-50 p-3 text-sm">
      <p class="font-medium text-gray-800">Wheel alignment &amp; balancing</p>
      <p v-if="!alignmentPartners.length" class="mt-1 text-xs text-gray-500">No partner yet (Masters › Outside Service Partners).</p>
      <div v-else class="mt-2 flex flex-wrap items-center gap-2">
        <select v-model="partnerId" class="input w-auto py-1.5">
          <option v-for="p in alignmentPartners" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <Button
          size="sm"
          :loading="busy"
          :disabled="!partnerId"
          @click="run(() => outsideWorkApi.send(jobCardId, { kind: 'wheel_alignment', service_partner_id: partnerId! }), 'Sent for wheel alignment & balancing')"
        >
          Send for alignment
        </Button>
      </div>
    </div>

    <label v-if="can('workshop.customer-links.manage')" class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3 text-sm">
      <input v-model="timeline" type="checkbox" class="rounded text-primary-600" :disabled="jobClosed" @change="saveTimeline" />
      Show the progress timeline to the customer
    </label>
  </Card>
</template>
