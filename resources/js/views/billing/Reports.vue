<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { format } from 'date-fns'
import { reportsApi } from '@/api/billing.api'
import { useBranchStore } from '@/stores/branch.store'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Spinner from '@/components/ui/Spinner.vue'

const branchStore = useBranchStore()
const toast = useToast()

const selectedDate = ref(new Date().toISOString().split('T')[0])
const loading = ref(false)

interface DailyCollectionData {
  date: string
  total: number
  by_method: Record<string, number>
  payments: any[]
}

interface OutstandingData {
  total: number
  by_customer: { customer: any; amount: number }[]
  invoices: any[]
}

const dailyCollection = ref<DailyCollectionData | null>(null)
const outstanding = ref<OutstandingData | null>(null)

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

const methodLabels: Record<string, string> = {
  cash: 'Cash',
  card: 'Card',
  upi: 'UPI',
  bank_transfer: 'Bank Transfer',
  cheque: 'Cheque',
}

async function fetchDailyCollection() {
  loading.value = true
  try {
    const response = await reportsApi.dailyCollection({
      date: selectedDate.value,
      branch_id: branchStore.currentBranchId || undefined,
    })
    dailyCollection.value = response.data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

async function fetchOutstanding() {
  try {
    const response = await reportsApi.outstanding({
      branch_id: branchStore.currentBranchId || undefined,
    })
    outstanding.value = response.data.data
  } catch (err) {
    toast.apiError(err)
  }
}

async function fetchReports() {
  await Promise.all([fetchDailyCollection(), fetchOutstanding()])
}

onMounted(fetchReports)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Reports</h1>
      <p class="text-gray-500 mt-1">View financial reports and summaries</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Daily Collection -->
      <Card>
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold">Daily Collection</h3>
            <div class="flex items-center gap-2">
              <Input
                v-model="selectedDate"
                type="date"
                @change="fetchDailyCollection"
              />
            </div>
          </div>
        </template>

        <div v-if="loading" class="flex justify-center py-8">
          <Spinner />
        </div>

        <div v-else-if="dailyCollection">
          <!-- Total -->
          <div class="text-center mb-6">
            <p class="text-sm text-gray-500">Total Collection</p>
            <p class="text-3xl font-bold text-green-600">
              {{ formatCurrency(dailyCollection.total) }}
            </p>
            <p class="text-sm text-gray-400">{{ formatDate(selectedDate) }}</p>
          </div>

          <!-- By Payment Method -->
          <div class="space-y-3">
            <h4 class="text-sm font-medium text-gray-700">By Payment Method</h4>
            <div
              v-for="(amount, method) in dailyCollection.by_method"
              :key="method"
              class="flex justify-between items-center py-2 border-b border-gray-100"
            >
              <span class="text-sm">{{ methodLabels[method] || method }}</span>
              <span class="text-sm font-medium">{{ formatCurrency(amount) }}</span>
            </div>
          </div>

          <!-- Recent Payments -->
          <div v-if="dailyCollection.payments.length > 0" class="mt-6">
            <h4 class="text-sm font-medium text-gray-700 mb-3">Payments</h4>
            <div class="space-y-2 max-h-48 overflow-y-auto">
              <div
                v-for="payment in dailyCollection.payments"
                :key="payment.id"
                class="flex justify-between items-center py-2 text-sm"
              >
                <div>
                  <span class="font-medium">{{ payment.payment_number }}</span>
                  <span class="text-gray-500 ml-2">{{ payment.customer?.name }}</span>
                </div>
                <span class="font-medium text-green-600">
                  {{ formatCurrency(payment.amount) }}
                </span>
              </div>
            </div>
          </div>

          <div v-else class="text-center py-6 text-gray-500">
            No payments recorded for this date.
          </div>
        </div>
      </Card>

      <!-- Outstanding -->
      <Card>
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold">Outstanding</h3>
            <Button size="sm" variant="secondary" @click="fetchOutstanding">
              Refresh
            </Button>
          </div>
        </template>

        <div v-if="outstanding">
          <!-- Total -->
          <div class="text-center mb-6">
            <p class="text-sm text-gray-500">Total Outstanding</p>
            <p class="text-3xl font-bold text-red-600">
              {{ formatCurrency(outstanding.total) }}
            </p>
          </div>

          <!-- By Customer -->
          <div v-if="outstanding.by_customer.length > 0">
            <h4 class="text-sm font-medium text-gray-700 mb-3">By Customer</h4>
            <div class="space-y-2 max-h-64 overflow-y-auto">
              <RouterLink
                v-for="item in outstanding.by_customer"
                :key="item.customer.id"
                :to="`/customers/${item.customer.id}`"
                class="flex justify-between items-center py-2 px-3 rounded-lg hover:bg-gray-50"
              >
                <div>
                  <span class="font-medium text-sm">{{ item.customer.name }}</span>
                  <span class="text-xs text-gray-500 ml-2">{{ item.customer.phone }}</span>
                </div>
                <span class="font-medium text-red-600 text-sm">
                  {{ formatCurrency(item.amount) }}
                </span>
              </RouterLink>
            </div>
          </div>

          <div v-else class="text-center py-6 text-gray-500">
            No outstanding payments.
          </div>
        </div>

        <div v-else class="flex justify-center py-8">
          <Spinner />
        </div>
      </Card>
    </div>
  </div>
</template>
