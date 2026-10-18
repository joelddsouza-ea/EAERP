<script setup lang="ts">
/**
 * The customer's bookings: upcoming ones can be cancelled here.
 */
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api, { message, date } from '../api'
import { toast } from '../ui'

interface BookingRow {
  id: number; number: string; date: string; slot: string | null; status: string; status_label: string
  vehicle: string | null; work: string; mode: string; branch: string | null; can_cancel: boolean; job_card_id: number | null
}

const rows = ref<BookingRow[]>([])
const loading = ref(true)
const confirming = ref<number | null>(null)

async function load() {
  try {
    rows.value = (await api.get('/bookings')).data.data
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    loading.value = false
  }
}

async function cancel(b: BookingRow) {
  if (confirming.value !== b.id) {
    confirming.value = b.id
    return
  }
  try {
    toast((await api.post(`/bookings/${b.id}/cancel`)).data.message)
    confirming.value = null
    await load()
  } catch (err) {
    toast(message(err), 'error')
  }
}

const modeLabel: Record<string, string> = { drive_in: 'You bring the car', pickup_drop: 'Pickup & drop', home: 'At your home' }

onMounted(load)
</script>

<template>
  <div>
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-bold text-gray-900">My bookings</h1>
      <RouterLink to="/book" class="text-sm font-medium text-primary-700">+ New</RouterLink>
    </div>
    <p v-if="loading" class="mt-8 text-center text-gray-500">Loading…</p>
    <p v-else-if="!rows.length" class="mt-4 rounded-2xl bg-white p-4 text-sm text-gray-500 ring-1 ring-gray-200/80">No bookings yet.</p>
    <ul class="mt-4 space-y-3">
      <li v-for="b in rows" :key="b.id" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200/80">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="font-semibold text-gray-900">{{ b.work }}</p>
            <p class="text-sm text-gray-600">{{ date(b.date) }}<template v-if="b.slot"> · {{ b.slot }}</template></p>
            <p class="text-xs text-gray-500">{{ b.vehicle }} · {{ modeLabel[b.mode] ?? b.mode }}<template v-if="b.branch"> · {{ b.branch }}</template></p>
          </div>
          <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">{{ b.status_label }}</span>
        </div>
        <div class="mt-3 flex items-center justify-between text-sm">
          <span class="text-xs text-gray-400">{{ b.number }}</span>
          <RouterLink v-if="b.job_card_id" :to="`/jobs/${b.job_card_id}`" class="font-medium text-primary-700">Track the job ›</RouterLink>
          <button v-else-if="b.can_cancel" type="button" class="font-medium text-red-700" @click="cancel(b)">{{ confirming === b.id ? 'Tap again to cancel' : 'Cancel' }}</button>
        </div>
      </li>
    </ul>
  </div>
</template>
