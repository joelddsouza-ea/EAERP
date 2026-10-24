<script setup lang="ts">
/**
 * Job card, laid out in the order the job progresses: what to do next, the car,
 * who and where, intake, complaints, job sheets, estimate & customer, report, billing.
 */
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth.store'
import { useRoute, useRouter } from 'vue-router'
import { format } from 'date-fns'
import { PencilIcon, DocumentTextIcon, ClipboardDocumentCheckIcon } from '@heroicons/vue/20/solid'
import { jobCardsApi } from '@/api/workshop.api'
import { jobSheetsApi } from '@/api/jobSheets.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { JobCard, JobSheet } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'
import { jobServiceLabel } from '@/constants/serviceTypes'
import { statusLabel, statusVariant } from '@/constants/jobCardStatus'
import StatusActions from '@/components/jobcard/StatusActions.vue'
import StatusTimeline from '@/components/jobcard/StatusTimeline.vue'
import IntakePanel from '@/components/jobcard/IntakePanel.vue'
import AssignmentsCard from '@/components/jobcard/AssignmentsCard.vue'
import EstimateSummaryCard from '@/components/estimate/EstimateSummaryCard.vue'
import CustomerChannelCard from '@/components/estimate/CustomerChannelCard.vue'
import ServiceReportCard from '@/components/estimate/ServiceReportCard.vue'
import JobBillingCard from '@/components/billing/JobBillingCard.vue'
import JobStockCard from '@/components/jobcard/JobStockCard.vue'
import TripsCard from '@/components/jobcard/TripsCard.vue'
import OutsideWorkCard from '@/components/jobcard/OutsideWorkCard.vue'
import { technicianTimeApi, hm, type JobTime } from '@/api/technicianTime.api'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const authStore = useAuthStore()
// Technicians see their work, not who else is on the job or bay planning
const isTechnician = computed(() => authStore.hasAnyRole(['technician', 'body-shop-technician']))
const toast = useToast()

const jobCardId = Number(route.params.id)

const loading = ref(true)
const jobCard = ref<JobCard | null>(null)

// Customer cards refresh after a status change (it may prepare a WhatsApp message)
const estimateCard = ref<InstanceType<typeof EstimateSummaryCard> | null>(null)
const channelCard = ref<InstanceType<typeof CustomerChannelCard> | null>(null)

const billingCard = ref<InstanceType<typeof JobBillingCard> | null>(null)

async function onStatusChanged() {
  await fetchJobCard()
  estimateCard.value?.reload()
  channelCard.value?.reload()
  billingCard.value?.reload()
}

// Billing actions can move the job on (Invoiced, Gate Pass, Delivered) and prepare WhatsApp messages
async function onBillingChanged() {
  await fetchJobCard()
  statusActions.value?.reload()
  channelCard.value?.reload()
}

// Intake and assignments decide which moves are open: refresh the Next Step panel after them
const statusActions = ref<InstanceType<typeof StatusActions> | null>(null)

async function refreshAfterChange() {
  await fetchJobCard()
  statusActions.value?.reload()
}

// Working time on the job (owner / managers): in work, less waits and outside work, in shift hours
const workingTime = ref<JobTime | null>(null)
async function loadWorkingTime() {
  if (!can('workshop.technician-time.view')) return
  try {
    workingTime.value = (await technicianTimeApi.job(jobCardId)).data.data
  } catch {
    workingTime.value = null
  }
}
onMounted(loadWorkingTime)

// Job sheets (technician sheets, observation report, pre-delivery)
const sheets = ref<JobSheet[]>([])

function formatCurrency(amount: number | string): string {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
  }).format(Number(amount))
}

function formatDate(dateString: string | null): string {
  if (!dateString) return '-'
  return format(new Date(dateString), 'dd MMM yyyy, h:mm a')
}

const complaintLines = (text: string | null) => (text ?? '').split(/\r?\n/).map((l) => l.trim()).filter(Boolean)

async function fetchJobCard() {
  loading.value = !jobCard.value
  try {
    const [response, sheetsRes] = await Promise.all([
      jobCardsApi.get(jobCardId),
      can('workshop.job-sheets.view') || can('workshop.pre-delivery.view')
        ? jobSheetsApi.list(jobCardId)
        : Promise.resolve(null),
    ])
    jobCard.value = response.data.data
    sheets.value = sheetsRes?.data.data ?? []
  } catch (err) {
    toast.apiError(err)
    router.push('/job-cards')
  } finally {
    loading.value = false
  }
}

onMounted(fetchJobCard)
</script>

