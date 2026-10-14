<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { jobCardsApi, customersApi } from '@/api/workshop.api'
import { useToast } from '@/composables/useToast'
import type { Customer, Vehicle, JobCard, ServiceType, FuelLevel } from '@/api/types'
import { serviceTypeOptions } from '@/constants/serviceTypes'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const jobCardId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEditing = computed(() => !!jobCardId.value)

const loading = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const form = ref({
  customer_id: null as number | null,
  vehicle_id: null as number | null,
  service_type: 'scheduled' as ServiceType,
  priority: 'normal' as JobCard['priority'],
  km_reading: null as number | null,
  fuel_level: null as FuelLevel | null,
  customer_complaints: '',
  estimated_delivery: '',
})

// Customer search
const customerSearch = ref('')
const customerSearchResults = ref<Customer[]>([])
const selectedCustomer = ref<Customer | null>(null)
const showCustomerDropdown = ref(false)

// Delay so a click on a dropdown option registers before it closes
function hideCustomerDropdownDelayed() {
  setTimeout(() => (showCustomerDropdown.value = false), 200)
}

// Vehicle selection
const customerVehicles = ref<Vehicle[]>([])
const selectedVehicle = ref<Vehicle | null>(null)

const fuelLevelOptions = [
  { value: 'empty', label: 'Empty' },
  { value: 'quarter', label: '1/4' },
  { value: 'half', label: '1/2' },
  { value: 'three_quarter', label: '3/4' },
  { value: 'full', label: 'Full' },
]

const priorityOptions = [
  { value: 'low', label: 'Low' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'High' },
  { value: 'urgent', label: 'Urgent' },
]

const vehicleOptions = computed(() =>
  customerVehicles.value.map((v) => ({
    value: v.id,
    label: `${v.registration_no} - ${v.make?.name ?? ''} ${v.model?.name ?? ''}`,
  }))
)

async function searchCustomers(query: string) {
  if (!query || query.length < 2) {
    customerSearchResults.value = []
    return
  }
  try {
    const response = await customersApi.search(query)
    customerSearchResults.value = response.data.data
    showCustomerDropdown.value = true
  } catch {
    customerSearchResults.value = []
  }
}

async function selectCustomer(customer: Customer) {
  selectedCustomer.value = customer
  form.value.customer_id = customer.id
  form.value.vehicle_id = null
  selectedVehicle.value = null
  customerSearch.value = customer.name
  showCustomerDropdown.value = false

  // Fetch customer vehicles
  try {
    const response = await customersApi.vehicles(customer.id)
    customerVehicles.value = response.data.data
  } catch {
    customerVehicles.value = []
  }
}

function selectVehicle(vehicleId: number | null) {
  if (!vehicleId) {
    selectedVehicle.value = null
    return
  }
  selectedVehicle.value = customerVehicles.value.find((v) => v.id === vehicleId) || null
}

async function fetchJobCard() {
  if (!jobCardId.value) return

  loading.value = true
  try {
    const response = await jobCardsApi.get(jobCardId.value)
    const jobCard = response.data.data

    form.value = {
      customer_id: jobCard.customer_id,
      vehicle_id: jobCard.vehicle_id,
      service_type: jobCard.service_type || 'scheduled',
      priority: jobCard.priority,
      km_reading: jobCard.km_reading,
      fuel_level: jobCard.fuel_level,
      customer_complaints: jobCard.customer_complaints || '',
      // datetime-local inputs need "YYYY-MM-DDTHH:mm"
      estimated_delivery: jobCard.estimated_delivery?.slice(0, 16) || '',
    }

    if (jobCard.customer) {
      await selectCustomer(jobCard.customer)
      form.value.vehicle_id = jobCard.vehicle_id
      selectVehicle(jobCard.vehicle_id)
    }
  } catch (err) {
    toast.apiError(err)
    router.push('/job-cards')
  } finally {
    loading.value = false
  }
}

