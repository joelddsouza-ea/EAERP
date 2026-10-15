<script setup lang="ts">
/**
 * Customer service report: the advisor picks what goes in, previews the PDF and
 * releases it. Only plans with "send report" (EA 360 / EA Elite) reach the
 * customer; for other plans it stays in the system.
 */
import { ref, onMounted } from 'vue'
import { reportsApi, openPdfBlob } from '@/api/estimates.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { ServiceReport, CustomerMessage } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import WhatsAppMessage from './WhatsAppMessage.vue'

const props = defineProps<{ jobCardId: number }>()
const { can } = usePermissions()
const toast = useToast()

const report = ref<ServiceReport | null>(null)
const busy = ref(false)
const message = ref<CustomerMessage | null>(null)

const PARTS = [
  { key: 'activities', field: 'include_activities', label: 'Activities carried out' },
  { key: 'additional', field: 'include_additional', label: 'Additional jobs & not approved' },
  { key: 'observations', field: 'include_observations', label: 'Observations' },
] as const

async function load() {
  try {
    report.value = (await reportsApi.get(props.jobCardId)).data.data
  } catch {
    report.value = null
  }
}

async function toggle(field: (typeof PARTS)[number]['field'], value: boolean) {
  try {
    report.value = (await reportsApi.update(props.jobCardId, { [field]: value })).data.data
  } catch (err) {
    toast.apiError(err)
  }
}

async function preview() {
  try {
    openPdfBlob((await reportsApi.pdf(props.jobCardId)).data)
  } catch (err) {
    toast.apiError(err)
  }
}

async function release() {
  busy.value = true
  try {
    const res = await reportsApi.release(props.jobCardId)
    report.value = res.data.data.report
    message.value = res.data.data.message
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <Card v-if="report">
    <template #header>
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold">Service Report</h3>
        <Badge v-if="report.released_at" size="sm" variant="success">Released</Badge>
        <Badge v-else-if="!report.sends_to_customer" size="sm">Internal only</Badge>
      </div>
    </template>

    <p class="text-xs text-gray-500 mb-3">
      {{ report.activities_carried_out }} activities done · {{ report.additional_jobs.length }} additional jobs ·
      {{ report.observations.length }} observations
    </p>

    <div v-if="report.sends_to_customer" class="space-y-1 mb-3">
      <label v-for="p in PARTS" :key="p.key" class="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          class="rounded border-gray-300"
          :checked="report.include[p.key]"
          :disabled="!can('workshop.reports.release')"
          @change="(e) => toggle(p.field, (e.target as HTMLInputElement).checked)"
        />
        {{ p.label }}
      </label>
    </div>
    <p v-else class="text-xs text-gray-500 mb-3">This plan does not include a customer report; it stays in the system.</p>

    <div class="flex flex-wrap gap-2">
      <Button size="sm" variant="secondary" @click="preview">Preview PDF</Button>
      <Button
        v-if="report.sends_to_customer && can('workshop.reports.release')"
        size="sm"
        :loading="busy"
        @click="release"
      >
        {{ report.released_at ? 'Send again' : 'Release to customer' }}
      </Button>
    </div>

    <WhatsAppMessage v-if="message" class="mt-3" :job-card-id="jobCardId" :message="message" @updated="(m) => (message = m.status === 'prepared' ? m : null)" />
  </Card>
</template>
