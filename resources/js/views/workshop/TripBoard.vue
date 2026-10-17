<script setup lang="ts">
/**
 * Pickups & drops board for advisors / managers: one day's pickups, drops and home
 * visits by driver, plus everything not yet given to anyone or still going.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { tripsApi, slotText, TRIP_STATUS_LABELS, type Trip, type TripPerson } from '@/api/trips.api'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'

const router = useRouter()
const toast = useToast()

const date = ref(new Date().toISOString().slice(0, 10))
const trips = ref<Trip[]>([])
const people = ref<TripPerson[]>([])
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    const data = (await tripsApi.board({ date: date.value })).data.data
    trips.value = data.trips
    people.value = data.people
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

/** Columns: not assigned, then each person with trips */
const columns = computed(() => {
  const cols: { key: string; title: string; trips: Trip[] }[] = []
  const unassigned = trips.value.filter((t) => !t.assigned_to)
  if (unassigned.length) cols.push({ key: 'none', title: 'Not assigned', trips: unassigned })
  const byPerson = new Map<number, Trip[]>()
  for (const t of trips.value) {
    if (t.assigned_to) byPerson.set(t.assigned_to, [...(byPerson.get(t.assigned_to) ?? []), t])
  }
  for (const [id, list] of byPerson) {
    const name = people.value.find((p) => p.id === id)?.name ?? list[0].assignee?.name ?? 'Someone'
    cols.push({ key: String(id), title: name, trips: list })
  }
  return cols
})

const counts = computed(() => ({
  pickup: trips.value.filter((t) => t.kind === 'pickup').length,
  drop: trips.value.filter((t) => t.kind === 'drop').length,
  home: trips.value.filter((t) => t.kind === 'home_visit').length,
}))

const variant = (t: Trip) => (t.status === 'done' ? 'success' : t.status === 'unassigned' ? 'warning' : t.status === 'scheduled' ? 'default' : 'info')
const kindClass: Record<string, string> = { pickup: 'border-l-blue-500', drop: 'border-l-green-600', home_visit: 'border-l-indigo-500' }

watch(date, load)
onMounted(load)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Pickups &amp; Drops</h1>
        <p class="text-gray-500 mt-1">
          {{ counts.pickup }} pickup{{ counts.pickup === 1 ? '' : 's' }} · {{ counts.drop }} drop{{ counts.drop === 1 ? '' : 's' }} ·
          {{ counts.home }} home visit{{ counts.home === 1 ? '' : 's' }}
        </p>
      </div>
      <input v-model="date" type="date" class="input w-44" />
    </div>

    <div v-if="loading" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <p v-else-if="!trips.length" class="text-center text-gray-500 py-12">No trips for this day.</p>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      <section v-for="col in columns" :key="col.key">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-2">{{ col.title }} ({{ col.trips.length }})</h2>
        <div class="space-y-2">
          <Card
            v-for="t in col.trips"
            :key="t.id"
            class="cursor-pointer border-l-4 hover:shadow-md"
            :class="kindClass[t.kind]"
            @click="router.push(t.job ? `/job-cards/${t.job.id}` : `/trips/${t.id}`)"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="text-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ t.label }}</p>
                <p class="font-bold text-gray-900">{{ t.job?.vehicle?.registration_no }} <span class="font-normal text-gray-500">{{ t.job?.job_number }}</span></p>
                <p class="text-gray-600">{{ slotText(t) }}</p>
                <p class="text-gray-500 line-clamp-1">{{ t.job?.customer?.name }} · {{ t.address }}</p>
              </div>
              <Badge :variant="variant(t)" size="sm">{{ TRIP_STATUS_LABELS[t.status] }}</Badge>
            </div>
          </Card>
        </div>
      </section>
    </div>
  </div>
</template>
