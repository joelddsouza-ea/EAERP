<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { format } from 'date-fns'
import { PlusIcon } from '@heroicons/vue/20/solid'
import { invoicesApi } from '@/api/billing.api'
import { useBranchStore } from '@/stores/branch.store'
import { usePagination } from '@/composables/usePagination'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { Invoice } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import DataTable from '@/components/ui/DataTable.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import Select from '@/components/ui/Select.vue'
import Badge from '@/components/ui/Badge.vue'
import type { Column } from '@/components/ui/DataTable.vue'

const router = useRouter()
const branchStore = useBranchStore()
const { can } = usePermissions()
const toast = useToast()
const pagination = usePagination()

const loading = ref(false)
const invoices = ref<Invoice[]>([])
const search = ref('')
const statusFilter = ref<string>('')

const columns: Column<Invoice>[] = [
  { key: 'invoice_number', label: 'Invoice #' },
  { key: 'invoice_date', label: 'Date' },
  { key: 'customer.name', label: 'Customer' },
  { key: 'total_amount', label: 'Total', align: 'right' },
  { key: 'paid_amount', label: 'Paid', align: 'right' },
  { key: 'balance_amount', label: 'Balance', align: 'right' },
  { key: 'status', label: 'Status' },
]

const statusOptions = [
  { value: '', label: 'All Status' },
  { value: 'draft', label: 'Draft' },
  { value: 'issued', label: 'Issued' },
  { value: 'partial', label: 'Partially Paid' },
  { value: 'paid', label: 'Paid' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'approved', label: 'Proforma approved' },
  { value: 'closed', label: 'Closed on proforma' },
]

const statusVariants: Record<string, 'default' | 'success' | 'warning' | 'danger' | 'info'> = {
  draft: 'default',
  issued: 'info',
  partial: 'warning',
  paid: 'success',
  cancelled: 'danger',
  // Proforma (final bill of a job)
  approved: 'info',
  converted: 'default',
  closed: 'success',
}

const statusLabels: Record<string, string> = {
  draft: 'Draft',
  issued: 'Issued',
  partial: 'Partial',
  paid: 'Paid',
  cancelled: 'Cancelled',
  approved: 'Proforma approved',
  converted: 'Proforma → tax invoice',
  closed: 'Closed on proforma',
}

function formatCurrency(amount: number | string): string {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

function formatDate(dateString: string): string {
  return format(new Date(dateString), 'dd MMM yyyy')
}

async function fetchInvoices() {
  loading.value = true
  try {
    const response = await invoicesApi.list({
      search: search.value || undefined,
      status: statusFilter.value || undefined,
      page: pagination.currentPage.value,
      per_page: pagination.perPage.value,
    })
    invoices.value = response.data.data
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
  fetchInvoices()
}

function handleStatusChange() {
  pagination.reset()
  fetchInvoices()
}

function handlePageChange(page: number) {
  pagination.goToPage(page)
  fetchInvoices()
}

function handleRowClick(invoice: Invoice) {
  router.push(`/invoices/${invoice.id}`)
}

onMounted(fetchInvoices)

watch(() => pagination.currentPage.value, fetchInvoices)
watch(() => branchStore.currentBranchId, fetchInvoices)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4 sm:mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Invoices</h1>
        <p class="text-gray-500 mt-1">Manage customer invoices</p>
      </div>
      <Button v-if="can('billing.invoices.create')" @click="router.push('/invoices/create')">
        <PlusIcon class="h-5 w-5 mr-1" />
        New Invoice
      </Button>
    </div>

    <DataTable
      :columns="columns"
      :data="invoices"
      :loading="loading"
      :pagination="pagination.totalItems.value > 0 ? {
        current_page: pagination.currentPage.value,
        last_page: pagination.lastPage.value,
        from: pagination.from.value,
        to: pagination.to.value,
        total: pagination.totalItems.value,
        per_page: pagination.perPage.value,
      } : null"
      empty-title="No invoices found"
      empty-description="Invoices will appear here once created."
      @row-click="handleRowClick"
      @page-change="handlePageChange"
    >
      <template #header>
        <div class="flex flex-wrap items-center gap-4">
          <div class="w-full sm:w-64">
            <SearchInput
              v-model="search"
              placeholder="Search invoices..."
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

      <template #cell-invoice_date="{ value }">
        {{ formatDate(value) }}
      </template>

      <template #cell-total_amount="{ value }">
        {{ formatCurrency(value) }}
      </template>

      <template #cell-paid_amount="{ value }">
        <span class="text-green-600">{{ formatCurrency(value) }}</span>
      </template>

      <template #cell-balance_amount="{ value }">
        <span :class="value > 0 ? 'text-red-600 font-medium' : ''">
          {{ formatCurrency(value) }}
        </span>
      </template>

      <template #cell-status="{ value }">
        <Badge :variant="statusVariants[value] || 'default'" size="sm">
          {{ statusLabels[value] || value }}
        </Badge>
      </template>
    </DataTable>
  </div>
</template>
