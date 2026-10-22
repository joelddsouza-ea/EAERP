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
import VoiceInput from '@/components/ui/VoiceInput.vue'
import AssignmentsCard from '@/components/jobcard/AssignmentsCard.vue'
import { jobCardIssuesApi } from '@/api/jobSheets.api'
import Spinner from '@/components/ui/Spinner.vue'
import { tripsApi, type CustomerAddress, type ServiceMode, type TripPerson } from '@/api/trips.api'
import { usePermissions } from '@/composables/usePermissions'
import { fleetApi } from '@/api/fleet.api'
import { useAuthStore } from '@/stores/auth.store'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const jobCardId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEditing = computed(() => !!jobCardId.value)

const loading = ref(false)
/** Set after creating: the screen switches to assigning technicians and bay */
const created = ref<{ id: number; job_number: string } | null>(null)
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
  estimated_delivery: '',
  /** A fleet cab: the driver who brought it in */
  fleet_driver_id: null as number | null,
})

// ---- Fleet cabs: which of the fleet's drivers brought it in (they get the job in their app)
const auth = useAuthStore()
const fleetDrivers = ref<{ id: number; name: string; phone: string; drives_this_cab: boolean }[]>([])
const fleetDriverOptions = computed(() =>
  fleetDrivers.value.map((d) => ({ value: d.id, label: `${d.name} · ${d.phone}${d.drives_this_cab ? '' : ' (other cab)'}` })),
)
async function loadFleetDrivers() {
  const customer = selectedCustomer.value
  if (!customer?.is_fleet || !auth.hasFeature('fleet') || !form.value.vehicle_id) {
    fleetDrivers.value = []
    return
  }
  try {
    fleetDrivers.value = (await fleetApi.driversForJob(customer.id, form.value.vehicle_id)).data.data
    const own = fleetDrivers.value.filter((d) => d.drives_this_cab)
    if (!form.value.fleet_driver_id && !isEditing.value && own.length === 1) form.value.fleet_driver_id = own[0].id
  } catch {
    fleetDrivers.value = []
  }
}

// ---- Drive-in, pickup & drop, or home service (new job cards only)

const { can } = usePermissions()
const mode = ref<ServiceMode>('drive_in')
const isDriveIn = computed(() => mode.value === 'drive_in')
const trip = ref({
  address: '',
  landmark: '',
  maps_url: '',
  slot_date: new Date().toISOString().slice(0, 10),
  slot_from: '',
  slot_to: '',
  assigned_to: null as number | null,
  charge: false,
  drop_charge: false,
  save_address: true,
})
const savedAddresses = ref<CustomerAddress[]>([])
const people = ref<TripPerson[]>([])
const tripPeople = computed(() =>
  people.value
    .filter((p) => (mode.value === 'home' ? !p.is_driver : p.is_driver))
    .map((p) => ({ value: p.id, label: p.name })),
)
const modeOptions: { value: ServiceMode; label: string; hint: string }[] = [
  { value: 'drive_in', label: 'Drive-in', hint: 'The customer brings the car' },
  { value: 'pickup_drop', label: 'Pickup & drop', hint: 'Our driver collects and returns it' },
  { value: 'home', label: 'Home service', hint: 'A technician works at the customer\x27s place' },
]

async function loadTripData(customerId: number) {
  if (isEditing.value) return
  try {
    savedAddresses.value = (await tripsApi.addresses(customerId)).data.data
    const first = savedAddresses.value[0]
    if (first && !trip.value.address) {
      Object.assign(trip.value, { address: first.address, landmark: first.landmark ?? '', maps_url: first.maps_url ?? '', save_address: false })
    } else if (!first && !trip.value.address && selectedCustomer.value?.address) {
      trip.value.address = selectedCustomer.value.address
    }
  } catch {
    savedAddresses.value = []
  }
  if (!people.value.length && can('workshop.trips.manage')) {
    try {
      people.value = (await tripsApi.board()).data.data.people
    } catch {
      people.value = []
    }
  }
}

