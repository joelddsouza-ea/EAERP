<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { format } from 'date-fns'
import type { Payment } from '@/api/types'

interface Props {
  payments: Payment[]
  loading?: boolean
}

withDefaults(defineProps<Props>(), {
  loading: false,
})

function formatCurrency(amount: number | string): string {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

function formatDate(dateString: string): string {
  return format(new Date(dateString), 'dd MMM, h:mm a')
}

const methodLabels: Record<string, string> = {
  cash: 'Cash',
  card: 'Card',
  upi: 'UPI',
  bank_transfer: 'Bank Transfer',
  cheque: 'Cheque',
}
</script>

<template>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
      <h3 class="text-lg font-semibold text-gray-900">Recent Payments</h3>
      <RouterLink to="/payments" class="text-sm text-primary-600 hover:text-primary-700">
        View all
      </RouterLink>
    </div>

    <div v-if="loading" class="p-6">
      <div class="space-y-4">
        <div v-for="i in 5" :key="i" class="animate-pulse flex items-center gap-4">
          <div class="flex-1">
            <div class="h-4 bg-gray-200 rounded w-1/3 mb-2" />
            <div class="h-3 bg-gray-200 rounded w-1/4" />
          </div>
          <div class="h-5 w-24 bg-gray-200 rounded" />
        </div>
      </div>
    </div>

    <div v-else-if="payments.length === 0" class="p-6 text-center text-gray-500">
      No recent payments
    </div>

    <ul v-else class="divide-y divide-gray-200">
      <li v-for="payment in payments" :key="payment.id" class="px-6 py-4">
        <div class="flex items-center justify-between">
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-900 truncate">
              {{ payment.payment_number }}
            </p>
            <p class="text-sm text-gray-500">
              {{ methodLabels[payment.method] || payment.method }}
              <span class="mx-1">·</span>
              {{ formatDate(payment.payment_date) }}
            </p>
          </div>
          <p class="text-sm font-semibold text-green-600">
            {{ formatCurrency(payment.amount) }}
          </p>
        </div>
      </li>
    </ul>
  </div>
</template>
