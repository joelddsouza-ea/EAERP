<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { vehiclesApi, customersApi, masterDataApi } from '@/api/workshop.api'
import { useToast } from '@/composables/useToast'
import type { Customer, VehicleMake, VehicleModel, VehicleCategory } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const vehicleId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEditing = computed(() => !!vehicleId.value)

// After creating, stay here to open a job card for this car next
const created = ref<{ id: number; customer_id: number; registration_no: string } | null>(null)
const preselectedCustomerId = computed(() =>
  route.query.customer_id ? Number(route.query.customer_id) : null
)

const loading = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const form = ref({
  customer_id: preselectedCustomerId.value || (null as number | null),
  registration_no: '',
  make_id: null as number | null,
  model_id: null as number | null,
  category_id: null as number | null,
  year: null as number | null,
  color: '',
  vin: '',
  engine_no: '',
  chassis_no: '',
  fuel_type: 'petrol' as 'petrol' | 'diesel' | 'cng' | 'electric' | 'hybrid',
  transmission: 'manual' as 'manual' | 'automatic',
  insurance_company: '',
  insurance_policy_no: '',
  insurance_expiry: '',
  notes: '',
})

// Make / model / category lookups
const makes = ref<VehicleMake[]>([])
const models = ref<VehicleModel[]>([])
const categories = ref<VehicleCategory[]>([])

const makeOptions = computed(() => makes.value.map((m) => ({ value: m.id, label: m.name })))
const modelOptions = computed(() => models.value.map((m) => ({ value: m.id, label: m.name })))
const categoryOptions = computed(() => categories.value.map((c) => ({ value: c.id, label: c.name })))

async function fetchLookups() {
  try {
    const [makesRes, categoriesRes] = await Promise.all([
      masterDataApi.vehicleMakes(),
      masterDataApi.vehicleCategories(),
    ])
    makes.value = makesRes.data.data
    categories.value = categoriesRes.data.data
  } catch (err) {
    toast.apiError(err)
  }
}

async function fetchModels(makeId: number | null) {
  models.value = []
  if (!makeId) return
  try {
    const response = await masterDataApi.vehicleModels(makeId)
    models.value = response.data.data
  } catch (err) {
    toast.apiError(err)
  }
}

async function onMakeChange(value: string | number | null) {
  form.value.make_id = value ? Number(value) : null
  form.value.model_id = null
  await fetchModels(form.value.make_id)
}

// The car class comes from the model (Price Masters › Car classes & models); it can still be changed
function onModelChange(value: string | number | null) {
  form.value.model_id = value ? Number(value) : null
  const model = models.value.find((m) => m.id === form.value.model_id)
  if (model?.default_category_id) {
    form.value.category_id = model.default_category_id
  }
}

// Customer search
const customerSearch = ref('')
const customerSearchResults = ref<Customer[]>([])
const selectedCustomer = ref<Customer | null>(null)
const showCustomerDropdown = ref(false)

// Delay so a click on a dropdown option registers before it closes
function hideCustomerDropdownDelayed() {
  setTimeout(() => (showCustomerDropdown.value = false), 200)
}

const fuelTypeOptions = [
  { value: 'petrol', label: 'Petrol' },
  { value: 'diesel', label: 'Diesel' },
  { value: 'cng', label: 'CNG' },
  { value: 'electric', label: 'Electric' },
  { value: 'hybrid', label: 'Hybrid' },
]

const transmissionOptions = [
  { value: 'manual', label: 'Manual' },
  { value: 'automatic', label: 'Automatic' },
]

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

function selectCustomer(customer: Customer) {
  selectedCustomer.value = customer
  form.value.customer_id = customer.id
  customerSearch.value = customer.name
  showCustomerDropdown.value = false
}

