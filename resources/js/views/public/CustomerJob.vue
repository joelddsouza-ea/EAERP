<script setup lang="ts">
/**
 * The customer's job page, opened from WhatsApp without logging in. Shows the job
 * status, the estimate (approve / decline each additional job, approve all,
 * comment), shared photos and videos, and the service report once released.
 */
import { ref, computed, onMounted, reactive } from 'vue'
import { useRoute } from 'vue-router'
import { format } from 'date-fns'
import { CheckIcon, XMarkIcon, PhoneIcon, DocumentArrowDownIcon, PlayIcon } from '@heroicons/vue/20/solid'
import { publicJobApi, type Decision } from '@/api/estimates.api'
import type { PublicJobPage, EstimateLine } from '@/api/types'
import VoiceInput from '@/components/ui/VoiceInput.vue'

const route = useRoute()
const token = String(route.params.token)

const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const page = ref<PublicJobPage | null>(null)
const showPlan = ref(false)
const comment = ref('')

/** Choices the customer has made on this screen but not saved yet */
const choices = reactive<Record<string, 'approved' | 'declined'>>({})

const estimate = computed(() => page.value?.estimate ?? null)
const lines = computed(() => estimate.value?.additional_lines ?? [])
const changed = computed(() =>
  lines.value.filter((l) => choices[l.line_key] && choices[l.line_key] !== l.approval_status)
)

/** Total as it will be once the unsaved choices are saved */
const previewTotal = computed(() => {
  const e = estimate.value
  if (!e) return 0
  const extras = lines.value
    .filter((l) => (choices[l.line_key] ?? l.approval_status) !== 'declined')
    .reduce((sum, l) => sum + l.amount, 0)
  const pct = Math.round((extras * e.extras_discount_pct) / 100)
  const extrasTotal = Math.max(0, extras - pct - e.extras_discount_flat)
  return Math.round(e.plan_total + extrasTotal)
})

