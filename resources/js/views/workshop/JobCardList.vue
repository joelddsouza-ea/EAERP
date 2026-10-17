<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { PlusIcon } from '@heroicons/vue/20/solid'
import { jobCardsApi } from '@/api/workshop.api'
import { useBranchStore } from '@/stores/branch.store'
import { usePagination } from '@/composables/usePagination'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { JobCard, JobCardStatus } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import DataTable from '@/components/ui/DataTable.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'
import type { Column } from '@/components/ui/DataTable.vue'
import { jobServiceLabel } from '@/constants/serviceTypes'
import { statusLabel, statusVariant, statusFilterOptions } from '@/constants/jobCardStatus'

const router = useRouter()
const route = useRoute()
const branchStore = useBranchStore()
const { can } = usePermissions()
const toast = useToast()
const pagination = usePagination()

const loading = ref(false)
const jobCards = ref<JobCard[]>([])
const search = ref('')
// Links such as /job-cards?status=ready_for_delivery (My Day) open filtered
const statusFilter = ref<string>((route.query.status as string) || '')

const columns: Column<JobCard>[] = [
  { key: 'job_number', label: 'Job Card #' },
  { key: 'vehicle.registration_no', label: 'Vehicle' },
  { key: 'customer.name', label: 'Customer' },
  { key: 'service_type', label: 'Type' },
  { key: 'status', label: 'Status' },
  { key: 'total_amount', label: 'Amount', align: 'right' },
]

const statusOptions = [{ value: '', label: 'All Status' }, ...statusFilterOptions]

function formatCurrency(amount: number | string): string {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

async function fetchJobCards() {
  loading.value = true
  try {
    const response = await jobCardsApi.list({
      search: search.value || undefined,
      status: statusFilter.value as JobCardStatus || undefined,
      page: pagination.currentPage.value,
      per_page: pagination.perPage.value,
    })
    jobCards.value = response.data.data
    pagination.setMeta(response.data.meta)
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

function handleSearch(value: string) {
  search.value = value
  pagination.reset()
  fetchJobCards()
}

function handleStatusChange() {
  pagination.reset()
  fetchJobCards()
}

function handlePageChange(page: number) {
  pagination.goToPage(page)
  fetchJobCards()
}

function handleRowClick(jobCard: JobCard) {
  router.push(`/job-cards/${jobCard.id}`)
}

onMounted(fetchJobCards)

watch(() => pagination.currentPage.value, fetchJobCards)
watch(() => branchStore.currentBranchId, fetchJobCards)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4 sm:mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Job Cards</h1>
        <p class="text-gray-500 mt-1">Manage workshop job cards</p>
      </div>
      <Button v-if="can('workshop.job-cards.create')" @click="router.push('/job-cards/create')">
        <PlusIcon class="h-5 w-5 mr-1" />
        New Job Card
      </Button>
    </div>

    <DataTable
      :columns="columns"
      :data="jobCards"
      :loading="loading"
      :pagination="pagination.totalItems.value > 0 ? {
        current_page: pagination.currentPage.value,
        last_page: pagination.lastPage.value,
        from: pagination.from.value,
        to: pagination.to.value,
        total: pagination.totalItems.value,
        per_page: pagination.perPage.value,
      } : null"
      empty-title="No job cards found"
      empty-description="Get started by creating your first job card."
      @row-click="handleRowClick"
      @page-change="handlePageChange"
    >
      <template #header>
        <div class="flex flex-wrap items-center gap-4">
          <div class="w-full sm:w-64">
            <SearchInput
              v-model="search"
              placeholder="Search job cards..."
              @search="handleSearch"
            />
          </div>
          <div class="w-full sm:w-48">
            <Select
              v-model="statusFilter"
              :options="statusOptions"
              @update:model-value="handleStatusChange"
            />
          </div>
        </div>
      </template>

      <template #cell-service_type="{ row }">
        <span>{{ jobServiceLabel(row) }}</span>
      </template>

      <template #cell-status="{ value }">
        <Badge :variant="statusVariant(value)" size="sm">
          {{ statusLabel(value) }}
        </Badge>
      </template>

      <template #cell-total_amount="{ value }">
        {{ formatCurrency(value || 0) }}
      </template>

      <template #empty>
        <Button v-if="can('workshop.job-cards.create')" @click="router.push('/job-cards/create')">
          <PlusIcon class="h-5 w-5 mr-1" />
          New Job Card
        </Button>
      </template>
    </DataTable>
  </div>
</template>
