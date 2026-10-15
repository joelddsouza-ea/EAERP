<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { jobCardsApi, customersApi } from '@/api/workshop.api'
import { useToast } from '@/composables/useToast'
import type { Customer, Vehicle, FuelLevel, JobCardOptions } from '@/api/types'
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
  /** "plan:{id}" for a service plan, otherwise the repair service_type */
  service_choice: '' as string,
  oil_type_id: null as number | null,
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

// ---- Service type (plan or repair type) and engine oil grade

const options = ref<JobCardOptions | null>(null)
let optionsRequest = 0

async function loadOptions() {
  // Only the latest request counts: a slower, older reply (e.g. for no vehicle yet) must not win
  const request = ++optionsRequest
  try {
    const response = await jobCardsApi.formOptions(form.value.vehicle_id, jobCardId.value)
    if (request === optionsRequest) options.value = response.data.data
  } catch {
    if (request === optionsRequest) options.value = null
  }
}

const serviceOptions = computed(() => [
  ...(options.value?.plans ?? []).map((p) => ({ value: `plan:${p.id}`, label: p.name })),
  ...(options.value?.service_types ?? []).map((t) => ({ value: t.value, label: t.label })),
])

const selectedPlan = computed(() => {
  const m = /^plan:(\d+)$/.exec(form.value.service_choice)
  return m ? options.value?.plans.find((p) => p.id === Number(m[1])) ?? null : null
})

const isPlan = computed(() => form.value.service_choice.startsWith('plan:'))

/** A plan offers only the grades it is priced for on this car type; repairs may take any grade */
const oilOptions = computed(() => {
  if (selectedPlan.value) {
    return selectedPlan.value.oil_options.map((o) => ({ value: o.id, label: `${o.name} — ₹ ${money(o.price)}` }))
  }
  return (options.value?.oil_types ?? []).map((o) => ({ value: o.id, label: o.name }))
})

const packagePrice = computed(() =>
  selectedPlan.value?.oil_options.find((o) => o.id === Number(form.value.oil_type_id))?.price ?? null
)

const sheets = computed(() => {
  if (selectedPlan.value) return selectedPlan.value.sheets
  return options.value?.service_types.find((t) => t.value === form.value.service_choice)?.sheets ?? []
})

const locked = computed(() => !!options.value?.lock)

function money(n: number): string {
  return new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(n)
}

/**
 * The user picked a service type. Switching to a plan that isn't priced with the chosen
 * grade clears the grade. Done here, not in a watcher, so loading options never clears a saved grade.
 */
function chooseService(value: string | number | null) {
  form.value.service_choice = value ? String(value) : ''
  const plan = selectedPlan.value
  if (plan && form.value.oil_type_id && !plan.oil_options.some((o) => o.id === Number(form.value.oil_type_id))) {
    form.value.oil_type_id = null
  }
}

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
      // Older plan jobs without a plan must have one chosen before saving
      service_choice:
        jobCard.service_type === 'scheduled'
          ? jobCard.package_id
            ? `plan:${jobCard.package_id}`
            : ''
          : jobCard.service_type || '',
      oil_type_id: jobCard.oil_type_id ?? null,
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
  if (!form.value.service_choice) {
    errors.value.service_type = 'Choose the service plan or service type'
  }
  if (isPlan.value && !form.value.oil_type_id) {
    errors.value.oil_type_id = 'Engine oil type is required for a service plan'
  }

  if (Object.keys(errors.value).length > 0) {
    return
  }

  saving.value = true
  try {
    // Branch comes from the X-Branch-Id header; customer and vehicle are fixed after creation
    const { customer_id, vehicle_id, service_choice, oil_type_id, ...editable } = form.value
    const plan = selectedPlan.value
    const payload: Record<string, unknown> = {
      ...editable,
      estimated_delivery: editable.estimated_delivery || null,
    }
    // Plan and oil grade are left out once locked (an estimate has been sent)
    if (!locked.value) {
      Object.assign(payload, {
        service_type: plan ? 'scheduled' : service_choice,
        package_id: plan ? plan.id : null,
        oil_type_id: oil_type_id ? Number(oil_type_id) : null,
      })
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
  (id) => {
    selectVehicle(id)
    // Prices and grades depend on the car type
    loadOptions()
  },
  { immediate: true }
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
              :model-value="form.service_choice"
              label="Service Type"
              :options="serviceOptions"
              :error="errors.service_type || errors.package_id"
              placeholder="Choose a service plan or service type"
              :disabled="locked"
              required
              @update:model-value="chooseService"
            />

            <Select
              :model-value="form.oil_type_id"
              label="Engine Oil Type"
              :options="oilOptions"
              :error="errors.oil_type_id"
              :placeholder="isPlan ? 'Choose the oil grade' : 'Optional'"
              :disabled="locked || (isPlan && !oilOptions.length)"
              :required="isPlan"
              @update:model-value="(v) => (form.oil_type_id = v ? Number(v) : null)"
            />

            <div v-if="form.service_choice || locked" class="md:col-span-2 -mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600 space-y-1">
              <p v-if="locked" class="text-amber-700">
                {{ options?.lock?.reason }}
                <RouterLink v-if="jobCardId" :to="`/job-cards/${jobCardId}/estimate`" class="underline">Open the estimate</RouterLink>
              </p>
              <p v-if="isPlan && !options?.car_type" class="text-amber-700">
                The vehicle has no car type, so the plan can't be priced.
                <RouterLink v-if="form.vehicle_id" :to="`/vehicles/${form.vehicle_id}/edit`" class="underline">Set it on the vehicle</RouterLink>
              </p>
              <p v-else-if="isPlan && !oilOptions.length" class="text-amber-700">
                This plan isn't priced for {{ options?.car_type?.name }} yet — set its prices in Price Masters.
              </p>
              <p v-if="packagePrice !== null">
                Package price <span class="font-semibold text-gray-900">₹ {{ money(packagePrice) }}</span>
                <span v-if="options?.car_type"> · {{ options.car_type.name }}</span>
                <span class="text-gray-400"> (incl. GST)</span>
              </p>
              <p v-if="sheets.length">Job sheets: {{ sheets.join(', ') }}</p>
            </div>

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