<template>
  <div>
    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <template v-else-if="jobCard">
      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-3 sm:gap-4 mb-4 rounded-2xl bg-white ring-1 ring-gray-200/80 shadow-sm p-4 sm:p-5 border-l-4 border-l-primary-600">
        <div>
          <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold text-gray-900">{{ jobCard.job_number }}</h1>
            <Badge :variant="statusVariant(jobCard.status)">
              {{ statusLabel(jobCard.status) }}
            </Badge>
          </div>
          <p class="text-gray-500 mt-1">
            {{ jobCard.vehicle?.registration_no }} -
            {{ jobCard.vehicle?.make?.name }} {{ jobCard.vehicle?.model?.name }}
          </p>
          <p class="mt-1 text-sm font-medium text-gray-800">
            {{ jobServiceLabel(jobCard) }}
            <template v-if="jobCard.oil_type"> · {{ jobCard.oil_type.name }} engine oil</template>
            <span v-else-if="jobCard.service_type === 'scheduled'" class="text-amber-700"> · engine oil type not chosen</span>
          </p>
        </div>

        <!-- Phones: the buttons share one row, each taking an equal part -->
        <div class="flex w-full sm:w-auto gap-2 [&>*]:flex-1 sm:[&>*]:flex-none">
          <Button v-if="sheets.length" variant="secondary" @click="router.push(`/job-cards/${jobCard.id}/sheet`)">
            <ClipboardDocumentCheckIcon class="h-4 w-4 mr-1" />
            Job Sheet
          </Button>
          <Button v-if="can('workshop.estimates.view')" variant="secondary" @click="router.push(`/job-cards/${jobCard.id}/estimate`)">
            <DocumentTextIcon class="h-4 w-4 mr-1" />
            Estimate
          </Button>
          <Button v-if="can('workshop.job-cards.update')" @click="router.push(`/job-cards/${jobCard.id}/edit`)">
            <PencilIcon class="h-4 w-4 mr-1" />
            Edit
          </Button>
        </div>
      </div>

      <!-- 1. What to do next -->
      <Card class="mb-4 sm:mb-6">
        <div class="flex flex-wrap items-start gap-4">
          <div class="min-w-[10rem]">
            <h3 class="text-lg font-semibold">Next Step</h3>
            <p v-if="jobCard.status === 'cancelled' && jobCard.cancel_reason" class="text-sm text-gray-600">
              Cancelled: {{ jobCard.cancel_reason }}
            </p>
          </div>
          <div class="flex-1 min-w-[16rem]">
            <StatusActions ref="statusActions" :job-card-id="jobCard.id" :status="jobCard.status" @changed="onStatusChanged" />
          </div>
        </div>
      </Card>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
        <!-- Main column, in the order the job progresses -->
        <div class="lg:col-span-2 space-y-6">
          <!-- 2. Customer & vehicle -->
          <Card>
            <template #header>
              <h3 class="text-lg font-semibold">Customer & Vehicle</h3>
            </template>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6">
              <div>
                <h4 class="text-sm font-medium text-gray-500 mb-2">Customer</h4>
                <p class="font-medium">{{ jobCard.customer?.name }}</p>
                <p class="text-sm text-gray-500">{{ jobCard.customer?.phone }}</p>
                <p v-if="jobCard.fleet_driver" class="mt-1 text-sm text-gray-700">
                  Driver: {{ jobCard.fleet_driver.name }} · <a :href="`tel:${jobCard.fleet_driver.phone}`" class="text-primary-700">{{ jobCard.fleet_driver.phone }}</a>
                </p>
              </div>
              <div>
                <h4 class="text-sm font-medium text-gray-500 mb-2">Vehicle</h4>
                <p class="font-medium">{{ jobCard.vehicle?.registration_no }}</p>
                <p class="text-sm text-gray-500">
                  {{ jobCard.vehicle?.make?.name }} {{ jobCard.vehicle?.model?.name }}
                  <span v-if="jobCard.vehicle?.year">({{ jobCard.vehicle.year }})</span>
                </p>
                <p v-if="jobCard.vehicle?.category" class="text-xs text-gray-500">{{ jobCard.vehicle.category.name }}</p>
              </div>
              <div>
                <h4 class="text-sm font-medium text-gray-500 mb-2">Service Advisor</h4>
                <p class="font-medium">{{ jobCard.service_advisor?.name || '-' }}</p>
                <p class="text-sm text-gray-500">In: {{ formatDate(jobCard.time_in) }}</p>
              </div>
            </div>
          </Card>

          <!-- 3. Who and where -->
          <AssignmentsCard v-if="!isTechnician" :job-card-id="jobCard.id" @changed="refreshAfterChange" />

          <!-- Pickup / drop / home visit -->
          <TripsCard
            v-if="!isTechnician || jobCard.service_mode === 'home'"
            :job-card-id="jobCard.id"
            :job-status="jobCard.status"
            @changed="onStatusChanged"
          />

          <!-- Washing / wheel alignment by outside partners; the customer's progress list on or off -->
          <OutsideWorkCard
            v-if="!isTechnician"
            :job-card-id="jobCard.id"
            :job-closed="['delivered', 'cancelled'].includes(jobCard.status)"
            :show-timeline="jobCard.show_customer_timeline !== false"
            @changed="refreshAfterChange"
          />

          <!-- 4. Intake: trial drive + inventory photos/videos -->
          <IntakePanel :job-card-id="jobCard.id" @changed="refreshAfterChange" />

          <!-- 5. Complaints -->
          <Card>
            <template #header>
              <h3 class="text-lg font-semibold">Customer Complaints</h3>
            </template>
            <ol v-if="complaintLines(jobCard.customer_complaints).length" class="list-decimal list-inside space-y-1 text-sm text-gray-900">
              <li v-for="(line, i) in complaintLines(jobCard.customer_complaints)" :key="i">{{ line }}</li>
            </ol>
            <p v-else class="text-sm text-gray-500">No complaint recorded.</p>
            <p class="mt-2 text-xs text-gray-500">Each complaint is a line under Additional Issues on the job sheet.</p>
            <dl v-if="jobCard.advisor_observations || jobCard.internal_notes" class="mt-4 space-y-3">
              <div v-if="jobCard.advisor_observations">
                <dt class="text-sm font-medium text-gray-500">Advisor Observations</dt>
                <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ jobCard.advisor_observations }}</dd>
              </div>
              <div v-if="jobCard.internal_notes">
                <dt class="text-sm font-medium text-gray-500">Internal Notes</dt>
                <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ jobCard.internal_notes }}</dd>
              </div>
            </dl>
          </Card>

          <!-- 6. Job sheets progress -->
          <Card v-if="sheets.length">
            <template #header>
              <h3 class="text-lg font-semibold">Job Sheets</h3>
            </template>

            <ul class="divide-y divide-gray-100">
              <li v-for="sheet in sheets" :key="sheet.id" class="py-3 first:pt-0 last:pb-0">
                <RouterLink
                  :to="`/job-cards/${jobCard.id}/sheet?sheet=${sheet.id}`"
                  class="flex items-center justify-between gap-4 hover:bg-gray-50 -mx-2 px-2 py-1 rounded"
                >
                  <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate">{{ sheet.name }}</p>
                    <p class="text-xs text-gray-500">
                      {{ sheet.progress?.resolved ?? 0 }} / {{ sheet.progress?.total ?? 0 }} done
                      <!-- Flags still open; not shown once the sheet is submitted -->
                      <span v-if="sheet.status !== 'completed' && sheet.progress?.needs_attention" class="text-amber-600">
                        · {{ sheet.progress.needs_attention }} need attention
                      </span>
                      <span v-if="sheet.override" class="text-amber-600"> · submitted with override</span>
                    </p>
                  </div>
                  <Badge :variant="sheet.status === 'completed' ? 'success' : sheet.status === 'in_progress' ? 'warning' : 'default'" size="sm">
                    {{ sheet.status === 'completed' ? 'Submitted' : sheet.status === 'in_progress' ? 'In progress' : 'Not started' }}
                  </Badge>
                </RouterLink>
                <div v-if="sheet.progress && sheet.progress.total" class="mt-2 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                  <div
                    class="h-full"
                    :class="sheet.status === 'completed' ? 'bg-green-500' : 'bg-primary-500'"
                    :style="{ width: `${sheet.status === 'completed' ? 100 : Math.round((sheet.progress.resolved / sheet.progress.total) * 100)}%` }"
                  />
                </div>
              </li>
            </ul>
          </Card>

          <!-- 7. Estimate & customer approval -->
          <EstimateSummaryCard v-if="can('workshop.estimates.view')" ref="estimateCard" :job-card-id="jobCard.id" />

          <!-- 8. Customer link & messages -->
          <CustomerChannelCard
            v-if="can('workshop.customer-links.manage')"
            ref="channelCard"
            :job-card-id="jobCard.id"
            :can-remind="jobCard.status === 'awaiting_approval'"
          />

          <!-- 9. Service report -->
          <ServiceReportCard v-if="can('workshop.reports.view')" :job-card-id="jobCard.id" />

          <!-- 10. Payments, final bill, gate pass, vehicle out -->
          <JobBillingCard
            v-if="can('billing.invoices.view') || can('billing.payments.create')"
            ref="billingCard"
            :job-card-id="jobCard.id"
            :job-status="jobCard.status"
            @changed="onBillingChanged"
          />

          <!-- Purchases module: plan kit from stock, parts cost -->
          <JobStockCard
            v-if="authStore.hasFeature('purchases') && can('purchases.stock.view')"
            :job-card-id="jobCard.id"
            :closed="['delivered', 'cancelled'].includes(jobCard.status)"
          />

          <!-- 11. Billing items -->
          <Card :padding="false">
            <template #header>
              <h3 class="text-lg font-semibold">Job Items</h3>
            </template>

            <div v-if="!jobCard.items || jobCard.items.length === 0" class="p-6 text-center text-gray-500">
              No items added yet.
            </div>

            <table v-else class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                  <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Qty</th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Rate</th>
                  <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200">
                <tr v-for="item in jobCard.items" :key="item.id">
                  <td class="px-6 py-4 text-sm">
                    <div class="font-medium text-gray-900">{{ item.description }}</div>
                    <div class="text-gray-500 text-xs capitalize">{{ item.type.replace('_', ' ') }}</div>
                  </td>
                  <td class="px-6 py-4 text-sm text-center">{{ item.quantity }}</td>
                  <td class="px-6 py-4 text-sm text-right">{{ formatCurrency(item.unit_price) }}</td>
                  <td class="px-6 py-4 text-sm text-right font-medium">{{ formatCurrency(item.total) }}</td>
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

            <dl class="space-y-3">
              <div class="flex justify-between">
                <dt class="text-sm text-gray-500">Service Type</dt>
                <dd class="text-sm font-medium">{{ jobServiceLabel(jobCard) }}</dd>
              </div>
              <div v-if="jobCard.oil_type || jobCard.service_type === 'scheduled'" class="flex justify-between">
                <dt class="text-sm text-gray-500">Engine Oil Type</dt>
                <dd class="text-sm font-medium" :class="jobCard.oil_type ? '' : 'text-amber-700'">
                  {{ jobCard.oil_type?.name ?? 'Not chosen' }}
                </dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-sm text-gray-500">Odometer</dt>
                <dd class="text-sm font-medium">{{ jobCard.km_reading != null ? `${Number(jobCard.km_reading).toLocaleString('en-IN')} km` : '-' }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-sm text-gray-500">Fuel</dt>
                <dd class="text-sm font-medium capitalize">{{ jobCard.fuel_level?.replace('_', ' ') ?? '-' }}</dd>
              </div>
              <div v-if="Number(jobCard.package_price) > 0" class="flex justify-between">
                <dt class="text-sm text-gray-500">Package Price</dt>
                <dd class="text-sm font-medium">{{ formatCurrency(jobCard.package_price ?? 0) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-sm text-gray-500">Estimated Amount</dt>
                <dd class="text-sm font-medium">{{ formatCurrency(jobCard.estimated_amount) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-sm text-gray-500">Total Amount</dt>
                <dd class="text-sm font-medium">{{ formatCurrency(jobCard.total_amount) }}</dd>
              </div>
              <div v-if="jobCard.estimated_delivery" class="flex justify-between">
                <dt class="text-sm text-gray-500">Promised</dt>
                <dd class="text-sm font-medium">{{ formatDate(jobCard.estimated_delivery) }}</dd>
              </div>
              <div v-if="workingTime" class="border-t border-gray-100 pt-3">
                <div class="flex justify-between">
                  <dt class="text-sm text-gray-500">Technician working time</dt>
                  <dd class="text-sm font-semibold">{{ hm(workingTime.working_minutes) }}</dd>
                </div>
                <p class="mt-1 text-xs text-gray-500">
                  In work {{ hm(workingTime.gross_minutes) }} · waiting approval {{ hm(workingTime.approval_minutes) }} ·
                  parts {{ hm(workingTime.parts_minutes) }} · outside {{ hm(workingTime.outside_minutes) }} (shift hours)
                </p>
              </div>
            </dl>
          </Card>

          <!-- Timeline -->
          <Card>
            <template #header>
              <h3 class="text-lg font-semibold">Timeline</h3>
            </template>

            <StatusTimeline v-if="jobCard.status_history?.length" :history="jobCard.status_history" />
            <p v-else class="text-sm text-gray-500">Created {{ formatDate(jobCard.created_at) }}</p>
          </Card>
        </div>
      </div>
    </template>
  </div>
</template>