async function handleSubmit() {
  errors.value = {}

  // Validation
  if (!form.value.customer_id) {
    errors.value.customer_id = 'Customer is required'
  }
  if (!form.value.vehicle_id) {
    errors.value.vehicle_id = 'Vehicle is required'
  }

  if (Object.keys(errors.value).length > 0) {
    return
  }

  saving.value = true
  try {
    // Branch comes from the X-Branch-Id header; customer and vehicle are fixed after creation
    const { customer_id, vehicle_id, ...editable } = form.value
    const payload = {
      ...editable,
      estimated_delivery: editable.estimated_delivery || null,
    }

    if (isEditing.value) {
      await jobCardsApi.update(jobCardId.value!, payload)
      toast.success('Job Card Updated', 'Job card has been updated.')
    } else {
      const response = await jobCardsApi.create({ ...payload, customer_id, vehicle_id })
      toast.success('Job Card Created', 'New job card has been created.')
      router.push(`/job-cards/${response.data.data.id}`)
      return
    }
    router.push('/job-cards')
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

onMounted(fetchJobCard)

watch(customerSearch, (value) => {
  if (value !== selectedCustomer.value?.name) {
    searchCustomers(value)
  }
})

watch(
  () => form.value.vehicle_id,
  (id) => selectVehicle(id)
)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">
        {{ isEditing ? 'Edit Job Card' : 'New Job Card' }}
      </h1>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <form v-else @submit.prevent="handleSubmit" class="max-w-3xl">
      <Card>
        <div class="space-y-6">
          <!-- Customer Selection -->
          <div class="relative">
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Customer <span class="text-red-500">*</span>
            </label>
            <input
              v-model="customerSearch"
              type="text"
              placeholder="Search for customer..."
              :class="['input', errors.customer_id ? 'input-error' : '']"
              :disabled="isEditing"
              @focus="showCustomerDropdown = customerSearchResults.length > 0"
              @blur="hideCustomerDropdownDelayed"
            />
            <p v-if="errors.customer_id" class="mt-1 text-sm text-red-600">
              {{ errors.customer_id }}
            </p>

            <div
              v-if="showCustomerDropdown && customerSearchResults.length > 0"
              class="absolute z-10 mt-1 w-full bg-white rounded-lg shadow-lg border border-gray-200 max-h-60 overflow-y-auto"
            >
              <button
                v-for="customer in customerSearchResults"
                :key="customer.id"
                type="button"
                class="w-full px-4 py-2 text-left hover:bg-gray-50 text-sm"
                @click="selectCustomer(customer)"
              >
                <div class="font-medium">{{ customer.name }}</div>
                <div class="text-gray-500">{{ customer.phone }}</div>
              </button>
            </div>
          </div>

          <!-- Vehicle Selection -->
          <Select
            v-model="form.vehicle_id"
            label="Vehicle"
            :options="vehicleOptions"
            :error="errors.vehicle_id"
            :disabled="!form.customer_id || customerVehicles.length === 0"
            placeholder="Select vehicle"
            required
          />

          <div v-if="form.customer_id && customerVehicles.length === 0" class="text-sm text-amber-600">
            No vehicles found for this customer.
            <RouterLink
              :to="`/vehicles/create?customer_id=${form.customer_id}`"
              class="underline hover:text-amber-700"
            >
              Add a vehicle
            </RouterLink>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Select
              v-model="form.service_type"
              label="Service Type"
              :options="serviceTypeOptions"
              :error="errors.service_type"
              required
            />

            <Select
              v-model="form.priority"
              label="Priority"
              :options="priorityOptions"
              :error="errors.priority"
              required
            />

            <Input
              v-model.number="form.km_reading"
              label="Odometer (km)"
              type="number"
              :error="errors.km_reading"
            />

            <Select
              v-model="form.fuel_level"
              label="Fuel Level"
              placeholder="Select fuel level"
              :options="fuelLevelOptions"
              :error="errors.fuel_level"
            />

            <Input
              v-model="form.estimated_delivery"
              label="Estimated Delivery"
              type="datetime-local"
              :error="errors.estimated_delivery"
            />
          </div>

          <Textarea
            v-model="form.customer_complaints"
            label="Customer Complaint / Work Required"
            :error="errors.customer_complaints"
            :rows="4"
          />
        </div>

        <template #footer>
          <div class="flex gap-3">
            <Button type="button" variant="secondary" @click="router.back()">
              Cancel
            </Button>
            <Button type="submit" :loading="saving">
              {{ isEditing ? 'Update Job Card' : 'Create Job Card' }}
            </Button>
          </div>
        </template>
      </Card>
    </form>
  </div>
</template>