function pickSavedAddress(id: string | number | null) {
  const a = savedAddresses.value.find((x) => x.id === Number(id))
  if (a) Object.assign(trip.value, { address: a.address, landmark: a.landmark ?? '', maps_url: a.maps_url ?? '', save_address: false })
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

// ---- Customer complaints: one line each (each becomes an Additional Issues line on the job sheet)

interface ComplaintLine {
  id?: number
  text: string
  original?: string
}
const complaints = ref<ComplaintLine[]>([{ text: '' }])
const removedComplaintIds = ref<number[]>([])

function addComplaint() {
  complaints.value.push({ text: '' })
}

function removeComplaint(index: number) {
  const [line] = complaints.value.splice(index, 1)
  if (line?.id) removedComplaintIds.value.push(line.id)
  if (!complaints.value.length) complaints.value.push({ text: '' })
}

async function loadComplaints() {
  if (!jobCardId.value) return
  try {
    const lines = (await jobCardIssuesApi.issues(jobCardId.value)).data.data.filter((l) => l.source === 'customer')
    complaints.value = lines.length ? lines.map((l) => ({ id: l.id, text: l.description, original: l.description })) : [{ text: '' }]
  } catch {
    // Older jobs keep their complaint text on the job card
  }
}

/** On edit: add new lines, save changed ones, remove deleted ones */
async function saveComplaints(id: number) {
  for (const lineId of removedComplaintIds.value) {
    await jobCardIssuesApi.deleteIssue(id, lineId)
  }
  for (const line of complaints.value) {
    const text = line.text.trim()
    if (!text) continue
    if (!line.id) await jobCardIssuesApi.createIssue(id, { description: text })
    else if (text !== line.original) await jobCardIssuesApi.updateIssue(id, line.id, { description: text })
  }
}

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
  // Home service: only plans offered at home (Service Plans master)
  ...(options.value?.plans ?? [])
    .filter((p) => mode.value !== 'home' || p.home_service_available !== false)
    .map((p) => ({ value: `plan:${p.id}`, label: p.name })),
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

  loadTripData(customer.id)

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
      // datetime-local inputs need "YYYY-MM-DDTHH:mm"
      estimated_delivery: jobCard.estimated_delivery?.slice(0, 16) || '',
      fleet_driver_id: jobCard.fleet_driver_id ?? null,
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
  // Pickup / home visit: the driver or technician records them at the car
  if (!isEditing.value && isDriveIn.value && (form.value.km_reading === null || (form.value.km_reading as unknown) === '')) {
    errors.value.km_reading = 'Odometer reading is required'
  }
  if (!isEditing.value && isDriveIn.value && !form.value.fuel_level) {
    errors.value.fuel_level = 'Fuel level is required'
  }
  if (!isEditing.value && !isDriveIn.value) {
    if (!trip.value.address.trim()) errors.value['trip.address'] = 'Address is required'
    if (!trip.value.slot_date) errors.value['trip.slot_date'] = 'Date is required'
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
      await saveComplaints(jobCardId.value!)
      toast.success('Job Card Updated', 'Job card has been updated.')
    } else {
      const lines = complaints.value.map((c) => c.text.trim()).filter(Boolean)
      const booking = isDriveIn.value
        ? {}
        : {
            km_reading: null,
            fuel_level: null,
            trip: {
              ...trip.value,
              landmark: trip.value.landmark || null,
              maps_url: trip.value.maps_url || null,
              slot_from: trip.value.slot_from || null,
              slot_to: trip.value.slot_to || null,
            },
          }
      const response = await jobCardsApi.create({ ...payload, ...booking, service_mode: mode.value, customer_id, vehicle_id, complaints: lines, booking_id: bookingId.value })
      toast.success('Job Card Created', 'Now assign the technician(s) and bay.')
      created.value = { id: response.data.data.id, job_number: response.data.data.job_number }
      window.scrollTo({ top: 0, behavior: 'smooth' })
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

/**
 * Opened from "Create Job Card" on the vehicle screen: customer and vehicle come chosen.
 */
async function prefillFromQuery() {
  const customerId = Number(route.query.customer_id)
  const vehicleId = Number(route.query.vehicle_id)
  if (isEditing.value || !customerId) return
  try {
    const customer = (await customersApi.get(customerId)).data.data
    await selectCustomer(customer)
    if (vehicleId && customerVehicles.value.some((v) => v.id === vehicleId)) {
      form.value.vehicle_id = vehicleId
    }
    // Opened from a fleet driver's problem report
    if (route.query.fleet_driver_id) form.value.fleet_driver_id = Number(route.query.fleet_driver_id)
  } catch {
    // Pick them by hand
  }

  // Opened from a CRM booking: what the customer booked comes filled in
  const q = route.query
  if (q.booking_id) {
    bookingId.value = Number(q.booking_id)
    if (q.package_id) form.value.service_choice = `plan:${q.package_id}`
    else if (q.service_type) form.value.service_choice = String(q.service_type)
    if (q.oil_type_id) form.value.oil_type_id = Number(q.oil_type_id)
    const lines = String(q.complaints ?? '').split(/\n/).map((l) => l.trim()).filter(Boolean)
    if (lines.length) complaints.value = lines.map((text) => ({ text }))
  }
}

/** The CRM booking this job card is opened for (the car arrived) */
const bookingId = ref<number | null>(null)

onMounted(async () => {
  await fetchJobCard()
  await loadComplaints()
  await prefillFromQuery()
})

// A plan not offered at home can't stay chosen for a home-service job
watch(mode, (m) => {
  if (m === 'home' && selectedPlan.value && selectedPlan.value.home_service_available === false) {
    form.value.service_choice = ''
    form.value.oil_type_id = null
  }
})

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
    loadFleetDrivers()
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

    <!-- Created: assign technicians and bay on the same screen -->
    <div v-else-if="created" class="max-w-3xl space-y-4">
      <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
        Job card <b>{{ created.job_number }}</b> has been created. Assign the technician(s) and the bay below.
      </div>
      <AssignmentsCard :job-card-id="created.id" />
      <div class="flex flex-wrap gap-3">
        <Button @click="router.push(`/job-cards/${created.id}`)">Open job card</Button>
        <Button variant="secondary" @click="router.push('/job-cards')">Back to job cards</Button>
      </div>
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

          <Select
            v-if="fleetDrivers.length"
            v-model="form.fleet_driver_id"
            label="Driver who brought the cab"
            :options="fleetDriverOptions"
            :error="errors.fleet_driver_id"
            placeholder="Not recorded"
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

          <!-- How the car comes in -->
          <div v-if="!isEditing">
            <label class="block text-sm font-medium text-gray-700 mb-1">Mode</label>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
              <button
                v-for="m in modeOptions"
                :key="m.value"
                type="button"
                class="rounded-lg border px-3 py-2 text-left text-sm"
                :class="mode === m.value ? 'border-primary-600 bg-primary-50 ring-1 ring-primary-600' : 'border-gray-300 bg-white'"
                @click="mode = m.value"
              >
                <span class="block font-medium text-gray-900">{{ m.label }}</span>
                <span class="block text-xs text-gray-500">{{ m.hint }}</span>
              </button>
            </div>
            <p v-if="errors.service_mode" class="mt-1 text-sm text-red-600">{{ errors.service_mode }}</p>
          </div>

          <!-- Pickup / home visit booking -->
          <div v-if="!isEditing && !isDriveIn" class="rounded-lg border border-gray-200 p-4 space-y-4">
            <p class="text-sm text-gray-600">
              {{ mode === 'home'
                ? 'The technician records the odometer, fuel and photos at the customer\'s place.'
                : 'The driver records the odometer, fuel and photos/videos at pickup — the advisor does not repeat the intake.' }}
            </p>
            <Select
              v-if="savedAddresses.length"
              :model-value="null"
              label="Saved address"
              placeholder="Choose a saved address"
              :options="savedAddresses.map((a) => ({ value: a.id, label: `${a.label ? a.label + ': ' : ''}${a.address}` }))"
              @update:model-value="pickSavedAddress"
            />
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Address <span class="text-red-500">*</span></label>
              <textarea v-model="trip.address" rows="2" :class="['input', errors['trip.address'] ? 'input-error' : '']" />
              <p v-if="errors['trip.address']" class="mt-1 text-sm text-red-600">{{ errors['trip.address'] }}</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Input v-model="trip.landmark" label="Landmark" />
              <Input v-model="trip.maps_url" label="Google Maps link" placeholder="Location the customer shared" :error="errors['trip.maps_url']" />
            </div>
            <div class="grid grid-cols-3 gap-3">
              <Input v-model="trip.slot_date" label="Date" type="date" :error="errors['trip.slot_date']" required />
              <Input v-model="trip.slot_from" label="From" type="time" />
              <Input v-model="trip.slot_to" label="To" type="time" :error="errors['trip.slot_to']" />
            </div>
            <Select
              v-if="tripPeople.length"
              v-model="trip.assigned_to"
              :label="mode === 'home' ? 'Technician (becomes the lead)' : 'Driver'"
              placeholder="Assign later"
              :options="tripPeople"
            />
            <div class="space-y-1 text-sm">
              <label class="flex items-center gap-2">
                <input v-model="trip.charge" type="checkbox" class="rounded border-gray-300" />
                Charge for the {{ mode === 'home' ? 'home visit' : 'pickup' }} (amount from the masters, on the bill)
              </label>
              <label v-if="mode === 'pickup_drop'" class="flex items-center gap-2">
                <input v-model="trip.drop_charge" type="checkbox" class="rounded border-gray-300" />
                Charge for the drop
              </label>
              <label class="flex items-center gap-2">
                <input v-model="trip.save_address" type="checkbox" class="rounded border-gray-300" />
                Save this address for the customer
              </label>
            </div>
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

            <template v-if="isEditing || isDriveIn">
              <Input
                v-model.number="form.km_reading"
                label="Odometer (km)"
                type="number"
                :error="errors.km_reading"
                :required="!isEditing"
              />

              <Select
                v-model="form.fuel_level"
                label="Fuel Level"
                placeholder="Select fuel level"
                :options="fuelLevelOptions"
                :error="errors.fuel_level"
                :required="!isEditing"
              />
            </template>

            <Input
              v-model="form.estimated_delivery"
              label="Estimated Delivery"
              type="datetime-local"
              :error="errors.estimated_delivery"
            />
          </div>

          <!-- Customer complaints, one per line -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Customer Complaints / Work Required</label>
            <p class="text-xs text-gray-500 mb-2">One complaint per line. Each appears under Additional Issues on the technician's job sheet.</p>
            <ol class="space-y-2">
              <li v-for="(line, index) in complaints" :key="line.id ?? `new-${index}`" class="flex items-center gap-2">
                <span class="w-5 text-right text-sm text-gray-500">{{ index + 1 }}.</span>
                <input
                  v-model="line.text"
                  class="input flex-1"
                  placeholder="e.g. Brake noise when stopping"
                  @keydown.enter.prevent="addComplaint"
                />
                <VoiceInput @text="(t: string) => (line.text = line.text ? `${line.text} ${t}` : t)" />
                <button
                  type="button"
                  class="p-2 text-gray-400 hover:text-red-600"
                  aria-label="Remove complaint"
                  @click="removeComplaint(index)"
                >
                  ✕
                </button>
              </li>
            </ol>
            <button type="button" class="mt-2 text-sm text-primary-600" @click="addComplaint">+ Add another complaint</button>
            <p v-if="errors.complaints" class="mt-1 text-sm text-red-600">{{ errors.complaints }}</p>
          </div>
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
