<script setup lang="ts">
/**
 * Workshop › Technician Time: how long each technician actually worked — time in Work In
 * Progress, less waiting for approval / parts and the time the car was at an outside partner,
 * counted in shift hours only (weekly off, holidays, leave and the break left out).
 */
import { ref, watch, onMounted } from 'vue'
import { ArrowDownTrayIcon } from '@heroicons/vue/20/solid'
import { technicianTimeApi, hm, type TechnicianTimeReport } from '@/api/technicianTime.api'
import { localDate } from '@/api/hr.api'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'

const toast = useToast()
const today = localDate()
const from = ref(today.slice(0, 8) + '01')
const to = ref(today)
const technicianId = ref<number | null>(null)
const data = ref<TechnicianTimeReport | null>(null)
const loading = ref(false)

const params = () => ({ from: from.value, to: to.value, technician_id: technicianId.value })

async function load() {
  loading.value = true
  try {
    data.value = (await technicianTimeApi.report(params())).data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

async function downloadCsv() {
  try {
    const blob = (await technicianTimeApi.csv(params())).data as Blob
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `technician-time-${from.value}-to-${to.value}.csv`
    a.click()
    URL.revokeObjectURL(url)
  } catch (err) {
    toast.apiError(err)
  }
}

const day = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')

watch([from, to, technicianId], load)
onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Technician Time</h1>
        <p class="text-sm text-gray-500">
          Working time on jobs in shift hours: time in work, less waiting for approval or parts and time at outside partners.
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2 text-sm">
        <label class="block"><span class="text-gray-600">From</span><input v-model="from" type="date" class="input mt-1 py-1.5" /></label>
        <label class="block"><span class="text-gray-600">To</span><input v-model="to" type="date" class="input mt-1 py-1.5" /></label>
        <label class="block">
          <span class="text-gray-600">Technician</span>
          <select v-model="technicianId" class="input mt-1 py-1.5">
            <option :value="null">All</option>
            <option v-for="t in data?.technician_list ?? []" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
        </label>
        <Button variant="secondary" size="sm" @click="downloadCsv"><ArrowDownTrayIcon class="mr-1 h-4 w-4" /> CSV</Button>
      </div>
    </div>

    <div v-if="loading && !data" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <template v-else-if="data">
      <Card :padding="false" class="mb-4">
        <template #header><h3 class="text-lg font-semibold">By technician</h3></template>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="border-b text-left text-xs text-gray-500">
                <th class="px-4 py-2">Technician</th>
                <th class="px-4 py-2 text-right">Jobs</th>
                <th class="px-4 py-2 text-right">Working time</th>
                <th class="px-4 py-2 text-right">Average per job</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in data.technicians" :key="t.id" class="border-b border-gray-50">
                <td class="px-4 py-2 font-medium text-gray-900">{{ t.name }}</td>
                <td class="px-4 py-2 text-right">{{ t.jobs }}</td>
                <td class="px-4 py-2 text-right">{{ hm(t.working_minutes) }}</td>
                <td class="px-4 py-2 text-right">{{ hm(t.average_minutes) }}</td>
              </tr>
              <tr v-if="!data.technicians.length"><td colspan="4" class="px-4 py-6 text-center text-gray-500">No work in these dates.</td></tr>
            </tbody>
          </table>
        </div>
      </Card>

      <Card :padding="false">
        <template #header><h3 class="text-lg font-semibold">By job</h3></template>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="border-b text-left text-xs text-gray-500">
                <th class="px-4 py-2">Job</th>
                <th class="px-4 py-2">Technicians</th>
                <th class="px-4 py-2">Work started → submitted</th>
                <th class="px-4 py-2 text-right">In work</th>
                <th class="px-4 py-2 text-right">Waiting approval</th>
                <th class="px-4 py-2 text-right">Waiting parts</th>
                <th class="px-4 py-2 text-right">At outside partner</th>
                <th class="px-4 py-2 text-right">Working time</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="j in data.jobs" :key="j.id" class="border-b border-gray-50">
                <td class="px-4 py-2">
                  <RouterLink :to="`/job-cards/${j.id}`" class="font-medium text-primary-700 hover:underline">{{ j.job_number }}</RouterLink>
                  <span class="block text-xs text-gray-500">{{ j.registration_no }} · {{ j.service }}</span>
                </td>
                <td class="px-4 py-2 text-gray-700">{{ j.technicians.join(', ') || '—' }}</td>
                <td class="px-4 py-2 whitespace-nowrap text-gray-600">{{ day(j.work_started_at) }} → {{ day(j.work_completed_at) }}</td>
                <td class="px-4 py-2 text-right">{{ hm(j.gross_minutes) }}</td>
                <td class="px-4 py-2 text-right text-gray-500">{{ hm(j.approval_minutes) }}</td>
                <td class="px-4 py-2 text-right text-gray-500">{{ hm(j.parts_minutes) }}</td>
                <td class="px-4 py-2 text-right text-gray-500">{{ hm(j.outside_minutes) }}</td>
                <td class="px-4 py-2 text-right font-semibold text-gray-900">{{ hm(j.working_minutes) }}</td>
              </tr>
              <tr v-if="!data.jobs.length"><td colspan="8" class="px-4 py-6 text-center text-gray-500">No work in these dates.</td></tr>
            </tbody>
          </table>
        </div>
      </Card>
      <p class="mt-3 text-xs text-gray-500">
        Hours count only inside each technician's shift (Attendance &amp; payroll › Shifts; the default shift otherwise), without weekly offs,
        holidays and leave; the break is taken out. Every technician on a job gets the job's full working time. Technicians are those on the
        job now — someone taken off a job is not counted for it.
      </p>
    </template>
  </div>
</template>
