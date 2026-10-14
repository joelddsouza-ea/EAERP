<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import {
  ClipboardDocumentListIcon,
  CurrencyDollarIcon,
  DocumentTextIcon,
  ExclamationCircleIcon,
} from '@heroicons/vue/24/outline'
import { dashboardApi } from '@/api/workshop.api'
import { useBranchStore } from '@/stores/branch.store'
import { useToast } from '@/composables/useToast'
import type { DashboardStats, JobCardStatus } from '@/api/types'
import StatCard from '@/components/dashboard/StatCard.vue'
import JobsByStatusChart from '@/components/dashboard/JobsByStatusChart.vue'
import RecentJobCards from '@/components/dashboard/RecentJobCards.vue'
import RecentPayments from '@/components/dashboard/RecentPayments.vue'

const branchStore = useBranchStore()
const toast = useToast()

const loading = ref(true)
const stats = ref<DashboardStats>({
  jobs_in_progress: 0,
  daily_collection: 0,
  billing_till_date: 0,
  total_outstanding: 0,
  jobs_by_status: {} as Record<JobCardStatus, number>,
  recent_job_cards: [],
  recent_payments: [],
})

async function fetchDashboardStats() {
  loading.value = true
  try {
    stats.value = await dashboardApi.stats(branchStore.currentBranchId || undefined)
  } catch (err) {
    toast.apiError(err, 'Failed to load dashboard data')
  } finally {
    loading.value = false
  }
}

function formatCurrency(amount: number | string): string {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

onMounted(fetchDashboardStats)

// Refetch when branch changes
watch(() => branchStore.currentBranchId, fetchDashboardStats)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
      <p class="text-gray-500 mt-1">
        Welcome back! Here's an overview of your workshop.
      </p>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <StatCard
        title="Jobs in Progress"
        :value="stats.jobs_in_progress"
        :icon="ClipboardDocumentListIcon"
        color="amber"
        :loading="loading"
      />
      <StatCard
        title="Daily Collection"
        :value="formatCurrency(stats.daily_collection)"
        :icon="CurrencyDollarIcon"
        color="green"
        :loading="loading"
      />
      <StatCard
        title="Billing Till Date"
        :value="formatCurrency(stats.billing_till_date)"
        :icon="DocumentTextIcon"
        color="blue"
        :loading="loading"
      />
      <StatCard
        title="Total Outstanding"
        :value="formatCurrency(stats.total_outstanding)"
        :icon="ExclamationCircleIcon"
        color="red"
        :loading="loading"
      />
    </div>

    <!-- Charts and Lists -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Jobs by Status Chart -->
      <JobsByStatusChart :data="stats.jobs_by_status" :loading="loading" />

      <!-- Recent Job Cards -->
      <RecentJobCards :job-cards="stats.recent_job_cards" :loading="loading" />

      <!-- Recent Payments -->
      <RecentPayments :payments="stats.recent_payments" :loading="loading" />
    </div>
  </div>
</template>
