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
import BookingDialog from '@/components/crm/BookingDialog.vue'
import { useAuthStore } from '@/stores/auth.store'
import { crmApi, fmtDateTime, type CustomerTimeline, type LeadOptions } from '@/api/crm.api'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const auth = useAuthStore()
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

// Customer 360: visits, spend, rating, credit, next service due, and one timeline
const timeline = ref<CustomerTimeline | null>(null)
const crmOptions = ref<LeadOptions | null>(null)
const bookFor = ref<number | null>(null)
async function fetchTimeline() {
  if (!auth.hasFeature('crm')) return
  try {
    timeline.value = (await crmApi.customerTimeline(customerId)).data.data
  } catch {
    // CRM view is optional
  }
}
async function openBooking(vehicleId: number | null) {
  if (!crmOptions.value) crmOptions.value = (await crmApi.options()).data.data
  bookFor.value = vehicleId ?? 0
}
const ICONS: Record<string, string> = { lead: '✨', booking: '📅', job: '🔧', bill: '🧾', feedback: '⭐', message: '💬' }

onMounted(() => {
  fetchCustomer()
  fetchTimeline()
})
</script>

<template>
  <div>
    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <template v-else-if="customer">
      <!-- Header -->
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4 sm:mb-6">
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
        <div class="flex gap-2">
          <Button v-if="auth.hasFeature('crm') && can('crm.bookings.manage')" variant="secondary" @click="openBooking(vehicles[0]?.id ?? null)">Book a service</Button>
          <Button v-if="can('workshop.customers.update')" @click="router.push(`/customers/${customer.id}/edit`)">
            <PencilIcon class="h-4 w-4 mr-1" />
            Edit
          </Button>
        </div>
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

      <!-- Customer 360 -->
      <template v-if="timeline">
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-5 gap-2 text-sm">
          <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Visits</p><p class="font-semibold">{{ timeline.summary.visits }}</p></div>
          <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Spent</p><p class="font-semibold">₹ {{ Math.round(timeline.summary.spend).toLocaleString('en-IN') }}</p></div>
          <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Rating</p><p class="font-semibold">{{ timeline.summary.avg_rating ?? '—' }} ★</p></div>
          <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Credit</p><p class="font-semibold">₹ {{ Math.round(timeline.summary.credit).toLocaleString('en-IN') }}</p></div>
          <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Referred</p><p class="font-semibold">{{ timeline.summary.referrals }}<span v-if="timeline.summary.referral_code" class="ml-1 text-xs font-normal text-gray-500">code {{ timeline.summary.referral_code }}</span></p></div>
        </div>
        <div class="mt-3 flex flex-wrap gap-2 text-xs">
          <span v-for="v in timeline.vehicles.filter((x) => x.next_service_due_date)" :key="v.id" class="rounded-full bg-amber-50 px-3 py-1 text-amber-800">
            {{ v.registration_no }}: next service {{ v.next_service_due_date }}<template v-if="v.next_service_due_km"> / {{ v.next_service_due_km.toLocaleString('en-IN') }} km</template>
          </span>
          <span v-for="j in timeline.summary.active_journeys" :key="j" class="rounded-full bg-blue-50 px-3 py-1 text-blue-800">In journey: {{ j }}</span>
          <span v-if="timeline.summary.opted_out" class="rounded-full bg-red-50 px-3 py-1 text-red-700">No marketing messages</span>
          <RouterLink v-if="timeline.summary.open_lead_id" :to="`/crm/leads/${timeline.summary.open_lead_id}`" class="rounded-full bg-primary-50 px-3 py-1 text-primary-700">Open lead</RouterLink>
        </div>
        <Card class="mt-4" :padding="false">
          <template #header><h3 class="text-lg font-semibold">History</h3></template>
          <ul class="divide-y divide-gray-100 text-sm">
            <li v-for="(e, i) in timeline.timeline" :key="i" class="px-4 py-2 flex gap-3">
              <span class="w-5 text-center">{{ ICONS[e.type] ?? '•' }}</span>
              <span class="min-w-0 flex-1">
                <RouterLink v-if="e.to" :to="e.to" class="font-medium text-gray-900 hover:text-primary-700">{{ e.title }}</RouterLink>
                <span v-else class="font-medium text-gray-900">{{ e.title }}</span>
                <span v-if="e.detail" class="block truncate text-gray-500">{{ e.detail }}</span>
              </span>
              <span class="text-xs text-gray-400 whitespace-nowrap">{{ fmtDateTime(e.at) }}</span>
            </li>
          </ul>
          <p v-if="!timeline.timeline.length" class="px-4 py-3 text-sm text-gray-500">No history yet.</p>
        </Card>
      </template>

      <BookingDialog
        :open="bookFor !== null"
        :options="crmOptions"
        :customer-id="customer.id"
        :vehicle-id="bookFor || null"
        @close="bookFor = null"
        @booked="bookFor = null; fetchTimeline()"
      />

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
