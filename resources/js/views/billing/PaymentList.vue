<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { format } from 'date-fns'
import { PlusIcon } from '@heroicons/vue/20/solid'
import { paymentsApi } from '@/api/billing.api'
import { useBranchStore } from '@/stores/branch.store'
import { usePagination } from '@/composables/usePagination'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { Payment } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Input from '@/components/ui/Input.vue'
import Badge from '@/components/ui/Badge.vue'
import type { Column } from '@/components/ui/DataTable.vue'

const router = useRouter()
const branchStore = useBranchStore()
const { can } = usePermissions()
const toast = useToast()
const pagination = usePagination()

const loading = ref(false)
const payments = ref<Payment[]>([])
const dateFrom = ref('')
const dateTo = ref('')

const columns: Column<Payment>[] = [
  { key: 'payment_number', label: 'Payment #' },
  { key: 'payment_date', label: 'Date' },
  { key: 'invoice.invoice_number', label: 'Invoice' },
  { key: 'customer.name', label: 'Customer' },
  { key: 'method', label: 'Method' },
  { key: 'amount', label: 'Amount', align: 'right' },
]

const methodLabels: Record<string, string> = {
  cash: 'Cash',
  card: 'Card',
  upi: 'UPI',
  bank_transfer: 'Bank Transfer',
  cheque: 'Cheque',
  wallet: 'Wallet',
  split: 'Split',
  customer_credit: 'Customer credit',
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

async function fetchPayments() {
  loading.value = true
  try {
    const response = await paymentsApi.list({
      date_from: dateFrom.value || undefined,
      date_to: dateTo.value || undefined,
      page: pagination.currentPage.value,
      per_page: pagination.perPage.value,
    })
    payments.value = response.data.data
    pagination.setMeta(response.data.meta)
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

function handleDateChange() {
  pagination.reset()
  fetchPayments()
}

function handlePageChange(page: number) {
  pagination.goToPage(page)
  fetchPayments()
}

function handleRowClick(payment: Payment) {
  if (payment.invoice) {
    router.push(`/invoices/${payment.invoice.id}`)
  }
}

onMounted(fetchPayments)

watch(() => pagination.currentPage.value, fetchPayments)
watch(() => branchStore.currentBranchId, fetchPayments)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4 sm:mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Payments</h1>
        <p class="text-gray-500 mt-1">View payment records</p>
      </div>
      <Button v-if="can('billing.payments.create')" @click="router.push('/payments/create')">
        <PlusIcon class="h-5 w-5 mr-1" />
        Record Payment
      </Button>
    </div>

    <DataTable
      :columns="columns"
      :data="payments"
      :loading="loading"
      :pagination="pagination.totalItems.value > 0 ? {
        current_page: pagination.currentPage.value,
        last_page: pagination.lastPage.value,
        from: pagination.from.value,
        to: pagination.to.value,
        total: pagination.totalItems.value,
        per_page: pagination.perPage.value,
      } : null"
      empty-title="No payments found"
      empty-description="Payment records will appear here."
      @row-click="handleRowClick"
      @page-change="handlePageChange"
    >
      <template #header>
        <div class="flex flex-wrap items-center gap-4">
          <Input
            v-model="dateFrom"
            type="date"
            label="From"
            @change="handleDateChange"
          />
          <Input
            v-model="dateTo"
            type="date"
            label="To"
            @change="handleDateChange"
          />
        </div>
      </template>

      <template #cell-payment_date="{ value }">
        {{ formatDate(value) }}
      </template>

      <template #cell-method="{ value }">
        <Badge size="sm">{{ methodLabels[value] || value }}</Badge>
      </template>

      <template #cell-amount="{ value }">
        <!-- Refunds and amounts moved to customer credit are negative -->
        <span :class="Number(value) < 0 ? 'text-red-700' : 'text-green-600'" class="font-medium">{{ formatCurrency(value) }}</span>
      </template>
    </DataTable>
  </div>
</template>
