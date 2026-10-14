<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { paymentsApi, invoicesApi } from '@/api/billing.api'
import { useToast } from '@/composables/useToast'
import type { Invoice, Payment } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const preselectedInvoiceId = computed(() =>
  route.query.invoice_id ? Number(route.query.invoice_id) : null
)

const loading = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const invoice = ref<Invoice | null>(null)

const form = ref({
  invoice_id: preselectedInvoiceId.value,
  amount: null as number | null,
  payment_date: new Date().toISOString().split('T')[0],
  method: 'cash' as Payment['method'],
  reference_number: '',
  cheque_number: '',
  cheque_date: '',
  bank_name: '',
  notes: '',
})

const paymentMethodOptions = [
  { value: 'cash', label: 'Cash' },
  { value: 'card', label: 'Card' },
  { value: 'upi', label: 'UPI' },
  { value: 'bank_transfer', label: 'Bank Transfer' },
  { value: 'cheque', label: 'Cheque' },
  { value: 'wallet', label: 'Wallet' },
]

// Invoice search
const invoiceSearch = ref('')
const invoiceSearchResults = ref<Invoice[]>([])
const showInvoiceDropdown = ref(false)

// Delay so a click on a dropdown option registers before it closes
function hideInvoiceDropdownDelayed() {
  setTimeout(() => (showInvoiceDropdown.value = false), 200)
}

