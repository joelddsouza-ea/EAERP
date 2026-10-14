<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { format } from 'date-fns'
import {
  PencilIcon,
  UserIcon,
  WrenchIcon,
  DocumentTextIcon,
} from '@heroicons/vue/20/solid'
import { jobCardsApi, baysApi, techniciansApi } from '@/api/workshop.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { JobCard, Bay } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Select from '@/components/ui/Select.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'
import { serviceTypeLabel } from '@/constants/serviceTypes'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const toast = useToast()

const jobCardId = Number(route.params.id)

const loading = ref(true)
const jobCard = ref<JobCard | null>(null)

// Status update
const showStatusModal = ref(false)
const newStatus = ref('')
const statusNotes = ref('')
const updatingStatus = ref(false)

// Technician assignment
const showTechModal = ref(false)
const technicians = ref<any[]>([])
const selectedTechnicianId = ref<number | null>(null)
const assigningTech = ref(false)

// Bay assignment
const showBayModal = ref(false)
const availableBays = ref<Bay[]>([])
const selectedBayId = ref<number | null>(null)
const assigningBay = ref(false)

const statusOptions = [
  { value: 'received', label: 'Received' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'qc', label: 'Quality Check' },
  { value: 'ready', label: 'Ready for Delivery' },
  { value: 'delivered', label: 'Delivered' },
  { value: 'cancelled', label: 'Cancelled' },
]

const statusVariants: Record<string, 'default' | 'success' | 'warning' | 'danger' | 'info' | 'purple'> = {
  draft: 'default',
  received: 'info',
  in_progress: 'warning',
  waiting_parts: 'danger',
  qc: 'purple',
  ready: 'success',
  delivered: 'success',
  cancelled: 'danger',
}

const statusLabels: Record<string, string> = {
  draft: 'Draft',
  received: 'Received',
  in_progress: 'In Progress',
  waiting_parts: 'Waiting Parts',
  qc: 'QC',
  ready: 'Ready',
  delivered: 'Delivered',
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
  return format(new Date(dateString), 'dd MMM yyyy, h:mm a')
}

async function fetchJobCard() {
  loading.value = true
  try {
    const response = await jobCardsApi.get(jobCardId)
    jobCard.value = response.data.data
  } catch (err) {
    toast.apiError(err)
    router.push('/job-cards')
  } finally {
    loading.value = false
  }
}

async function openStatusModal() {
  newStatus.value = jobCard.value?.status || ''
  statusNotes.value = ''
  showStatusModal.value = true
}

async function updateStatus() {
  if (!newStatus.value) return

  updatingStatus.value = true
  try {
    await jobCardsApi.updateStatus(jobCardId, newStatus.value, statusNotes.value)
    toast.success('Status Updated', 'Job card status has been updated.')
    await fetchJobCard()
    showStatusModal.value = false
  } catch (err) {
    toast.apiError(err)
  } finally {
    updatingStatus.value = false
  }
}

async function openTechModal() {
  try {
    const response = await techniciansApi.available(jobCard.value?.branch_id)
    technicians.value = response.data.data
    selectedTechnicianId.value = jobCard.value?.technician_id || null
    showTechModal.value = true
  } catch (err) {
    toast.apiError(err)
  }
}

async function assignTechnician() {
  if (!selectedTechnicianId.value) return

  assigningTech.value = true
  try {
    await jobCardsApi.assignTechnician(jobCardId, selectedTechnicianId.value)
    toast.success('Technician Assigned', 'Technician has been assigned to this job card.')
    await fetchJobCard()
    showTechModal.value = false
  } catch (err) {
    toast.apiError(err)
  } finally {
    assigningTech.value = false
  }
}

async function openBayModal() {
  try {
    const response = await baysApi.available(jobCard.value?.branch_id)
    availableBays.value = response.data.data
    selectedBayId.value = jobCard.value?.bay_id || null
    showBayModal.value = true
  } catch (err) {
    toast.apiError(err)
  }
}

