<script setup lang="ts">
/**
 * The customer's job page, opened from WhatsApp without logging in. Shows the job
 * status, the estimate (approve / decline each additional job, approve all,
 * comment), photos and videos section by section, the bill and the service report.
 * It refreshes itself when anything changes, so the customer can keep it open.
 */
import { ref, computed, onMounted, onBeforeUnmount, reactive } from 'vue'
import { useRoute } from 'vue-router'
import { format } from 'date-fns'
import { CheckIcon, XMarkIcon, PhoneIcon, DocumentArrowDownIcon, PlayIcon } from '@heroicons/vue/20/solid'
import { publicJobApi } from '@/api/publicJob.api'
import type { Decision } from '@/api/estimates.api'
import type { PublicJobPage, EstimateLine } from '@/api/types'
import VoiceInput from '@/components/ui/VoiceInput.vue'

/**
 * Also shown inside the customer app (embedded: no logo bar; the app passes the page token and,
 * when its assistant is on, an "explain" handler that adds "Why is this needed?" to each line).
 */
const props = defineProps<{
  token?: string
  embedded?: boolean
  explain?: (lineKey: string) => Promise<string>
}>()

const route = useRoute()
const token = props.token ?? String(route.params.token)

const explanations = reactive<Record<string, string>>({})
const explaining = ref<string | null>(null)
async function askWhy(lineKey: string) {
  if (!props.explain || explanations[lineKey]) return
  explaining.value = lineKey
  try {
    explanations[lineKey] = await props.explain(lineKey)
  } catch (err: any) {
    explanations[lineKey] = err?.response?.data?.message ?? 'Ask your service advisor about this item.'
  } finally {
    explaining.value = null
  }
}

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
/** Additional work shown for approval; what was not approved is listed under Observations */
const workLines = computed(() => lines.value.filter((l) => l.approval_status !== 'declined' || choices[l.line_key] === 'approved'))
const declinedLine = (key: string | null) =>
  key ? lines.value.find((l) => l.line_key === key && l.approval_status === 'declined' && choices[l.line_key] !== 'approved') ?? null : null
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

/** A fleet driver can't change what the fleet owner decided */
const ownerLine = (line: EstimateLine) => !!page.value?.fleet?.owner_lines.includes(line.line_key)

function choose(line: EstimateLine, decision: 'approved' | 'declined') {
  if (ownerLine(line)) return
  choices[line.line_key] = decision
}

function approveAll() {
  for (const l of workLines.value) if (!ownerLine(l)) choices[l.line_key] = 'approved'
}

function declineAll() {
  for (const l of workLines.value) if (!ownerLine(l)) choices[l.line_key] = 'declined'
}

function resetChoices() {
  for (const k of Object.keys(choices)) delete choices[k]
}

function errorMessage(err: any): string {
  return err?.response?.data?.message ?? 'Something went wrong. Please try again or call the workshop.'
}

async function load() {
  loading.value = !page.value
  try {
    page.value = (await publicJobApi.get(token)).data.data
    loadedAt = Date.now()
  } catch (err) {
    if (!page.value) error.value = errorMessage(err)
  } finally {
    loading.value = false
  }
}

// ---- refreshes itself: a cheap check every 20 s while the page is on screen, and when the
// customer comes back to it. Unsaved approve/decline choices and the comment are kept.
const POLL_MS = 20_000
// Photo/video links expire after a while: reload fully every 15 minutes anyway
const MAX_AGE_MS = 15 * 60_000
const updatedNote = ref(false)
let loadedAt = 0
let pollTimer: ReturnType<typeof setInterval> | undefined

async function checkForUpdates() {
  if (document.visibilityState !== 'visible' || !page.value || saving.value) return
  try {
    const { version } = (await publicJobApi.version(token)).data.data
    if (version !== page.value.version || Date.now() - loadedAt > MAX_AGE_MS) {
      const changedContent = version !== page.value.version
      await load()
      if (changedContent) {
        updatedNote.value = true
        setTimeout(() => (updatedNote.value = false), 6000)
      }
    }
  } catch {
    // offline for a moment: try again next time
  }
}

