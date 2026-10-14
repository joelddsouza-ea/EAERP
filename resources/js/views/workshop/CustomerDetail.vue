<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { PencilIcon, PlusIcon } from '@heroicons/vue/20/solid'
import { customersApi } from '@/api/workshop.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { Customer, Vehicle } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const toast = useToast()

const loading = ref(true)
const customer = ref<Customer | null>(null)
const vehicles = ref<Vehicle[]>([])

const customerId = Number(route.params.id)

async function fetchCustomer() {
  loading.value = true
  try {
    const [customerRes, vehiclesRes] = await Promise.all([
      customersApi.get(customerId),
      customersApi.vehicles(customerId),
    ])
    customer.value = customerRes.data.data
    vehicles.value = vehiclesRes.data.data
  } catch (err) {
    toast.apiError(err)
    router.push('/customers')
  } finally {
    loading.value = false
  }
}

onMounted(fetchCustomer)
</script>

<template>
  <div>
    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <template v-else-if="customer">
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">{{ customer.name }}</h1>
          <div class="flex items-center gap-2 mt-1">
            <Badge :variant="customer.customer_type === 'corporate' ? 'purple' : 'default'">
              {{ customer.customer_type === 'corporate' ? 'Corporate' : 'Individual' }}
            </Badge>
            <span v-if="customer.company_name" class="text-gray-500">
              {{ customer.company_name }}
            </span>
          </div>
        </div>
        <Button v-if="can('workshop.customers.update')" @click="router.push(`/customers/${customer.id}/edit`)">
          <PencilIcon class="h-4 w-4 mr-1" />
          Edit
        </Button>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Customer Info -->
        <Card>
          <template #header>
            <h3 class="text-lg font-semibold">Contact Information</h3>
          </template>

          <dl class="space-y-4">
            <div>
              <dt class="text-sm text-gray-500">Phone</dt>
              <dd class="mt-1 text-sm font-medium text-gray-900">{{ customer.phone }}</dd>
            </div>
            <div v-if="customer.alt_phone">
              <dt class="text-sm text-gray-500">Alternate Phone</dt>
              <dd class="mt-1 text-sm font-medium text-gray-900">{{ customer.alt_phone }}</dd>
            </div>
            <div v-if="customer.email">
              <dt class="text-sm text-gray-500">Email</dt>
              <dd class="mt-1 text-sm font-medium text-gray-900">{{ customer.email }}</dd>
            </div>
            <div v-if="customer.address">
              <dt class="text-sm text-gray-500">Address</dt>
              <dd class="mt-1 text-sm font-medium text-gray-900">
                {{ customer.address }}
                <template v-if="customer.city">, {{ customer.city }}</template>
                <template v-if="customer.state">, {{ customer.state }}</template>
                <template v-if="customer.pincode"> - {{ customer.pincode }}</template>
              </dd>
            </div>
            <div v-if="customer.gstin">
              <dt class="text-sm text-gray-500">GST Number</dt>
              <dd class="mt-1 text-sm font-medium text-gray-900">{{ customer.gstin }}</dd>
            </div>
          </dl>
        </Card>

        <!-- Vehicles -->
        <div class="lg:col-span-2">
          <Card :padding="false">
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">Vehicles</h3>
                <Button v-if="can('workshop.vehicles.create')" size="sm" @click="router.push(`/vehicles/create?customer_id=${customer.id}`)">
                  <PlusIcon class="h-4 w-4 mr-1" />
                  Add Vehicle
                </Button>
              </div>
            </template>

            <div v-if="vehicles.length === 0" class="p-6 text-center text-gray-500">
              No vehicles registered for this customer.
            </div>

            <ul v-else class="divide-y divide-gray-200">
              <li v-for="vehicle in vehicles" :key="vehicle.id" class="p-4 hover:bg-gray-50">
                <RouterLink :to="`/vehicles/${vehicle.id}/edit`" class="block">
                  <div class="flex items-center justify-between">
                    <div>
                      <p class="text-sm font-medium text-gray-900">
                        {{ vehicle.registration_no }}
                      </p>
                      <p class="text-sm text-gray-500">
                        {{ vehicle.make?.name }} {{ vehicle.model?.name }}
                        <span v-if="vehicle.year">({{ vehicle.year }})</span>
                      </p>
                    </div>
                    <div class="text-right">
                      <Badge size="sm">{{ vehicle.fuel_type }}</Badge>
                    </div>
                  </div>
                </RouterLink>
              </li>
            </ul>
          </Card>
        </div>
      </div>

      <!-- Notes -->
      <Card v-if="customer.notes" class="mt-6">
        <template #header>
          <h3 class="text-lg font-semibold">Notes</h3>
        </template>
        <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ customer.notes }}</p>
      </Card>
    </template>
  </div>
</template>
