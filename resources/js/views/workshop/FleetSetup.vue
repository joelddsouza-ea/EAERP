<script setup lang="ts">
/**
 * Fleet intake, after "Fleet" is picked on the New Customer form: the owner (saved), then the
 * cabs (Vehicles, added one after another), then the drivers and which cabs each one drives.
 * The driver approval amount comes from Masters › Fleet Cabs.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CheckCircleIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { customersApi } from '@/api/workshop.api'
import type { Customer, Vehicle } from '@/api/types'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'
import FleetCard from '@/components/customer/FleetCard.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const { can } = usePermissions()

const customerId = computed(() => Number(route.params.id))
const customer = ref<Customer | null>(null)
const cabs = ref<Vehicle[]>([])

async function load() {
  try {
    const [c, v] = await Promise.all([customersApi.get(customerId.value), customersApi.vehicles(customerId.value)])
    customer.value = c.data.data
    cabs.value = v.data.data
  } catch (err) {
    toast.apiError(err)
    router.push('/customers')
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">Fleet setup</h1>
      <p class="text-sm text-gray-500">Owner, then the cabs, then the drivers. You can come back to this from the customer's page.</p>
    </div>

    <div v-if="!customer" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <div v-else class="space-y-4">
      <!-- 1. Owner -->
      <Card>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-start gap-3">
            <CheckCircleIcon class="h-6 w-6 shrink-0 text-green-600" />
            <div>
              <h3 class="font-semibold text-gray-900">1. Fleet owner</h3>
              <p class="text-sm text-gray-600">
                {{ customer.name }}<template v-if="customer.company_name"> · {{ customer.company_name }}</template> · {{ customer.phone }}
              </p>
            </div>
          </div>
          <RouterLink v-if="can('workshop.customers.update')" :to="`/customers/${customer.id}/edit`" class="text-sm text-primary-700 hover:underline">Edit</RouterLink>
        </div>
      </Card>

      <!-- 2. Cabs -->
      <Card>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-start gap-3">
            <CheckCircleIcon class="h-6 w-6 shrink-0" :class="cabs.length ? 'text-green-600' : 'text-gray-300'" />
            <div>
              <h3 class="font-semibold text-gray-900">2. Cabs ({{ cabs.length }})</h3>
              <p class="text-sm text-gray-600">Every cab the fleet sends to us. Make and model stay filled in for the next cab.</p>
            </div>
          </div>
          <Button v-if="can('workshop.vehicles.create')" size="sm" @click="router.push(`/vehicles/create?customer_id=${customer.id}&then=fleet-setup`)">
            <PlusIcon class="mr-1 h-4 w-4" /> Add cab
          </Button>
        </div>
        <ul v-if="cabs.length" class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
          <li v-for="v in cabs" :key="v.id" class="rounded-lg bg-gray-50 px-3 py-2">
            <span class="font-medium text-gray-900">{{ v.registration_no }}</span>
            <span class="text-gray-500"> · {{ [v.make?.name, v.model?.name].filter(Boolean).join(' ') }}</span>
          </li>
        </ul>
      </Card>

      <!-- 3. Drivers (and the approval amount) -->
      <div>
        <h3 class="mb-2 px-1 font-semibold text-gray-900">3. Drivers and their cabs</h3>
        <FleetCard :customer-id="customer.id" />
      </div>

      <div class="flex justify-end">
        <Button @click="router.push(`/customers/${customer.id}`)">Finish</Button>
      </div>
    </div>
  </div>
</template>
