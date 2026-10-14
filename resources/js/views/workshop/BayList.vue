<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { baysApi } from '@/api/workshop.api'
import { useBranchStore } from '@/stores/branch.store'
import { useToast } from '@/composables/useToast'
import type { Bay } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'

const branchStore = useBranchStore()
const toast = useToast()

const loading = ref(true)
const bays = ref<Bay[]>([])

const bayTypeLabels: Record<string, string> = {
  service: 'Service',
  bodyshop: 'Body Shop',
  detailing: 'Detailing',
  ac: 'AC',
}

async function fetchBays() {
  loading.value = true
  try {
    const response = await baysApi.list({
      branch_id: branchStore.currentBranchId || undefined,
    })
    bays.value = response.data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

onMounted(fetchBays)

watch(() => branchStore.currentBranchId, fetchBays)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Bays</h1>
      <p class="text-gray-500 mt-1">View workshop bay status and assignments</p>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <div v-else-if="bays.length === 0" class="text-center py-12 text-gray-500">
      No bays configured for this branch.
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <Card v-for="bay in bays" :key="bay.id">
        <div class="flex items-start justify-between">
          <div>
            <h3 class="text-lg font-semibold text-gray-900">{{ bay.name }}</h3>
            <p class="text-sm text-gray-500">{{ bayTypeLabels[bay.type] || bay.type }}</p>
          </div>
          <Badge :variant="bay.current_job ? 'warning' : 'success'" size="sm">
            {{ bay.current_job ? 'Occupied' : 'Available' }}
          </Badge>
        </div>

        <div v-if="bay.current_job" class="mt-4 p-3 bg-gray-50 rounded-lg">
          <RouterLink
            :to="`/job-cards/${bay.current_job.id}`"
            class="block hover:text-primary-600"
          >
            <p class="text-sm font-medium">{{ bay.current_job.job_number }}</p>
            <p class="text-xs text-gray-500">
              {{ bay.current_job.vehicle?.registration_no }}
            </p>
          </RouterLink>
        </div>

        <div v-else class="mt-4 p-3 bg-green-50 rounded-lg text-center">
          <p class="text-sm text-green-700">Ready for assignment</p>
        </div>
      </Card>
    </div>
  </div>
</template>
