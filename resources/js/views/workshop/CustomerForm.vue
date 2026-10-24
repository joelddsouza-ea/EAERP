<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { customersApi } from '@/api/workshop.api'
import { fleetApi } from '@/api/fleet.api'
import { useAuthStore } from '@/stores/auth.store'
import { isMobile, cleanMobile, MOBILE_ERROR } from '@/utils/phone'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const customerId = computed(() => route.params.id ? Number(route.params.id) : null)
const isEditing = computed(() => !!customerId.value)

// After creating, stay here to add the customer's vehicle next
const created = ref<{ id: number; name: string } | null>(null)

const loading = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const form = ref({
  name: '',
  email: '',
  phone: '',
  alt_phone: '',
  address: '',
  city: '',
  state: '',
  pincode: '',
  gstin: '',
  customer_type: 'individual' as 'individual' | 'corporate' | 'fleet',
  source: '' as string,
  company_name: '',
  notes: '',
})

const sourceOptions = [
  { value: 'walk_in', label: 'Walk-in' },
  { value: 'referral', label: 'Referral' },
  { value: 'online', label: 'Online / Google' },
  { value: 'lead', label: 'Lead (marketing)' },
  { value: 'corporate', label: 'Corporate' },
  { value: 'other', label: 'Other' },
]

// Fleet (cab owners) only while the Fleet module is on; a fleet being edited keeps its type
const customerTypeOptions = computed(() => [
  { value: 'individual', label: 'Individual' },
  { value: 'corporate', label: 'Corporate' },
  ...(auth.hasFeature('fleet') || form.value.customer_type === 'fleet' ? [{ value: 'fleet', label: 'Fleet (cab owner)' }] : []),
])

// Fleet intake: what a driver may approve per job comes from Masters › Fleet Cabs
const fleetLimit = ref<number | null>(null)
watch(() => form.value.customer_type, async (type) => {
  if (type !== 'fleet' || fleetLimit.value !== null) return
  try {
    fleetLimit.value = (await fleetApi.settings()).data.data.driver_limit
  } catch {
    // Fleet module off: no limit to show
  }
})
const rupees = (n: number) => '₹ ' + Math.round(n).toLocaleString('en-IN')

async function fetchCustomer() {
  if (!customerId.value) return

  loading.value = true
  try {
    const response = await customersApi.get(customerId.value)
    const customer = response.data.data
    Object.assign(form.value, {
      name: customer.name || '',
      email: customer.email || '',
      phone: customer.phone || '',
      alt_phone: customer.alt_phone || '',
      address: customer.address || '',
      city: customer.city || '',
      state: customer.state || '',
      pincode: customer.pincode || '',
      gstin: customer.gstin || '',
      customer_type: customer.customer_type || 'individual',
      source: customer.source || '',
      company_name: customer.company_name || '',
      notes: customer.notes || '',
    })
  } catch (err) {
    toast.apiError(err)
    router.push('/customers')
  } finally {
    loading.value = false
  }
}

