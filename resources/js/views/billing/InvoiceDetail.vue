<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { format } from 'date-fns'
import {
  DocumentArrowDownIcon,
  CurrencyDollarIcon,
  PaperAirplaneIcon,
  XMarkIcon,
} from '@heroicons/vue/20/solid'
import { invoicesApi } from '@/api/billing.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { Invoice } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import Textarea from '@/components/ui/Textarea.vue'
import { serviceTypeLabel } from '@/constants/serviceTypes'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const toast = useToast()

const invoiceId = Number(route.params.id)

const loading = ref(true)
const invoice = ref<Invoice | null>(null)

const showCancelDialog = ref(false)
const cancelling = ref(false)
const issuing = ref(false)

const statusVariants: Record<string, 'default' | 'success' | 'warning' | 'danger' | 'info'> = {
  draft: 'default',
  issued: 'info',
  partial: 'warning',
  paid: 'success',
  cancelled: 'danger',
}

const statusLabels: Record<string, string> = {
  draft: 'Draft',
  issued: 'Issued',
  partial: 'Partially Paid',
  paid: 'Paid',
  cancelled: 'Cancelled',
}

function formatCurrency(amount: number | string): string {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

function formatDate(dateString: string | null): string {
  if (!dateString) return '-'
  return format(new Date(dateString), 'dd MMM yyyy')
}

const canIssue = computed(() => invoice.value?.status === 'draft')
const canRecordPayment = computed(() =>
  invoice.value &&
  ['issued', 'partial'].includes(invoice.value.status) &&
  Number(invoice.value.balance_amount) > 0
)
const canCancel = computed(() =>
  invoice.value && ['draft', 'issued'].includes(invoice.value.status)
)

async function fetchInvoice() {
  loading.value = true
  try {
    const response = await invoicesApi.get(invoiceId)
    invoice.value = response.data.data
  } catch (err) {
    toast.apiError(err)
    router.push('/invoices')
  } finally {
    loading.value = false
  }
}

async function issueInvoice() {
  issuing.value = true
  try {
    await invoicesApi.issue(invoiceId)
    toast.success('Invoice Issued', 'Invoice has been issued successfully.')
    await fetchInvoice()
  } catch (err) {
    toast.apiError(err)
  } finally {
    issuing.value = false
  }
}

const cancelReason = ref('')

async function cancelInvoice() {
  if (!cancelReason.value.trim()) {
    toast.error('Reason Required', 'Enter a reason for cancelling this invoice.')
    return
  }
  cancelling.value = true
  try {
    await invoicesApi.cancel(invoiceId, cancelReason.value.trim())
    toast.success('Invoice Cancelled', 'Invoice has been cancelled.')
    await fetchInvoice()
    showCancelDialog.value = false
  } catch (err) {
    toast.apiError(err)
  } finally {
    cancelling.value = false
  }
}

async function downloadInvoice() {
  try {
    const response = await invoicesApi.download(invoiceId)
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${invoice.value?.invoice_number}.pdf`
    link.click()
    window.URL.revokeObjectURL(url)
  } catch (err) {
    toast.apiError(err)
  }
}

onMounted(fetchInvoice)
</script>

<template>
  <div>
    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <template v-else-if="invoice">
      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold text-gray-900">{{ invoice.invoice_number }}</h1>
            <Badge :variant="statusVariants[invoice.status] || 'default'">
              {{ statusLabels[invoice.status] || invoice.status }}
            </Badge>
          </div>
          <p class="text-gray-500 mt-1">
            {{ invoice.customer?.name }} | {{ formatDate(invoice.invoice_date) }}
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <Button variant="secondary" @click="downloadInvoice">
            <DocumentArrowDownIcon class="h-4 w-4 mr-1" />
            Download
          </Button>
          <Button
            v-if="canIssue && can('billing.invoices.update')"
            :loading="issuing"
            @click="issueInvoice"
          >
            <PaperAirplaneIcon class="h-4 w-4 mr-1" />
            Issue Invoice
          </Button>
          <Button
            v-if="canRecordPayment && can('billing.payments.create')"
            @click="router.push(`/payments/create?invoice_id=${invoice.id}`)"
          >
            <CurrencyDollarIcon class="h-4 w-4 mr-1" />
            Record Payment
          </Button>
          <Button
            v-if="canCancel && can('billing.invoices.update')"
            variant="danger"
            @click="showCancelDialog = true"
          >
            <XMarkIcon class="h-4 w-4 mr-1" />
            Cancel
          </Button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Customer & Job Card -->
          <Card>
            <template #header>
              <h3 class="text-lg font-semibold">Invoice Details</h3>
            </template>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <h4 class="text-sm font-medium text-gray-500 mb-2">Bill To</h4>
                <p class="font-medium">{{ invoice.customer?.name }}</p>
                <p class="text-sm text-gray-500">{{ invoice.customer?.phone }}</p>
                <p v-if="invoice.customer?.email" class="text-sm text-gray-500">
                  {{ invoice.customer.email }}
                </p>
              </div>
              <div>
                <h4 class="text-sm font-medium text-gray-500 mb-2">Vehicle</h4>
                <p class="font-medium">{{ invoice.job_card?.vehicle?.registration_no }}</p>
                <p class="text-sm text-gray-500">
                  {{ invoice.job_card?.vehicle?.make?.name }} {{ invoice.job_card?.vehicle?.model?.name }}
                </p>
              </div>
            </div>
          </Card>

          <!-- Items -->
          <Card :padding="false">
            <template #header>
              <h3 class="text-lg font-semibold">Items</h3>
            </template>

            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Description
                  </th>
                  <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                    Qty
                  </th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                    Rate
                  </th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                    Tax
                  </th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                    Amount
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200">
                <tr v-for="item in invoice.items" :key="item.id">
                  <td class="px-6 py-4 text-sm">
                    <div class="font-medium text-gray-900">{{ item.description }}</div>
                    <div v-if="item.hsn_code" class="text-xs text-gray-500">
                      HSN/SAC: {{ item.hsn_code }}
                    </div>
                  </td>
                  <td class="px-6 py-4 text-sm text-center">{{ item.quantity }}</td>
                  <td class="px-6 py-4 text-sm text-right">{{ formatCurrency(item.unit_price) }}</td>
                  <td class="px-6 py-4 text-sm text-right">{{ item.tax_rate }}%</td>
                  <td class="px-6 py-4 text-sm text-right font-medium">
                    {{ formatCurrency(item.total) }}
                  </td>
                </tr>
              </tbody>
              <tfoot class="bg-gray-50">
                <tr>
                  <td colspan="4" class="px-6 py-3 text-sm font-medium text-right">Subtotal</td>
                  <td class="px-6 py-3 text-sm font-medium text-right">
                    {{ formatCurrency(invoice.subtotal) }}
                  </td>
                </tr>
                <tr v-if="Number(invoice.discount) > 0">
                  <td colspan="4" class="px-6 py-2 text-sm text-right">Discount</td>
                  <td class="px-6 py-2 text-sm text-right text-red-600">
                    -{{ formatCurrency(invoice.discount) }}
                  </td>
                </tr>
                <tr>
                  <td colspan="4" class="px-6 py-2 text-sm text-right">Tax</td>
                  <td class="px-6 py-2 text-sm text-right">
                    {{ formatCurrency(invoice.total_tax) }}
                  </td>
                </tr>
                <tr class="font-bold">
                  <td colspan="4" class="px-6 py-3 text-base text-right">Total</td>
                  <td class="px-6 py-3 text-base text-right">
                    {{ formatCurrency(invoice.total_amount) }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </Card>

          <!-- Payments -->
          <Card v-if="invoice.payments && invoice.payments.length > 0" :padding="false">
            <template #header>
              <h3 class="text-lg font-semibold">Payments</h3>
            </template>

            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Payment #
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Date
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Method
                  </th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                    Amount
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200">
                <tr v-for="payment in invoice.payments" :key="payment.id">
                  <td class="px-6 py-4 text-sm font-medium">{{ payment.payment_number }}</td>
                  <td class="px-6 py-4 text-sm">{{ formatDate(payment.payment_date) }}</td>
                  <td class="px-6 py-4 text-sm capitalize">
                    {{ payment.method.replace('_', ' ') }}
                  </td>
                  <td class="px-6 py-4 text-sm text-right font-medium text-green-600">
                    {{ formatCurrency(payment.amount) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </Card>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
          <!-- Summary -->
          <Card>
            <template #header>
              <h3 class="text-lg font-semibold">Summary</h3>
            </template>

            <dl class="space-y-4">
              <div class="flex justify-between">
                <dt class="text-sm text-gray-500">Invoice Date</dt>
                <dd class="text-sm font-medium">{{ formatDate(invoice.invoice_date) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-sm text-gray-500">Due Date</dt>
                <dd class="text-sm font-medium">{{ formatDate(invoice.due_date) }}</dd>
              </div>
              <div class="flex justify-between pt-3 border-t">
                <dt class="text-sm text-gray-500">Total Amount</dt>
                <dd class="text-sm font-medium">{{ formatCurrency(invoice.total_amount) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-sm text-gray-500">Paid Amount</dt>
                <dd class="text-sm font-medium text-green-600">
                  {{ formatCurrency(invoice.paid_amount) }}
                </dd>
              </div>
              <div class="flex justify-between pt-3 border-t">
                <dt class="text-base font-medium text-gray-900">Balance Due</dt>
                <dd :class="[
                  'text-base font-bold',
                  Number(invoice.balance_amount) > 0 ? 'text-red-600' : 'text-green-600',
                ]">
                  {{ formatCurrency(invoice.balance_amount) }}
                </dd>
              </div>
            </dl>
          </Card>

          <!-- Job Card Link -->
          <Card v-if="invoice.job_card">
            <template #header>
              <h3 class="text-lg font-semibold">Related Job Card</h3>
            </template>

            <RouterLink
              :to="`/job-cards/${invoice.job_card.id}`"
              class="block p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors"
            >
              <p class="font-medium">{{ invoice.job_card.job_number }}</p>
              <p class="text-sm text-gray-500 capitalize">
                {{ serviceTypeLabel(invoice.job_card.service_type) }}
              </p>
            </RouterLink>
          </Card>
        </div>
      </div>

      <!-- Cancel Dialog -->
      <ConfirmDialog
        :open="showCancelDialog"
        title="Cancel Invoice"
        message="Are you sure you want to cancel this invoice? This action cannot be undone."
        confirm-text="Cancel Invoice"
        :loading="cancelling"
        @confirm="cancelInvoice"
        @cancel="showCancelDialog = false"
      >
        <Textarea v-model="cancelReason" label="Reason" required class="mt-4" />
      </ConfirmDialog>
    </template>
  </div>
</template>
