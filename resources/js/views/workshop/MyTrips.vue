<script setup lang="ts">
/**
 * Driver / home-service technician: my pickups, drops and home visits — on the way
 * now, coming up, and done today. Tapping a trip opens its step-by-step screen.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { PhoneIcon, MapPinIcon, ClockIcon } from '@heroicons/vue/20/solid'
import { tripsApi, slotText, TRIP_STATUS_LABELS, type Trip } from '@/api/trips.api'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'

const router = useRouter()
const toast = useToast()

const trips = ref<Trip[]>([])
const loading = ref(true)
let timer: ReturnType<typeof setInterval> | undefined

const now = computed(() => trips.value.filter((t) => ['en_route', 'collected', 'arrived'].includes(t.status)))
const next = computed(() => trips.value.filter((t) => t.status === 'scheduled'))
const done = computed(() => trips.value.filter((t) => t.status === 'done'))

async function load(quiet = false) {
  if (!quiet) loading.value = true
  try {
    trips.value = (await tripsApi.mine()).data.data
  } catch (err) {
    if (!quiet) toast.apiError(err)
  } finally {
    loading.value = false
  }
}

const kindClass: Record<string, string> = {
  pickup: 'border-l-blue-500',
  drop: 'border-l-green-600',
  home_visit: 'border-l-indigo-500',
}

onMounted(() => {
  load()
  // New trips given to me show up without a refresh
  timer = setInterval(() => load(true), 60000)
})
onUnmounted(() => clearInterval(timer))
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">My Trips</h1>
      <p class="text-gray-500 mt-1">Pickups, drops and home visits given to you</p>
    </div>

    <div v-if="loading" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <p v-else-if="!trips.length" class="text-center text-gray-500 py-12">No trips for you right now.</p>

    <div v-else class="space-y-6">
      <section v-for="group in [
        { title: 'Now', list: now },
        { title: 'Next', list: next },
        { title: 'Done today', list: done },
      ]" v-show="group.list.length" :key="group.title">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-2">{{ group.title }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <Card
            v-for="trip in group.list"
            :key="trip.id"
            class="cursor-pointer border-l-4 hover:shadow-md active:scale-[0.99] transition"
            :class="[kindClass[trip.kind], trip.status === 'done' ? 'opacity-70' : '']"
            @click="router.push(`/trips/${trip.id}`)"
          >
            <div class="flex items-start justify-between gap-2">
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ trip.label }}</p>
                <h3 class="text-lg font-bold tracking-wide text-gray-900">{{ trip.job?.vehicle?.registration_no }}</h3>
                <p class="text-xs text-gray-500">{{ trip.job?.vehicle?.name }} · {{ trip.job?.job_number }}</p>
              </div>
              <Badge :variant="trip.status === 'done' ? 'success' : trip.status === 'scheduled' ? 'default' : 'info'" size="sm">
                {{ TRIP_STATUS_LABELS[trip.status] }}
              </Badge>
            </div>

            <div class="mt-3 space-y-1.5 text-sm text-gray-700">
              <p class="flex items-start gap-1.5"><ClockIcon class="h-4 w-4 mt-0.5 shrink-0 text-gray-400" />{{ slotText(trip) }}</p>
              <p v-if="trip.address" class="flex items-start gap-1.5">
                <MapPinIcon class="h-4 w-4 mt-0.5 shrink-0 text-gray-400" />
                <span class="line-clamp-2">{{ trip.address }}<span v-if="trip.landmark" class="text-gray-500"> ({{ trip.landmark }})</span></span>
              </p>
              <p v-if="trip.job?.customer" class="flex items-center gap-1.5">
                <PhoneIcon class="h-4 w-4 shrink-0 text-gray-400" />
                {{ trip.job.customer.name }}
                <a
                  v-if="trip.job.customer.phone"
                  :href="`tel:${trip.job.customer.phone}`"
                  class="ml-auto text-primary-700 font-medium"
                  @click.stop
                >Call</a>
              </p>
            </div>
          </Card>
        </div>
      </section>
    </div>
  </div>
</template>