async function handleSubmit() {
  errors.value = {}

  // Validation
  if (!form.value.name.trim()) {
    errors.value.name = 'Name is required'
  }
  if (!form.value.phone.trim()) {
    errors.value.phone = 'Phone is required'
  } else if (!isMobile(form.value.phone)) {
    errors.value.phone = MOBILE_ERROR
  }
  if (form.value.alt_phone.trim() && !isMobile(form.value.alt_phone)) {
    errors.value.alt_phone = MOBILE_ERROR
  }
  if (form.value.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email)) {
    errors.value.email = 'Invalid email format'
  }
  if (form.value.customer_type === 'corporate' && !form.value.company_name.trim()) {
    errors.value.company_name = 'Company name is required for corporate customers'
  }

  if (Object.keys(errors.value).length > 0) {
    return
  }

  saving.value = true
  try {
    if (isEditing.value) {
      await customersApi.update(customerId.value!, form.value)
      toast.success('Customer Updated', 'Customer details have been updated.')
    } else {
      const response = await customersApi.create(form.value)
      if (form.value.customer_type === 'fleet') {
        // Fleet intake goes on: cabs, then drivers
        toast.success('Fleet Created', 'Now add the cabs and their drivers.')
        router.push(`/customers/${response.data.data.id}/fleet-setup`)
        return
      }
      toast.success('Customer Created', 'Now add the customer\'s vehicle.')
      created.value = { id: response.data.data.id, name: response.data.data.name }
      return
    }
    router.push('/customers')
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

onMounted(fetchCustomer)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">
        {{ isEditing ? 'Edit Customer' : 'New Customer' }}
      </h1>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <form v-else @submit.prevent="handleSubmit" class="max-w-3xl">
      <Card>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Basic Info -->
          <Input
            v-model="form.name"
            label="Full Name"
            :error="errors.name"
            required
          />

          <Select
            v-model="form.customer_type"
            label="Customer Type"
            :options="customerTypeOptions"
            :error="errors.customer_type"
            required
          />

          <Select
            v-model="form.source"
            label="How did they find us?"
            :options="sourceOptions"
            placeholder="Choose…"
          />

          <Input
            v-if="form.customer_type === 'corporate'"
            v-model="form.company_name"
            label="Company Name"
            :error="errors.company_name"
            required
          />

          <Input
            v-if="form.customer_type === 'fleet'"
            v-model="form.company_name"
            label="Fleet / Company Name"
            :error="errors.company_name"
            hint="e.g. Patil Cabs. Leave blank for a cab owner without a company."
          />

          <div v-if="form.customer_type === 'fleet'" class="md:col-span-2 rounded-lg bg-blue-50 p-3 text-sm text-blue-900">
            <p class="font-medium">Fleet intake</p>
            <p class="mt-1">
              Enter the fleet owner's details here (Full Name and Phone are the owner's). After saving you add the cabs, then the drivers
              and which cabs each one drives. Drivers sign in to the customer app with their own mobile number.
            </p>
            <p v-if="fleetLimit !== null" class="mt-1">
              A driver may approve up to <strong>{{ rupees(fleetLimit) }}</strong> of extra work per job; above it the owner is asked
              (Masters › Fleet Cabs).
            </p>
          </div>

          <Input
            :model-value="form.phone"
            label="Mobile number"
            type="tel"
            inputmode="numeric"
            maxlength="10"
            placeholder="10 digits"
            :error="errors.phone"
            required
            @update:model-value="(v: string | number) => (form.phone = cleanMobile(String(v)).slice(0, 10))"
          />

          <Input
            :model-value="form.alt_phone"
            label="Alternate mobile"
            type="tel"
            inputmode="numeric"
            maxlength="10"
            :error="errors.alt_phone"
            @update:model-value="(v: string | number) => (form.alt_phone = cleanMobile(String(v)).slice(0, 10))"
          />

          <Input
            v-model="form.email"
            label="Email"
            type="email"
            :error="errors.email"
          />

          <Input
            v-model="form.gstin"
            label="GST Number"
            :error="errors.gstin"
          />

          <!-- Address -->
          <div class="md:col-span-2">
            <Textarea
              v-model="form.address"
              label="Address"
              :error="errors.address"
              :rows="2"
            />
          </div>

          <Input
            v-model="form.city"
            label="City"
            :error="errors.city"
          />

          <Input
            v-model="form.state"
            label="State"
            :error="errors.state"
          />

          <Input
            v-model="form.pincode"
            label="Pincode"
            :error="errors.pincode"
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
            <p class="text-sm text-green-700 mr-auto">{{ created.name }} has been saved.</p>
            <Button type="button" variant="secondary" @click="router.push('/customers')">Done</Button>
            <Button type="button" @click="router.push(`/vehicles/create?customer_id=${created.id}`)">Add Vehicle</Button>
          </div>
          <div v-else class="flex gap-3">
            <Button type="button" variant="secondary" @click="router.back()">
              Cancel
            </Button>
            <Button type="submit" :loading="saving">
              {{ isEditing ? 'Update Customer' : 'Create Customer' }}
            </Button>
          </div>
        </template>
      </Card>
    </form>
  </div>
</template>