function formatCurrency(amount: number | string): string {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

async function searchInvoices(query: string) {
  if (!query || query.length < 2) {
    invoiceSearchResults.value = []
    return
  }
  try {
    const response = await invoicesApi.list({
      search: query,
      status: ['issued', 'partial'],
    })
    invoiceSearchResults.value = response.data.data.filter((i) => Number(i.balance_amount) > 0)
    showInvoiceDropdown.value = true
  } catch {
    invoiceSearchResults.value = []
  }
}

function selectInvoice(inv: Invoice) {
  invoice.value = inv
  form.value.invoice_id = inv.id
  form.value.amount = Number(inv.balance_amount)
  invoiceSearch.value = inv.invoice_number
  showInvoiceDropdown.value = false
}

async function fetchInvoice() {
  if (!preselectedInvoiceId.value) return

  loading.value = true
  try {
    const response = await invoicesApi.get(preselectedInvoiceId.value)
    selectInvoice(response.data.data)
  } catch (err) {
    toast.apiError(err)
    router.push('/payments')
  } finally {
    loading.value = false
  }
}

async function handleSubmit() {
  errors.value = {}

  // Validation
  if (!form.value.invoice_id) {
    errors.value.invoice_id = 'Invoice is required'
  }
  if (!form.value.amount || form.value.amount <= 0) {
    errors.value.amount = 'Amount must be greater than 0'
  }
  if (invoice.value && form.value.amount! > Number(invoice.value.balance_amount)) {
    errors.value.amount = `Amount cannot exceed balance of ${formatCurrency(invoice.value.balance_amount)}`
  }
  if (!form.value.payment_date) {
    errors.value.payment_date = 'Payment date is required'
  }

  if (Object.keys(errors.value).length > 0) {
    return
  }

  saving.value = true
  try {
    const { invoice_id, cheque_number, cheque_date, bank_name, ...payment } = form.value
    // Cheque fields are only accepted (and then required) for cheque payments
    const chequeDetails = payment.method === 'cheque' ? { cheque_number, cheque_date, bank_name } : {}
    await paymentsApi.create(invoice_id!, { ...payment, ...chequeDetails })
    toast.success('Payment Recorded', 'Payment has been recorded successfully.')
    router.push(`/invoices/${form.value.invoice_id}`)
  } catch (err: any) {
    if (err.response?.status === 422) {
      const validationErrors = err.response.data.errors || {}
      Object.keys(validationErrors).forEach((key) => {
        errors.value[key] = validationErrors[key][0]
      })
    } else {
      toast.apiError(err)
    }
  } finally {
    saving.value = false
  }
}

onMounted(fetchInvoice)

watch(invoiceSearch, (value) => {
  if (value !== invoice.value?.invoice_number) {
    searchInvoices(value)
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Record Payment</h1>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <form v-else @submit.prevent="handleSubmit" class="max-w-xl">
      <Card>
        <div class="space-y-6">
          <!-- Invoice Selection -->
          <div class="relative">
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Invoice <span class="text-red-500">*</span>
            </label>
            <input
              v-model="invoiceSearch"
              type="text"
              placeholder="Search for invoice..."
              :class="['input', errors.invoice_id ? 'input-error' : '']"
              :disabled="!!preselectedInvoiceId"
              @focus="showInvoiceDropdown = invoiceSearchResults.length > 0"
              @blur="hideInvoiceDropdownDelayed"
            />
            <p v-if="errors.invoice_id" class="mt-1 text-sm text-red-600">
              {{ errors.invoice_id }}
            </p>

            <div
              v-if="showInvoiceDropdown && invoiceSearchResults.length > 0"
              class="absolute z-10 mt-1 w-full bg-white rounded-lg shadow-lg border border-gray-200 max-h-60 overflow-y-auto"
            >
              <button
                v-for="inv in invoiceSearchResults"
                :key="inv.id"
                type="button"
                class="w-full px-4 py-2 text-left hover:bg-gray-50 text-sm"
                @click="selectInvoice(inv)"
              >
                <div class="flex justify-between">
                  <span class="font-medium">{{ inv.invoice_number }}</span>
                  <span class="text-red-600">{{ formatCurrency(inv.balance_amount) }}</span>
                </div>
                <div class="text-gray-500">{{ inv.customer?.name }}</div>
              </button>
            </div>
          </div>

          <!-- Invoice Summary -->
          <div v-if="invoice" class="p-4 bg-gray-50 rounded-lg">
            <div class="grid grid-cols-2 gap-4 text-sm">
              <div>
                <span class="text-gray-500">Customer:</span>
                <span class="ml-2 font-medium">{{ invoice.customer?.name }}</span>
              </div>
              <div>
                <span class="text-gray-500">Total:</span>
                <span class="ml-2 font-medium">{{ formatCurrency(invoice.total_amount) }}</span>
              </div>
              <div>
                <span class="text-gray-500">Paid:</span>
                <span class="ml-2 font-medium text-green-600">
                  {{ formatCurrency(invoice.paid_amount) }}
                </span>
              </div>
              <div>
                <span class="text-gray-500">Balance:</span>
                <span class="ml-2 font-medium text-red-600">
                  {{ formatCurrency(invoice.balance_amount) }}
                </span>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Input
              v-model.number="form.amount"
              type="number"
              label="Amount"
              :error="errors.amount"
              required
            />

            <Input
              v-model="form.payment_date"
              type="date"
              label="Payment Date"
              :error="errors.payment_date"
              required
            />

            <Select
              v-model="form.method"
              label="Payment Method"
              :options="paymentMethodOptions"
              :error="errors.method"
              required
            />

            <Input
              v-model="form.reference_number"
              label="Reference Number"
              placeholder="Transaction ID, Cheque No., etc."
              :error="errors.reference_number"
            />

            <template v-if="form.method === 'cheque'">
              <Input
                v-model="form.cheque_number"
                label="Cheque Number"
                :error="errors.cheque_number"
                required
              />

              <Input
                v-model="form.cheque_date"
                label="Cheque Date"
                type="date"
                :error="errors.cheque_date"
                required
              />

              <Input
                v-model="form.bank_name"
                label="Bank Name"
                :error="errors.bank_name"
              />
            </template>
          </div>

          <Textarea
            v-model="form.notes"
            label="Notes"
            :error="errors.notes"
            :rows="2"
          />
        </div>

        <template #footer>
          <div class="flex gap-3">
            <Button type="button" variant="secondary" @click="router.back()">
              Cancel
            </Button>
            <Button type="submit" :loading="saving">Record Payment</Button>
          </div>
        </template>
      </Card>
    </form>
  </div>
</template>
