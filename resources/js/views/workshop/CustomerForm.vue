<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { customersApi } from '@/api/workshop.api'
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
  customer_type: 'individual' as 'individual' | 'corporate',
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

const customerTypeOptions = [
  { value: 'individual', label: 'Individual' },
  { value: 'corporate', label: 'Corporate' },
]

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
            v-model="form.phone"
            label="Phone"
            type="tel"
            :error="errors.phone"
            required
          />

          <Input
            v-model="form.alt_phone"
            label="Alternate Phone"
            type="tel"
            :error="errors.alt_phone"
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
