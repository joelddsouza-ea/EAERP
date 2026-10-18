<script setup lang="ts">
/**
 * One job: the driver on the way (live), paying by UPI, and the full job page (stages,
 * estimate approvals, photos and videos, report and bill) — the same page as the WhatsApp
 * link, with "Why is this needed?" on estimate lines when the assistant is on.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { ChevronLeftIcon } from '@heroicons/vue/24/outline'
import api, { message, type JobScreen } from '../api'
import { toast } from '../ui'
import CustomerJob from '@/views/public/CustomerJob.vue'
import DriverMap from '../components/DriverMap.vue'
import PayByUpi from '../components/PayByUpi.vue'

const route = useRoute()
const id = Number(route.params.id)
const screen = ref<JobScreen | null>(null)
const assistantOn = ref(false)
const error = ref<string | null>(null)

async function load() {
  try {
    screen.value = (await api.get(`/jobs/${id}`)).data.data
  } catch (err) {
    if (!screen.value) error.value = message(err)
  }
}

async function explain(lineKey: string): Promise<string> {
  return (await api.post(`/jobs/${id}/explain`, { line_key: lineKey })).data.data.text
}

// The driver's position moves: refresh every 30 s while they're on the way
let timer: ReturnType<typeof setInterval> | undefined
onMounted(async () => {
  await load()
  try {
    assistantOn.value = (await api.get('/assistant')).data.data.enabled
  } catch {
    /* assistant status is optional */
  }
  timer = setInterval(() => {
    if (screen.value?.driver && document.visibilityState === 'visible') load()
  }, 30_000)
})
onBeforeUnmount(() => clearInterval(timer))

function claimed() {
  load().catch((err) => toast(message(err), 'error'))
}
</script>

<template>
  <div>
    <RouterLink to="/" class="-ml-1 mb-2 inline-flex items-center text-sm text-gray-600"><ChevronLeftIcon class="h-4 w-4" /> Home</RouterLink>
    <p v-if="error" class="rounded-2xl bg-white p-4 text-gray-700 ring-1 ring-gray-200/80">{{ error }}</p>

    <template v-else-if="screen">
      <div class="mb-3">
        <h1 class="text-xl font-bold text-gray-900">{{ screen.job.registration_no }}</h1>
        <p class="text-sm text-gray-500">{{ screen.job.car }} · Job {{ screen.job.job_number }}</p>
        <p class="mt-2 inline-flex rounded-full bg-primary-600 px-3 py-1 text-sm font-medium text-white">{{ screen.job.status_label }}</p>
      </div>

      <div class="space-y-4">
        <DriverMap v-if="screen.driver" :driver="screen.driver" />
        <PayByUpi v-if="screen.payment.bill_total !== null || screen.payment.claims.length" :job-id="screen.job.id" :job-number="screen.job.job_number" :payment="screen.payment" @claimed="claimed" />
        <CustomerJob :token="screen.page_token" embedded :explain="assistantOn ? explain : undefined" />
      </div>
    </template>
    <p v-else class="mt-10 text-center text-gray-500">Loading…</p>
  </div>
</template>