function money(n: number, decimals = 0) {
  return '₹ ' + new Intl.NumberFormat('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(n)
}

function when(d: string | null | undefined) {
  return d ? format(new Date(d), 'dd MMM, h:mm a') : ''
}

function statusOf(line: EstimateLine) {
  return choices[line.line_key] ?? line.approval_status
}

function choose(line: EstimateLine, decision: 'approved' | 'declined') {
  choices[line.line_key] = decision
}

function approveAll() {
  for (const l of lines.value) choices[l.line_key] = 'approved'
}

function declineAll() {
  for (const l of lines.value) choices[l.line_key] = 'declined'
}

function resetChoices() {
  for (const k of Object.keys(choices)) delete choices[k]
}

function errorMessage(err: any): string {
  return err?.response?.data?.message ?? 'Something went wrong. Please try again or call the workshop.'
}

async function load() {
  loading.value = true
  try {
    page.value = (await publicJobApi.get(token)).data.data
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}

async function save() {
  const decisions: Decision[] = changed.value.map((l) => ({ line_key: l.line_key, decision: choices[l.line_key] }))
  if (!decisions.length && !comment.value.trim()) return

  saving.value = true
  notice.value = null
  try {
    page.value = decisions.length
      ? (await publicJobApi.decide(token, decisions, comment.value.trim() || undefined)).data.data
      : (await publicJobApi.comment(token, comment.value.trim())).data.data
    resetChoices()
    comment.value = ''
    notice.value = 'Thank you, the workshop has your answer.'
  } catch (err) {
    notice.value = errorMessage(err)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="min-h-screen bg-gray-50 pb-28">
    <div v-if="loading" class="flex justify-center pt-24 text-gray-500">Loading…</div>

    <div v-else-if="error" class="max-w-md mx-auto px-4 pt-24 text-center">
      <p class="text-lg font-semibold text-gray-900 mb-2">Link not available</p>
      <p class="text-gray-600">{{ error }}</p>
    </div>

    <template v-else-if="page">
      <!-- Header -->
      <header class="bg-primary-700 text-white px-4 pt-5 pb-6">
        <div class="max-w-xl mx-auto">
          <p class="text-sm opacity-80">{{ page.workshop.name }}</p>
          <h1 class="text-xl font-bold mt-1">{{ page.job.registration_no }}</h1>
          <p class="text-sm opacity-90">{{ page.job.car }} · Job {{ page.job.job_number }}</p>
          <div class="mt-3 inline-flex items-center rounded-full bg-white/15 px-3 py-1 text-sm font-medium">
            {{ page.job.status_label }}
          </div>
          <p v-if="page.job.plan" class="mt-2 text-sm opacity-90">Plan: {{ page.job.plan }}</p>
        </div>
      </header>

      <main class="max-w-xl mx-auto px-4 -mt-3 space-y-4">
        <!-- Estimate -->
        <section v-if="estimate" class="rounded-xl bg-white shadow-sm border border-gray-200">
          <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 class="font-semibold">Estimate <span v-if="estimate.version > 1" class="text-xs text-gray-500">(updated)</span></h2>
            <a :href="publicJobApi.estimatePdfUrl(token)" target="_blank" class="text-sm text-primary-600 inline-flex items-center gap-1">
              <DocumentArrowDownIcon class="h-4 w-4" /> PDF
            </a>
          </div>

          <!-- Plan (included) -->
          <div v-if="estimate.package_name" class="px-4 py-3 border-b border-gray-100">
            <button class="w-full flex items-center justify-between text-left" @click="showPlan = !showPlan">
              <span>
                <span class="font-medium">{{ estimate.package_name }}</span>
                <span class="block text-xs text-gray-500">Service plan · tap to {{ showPlan ? 'hide' : 'see' }} what's included</span>
              </span>
              <span class="font-semibold">{{ money(estimate.package_price) }}</span>
            </button>
            <ul v-if="showPlan" class="mt-2 space-y-1 text-sm text-gray-600">
              <li v-for="l in estimate.plan_lines" :key="l.id" class="flex justify-between gap-3">
                <span>{{ l.description }}</span><span class="shrink-0">{{ money(l.amount, 0) }}</span>
              </li>
            </ul>
            <p v-if="estimate.plan_discount > 0" class="mt-1 text-sm text-green-700">Discount − {{ money(estimate.plan_discount) }}</p>
          </div>

          <!-- Additional jobs to approve -->
          <div v-if="lines.length" class="px-4 py-3">
            <div class="flex items-center justify-between mb-2">
              <h3 class="font-medium">Additional work</h3>
              <div v-if="page.decisions_open" class="flex gap-4">
                <button
                  v-if="lines.some((l) => statusOf(l) !== 'declined')"
                  class="text-sm text-gray-600 font-medium"
                  @click="declineAll"
                >
                  Decline all
                </button>
                <button
                  v-if="lines.some((l) => statusOf(l) !== 'approved')"
                  class="text-sm text-primary-600 font-medium"
                  @click="approveAll"
                >
                  Approve all
                </button>
              </div>
            </div>

            <ul class="space-y-3">
              <li v-for="line in lines" :key="line.line_key" class="rounded-lg border p-3" :class="statusOf(line) === 'declined' ? 'border-gray-200 bg-gray-50' : statusOf(line) === 'approved' ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50'">
                <div class="flex justify-between gap-3">
                  <p class="font-medium text-gray-900">{{ line.description }}</p>
                  <p class="font-semibold shrink-0">{{ money(line.amount) }}</p>
                </div>

                <div v-if="line.media.length" class="mt-2 flex gap-2 overflow-x-auto">
                  <a v-for="m in line.media" :key="m.id" :href="m.url" target="_blank" class="relative block h-16 w-16 shrink-0 overflow-hidden rounded bg-gray-200">
                    <img v-if="m.thumbnail_url" :src="m.thumbnail_url" class="h-full w-full object-cover" alt="" />
                    <PlayIcon v-if="m.media_type === 'video'" class="absolute inset-0 m-auto h-6 w-6 text-white drop-shadow" />
                  </a>
                </div>

                <p v-if="line.decided_via === 'advisor_on_behalf' && !choices[line.line_key]" class="mt-2 text-xs text-gray-600">
                  Recorded by {{ line.decided_by }} on {{ when(line.decided_at) }} after speaking with you.
                </p>

                <div v-if="page.decisions_open" class="mt-3 grid grid-cols-2 gap-2">
                  <button
                    class="inline-flex items-center justify-center gap-1 rounded-lg border py-2 text-sm font-medium"
                    :class="statusOf(line) === 'approved' ? 'bg-green-600 border-green-600 text-white' : 'bg-white border-gray-300 text-gray-700'"
                    @click="choose(line, 'approved')"
                  >
                    <CheckIcon class="h-4 w-4" /> Approve
                  </button>
                  <button
                    class="inline-flex items-center justify-center gap-1 rounded-lg border py-2 text-sm font-medium"
                    :class="statusOf(line) === 'declined' ? 'bg-gray-700 border-gray-700 text-white' : 'bg-white border-gray-300 text-gray-700'"
                    @click="choose(line, 'declined')"
                  >
                    <XMarkIcon class="h-4 w-4" /> Not now
                  </button>
                </div>
                <p v-else class="mt-2 text-sm" :class="line.approval_status === 'approved' ? 'text-green-700' : 'text-gray-600'">
                  {{ line.approval_label }}
                </p>
              </li>
            </ul>
          </div>

          <div class="px-4 py-3 border-t border-gray-100 bg-gray-50 rounded-b-xl">
            <div class="flex justify-between font-bold text-lg">
              <span>Total</span><span>{{ money(changed.length ? previewTotal : estimate.grand_total) }}</span>
            </div>
            <p class="text-xs text-gray-500">All prices include GST. Work not approved is not charged.</p>
            <p v-if="estimate.next_service_km || estimate.next_service_date" class="text-xs text-gray-500 mt-1">
              Next service: <span v-if="estimate.next_service_km">{{ estimate.next_service_km.toLocaleString('en-IN') }} km</span>
              <span v-if="estimate.next_service_km && estimate.next_service_date"> or </span>
              <span v-if="estimate.next_service_date">{{ format(new Date(estimate.next_service_date), 'dd MMM yyyy') }}</span>, whichever is earlier
            </p>
          </div>

          <!-- Comments -->
          <div class="px-4 py-3 border-t border-gray-100">
            <ul v-if="estimate.comments.length" class="space-y-2 mb-3">
              <li v-for="c in estimate.comments" :key="c.id" class="rounded-lg p-2 text-sm" :class="c.author_type === 'customer' ? 'bg-blue-50 ml-6' : 'bg-gray-100 mr-6'">
                <p class="text-xs text-gray-500">{{ c.author_type === 'customer' ? 'You' : c.author ?? 'Workshop' }} · {{ when(c.created_at) }}</p>
                <p class="whitespace-pre-wrap">{{ c.body }}</p>
              </li>
            </ul>
            <label class="block text-sm text-gray-600 mb-1">Message to the workshop (optional)</label>
            <div class="flex gap-2">
              <textarea v-model="comment" rows="2" class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="Type or tap the mic" />
              <VoiceInput @text="(t) => (comment = (comment ? comment + ' ' : '') + t)" />
            </div>
          </div>
        </section>

        <section v-else class="rounded-xl bg-white shadow-sm border border-gray-200 p-4 text-sm text-gray-600">
          The estimate will appear here once the workshop has prepared it.
        </section>

        <!-- Photos & videos -->
        <section v-if="page.media.length" class="rounded-xl bg-white shadow-sm border border-gray-200 p-4">
          <h2 class="font-semibold mb-3">Photos &amp; videos</h2>
          <div class="grid grid-cols-3 gap-2">
            <a v-for="m in page.media" :key="m.id" :href="m.url" target="_blank" class="relative block aspect-square overflow-hidden rounded bg-gray-200">
              <img v-if="m.thumbnail_url" :src="m.thumbnail_url" class="h-full w-full object-cover" :alt="m.description ?? ''" />
              <span v-else class="flex h-full items-center justify-center text-xs text-gray-500">{{ m.media_type }}</span>
              <PlayIcon v-if="m.media_type === 'video'" class="absolute inset-0 m-auto h-8 w-8 text-white drop-shadow" />
            </a>
          </div>
        </section>

        <!-- Service report -->
        <section v-if="page.report" class="rounded-xl bg-white shadow-sm border border-gray-200 p-4">
          <div class="flex items-center justify-between mb-2">
            <h2 class="font-semibold">{{ page.report.title }}</h2>
            <a :href="publicJobApi.reportPdfUrl(token)" target="_blank" class="text-sm text-primary-600 inline-flex items-center gap-1">
              <DocumentArrowDownIcon class="h-4 w-4" /> PDF
            </a>
          </div>
          <p v-if="page.report.activities_carried_out" class="text-sm text-gray-600 mb-2">
            {{ page.report.activities_carried_out }} activity points carried out.
          </p>
          <div v-if="page.report.additional_jobs.length" class="mb-2">
            <p class="text-sm font-medium">Additional jobs done</p>
            <ul class="list-disc ml-5 text-sm text-gray-700"><li v-for="j in page.report.additional_jobs" :key="j">{{ j }}</li></ul>
          </div>
          <div v-if="page.report.observations.length" class="mb-2">
            <p class="text-sm font-medium">Observations</p>
            <ul class="list-disc ml-5 text-sm text-gray-700">
              <li v-for="o in page.report.observations" :key="o.text">
                {{ o.text }} <span v-if="o.severity" class="text-xs" :class="o.severity === 'major' ? 'text-red-600' : 'text-amber-600'">({{ o.severity }})</span>
              </li>
            </ul>
          </div>
          <div v-if="page.report.not_approved.length">
            <p class="text-sm font-medium">{{ page.report.not_approved_label }}</p>
            <ul class="list-disc ml-5 text-sm text-gray-700"><li v-for="j in page.report.not_approved" :key="j">{{ j }}</li></ul>
          </div>
        </section>

        <!-- Timeline -->
        <section class="rounded-xl bg-white shadow-sm border border-gray-200 p-4">
          <h2 class="font-semibold mb-2">Progress</h2>
          <ol class="space-y-2">
            <li v-for="t in page.job.timeline" :key="t.at + t.status" class="flex justify-between text-sm">
              <span>{{ t.label }}</span><span class="text-gray-500">{{ when(t.at) }}</span>
            </li>
          </ol>
          <a
            v-if="page.workshop.phone || page.job.advisor_phone"
            :href="`tel:${page.job.advisor_phone || page.workshop.phone}`"
            class="mt-4 flex items-center justify-center gap-2 rounded-lg border border-gray-300 py-2 text-sm font-medium"
          >
            <PhoneIcon class="h-4 w-4" /> Call {{ page.job.advisor || 'the workshop' }}
          </a>
        </section>
      </main>

      <!-- Save bar -->
      <div
        v-if="estimate && (changed.length || comment.trim() || notice)"
        class="fixed inset-x-0 bottom-0 border-t border-gray-200 bg-white px-4 py-3 shadow-lg"
      >
        <div class="max-w-xl mx-auto">
          <p v-if="notice && !changed.length && !comment.trim()" class="text-sm text-center text-gray-700">{{ notice }}</p>
          <div v-else class="flex items-center gap-3">
            <p class="flex-1 text-sm text-gray-600">
              <span v-if="changed.length">{{ changed.length }} change(s) · new total {{ money(previewTotal) }}</span>
              <span v-else>Send your message</span>
            </p>
            <button v-if="changed.length" class="text-sm text-gray-500" @click="resetChoices">Undo</button>
            <button
              class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
              :disabled="saving"
              @click="save"
            >
              {{ saving ? 'Saving…' : 'Confirm' }}
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
