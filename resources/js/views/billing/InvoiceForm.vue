<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { invoicesApi } from '@/api/billing.api'
import { jobCardsApi } from '@/api/workshop.api'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const jobCardId = computed(() =>
  route.query.job_card_id ? Number(route.query.job_card_id) : null
)

const loading = ref(false)
const creating = ref(false)

async function createFromJobCard() {
  if (!jobCardId.value) {
    toast.error('Error', 'Job Card ID is required')
    router.push('/invoices')
    return
  }

  loading.value = true
  try {
    // Verify job card exists and is ready
    const jobCardRes = await jobCardsApi.get(jobCardId.value)
    const jobCard = jobCardRes.data.data

    if (jobCard.status !== 'ready') {
      toast.warning('Warning', 'Job card must be in "Ready" status to create an invoice.')
      router.push(`/job-cards/${jobCardId.value}`)
      return
    }

    creating.value = true
    const response = await invoicesApi.createFromJobCard(jobCardId.value)
    toast.success('Invoice Created', 'Invoice has been created from job card.')
    router.push(`/invoices/${response.data.data.id}`)
  } catch (err) {
    toast.apiError(err)
    router.push('/invoices')
  } finally {
    loading.value = false
    creating.value = false
  }
}

onMounted(() => {
  if (jobCardId.value) {
    createFromJobCard()
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Create Invoice</h1>
    </div>

    <div v-if="loading || creating" class="flex flex-col items-center justify-center py-12">
      <Spinner size="lg" />
      <p class="mt-4 text-gray-500">Creating invoice from job card...</p>
    </div>

    <Card v-else class="max-w-xl mx-auto text-center">
      <div class="py-8">
        <p class="text-gray-500 mb-6">
          Invoices are created from completed job cards. Please select a job card that is ready for
          delivery to create an invoice.
        </p>
        <Button @click="router.push('/job-cards?status=ready')">
          View Ready Job Cards
        </Button>
      </div>
    </Card>
  </div>
</template>
