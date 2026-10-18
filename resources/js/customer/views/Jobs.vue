<script setup lang="ts">
/**
 * Every visit (in progress and past), newest first.
 */
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api, { message, money, date, type JobCardSummary } from '../api'
import { toast } from '../ui'

const jobs = ref<JobCardSummary[]>([])
const loading = ref(true)

onMounted(async () => {
  try {
    jobs.value = (await api.get('/jobs')).data.data
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <h1 class="text-xl font-bold text-gray-900">My visits</h1>
    <p v-if="loading" class="mt-8 text-center text-gray-500">Loading…</p>
    <p v-else-if="!jobs.length" class="mt-6 rounded-2xl bg-white p-4 text-sm text-gray-500 ring-1 ring-gray-200/80">No visits yet.</p>
    <ul class="mt-4 space-y-2">
      <li v-for="j in jobs" :key="j.id">
        <RouterLink :to="`/jobs/${j.id}`" class="flex items-center justify-between rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200/80">
          <span>
            <span class="block font-semibold text-gray-900">{{ j.registration_no }} · {{ j.plan || 'Repair' }}</span>
            <span class="block text-xs text-gray-500">{{ date(j.created_at) }} · Job {{ j.job_number }}</span>
          </span>
          <span class="text-right text-xs">
            <span class="block font-medium text-gray-700">{{ j.status_label }}</span>
            <span v-if="j.balance" class="block font-semibold text-primary-700">{{ money(j.balance) }} due</span>
          </span>
        </RouterLink>
      </li>
    </ul>
  </div>
</template>
