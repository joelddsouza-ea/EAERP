<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  ClipboardDocumentListIcon,
  BanknotesIcon,
  DocumentTextIcon,
  ExclamationCircleIcon,
  PlusIcon,
  WrenchScrewdriverIcon,
  CubeIcon,
  UserPlusIcon,
  FingerPrintIcon,
  BuildingLibraryIcon,
  ArrowTrendingUpIcon,
  WalletIcon,
} from '@heroicons/vue/24/outline'
import { accountsApi, inr, type AccountsDashboard } from '@/api/accounts.api'
import { useAuthStore } from '@/stores/auth.store'
import { dashboardApi } from '@/api/workshop.api'
import { useBranchStore } from '@/stores/branch.store'
import { useToast } from '@/composables/useToast'
import type { DashboardStats, JobCardStatus } from '@/api/types'
import StatCard from '@/components/dashboard/StatCard.vue'
import JobsByStatusChart from '@/components/dashboard/JobsByStatusChart.vue'
import RecentJobCards from '@/components/dashboard/RecentJobCards.vue'
import RecentPayments from '@/components/dashboard/RecentPayments.vue'

const branchStore = useBranchStore()
const authStore = useAuthStore()
const toast = useToast()

const greeting = computed(() => {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'
})
const firstName = computed(() => authStore.userName?.split(' ')[0] ?? '')
const today = new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' })

// One-tap shortcuts for what this user does most
const quickActions = computed(() => {
  const list: { label: string; to: string; icon: any }[] = []
  if (authStore.hasPermission('hr.self')) list.push({ label: 'Attendance', to: '/attendance', icon: FingerPrintIcon })
  if (authStore.hasAnyRole(['technician', 'body-shop-technician'])) list.push({ label: 'My jobs', to: '/my-jobs', icon: WrenchScrewdriverIcon })
  if (authStore.hasPermission('workshop.job-cards.create')) list.push({ label: 'New job card', to: '/job-cards/create', icon: PlusIcon })
  if (authStore.hasPermission('workshop.customers.create')) list.push({ label: 'New customer', to: '/customers/create', icon: UserPlusIcon })
  if (authStore.hasPermission('workshop.spare-parts.view')) list.push({ label: 'Parts desk', to: '/parts-desk', icon: CubeIcon })
  if (authStore.hasPermission('billing.payments.view')) list.push({ label: 'Payments', to: '/payments', icon: BanknotesIcon })
  return list.slice(0, 4)
})

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

// Owner / accounts: money position (posted by the accounts module)
const money = ref<AccountsDashboard | null>(null)
async function fetchMoney() {
  if (!authStore.hasPermission('accounts.reports.view')) return
  try {
    money.value = (await accountsApi.dashboard(branchStore.currentBranchId)).data.data
  } catch {
    money.value = null
  }
}

async function fetchDashboardStats() {
  loading.value = true
  fetchMoney()
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
    <!-- Greeting -->
    <section class="relative mb-4 sm:mb-6 overflow-hidden rounded-2xl bg-gray-800 px-5 py-5 sm:px-6 sm:py-6 text-white shadow-sm">
      <span class="absolute inset-y-0 left-0 w-1.5 bg-primary-600" />
      <span class="absolute -right-12 -top-12 h-40 w-40 rounded-full bg-primary-600/25" />
      <span class="absolute right-16 -bottom-16 h-32 w-32 rounded-full bg-white/5" />
      <p class="relative text-xs font-medium uppercase tracking-wider text-gray-300">{{ today }}</p>
      <h1 class="relative mt-1 text-xl sm:text-2xl font-bold">{{ greeting }}{{ firstName ? `, ${firstName}` : '' }}</h1>
      <p class="relative mt-0.5 text-sm text-gray-300">
        {{ branchStore.currentBranch?.name ? `${branchStore.currentBranch.name} · ` : '' }}Here's how the workshop is doing today.
      </p>
      <div v-if="quickActions.length" class="relative mt-4 flex gap-2 overflow-x-auto scrollbar-none -mx-1 px-1">
        <RouterLink
          v-for="a in quickActions"
          :key="a.to"
          :to="a.to"
          class="shrink-0 inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3.5 py-2 text-sm font-medium ring-1 ring-white/15 hover:bg-white/20 active:bg-white/25"
        >
          <component :is="a.icon" class="h-4 w-4" />
          {{ a.label }}
        </RouterLink>
      </div>
    </section>

    <!-- Stats: 2 × 2 on phones -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5 mb-4 sm:mb-6">
      <StatCard title="Jobs in progress" :value="stats.jobs_in_progress" :icon="ClipboardDocumentListIcon" color="amber" :loading="loading" />
      <StatCard title="Today's collection" :value="formatCurrency(stats.daily_collection)" :icon="BanknotesIcon" color="green" :loading="loading" />
      <StatCard title="Billing till date" :value="formatCurrency(stats.billing_till_date)" :icon="DocumentTextIcon" color="blue" :loading="loading" />
      <StatCard title="Total outstanding" :value="formatCurrency(stats.total_outstanding)" :icon="ExclamationCircleIcon" color="red" :loading="loading" />
    </div>

    <!-- Money position (accounts) -->
    <div v-if="money" class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5 mb-4 sm:mb-6">
      <StatCard title="Cash in hand" :value="inr(money.cash_in_hand)" :icon="BanknotesIcon" color="green" />
      <StatCard title="In the bank" :value="inr(money.bank)" :icon="BuildingLibraryIcon" color="blue" />
      <StatCard :title="money.month_profit >= 0 ? 'Profit this month' : 'Loss this month'" :value="inr(Math.abs(money.month_profit))" :icon="ArrowTrendingUpIcon" :color="money.month_profit >= 0 ? 'green' : 'red'" />
      <StatCard title="Customer money with staff" :value="inr(money.staff_held)" :icon="WalletIcon" :color="money.staff_held > 0 ? 'amber' : 'blue'" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
      <JobsByStatusChart :data="stats.jobs_by_status" :loading="loading" />
      <RecentJobCards :job-cards="stats.recent_job_cards" :loading="loading" />
      <RecentPayments :payments="stats.recent_payments" :loading="loading" />
    </div>
  </div>
</template>
