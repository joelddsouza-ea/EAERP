<script setup lang="ts">
/**
 * A car's service book: every visit (open it for the report, bill and photos), work recommended
 * earlier, due dates, and the history as a PDF (useful when selling the car).
 */
import { ref, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { ChevronLeftIcon, ArrowDownTrayIcon, CalendarDaysIcon } from '@heroicons/vue/24/outline'
import api, { message, money, date } from '../api'
import { toast } from '../ui'

interface Book {
  vehicle: {
    id: number; car: string; registration_no: string; fuel_type: string | null; year: number | null; color: string | null
    next_service_due: string | null; next_service_due_km: number | null; insurance_expiry: string | null; puc_expiry: string | null
    last_service_at: string | null; last_service_km: number | null; insurance_company: string | null
  }
  visits: { job_id: number; job_number: string; date: string | null; km: number | null; work: string; amount: number | null }[]
  observations: { text: string; remark: string | null; severity: string | null; date: string | null }[]
  total_spent: number
}

const route = useRoute()
const id = Number(route.params.id)
const book = ref<Book | null>(null)
const downloading = ref(false)

onMounted(async () => {
  try {
    book.value = (await api.get(`/vehicles/${id}`)).data.data
  } catch (err) {
    toast(message(err), 'error')
  }
})

async function downloadPdf() {
  downloading.value = true
  try {
    const res = await api.get(`/vehicles/${id}/history.pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `service-history-${book.value?.vehicle.registration_no ?? id}.pdf`
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 30_000)
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <div>
    <RouterLink to="/" class="-ml-1 mb-2 inline-flex items-center text-sm text-gray-600"><ChevronLeftIcon class="h-4 w-4" /> Home</RouterLink>
    <p v-if="!book" class="mt-10 text-center text-gray-500">Loading…</p>
    <template v-else>
      <h1 class="text-xl font-bold text-gray-900">{{ book.vehicle.registration_no }}</h1>
      <p class="text-sm text-gray-500">{{ book.vehicle.car }}<template v-if="book.vehicle.year"> · {{ book.vehicle.year }}</template><template v-if="book.vehicle.fuel_type"> · {{ book.vehicle.fuel_type }}</template></p>

      <section class="mt-4 grid grid-cols-2 gap-2 text-sm">
        <div class="rounded-2xl bg-white p-3 ring-1 ring-gray-200/80">
          <p class="text-xs text-gray-500">Next service</p>
          <p class="font-semibold">{{ book.vehicle.next_service_due ? date(book.vehicle.next_service_due) : '-' }}</p>
          <p v-if="book.vehicle.next_service_due_km" class="text-xs text-gray-500">or at {{ book.vehicle.next_service_due_km.toLocaleString('en-IN') }} km</p>
        </div>
        <div class="rounded-2xl bg-white p-3 ring-1 ring-gray-200/80">
          <p class="text-xs text-gray-500">Last service</p>
          <p class="font-semibold">{{ book.vehicle.last_service_at ? date(book.vehicle.last_service_at) : '-' }}</p>
          <p v-if="book.vehicle.last_service_km" class="text-xs text-gray-500">{{ book.vehicle.last_service_km.toLocaleString('en-IN') }} km</p>
        </div>
        <div class="rounded-2xl bg-white p-3 ring-1 ring-gray-200/80">
          <p class="text-xs text-gray-500">Insurance</p>
          <p class="font-semibold">{{ book.vehicle.insurance_expiry ? date(book.vehicle.insurance_expiry) : 'Not added' }}</p>
        </div>
        <div class="rounded-2xl bg-white p-3 ring-1 ring-gray-200/80">
          <p class="text-xs text-gray-500">PUC</p>
          <p class="font-semibold">{{ book.vehicle.puc_expiry ? date(book.vehicle.puc_expiry) : 'Not added' }}</p>
        </div>
      </section>
      <RouterLink to="/documents" class="mt-2 block text-right text-xs font-medium text-primary-700">Add insurance / PUC ›</RouterLink>

      <RouterLink :to="`/book?vehicle=${book.vehicle.id}`" class="mt-3 flex items-center justify-center gap-2 rounded-xl bg-primary-600 py-3 font-semibold text-white"><CalendarDaysIcon class="h-5 w-5" /> Book a service for this car</RouterLink>

      <section v-if="book.observations.length" class="mt-5 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200">
        <h2 class="font-semibold text-amber-900">Recommended on earlier visits</h2>
        <ul class="mt-2 space-y-1.5 text-sm text-amber-900">
          <li v-for="(o, i) in book.observations" :key="i">• {{ o.text }}<span v-if="o.remark" class="text-amber-700"> ({{ o.remark }})</span></li>
        </ul>
      </section>

      <section class="mt-5">
        <div class="mb-2 flex items-center justify-between">
          <h2 class="font-semibold text-gray-900">Visits ({{ book.visits.length }})</h2>
          <button type="button" class="inline-flex items-center gap-1 text-sm font-medium text-primary-700" :disabled="downloading" @click="downloadPdf">
            <ArrowDownTrayIcon class="h-4 w-4" /> {{ downloading ? 'Preparing…' : 'History PDF' }}
          </button>
        </div>
        <p v-if="!book.visits.length" class="rounded-2xl bg-white p-4 text-sm text-gray-500 ring-1 ring-gray-200/80">No completed visits yet.</p>
        <ol class="relative space-y-3 border-l-2 border-gray-200 pl-4">
          <li v-for="v in book.visits" :key="v.job_id">
            <span class="absolute -left-[7px] mt-1.5 h-3 w-3 rounded-full bg-primary-600"></span>
            <RouterLink :to="`/jobs/${v.job_id}`" class="block rounded-2xl bg-white p-3 shadow-sm ring-1 ring-gray-200/80">
              <p class="font-semibold text-gray-900">{{ v.work }}</p>
              <p class="text-xs text-gray-500">{{ date(v.date) }}<template v-if="v.km"> · {{ v.km.toLocaleString('en-IN') }} km</template> · Job {{ v.job_number }}</p>
              <p v-if="v.amount" class="mt-1 text-sm text-gray-700">{{ money(v.amount) }} · report &amp; bill ›</p>
            </RouterLink>
          </li>
        </ol>
        <p v-if="book.total_spent" class="mt-3 text-center text-xs text-gray-500">Total spent on this car with us: {{ money(book.total_spent) }}</p>
      </section>
    </template>
  </div>
</template>