async function fetchVehicle() {
  if (!vehicleId.value) {
    if (preselectedCustomerId.value) {
      try {
        const response = await customersApi.get(preselectedCustomerId.value)
        selectCustomer(response.data.data)
      } catch {
        // Ignore
      }
    }
    return
  }

  loading.value = true
  try {
    const response = await vehiclesApi.get(vehicleId.value)
    const vehicle = response.data.data
    Object.assign(form.value, {
      customer_id: vehicle.customer_id,
      registration_no: vehicle.registration_no || '',
      make_id: vehicle.make?.id ?? null,
      model_id: vehicle.model?.id ?? null,
      category_id: vehicle.category?.id ?? null,
      year: vehicle.year ? Number(vehicle.year) : null,
      color: vehicle.color || '',
      vin: vehicle.vin || '',
      engine_no: vehicle.engine_no || '',
      chassis_no: vehicle.chassis_no || '',
      fuel_type: vehicle.fuel_type || 'petrol',
      transmission: vehicle.transmission || 'manual',
      insurance_company: vehicle.insurance_company || '',
      insurance_policy_no: vehicle.insurance_policy_no || '',
      insurance_expiry: vehicle.insurance_expiry || '',
      notes: vehicle.notes || '',
    })
    await fetchModels(form.value.make_id)
    if (vehicle.customer) {
      selectCustomer(vehicle.customer as Customer)
    }
  } catch (err) {
    toast.apiError(err)
    router.push('/vehicles')
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
  if (!form.value.registration_no.trim()) {
    errors.value.registration_no = 'Registration number is required'
  }
  if (!form.value.make_id) {
    errors.value.make_id = 'Make is required'
  }
  if (!form.value.model_id) {
    errors.value.model_id = 'Model is required'
  }

  if (Object.keys(errors.value).length > 0) {
    return
  }

  saving.value = true
  try {
    if (isEditing.value) {
      await vehiclesApi.update(vehicleId.value!, form.value)
      toast.success('Vehicle Updated', 'Vehicle details have been updated.')
    } else {
      const response = await vehiclesApi.create(form.value)
      toast.success('Vehicle Created', 'You can open a job card for it now.')
      created.value = {
        id: response.data.data.id,
        customer_id: response.data.data.customer_id,
        registration_no: response.data.data.registration_no,
      }
      return
    }
    router.push('/vehicles')
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

onMounted(() => {
  fetchLookups()
  fetchVehicle()
})

watch(customerSearch, (value) => {
  if (value !== selectedCustomer.value?.name) {
    searchCustomers(value)
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">
        {{ isEditing ? 'Edit Vehicle' : 'New Vehicle' }}
      </h1>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <form v-else @submit.prevent="handleSubmit" class="max-w-3xl">
      <Card>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Customer Selection -->
          <div class="md:col-span-2 relative">
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Customer <span class="text-red-500">*</span>
            </label>
            <input
              v-model="customerSearch"
              type="text"
              placeholder="Search for customer..."
              :class="[
                'input',
                errors.customer_id ? 'input-error' : '',
              ]"
              :disabled="isEditing"
              @focus="showCustomerDropdown = customerSearchResults.length > 0"
              @blur="hideCustomerDropdownDelayed"
            />
            <p v-if="errors.customer_id" class="mt-1 text-sm text-red-600">
              {{ errors.customer_id }}
            </p>

            <!-- Dropdown -->
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

          <!-- Vehicle Info -->
          <Input
            v-model="form.registration_no"
            label="Registration Number"
            :error="errors.registration_no"
            required
          />

          <Select
            :model-value="form.make_id"
            label="Make"
            placeholder="Select make"
            :options="makeOptions"
            :error="errors.make_id"
            required
            @update:model-value="onMakeChange"
          />

          <Select
            :model-value="form.model_id"
            label="Model"
            :placeholder="form.make_id ? 'Select model' : 'Select a make first'"
            :options="modelOptions"
            :error="errors.model_id"
            :disabled="!form.make_id"
            required
            @update:model-value="onModelChange"
          />

          <Select
            v-model="form.category_id"
            label="Car Class"
            placeholder="Picked from the model, or choose"
            :options="categoryOptions"
            :error="errors.category_id"
          />

          <Input
            v-model.number="form.year"
            label="Year"
            type="number"
            :error="errors.year"
          />

          <Input
            v-model="form.color"
            label="Color"
            :error="errors.color"
          />

          <Select
            v-model="form.fuel_type"
            label="Fuel Type"
            :options="fuelTypeOptions"
            :error="errors.fuel_type"
            required
          />

          <Select
            v-model="form.transmission"
            label="Transmission"
            :options="transmissionOptions"
            :error="errors.transmission"
            required
          />

          <Input
            v-model="form.vin"
            label="VIN"
            :error="errors.vin"
          />

          <Input
            v-model="form.chassis_no"
            label="Chassis Number"
            :error="errors.chassis_no"
          />

          <Input
            v-model="form.engine_no"
            label="Engine Number"
            :error="errors.engine_no"
          />

          <Input
            v-model="form.insurance_company"
            label="Insurance Company"
            :error="errors.insurance_company"
          />

          <Input
            v-model="form.insurance_policy_no"
            label="Insurance Policy Number"
            :error="errors.insurance_policy_no"
          />

          <Input
            v-model="form.insurance_expiry"
            label="Insurance Expiry"
            type="date"
            :error="errors.insurance_expiry"
          />

          <!-- Notes -->
          <div class="md:col-span-2">
            <Textarea
              v-model="form.notes"
              label="Notes"
              :error="errors.notes"
              :rows="3"
            />
          </div>
        </div>

        <template #footer>
          <div v-if="created" class="flex flex-wrap items-center gap-3">
            <p class="text-sm text-green-700 mr-auto">{{ created.registration_no }} has been saved.</p>
            <Button type="button" variant="secondary" @click="router.push('/vehicles')">Done</Button>
            <Button
              type="button"
              @click="router.push(`/job-cards/create?customer_id=${created.customer_id}&vehicle_id=${created.id}`)"
            >
              Create Job Card
            </Button>
          </div>
          <div v-else class="flex gap-3">
            <Button type="button" variant="secondary" @click="router.back()">
              Cancel
            </Button>
            <Button type="submit" :loading="saving">
              {{ isEditing ? 'Update Vehicle' : 'Create Vehicle' }}
            </Button>
          </div>
        </template>
      </Card>
    </form>
  </div>
</template>
