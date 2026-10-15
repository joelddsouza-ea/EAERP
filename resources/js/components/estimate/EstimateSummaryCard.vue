<script setup lang="ts">
/**
 * Job card sidebar card: the estimate the customer sees, approval progress,
 * and a way into the estimate screen.
 */
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { estimatesApi } from '@/api/estimates.api'
import type { EstimateOverview } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'

const props = defineProps<{ jobCardId: number }>()
const router = useRouter()

const overview = ref<EstimateOverview | null>(null)
const failed = ref(false)

const current = computed(() => overview.value?.current ?? null)
const counts = computed(() => {
  const lines = current.value?.additional_lines ?? []
  return {
    approved: lines.filter((l) => l.approval_status === 'approved').length,
    declined: lines.filter((l) => l.approval_status === 'declined').length,
    pending: lines.filter((l) => l.approval_status === 'pending').length,
  }
})

function money(n: number) {
  return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(n)
}

async function load() {
  try {
    overview.value = (await estimatesApi.overview(props.jobCardId)).data.data
  } catch {
    failed.value = true
  }
}

defineExpose({ reload: load })
onMounted(load)
</script>

<template>
  <Card v-if="!failed">
    <template #header>
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold">Estimate</h3>
        <Badge v-if="current" size="sm" variant="success">v{{ current.version }} sent</Badge>
        <Badge v-else-if="overview?.draft" size="sm" variant="warning">Draft</Badge>
      </div>
    </template>

    <template v-if="current">
      <p class="text-2xl font-bold">{{ money(current.grand_total) }}</p>
      <p class="text-xs text-gray-500 mb-3">incl. GST · {{ current.package_name ?? 'No plan' }}</p>
      <div v-if="current.additional_lines.length" class="flex flex-wrap gap-2 mb-3">
        <Badge size="sm" variant="success">{{ counts.approved }} approved</Badge>
        <Badge v-if="counts.pending" size="sm" variant="warning">{{ counts.pending }} waiting</Badge>
        <Badge v-if="counts.declined" size="sm" variant="danger">{{ counts.declined }} not approved</Badge>
      </div>
      <p v-if="!current.customer_viewed_at" class="text-xs text-gray-500 mb-3">The customer has not opened it yet.</p>
    </template>
    <p v-else class="text-sm text-gray-500 mb-3">Not sent to the customer yet.</p>

    <Button block size="sm" variant="secondary" @click="router.push(`/job-cards/${jobCardId}/estimate`)">
      {{ current || overview?.draft ? 'Open estimate' : 'Prepare estimate' }}
    </Button>
  </Card>
</template>
