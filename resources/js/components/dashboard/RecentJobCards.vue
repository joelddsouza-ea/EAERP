<script setup lang="ts">
import { RouterLink } from 'vue-router'
import type { JobCard } from '@/api/types'
import Badge from '@/components/ui/Badge.vue'

interface Props {
  jobCards: JobCard[]
  loading?: boolean
}

withDefaults(defineProps<Props>(), {
  loading: false,
})

const statusVariants: Record<string, 'default' | 'success' | 'warning' | 'danger' | 'info' | 'purple'> = {
  draft: 'default',
  received: 'info',
  in_progress: 'warning',
  waiting_parts: 'danger',
  qc: 'purple',
  ready: 'success',
  delivered: 'success',
  cancelled: 'danger',
}

const statusLabels: Record<string, string> = {
  draft: 'Draft',
  received: 'Received',
  in_progress: 'In Progress',
  waiting_parts: 'Waiting Parts',
  qc: 'QC',
  ready: 'Ready',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
}
</script>

<template>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
      <h3 class="text-lg font-semibold text-gray-900">Recent Job Cards</h3>
      <RouterLink to="/job-cards" class="text-sm text-primary-600 hover:text-primary-700">
        View all
      </RouterLink>
    </div>

    <div v-if="loading" class="p-6">
      <div class="space-y-4">
        <div v-for="i in 5" :key="i" class="animate-pulse flex items-center gap-4">
          <div class="h-10 w-10 bg-gray-200 rounded-lg" />
          <div class="flex-1">
            <div class="h-4 bg-gray-200 rounded w-1/3 mb-2" />
            <div class="h-3 bg-gray-200 rounded w-1/4" />
          </div>
          <div class="h-6 w-20 bg-gray-200 rounded-full" />
        </div>
      </div>
    </div>

    <div v-else-if="jobCards.length === 0" class="p-6 text-center text-gray-500">
      No recent job cards
    </div>

    <ul v-else class="divide-y divide-gray-200">
      <li v-for="jobCard in jobCards" :key="jobCard.id">
        <RouterLink
          :to="`/job-cards/${jobCard.id}`"
          class="block px-6 py-4 hover:bg-gray-50 transition-colors"
        >
          <div class="flex items-center justify-between">
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-900 truncate">
                {{ jobCard.job_number }}
              </p>
              <p class="text-sm text-gray-500 truncate">
                {{ jobCard.vehicle?.registration_no }} - {{ jobCard.customer?.name }}
              </p>
            </div>
            <Badge :variant="statusVariants[jobCard.status] || 'default'" size="sm">
              {{ statusLabels[jobCard.status] || jobCard.status }}
            </Badge>
          </div>
        </RouterLink>
      </li>
    </ul>
  </div>
</template>