const onVisible = () => document.visibilityState === 'visible' && checkForUpdates()

onMounted(() => {
  pollTimer = setInterval(checkForUpdates, POLL_MS)
  document.addEventListener('visibilitychange', onVisible)
})
onBeforeUnmount(() => {
  clearInterval(pollTimer)
  document.removeEventListener('visibilitychange', onVisible)
})

async function save() {
  const decisions: Decision[] = changed.value.map((l) => ({ line_key: l.line_key, decision: choices[l.line_key] }))
  if (!decisions.length && !comment.value.trim()) return

  saving.value = true
  notice.value = null
  try {
    const res = decisions.length
      ? await publicJobApi.decide(token, decisions, comment.value.trim() || undefined)
      : await publicJobApi.comment(token, comment.value.trim())
    page.value = res.data.data
    resetChoices()
    comment.value = ''
    // A fleet driver: approvals above the limit went to the fleet owner
    notice.value = res.data.data.fleet_result ? (res.data.message ?? null) : 'Thank you, the workshop has your answer.'
  } catch (err) {
    notice.value = errorMessage(err)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div :class="embedded ? 'pb-6' : 'min-h-screen bg-gray-100 pb-28'">
    <div v-if="updatedNote" class="fixed top-3 inset-x-0 z-50 mx-auto w-max rounded-full bg-gray-900/90 px-4 py-1.5 text-sm text-white shadow">
      Updated just now
    </div>
    <div v-if="loading" class="flex justify-center pt-24 text-gray-500">Loading…</div>

    <div v-else-if="error" class="max-w-md mx-auto px-4 pt-24 text-center">
      <p class="text-lg font-semibold text-gray-900 mb-2">Link not available</p>
      <p class="text-gray-600">{{ error }}</p>
    </div>

    <template v-else-if="page">
      <!-- Header -->
      <div v-if="!embedded" class="bg-white border-b border-gray-200">
        <div class="max-w-xl mx-auto px-4 py-2 flex items-center justify-between">
          <img src="/images/logo.png" alt="Express Autocare" class="h-10 w-auto" />
          <span class="text-xs text-gray-500">{{ page.workshop.name }}</span>
        </div>
      </div>
      <header v-if="!embedded" class="bg-primary-600 text-white px-4 pt-5 pb-8">
        <div class="max-w-xl mx-auto">
          <h1 class="text-xl font-bold mt-1">{{ page.job.registration_no }}</h1>
          <p class="text-sm opacity-90">{{ page.job.car }} · Job {{ page.job.job_number }}</p>
          <div class="mt-3 inline-flex items-center rounded-full bg-white/15 px-3 py-1 text-sm font-medium">
            {{ page.job.status_label }}
          </div>
          <p v-if="page.job.plan" class="mt-2 text-sm opacity-90">Plan: {{ page.job.plan }}</p>
          <a :href="`/app/link/job/${token}`" class="mt-3 inline-flex items-center rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-primary-700">Open in the Express Autocare app ›</a>
        </div>
      </header>

      <main class="max-w-xl mx-auto space-y-4" :class="embedded ? '' : 'px-4 -mt-4'">
        <!-- Driver / technician on the way (the app shows them on a map) -->
        <section v-if="!embedded && page.driver" class="flex items-center gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-green-200">
          <span class="text-2xl">🚗</span>
          <div class="flex-1">
            <p class="font-semibold text-gray-900">{{ page.driver.name }} is on the way</p>
            <p class="text-sm text-gray-600">{{ page.driver.label }}<template v-if="page.driver.eta_minutes"> · about {{ page.driver.eta_minutes }} min</template></p>
          </div>
          <a v-if="page.driver.phone" :href="`tel:${page.driver.phone}`" class="rounded-full bg-green-600 p-2.5 text-white" aria-label="Call"><PhoneIcon class="h-5 w-5" /></a>
        </section>
        <!-- Estimate -->
        <section v-if="estimate" class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200/80">
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
          <div v-if="workLines.length" class="px-4 py-3">
            <div class="flex items-center justify-between mb-2">
              <h3 class="font-medium">Additional work</h3>
              <div v-if="page.decisions_open" class="flex gap-4">
                <button
                  v-if="workLines.some((l) => statusOf(l) !== 'declined')"
                  class="text-sm text-gray-600 font-medium"
                  @click="declineAll"
                >
                  Decline all
                </button>
                <button
                  v-if="workLines.some((l) => statusOf(l) !== 'approved')"
                  class="text-sm text-primary-600 font-medium"
                  @click="approveAll"
                >
                  Approve all
                </button>
              </div>
            </div>

            <p v-if="page.fleet && page.decisions_open" class="mb-3 rounded-lg bg-blue-50 p-2.5 text-sm text-blue-900">
              You can approve up to {{ money(page.fleet.remaining) }} more on this job (limit {{ money(page.fleet.limit) }}). Anything above it is sent to the fleet owner to approve.
            </p>
            <ul class="space-y-3">
              <li v-for="line in workLines" :key="line.line_key" class="rounded-lg border p-3" :class="statusOf(line) === 'declined' ? 'border-gray-200 bg-gray-50' : statusOf(line) === 'approved' ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50'">
                <div class="flex justify-between gap-3">
                  <p class="font-medium text-gray-900">{{ line.description }}</p>
                  <p class="font-semibold shrink-0">{{ money(line.amount) }}</p>
                </div>

                <div v-if="explain" class="mt-1">
                  <p v-if="explanations[line.line_key]" class="rounded-md bg-white/70 p-2 text-sm text-gray-700">{{ explanations[line.line_key] }}</p>
                  <button v-else type="button" class="text-xs font-medium text-primary-700 underline" :disabled="explaining === line.line_key" @click="askWhy(line.line_key)">
                    {{ explaining === line.line_key ? 'Asking…' : 'Why is this needed?' }}
                  </button>
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
                <p v-else-if="line.decided_via === 'fleet_driver' && line.decided_by_driver && !choices[line.line_key]" class="mt-2 text-xs text-gray-600">
                  {{ line.approval_status === 'approved' ? 'Approved' : 'Declined' }} by driver {{ line.decided_by_driver }} on {{ when(line.decided_at) }}.
                </p>
                <p v-if="ownerLine(line)" class="mt-2 text-xs text-gray-600">Decided by the fleet owner.</p>

                <div v-if="page.decisions_open && !ownerLine(line)" class="mt-3 grid grid-cols-2 gap-2">
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

          <!-- Observations & recommendations (incl. work not approved), with remarks -->
          <div v-if="estimate.observations?.length" class="px-4 py-3 border-t border-gray-100">
            <h3 class="font-medium mb-2">Observations &amp; recommendations</h3>
            <ol class="space-y-2">
              <li v-for="(o, i) in estimate.observations" :key="o.id" class="rounded-lg border border-gray-200 p-3 text-sm">
                <p class="text-gray-900">{{ i + 1 }}. {{ o.text }}</p>
                <p v-if="o.remark" class="mt-0.5 text-xs font-medium text-amber-800">{{ o.remark }}</p>
                <button
                  v-if="page.decisions_open && declinedLine(o.estimate_line_key)"
                  class="mt-2 inline-flex items-center gap-1 rounded-lg border border-green-600 px-3 py-1.5 text-xs font-medium text-green-700"
                  @click="choose(declinedLine(o.estimate_line_key)!, 'approved')"
                >
                  <CheckIcon class="h-4 w-4" /> Approve this work after all ({{ money(declinedLine(o.estimate_line_key)!.amount) }})
                </button>
              </li>
            </ol>
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

        <section v-else class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200/80 p-4 text-sm text-gray-600">
          The estimate will appear here once the workshop has prepared it.
        </section>

        <!-- Photos & videos, section by section -->
        <section v-if="page.gallery.length" class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200/80 p-4">
          <h2 class="font-semibold mb-1">Photos &amp; videos</h2>
          <p class="text-xs text-gray-500 mb-3">New ones appear here by themselves while this page is open.</p>
          <details v-for="(area, ai) in page.gallery" :key="area.key" class="border-t border-gray-100 py-2" :open="ai === page.gallery.length - 1">
            <summary class="cursor-pointer select-none font-medium text-gray-900">
              {{ area.title }} <span class="text-sm font-normal text-gray-500">({{ area.count }})</span>
            </summary>
            <div v-for="(group, gi) in area.groups" :key="gi" class="mt-2">
              <p v-if="group.title" class="text-sm font-medium text-gray-700 mb-1">{{ group.title }}</p>
              <div class="grid grid-cols-3 gap-2">
                <a v-for="m in group.items" :key="m.id" :href="m.url" target="_blank" class="block">
                  <span class="relative block aspect-square overflow-hidden rounded bg-gray-200">
                    <img v-if="m.thumbnail_url" :src="m.thumbnail_url" class="h-full w-full object-cover" :alt="m.caption ?? ''" loading="lazy" />
                    <span v-else class="flex h-full items-center justify-center text-xs text-gray-500">{{ m.media_type }}</span>
                    <PlayIcon v-if="m.media_type === 'video'" class="absolute inset-0 m-auto h-8 w-8 text-white drop-shadow" />
                  </span>
                  <span v-if="m.caption" class="mt-0.5 block text-[11px] leading-tight text-gray-600 line-clamp-2">{{ m.caption }}</span>
                </a>
              </div>
            </div>
          </details>
        </section>

        <!-- Final bill -->
        <section v-if="page.bill" class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200/80 p-4">
          <div class="flex items-center justify-between mb-2">
            <h2 class="font-semibold">{{ page.bill.kind === 'tax' ? 'Tax invoice' : 'Bill' }} {{ page.bill.number }}</h2>
            <a :href="publicJobApi.billPdfUrl(token)" target="_blank" class="text-sm text-primary-600 inline-flex items-center gap-1">
              <DocumentArrowDownIcon class="h-4 w-4" /> PDF
            </a>
          </div>
          <dl class="text-sm space-y-1">
            <div class="flex justify-between"><dt class="text-gray-600">Total</dt><dd class="font-medium">Rs {{ page.bill.total.toLocaleString('en-IN') }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-600">Paid</dt><dd>Rs {{ page.bill.paid.toLocaleString('en-IN') }}</dd></div>
            <div v-if="page.bill.refund_due > 0" class="flex justify-between font-semibold text-green-700">
              <dt>To be refunded to you</dt><dd>Rs {{ page.bill.refund_due.toLocaleString('en-IN') }}</dd>
            </div>
            <div v-else class="flex justify-between font-semibold"><dt>Balance to pay</dt><dd>Rs {{ page.bill.balance.toLocaleString('en-IN') }}</dd></div>
          </dl>
          <p v-if="page.bill.gate_pass" class="mt-2 text-sm text-green-700">Gate pass {{ page.bill.gate_pass }} issued — your car is ready to collect.</p>
        </section>

        <!-- Service report -->
        <section v-if="page.report" class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200/80 p-4">
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
        <section class="rounded-2xl bg-white shadow-sm ring-1 ring-gray-200/80 p-4">
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
        class="fixed inset-x-0 z-30 border-t border-gray-200 bg-white px-4 pt-3 shadow-lg"
        :class="embedded ? 'bottom-[calc(4rem+env(safe-area-inset-bottom))] pb-3' : 'bottom-0 pb-[max(0.75rem,env(safe-area-inset-bottom))]'"
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
