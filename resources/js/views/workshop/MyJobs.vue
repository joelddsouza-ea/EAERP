<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { jobCardsApi } from '@/api/workshop.api'
import { useAuthStore } from '@/stores/auth.store'
import { serviceTypeLabel } from '@/constants/serviceTypes'
import { useToast } from '@/composables/useToast'
import type { JobCard } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'
import Select from '@/components/ui/Select.vue'

const router = useRouter()
const toast = useToast()
const authStore = useAuthStore()

const loading = ref(true)
const jobCards = ref<JobCard[]>([])
const statusFilter = ref('in_progress')

const statusOptions = [
  { value: 'in_progress', label: 'In Progress' },
  { value: 'qc', label: 'Quality Check' },
  { value: 'all', label: 'All Active' },
]

const statusVariants: Record<string, 'default' | 'success' | 'warning' | 'danger' | 'info' | 'purple'> = {
  in_progress: 'warning',
  waiting_parts: 'danger',
  qc: 'purple',
  ready: 'success',
}

const statusLabels: Record<string, string> = {
  in_progress: 'In Progress',
  waiting_parts: 'Waiting Parts',
  qc: 'QC',
  ready: 'Ready',
}

const priorityColors: Record<string, string> = {
  low: 'text-gray-500',
  normal: 'text-blue-600',
  high: 'text-amber-600',
  urgent: 'text-red-600',
}

async function fetchMyJobs() {
  loading.value = true
  try {
    const response = await jobCardsApi.myJobs(authStore.user!.id, {
      status: statusFilter.value === 'all' ? undefined : statusFilter.value,
    })
    jobCards.value = response.data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

function handleJobClick(jobCard: JobCard) {
  router.push(`/job-cards/${jobCard.id}`)
}

onMounted(fetchMyJobs)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">My Jobs</h1>
        <p class="text-gray-500 mt-1">Jobs assigned to you</p>
      </div>
      <div class="w-48">
        <Select
          v-model="statusFilter"
          :options="statusOptions"
          @update:model-value="fetchMyJobs"
        />
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <div v-else-if="jobCards.length === 0" class="text-center py-12">
      <div class="text-gray-400 mb-4">
        <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
          />
        </svg>
      </div>
      <p class="text-gray-500">No jobs assigned to you at the moment.</p>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <Card
        v-for="job in jobCards"
        :key="job.id"
        class="cursor-pointer hover:shadow-md transition-shadow"
        @click="handleJobClick(job)"
      >
        <div class="flex items-start justify-between mb-3">
          <div>
            <h3 class="text-lg font-semibold text-gray-900">{{ job.job_number }}</h3>
            <p class="text-sm text-gray-500">{{ job.vehicle?.registration_no }}</p>
          </div>
          <Badge :variant="statusVariants[job.status] || 'default'" size="sm">
            {{ statusLabels[job.status] || job.status }}
          </Badge>
        </div>

        <div class="space-y-2 text-sm">
          <div>
            <span class="text-gray-500">Vehicle:</span>
            <span class="ml-2 font-medium">
              {{ job.vehicle?.make?.name }} {{ job.vehicle?.model?.name }}
            </span>
          </div>
          <div>
            <span class="text-gray-500">Customer:</span>
            <span class="ml-2 font-medium">{{ job.customer?.name }}</span>
          </div>
          <div>
            <span class="text-gray-500">Type:</span>
            <span class="ml-2 font-medium capitalize">{{ serviceTypeLabel(job.service_type) }}</span>
          </div>
          <div>
            <span class="text-gray-500">Priority:</span>
            <span :class="['ml-2 font-medium capitalize', priorityColors[job.priority]]">
              {{ job.priority }}
            </span>
          </div>
          <div v-if="job.bay">
            <span class="text-gray-500">Bay:</span>
            <span class="ml-2 font-medium">{{ job.bay.name }}</span>
          </div>
        </div>

        <div v-if="job.customer_complaints" class="mt-3 pt-3 border-t border-gray-100">
          <p class="text-xs text-gray-500 mb-1">Complaint:</p>
          <p class="text-sm text-gray-700 line-clamp-2">{{ job.customer_complaints }}</p>
        </div>
      </Card>
    </div>
  </div>
</template>