async function assignBay() {
  if (!selectedBayId.value) return

  assigningBay.value = true
  try {
    await jobCardsApi.assignBay(jobCardId, selectedBayId.value)
    toast.success('Bay Assigned', 'Bay has been assigned to this job card.')
    await fetchJobCard()
    showBayModal.value = false
  } catch (err) {
    toast.apiError(err)
  } finally {
    assigningBay.value = false
  }
}

const technicianOptions = computed(() =>
  technicians.value.map((t) => ({ value: t.id, label: t.name }))
)

const bayOptions = computed(() =>
  availableBays.value.map((b) => ({ value: b.id, label: b.name }))
)

onMounted(fetchJobCard)
</script>

<template>
  <div>
    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <template v-else-if="jobCard">
      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold text-gray-900">{{ jobCard.job_number }}</h1>
            <Badge :variant="statusVariants[jobCard.status] || 'default'">
              {{ statusLabels[jobCard.status] || jobCard.status }}
            </Badge>
          </div>
          <p class="text-gray-500 mt-1">
            {{ jobCard.vehicle?.registration_no }} -
            {{ jobCard.vehicle?.make?.name }} {{ jobCard.vehicle?.model?.name }}
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <Button v-if="can('workshop.job-cards.update')" variant="secondary" @click="openStatusModal">
            Update Status
          </Button>
          <Button v-if="can('workshop.job-cards.update')" @click="router.push(`/job-cards/${jobCard.id}/edit`)">
            <PencilIcon class="h-4 w-4 mr-1" />
            Edit
          </Button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Info -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Customer & Vehicle -->
          <Card>
            <template #header>
              <h3 class="text-lg font-semibold">Customer & Vehicle</h3>
            </template>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <h4 class="text-sm font-medium text-gray-500 mb-2">Customer</h4>
                <p class="font-medium">{{ jobCard.customer?.name }}</p>
                <p class="text-sm text-gray-500">{{ jobCard.customer?.phone }}</p>
              </div>
              <div>
                <h4 class="text-sm font-medium text-gray-500 mb-2">Vehicle</h4>
                <p class="font-medium">{{ jobCard.vehicle?.registration_no }}</p>
                <p class="text-sm text-gray-500">
                  {{ jobCard.vehicle?.make?.name }} {{ jobCard.vehicle?.model?.name }}
                  <span v-if="jobCard.vehicle?.year">({{ jobCard.vehicle.year }})</span>
                </p>
              </div>
            </div>
          </Card>

          <!-- Complaint & Work -->
          <Card>
            <template #header>
              <h3 class="text-lg font-semibold">Work Details</h3>
            </template>

            <dl class="space-y-4">
              <div>
                <dt class="text-sm font-medium text-gray-500">Customer Complaint</dt>
                <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">
                  {{ jobCard.customer_complaints || 'No complaint recorded' }}
                </dd>
              </div>
              <div v-if="jobCard.advisor_observations">
                <dt class="text-sm font-medium text-gray-500">Advisor Observations</dt>
                <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">
                  {{ jobCard.advisor_observations }}
                </dd>
              </div>
              <div v-if="jobCard.internal_notes">
                <dt class="text-sm font-medium text-gray-500">Internal Notes</dt>
                <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">
                  {{ jobCard.internal_notes }}
                </dd>
              </div>
            </dl>
          </Card>

          <!-- Items -->
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
                <dd class="text-sm font-medium">{{ serviceTypeLabel(jobCard.service_type) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-sm text-gray-500">Priority</dt>
                <dd class="text-sm font-medium capitalize">{{ jobCard.priority }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-sm text-gray-500">Estimated Amount</dt>
                <dd class="text-sm font-medium">{{ formatCurrency(jobCard.estimated_amount) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-sm text-gray-500">Total Amount</dt>
                <dd class="text-sm font-medium">{{ formatCurrency(jobCard.total_amount) }}</dd>
              </div>
            </dl>
          </Card>

          <!-- Assignments -->
          <Card>
            <template #header>
              <h3 class="text-lg font-semibold">Assignments</h3>
            </template>

            <dl class="space-y-4">
              <div>
                <dt class="text-sm text-gray-500 mb-1">Service Advisor</dt>
                <dd class="text-sm font-medium">
                  {{ jobCard.service_advisor?.name || '-' }}
                </dd>
              </div>

              <div>
                <dt class="text-sm text-gray-500 mb-1">Technician</dt>
                <dd class="flex items-center justify-between">
                  <span class="text-sm font-medium">
                    {{ jobCard.technician?.name || 'Not assigned' }}
                  </span>
                  <Button v-if="can('workshop.job-cards.update')" size="sm" variant="ghost" @click="openTechModal">
                    <UserIcon class="h-4 w-4" />
                  </Button>
                </dd>
              </div>

              <div>
                <dt class="text-sm text-gray-500 mb-1">Bay</dt>
                <dd class="flex items-center justify-between">
                  <span class="text-sm font-medium">
                    {{ jobCard.bay?.name || 'Not assigned' }}
                  </span>
                  <Button v-if="can('workshop.job-cards.update')" size="sm" variant="ghost" @click="openBayModal">
                    <WrenchIcon class="h-4 w-4" />
                  </Button>
                </dd>
              </div>
            </dl>
          </Card>

          <!-- Timeline -->
          <Card>
            <template #header>
              <h3 class="text-lg font-semibold">Timeline</h3>
            </template>

            <dl class="space-y-3 text-sm">
              <div class="flex justify-between">
                <dt class="text-gray-500">Created</dt>
                <dd>{{ formatDate(jobCard.created_at) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500">Received</dt>
                <dd>{{ formatDate(jobCard.time_in) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500">Started</dt>
                <dd>{{ formatDate(jobCard.work_started_at) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500">Completed</dt>
                <dd>{{ formatDate(jobCard.work_completed_at) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500">Delivered</dt>
                <dd>{{ formatDate(jobCard.time_out) }}</dd>
              </div>
            </dl>
          </Card>

          <!-- Actions -->
          <Card>
            <div class="space-y-3">
              <Button
                v-if="can('billing.invoices.create') && jobCard.status === 'ready'"
                block
                @click="router.push(`/invoices/create?job_card_id=${jobCard.id}`)"
              >
                <DocumentTextIcon class="h-4 w-4 mr-2" />
                Create Invoice
              </Button>
            </div>
          </Card>
        </div>
      </div>

      <!-- Status Modal -->
      <Modal :open="showStatusModal" title="Update Status" @close="showStatusModal = false">
        <div class="space-y-4">
          <Select
            v-model="newStatus"
            label="New Status"
            :options="statusOptions"
            required
          />
          <div>
            <label class="label">Notes (optional)</label>
            <textarea
              v-model="statusNotes"
              class="input"
              rows="3"
              placeholder="Add any notes about this status change..."
            />
          </div>
        </div>

        <template #footer>
          <Button variant="secondary" @click="showStatusModal = false">Cancel</Button>
          <Button :loading="updatingStatus" @click="updateStatus">Update Status</Button>
        </template>
      </Modal>

      <!-- Technician Modal -->
      <Modal :open="showTechModal" title="Assign Technician" @close="showTechModal = false">
        <Select
          v-model="selectedTechnicianId"
          label="Select Technician"
          :options="technicianOptions"
          placeholder="Choose a technician"
        />

        <template #footer>
          <Button variant="secondary" @click="showTechModal = false">Cancel</Button>
          <Button :loading="assigningTech" :disabled="!selectedTechnicianId" @click="assignTechnician">
            Assign
          </Button>
        </template>
      </Modal>

      <!-- Bay Modal -->
      <Modal :open="showBayModal" title="Assign Bay" @close="showBayModal = false">
        <Select
          v-model="selectedBayId"
          label="Select Bay"
          :options="bayOptions"
          placeholder="Choose a bay"
        />

        <template #footer>
          <Button variant="secondary" @click="showBayModal = false">Cancel</Button>
          <Button :loading="assigningBay" :disabled="!selectedBayId" @click="assignBay">
            Assign
          </Button>
        </template>
      </Modal>
    </template>
  </div>
</template>
