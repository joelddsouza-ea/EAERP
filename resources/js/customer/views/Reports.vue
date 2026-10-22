<script setup lang="ts">
/**
 * Problem reports on the fleet's cabs (a driver: their cabs) and what the workshop did with
 * each: booked in, at the workshop (with the job), or closed with a note.
 */
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { ChevronLeftIcon, PlusIcon, PlayIcon } from '@heroicons/vue/24/outline'
import api, { message, date, type FleetReport } from '../api'
import { toast } from '../ui'

const reports = ref<FleetReport[] | null>(null)

onMounted(async () => {
  try {
    reports.value = (await api.get('/reports')).data.data.reports
  } catch (err) {
    toast(message(err), 'error')
  }
})

const tone = (s: FleetReport['status']) => (s === 'open' ? 'bg-amber-50 text-amber-800' : s === 'closed' ? 'bg-gray-100 text-gray-600' : 'bg-green-50 text-green-700')
</script>

<template>
  <div>
    <RouterLink to="/" class="-ml-1 mb-2 inline-flex items-center text-sm text-gray-600"><ChevronLeftIcon class="h-4 w-4" /> Home</RouterLink>
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-bold text-gray-900">Problem reports</h1>
      <RouterLink to="/reports/new" class="inline-flex items-center gap-1 text-sm font-medium text-primary-700"><PlusIcon class="h-4 w-4" /> Report a problem</RouterLink>
    </div>

    <div v-if="!reports" class="mt-10 text-center text-gray-500">Loading…</div>
    <p v-else-if="!reports.length" class="mt-4 rounded-2xl bg-white p-4 text-sm text-gray-500 ring-1 ring-gray-200/80">No problems reported. Something wrong with a cab? Report it here and the workshop will call.</p>
    <ul v-else class="mt-4 space-y-3">
      <li v-for="r in reports" :key="r.id" class="rounded-2xl bg-white p-4 ring-1 ring-gray-200/80">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="font-semibold text-gray-900">{{ r.vehicle.registration_no }} · {{ r.kind_label }}</p>
            <p class="text-xs text-gray-500">{{ r.number }} · {{ date(r.created_at) }}<template v-if="r.driver"> · {{ r.driver.name }}</template></p>
          </div>
          <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="tone(r.status)">{{ r.status_label }}</span>
        </div>
        <p class="mt-2 text-sm text-gray-700">{{ r.description }}</p>
        <div v-if="r.media.length" class="mt-2 flex gap-2 overflow-x-auto">
          <a v-for="m in r.media" :key="m.id" :href="m.url" target="_blank" class="relative block h-16 w-16 shrink-0 overflow-hidden rounded bg-gray-200">
            <img v-if="m.media_type === 'photo'" :src="m.url" class="h-full w-full object-cover" alt="" />
            <PlayIcon v-else class="absolute inset-0 m-auto h-6 w-6 text-gray-700" />
          </a>
        </div>
        <p v-if="r.booking" class="mt-2 text-sm text-green-800">Booked {{ date(r.booking.date) }}<template v-if="r.booking.slot">, {{ r.booking.slot }}</template>{{ r.booking.mode === 'pickup_drop' ? ' — our driver will pick it up' : '' }}</p>
        <RouterLink v-if="r.job" :to="`/jobs/${r.job.id}`" class="mt-2 block text-sm font-medium text-primary-700">Job {{ r.job.job_number }} ›</RouterLink>
        <p v-if="r.staff_note" class="mt-2 rounded-lg bg-gray-50 p-2 text-sm text-gray-700">Workshop: {{ r.staff_note }}</p>
      </li>
    </ul>
  </div>
</template>
