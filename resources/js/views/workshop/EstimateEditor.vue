<script setup lang="ts">
/**
 * Advisor estimate screen, laid out like the owner's estimate sheet: the plan
 * block (oil + plan items + labour that balances to the package price), then
 * additional parts & labour for the customer to approve, discounts and totals.
 * Only a draft is editable; editing a sent estimate starts a new version.
 */
import { ref, computed, onMounted, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { format } from 'date-fns'
import {
  ArrowLeftIcon,
  ArrowPathIcon,
  DocumentArrowDownIcon,
  PaperAirplaneIcon,
  PencilSquareIcon,
  PhoneIcon,
  PlusIcon,
  TrashIcon,
  ExclamationTriangleIcon,
} from '@heroicons/vue/20/solid'
import { estimatesApi, pricingApi, partsApi, openPdfBlob, type Decision, type PartRequestInput } from '@/api/estimates.api'
import { jobCardIssuesApi } from '@/api/jobSheets.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type {
  Estimate,
  EstimateOverview,
  EstimateLine,
  EstimateLineKind,
  EstimateSuggestion,
  CustomerMessage,
  JobCardMedia,
  JobCardAdditionalJob,
  JobCardIssue,
  PricingMasters,
  LabourCharge,
  SparePartRequest,
} from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'
import VoiceInput from '@/components/ui/VoiceInput.vue'
import WhatsAppMessage from '@/components/estimate/WhatsAppMessage.vue'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const toast = useToast()

const jobCardId = Number(route.params.id)

const loading = ref(true)
const busy = ref(false)
const overview = ref<EstimateOverview | null>(null)
const estimate = ref<Estimate | null>(null)
const masters = ref<PricingMasters | null>(null)
const media = ref<JobCardMedia[]>([])
const additionalJobs = ref<JobCardAdditionalJob[]>([])
const issues = ref<JobCardIssue[]>([])

const canManage = computed(() => can('workshop.estimates.manage'))
const editable = computed(() => estimate.value?.status === 'draft' && canManage.value)

const KIND_OPTIONS: { value: EstimateLineKind; label: string }[] = [
  { value: 'part', label: 'Part' },
  { value: 'labor', label: 'Labour' },
  { value: 'third_party', label: 'Third-party service' },
  { value: 'plan_extra', label: 'Inside the plan (reduces labour)' },
]

const PAYMENT_MODES = ['Cash', 'UPI', 'Card', 'Bank Transfer', 'Credit (Pending)'].map((m) => ({ value: m, label: m }))

function money(n: number | string | null | undefined, decimals = 2): string {
  return new Intl.NumberFormat('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(n ?? 0))
}

function qty(n: number): string {
  return Number.isInteger(n) ? String(n) : String(Number(n.toFixed(2)))
}

function when(d: string | null): string {
  return d ? format(new Date(d), 'dd MMM yyyy, h:mm a') : '-'
}

function approvalVariant(status: string) {
  return status === 'approved' ? 'success' : status === 'declined' ? 'danger' : status === 'pending' ? 'warning' : 'default'
}

// ---------------------------------------------------------------- loading

async function load() {
  loading.value = true
  try {
    const [ov, pricing, lib, jobs, iss, labour] = await Promise.all([
      estimatesApi.overview(jobCardId),
      pricingApi.get().catch(() => null),
      estimatesApi.mediaLibrary(jobCardId).catch(() => null),
      jobCardIssuesApi.additionalJobs(jobCardId).catch(() => null),
      jobCardIssuesApi.issues(jobCardId).catch(() => null),
      canManage.value ? partsApi.labourCharges(jobCardId).catch(() => null) : null,
      loadPartRequests(),
    ])
    overview.value = ov.data.data
    estimate.value = ov.data.data.draft ?? ov.data.data.current
    masters.value = pricing?.data.data ?? null
    media.value = lib?.data.data ?? []
    additionalJobs.value = jobs?.data.data ?? []
    issues.value = iss?.data.data ?? []
    labourCharges.value = labour?.data.data ?? []
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

async function refreshOverview() {
  const ov = await estimatesApi.overview(jobCardId)
  overview.value = ov.data.data
}

async function run(action: () => Promise<{ data: { data: Estimate } }>, ok?: string) {
  busy.value = true
  try {
    const res = await action()
    estimate.value = res.data.data
    if (ok) toast.success(ok)
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function startDraft() {
  await run(() => estimatesApi.draft(jobCardId))
  await refreshOverview()
}

// ---------------------------------------------------------------- header

/** Active plans, plus the estimate's own plan if it has since been deactivated */
const packageOptions = computed(() =>
  (masters.value?.packages ?? [])
    .filter((p) => p.is_active || p.id === estimate.value?.package_id)
    .map((p) => ({ value: p.id, label: p.name }))
)

/** Oil grades this plan is offered with for the car type */
const oilOptions = computed(() => {
  const m = masters.value
  const e = estimate.value
  if (!m || !e) return []
  const offered = new Set(
    m.prices.filter((p) => p.package_id === e.package_id && p.category_id === e.category_id).map((p) => p.oil_type_id)
  )
  return m.oil_types
    .filter((o) => !e.package_id || offered.has(o.id))
    .map((o) => ({ value: o.id, label: o.name }))
})

function saveHeader(patch: Record<string, unknown>) {
  if (!estimate.value || !editable.value) return
  const id = estimate.value.id
  return run(() => estimatesApi.update(jobCardId, id, patch))
}

function numberOr(value: string | number | null, fallback: number | null = 0): number | null {
  if (value === '' || value === null) return fallback
  const n = Number(value)
  return Number.isFinite(n) ? n : fallback
}

// ---------------------------------------------------------------- lines

function saveLine(line: EstimateLine, patch: Record<string, unknown>) {
  if (!estimate.value) return
  const id = estimate.value.id
  return run(() => estimatesApi.updateLine(jobCardId, id, line.id, patch))
}

function removeLine(line: EstimateLine) {
  if (!estimate.value) return
  const id = estimate.value.id
  return run(() => estimatesApi.removeLine(jobCardId, id, line.id), 'Line removed')
}

const lineForm = reactive({
  kind: 'part' as EstimateLineKind,
  description: '',
  quantity: 1 as number | string,
  rate: '' as number | string,
  media_ids: [] as number[],
  job_card_additional_job_id: null as number | null,
  job_card_issue_id: null as number | null,
  labour_service_id: null as number | null,
})
const showLineModal = ref(false)

// Labour charges master, priced for this car's type and fuel
const labourCharges = ref<LabourCharge[]>([])
const labourOptions = computed(() =>
  labourCharges.value.map((c) => ({
    value: c.labour_service_id,
    label: `${c.description} — ${c.rate === null ? 'not on chart for this car' : `₹ ${money(c.rate, 0)}${c.unit === 'hour' ? ' / hour' : ''}`}`,
  }))
)

function pickLabour(id: number | string | null) {
  const charge = labourCharges.value.find((c) => c.labour_service_id === Number(id))
  if (!charge) return
  lineForm.kind = 'labor'
  lineForm.description = charge.description
  lineForm.labour_service_id = charge.labour_service_id
  lineForm.rate = charge.rate ?? ''
  lineForm.quantity = 1
  suggestions.value = []
}
const suggestions = ref<EstimateSuggestion[]>([])
let suggestTimer: ReturnType<typeof setTimeout> | undefined

function openLineModal(prefill: Partial<typeof lineForm> = {}) {
  Object.assign(lineForm, {
    kind: 'part',
    description: '',
    quantity: 1,
    rate: '',
    media_ids: [],
    job_card_additional_job_id: null,
    job_card_issue_id: null,
    labour_service_id: null,
    ...prefill,
  })
  suggestions.value = []
  showLineModal.value = true
}

function onDescriptionInput(value: string | number) {
  lineForm.description = String(value)
  lineForm.labour_service_id = null
  clearTimeout(suggestTimer)
  if (lineForm.description.trim().length < 2) {
    suggestions.value = []
    return
  }
  suggestTimer = setTimeout(async () => {
    try {
      const res = await estimatesApi.suggestions(lineForm.description.trim(), estimate.value?.category_id, jobCardId)
      suggestions.value = res.data.data
    } catch {
      suggestions.value = []
    }
  }, 300)
}

function pickSuggestion(s: EstimateSuggestion) {
  lineForm.description = s.description
  lineForm.rate = s.last_rate ?? ''
  lineForm.labour_service_id = s.labour_service_id ?? null
  if (s.kind && s.kind !== 'plan_item') lineForm.kind = s.kind
  suggestions.value = []
}

const SOURCE_LABEL: Record<EstimateSuggestion['source'], string> = {
  labour: 'Labour chart',
  history: 'Used before',
  addon: 'Add-on',
}

function toggleMedia(id: number) {
  const i = lineForm.media_ids.indexOf(id)
  if (i >= 0) lineForm.media_ids.splice(i, 1)
  else lineForm.media_ids.push(id)
}

async function submitLine() {
  if (!estimate.value) return
  const id = estimate.value.id
  await run(
    () =>
      estimatesApi.addLine(jobCardId, id, {
        kind: lineForm.kind,
        description: lineForm.description.trim(),
        quantity: Number(lineForm.quantity) || 1,
        rate: Number(lineForm.rate) || 0,
        media_ids: lineForm.media_ids,
        job_card_additional_job_id: lineForm.job_card_additional_job_id,
        job_card_issue_id: lineForm.job_card_issue_id,
        labour_service_id: lineForm.kind === 'labor' ? lineForm.labour_service_id : null,
      }),
    'Line added'
  )
  showLineModal.value = false
}

// ---------------------------------------------------------------- parts prices (spare parts coordinator)

const partRequests = ref<SparePartRequest[]>([])
const showPartsModal = ref(false)
const partRows = ref<PartRequestInput[]>([])
const marginInputs = reactive<Record<number, number | string>>({})

async function loadPartRequests() {
  try {
    partRequests.value = (await partsApi.forJobCard(jobCardId)).data.data
  } catch {
    partRequests.value = []
  }
}

/** Requests still open: waiting for prices, or priced and not on an estimate yet */
const openPartRequests = computed(() =>
  partRequests.value.filter((r) => r.status === 'requested' || (r.status === 'quoted' && !r.estimate_line_key))
)

// Parts the technician sent ("Submit to SA for Parts"), for the advisor to forward
const technicianParts = computed(() => partRequests.value.filter((r) => r.status === 'proposed'))
const forwardIds = ref<number[]>([])

async function forwardTechnicianParts() {
  const ids = forwardIds.value.length ? forwardIds.value : technicianParts.value.map((r) => r.id)
  busy.value = true
  try {
    await partsApi.forward(jobCardId, ids)
    toast.success('Sent to the spare parts coordinator', 'You will be notified when the prices are in.')
    forwardIds.value = []
    await loadPartRequests()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

function selectedQuote(r: SparePartRequest) {
  return r.quotes.find((q) => q.id === r.selected_quote_id) ?? null
}

function suggestedRate(r: SparePartRequest): number | null {
  const q = selectedQuote(r)
  if (!q) return null
  const margin = Number(marginInputs[r.id] ?? masters.value?.parts_default_margin_pct ?? 0)
  return Math.round(q.landed_unit_cost * (1 + margin / 100))
}

function openPartsModal() {
  partRows.value = [{ part_name: '', part_number: '', quantity: 1, note: '' }]
  showPartsModal.value = true
}

async function sendPartRequests() {
  const parts = partRows.value
    .filter((p) => p.part_name.trim())
    .map((p) => ({ ...p, part_name: p.part_name.trim(), quantity: Number(p.quantity) || 1 }))
  if (!parts.length) return
  busy.value = true
  try {
    await partsApi.requestPrices(jobCardId, parts)
    toast.success('Sent to the spare parts coordinator', 'You will be notified when the prices are in.')
    showPartsModal.value = false
    await loadPartRequests()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function addPartToEstimate(r: SparePartRequest) {
  if (!estimate.value) return
  const id = estimate.value.id
  const margin = marginInputs[r.id]
  await run(() => partsApi.addToEstimate(jobCardId, id, r.id, margin === undefined || margin === '' ? null : Number(margin)), 'Part added')
  await loadPartRequests()
}

async function cancelPartRequest(r: SparePartRequest) {
  try {
    await partsApi.cancel(jobCardId, r.id)
    await loadPartRequests()
  } catch (err) {
    toast.apiError(err)
  }
}

async function removeAdditionalLine(line: EstimateLine) {
  await removeLine(line)
  if (line.spare_part_request_id) await loadPartRequests()
}

/** Technician's additional jobs and open customer issues not yet on the estimate */
const activeAdditional = computed(() => (estimate.value?.additional_lines ?? []).filter((l) => l.approval_status !== 'declined'))
const declinedAdditional = computed(() => (estimate.value?.additional_lines ?? []).filter((l) => l.approval_status === 'declined'))

const pullable = computed(() => {
  const lines = estimate.value?.additional_lines ?? []
  const jobIds = new Set(lines.map((l) => l.job_card_additional_job_id).filter(Boolean))
  const issueIds = new Set(lines.map((l) => l.job_card_issue_id).filter(Boolean))
  return [
    ...additionalJobs.value
      .filter((j) => !jobIds.has(j.id))
      .map((j) => ({ key: `job-${j.id}`, label: j.description, hint: 'Additional job', prefill: { description: j.description, rate: Number(j.cost) || '', job_card_additional_job_id: j.id, job_card_issue_id: j.job_card_issue_id } })),
    ...issues.value
      .filter((i) => !issueIds.has(i.id) && ['open', 'in_progress'].includes(i.status))
      .map((i) => ({ key: `issue-${i.id}`, label: i.description, hint: `Customer issue (${i.priority})`, prefill: { description: i.description, job_card_issue_id: i.id } })),
  ]
})

// ---------------------------------------------------------------- send & decisions

const sentMessage = ref<CustomerMessage | null>(null)
const movedTo = ref<string | null>(null)

async function send() {
  if (!estimate.value) return
  busy.value = true
  try {
    const res = await estimatesApi.send(jobCardId, estimate.value.id)
    estimate.value = res.data.data.estimate
    sentMessage.value = res.data.data.message
    movedTo.value = res.data.data.moved_to
    await refreshOverview()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

const showDecisionModal = ref(false)
const decisionForm = reactive<{ choices: Record<string, '' | 'approved' | 'declined'>; note: string }>({ choices: {}, note: '' })
const decisionMessage = ref<CustomerMessage | null>(null)

function openDecisionModal() {
  decisionForm.choices = Object.fromEntries((estimate.value?.additional_lines ?? []).map((l) => [l.line_key, '']))
  decisionForm.note = ''
  showDecisionModal.value = true
}

function chooseAll(decision: 'approved' | 'declined') {
  for (const key of Object.keys(decisionForm.choices)) decisionForm.choices[key] = decision
}

async function submitDecisions() {
  const decisions: Decision[] = Object.entries(decisionForm.choices)
    .filter(([, d]) => d)
    .map(([line_key, decision]) => ({ line_key, decision: decision as Decision['decision'] }))
  if (!decisions.length) return

  busy.value = true
  try {
    const res = await estimatesApi.decideOnBehalf(jobCardId, decisions, decisionForm.note)
    estimate.value = res.data.data.estimate
    decisionMessage.value = res.data.data.message
    showDecisionModal.value = false
    toast.success('Decisions recorded', 'Send the customer the message that lists them.')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function downloadPdf() {
  if (!estimate.value) return
  try {
    const res = await estimatesApi.pdf(jobCardId, estimate.value.id)
    openPdfBlob(res.data)
  } catch (err) {
    toast.apiError(err)
  }
}

async function viewVersion(id: number) {
  await run(() => estimatesApi.get(jobCardId, id))
}

onMounted(load)
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div class="flex items-center gap-2">
        <Button variant="ghost" size="sm" @click="router.push(`/job-cards/${jobCardId}`)">
          <ArrowLeftIcon class="h-4 w-4" />
        </Button>
        <h1 class="text-xl font-bold text-gray-900">Estimate</h1>
        <Badge v-if="estimate" :variant="estimate.status === 'sent' ? 'success' : estimate.status === 'draft' ? 'warning' : 'default'">
          v{{ estimate.version }} · {{ estimate.status === 'draft' ? 'Draft' : estimate.status === 'sent' ? 'Sent' : 'Old version' }}
        </Badge>
      </div>

      <div v-if="estimate" class="flex flex-wrap gap-2">
        <Button variant="secondary" size="sm" @click="downloadPdf">
          <DocumentArrowDownIcon class="h-4 w-4 mr-1" /> PDF
        </Button>
        <Button
          v-if="estimate.status === 'sent' && canManage && !overview?.draft"
          variant="secondary"
          size="sm"
          :loading="busy"
          @click="startDraft"
        >
          <PencilSquareIcon class="h-4 w-4 mr-1" /> Edit (new version)
        </Button>
        <Button
          v-if="estimate.status === 'sent' && estimate.additional_lines.length && overview?.decisions_open && can('workshop.estimates.decide-on-behalf')"
          variant="secondary"
          size="sm"
          @click="openDecisionModal"
        >
          <PhoneIcon class="h-4 w-4 mr-1" /> Record customer decision
        </Button>
        <Button v-if="editable && can('workshop.estimates.send')" size="sm" :loading="busy" @click="send">
          <PaperAirplaneIcon class="h-4 w-4 mr-1" /> Send to customer
        </Button>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <Card v-else-if="!estimate">
      <div class="text-center py-6">
        <p class="text-gray-600 mb-4">No estimate yet. It starts from the plan's price list for this car.</p>
        <Button v-if="canManage" :loading="busy" @click="startDraft">
          <PlusIcon class="h-4 w-4 mr-1" /> Prepare estimate
        </Button>
      </div>
    </Card>

    <template v-else>
      <!-- Warnings -->
      <div v-for="w in estimate.warnings ?? []" :key="w" class="mb-3 flex gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
        <ExclamationTriangleIcon class="h-5 w-5 shrink-0" /> {{ w }}
      </div>

      <div v-if="overview?.draft && estimate.status !== 'draft'" class="mb-3 rounded-lg bg-blue-50 p-3 text-sm text-blue-800">
        A draft (v{{ overview.draft.version }}) is open.
        <button class="underline" @click="viewVersion(overview.draft.id)">Continue editing it</button>
      </div>

      <!-- Plan -->
      <Card :padding="false" class="mb-4">
        <template #header>
          <div class="flex flex-wrap items-end gap-3">
            <div class="w-48">
              <Select
                label="Service plan"
                :model-value="estimate.package_id"
                :options="packageOptions"
                placeholder="No plan"
                :disabled="!editable"
                @update:model-value="(v) => saveHeader({ package_id: v ? Number(v) : null })"
              />
            </div>
            <div class="w-44">
              <Select
                label="Oil grade"
                :model-value="estimate.oil_type_id"
                :options="oilOptions"
                :disabled="!editable || !estimate.package_id"
                @update:model-value="(v) => saveHeader({ oil_type_id: v ? Number(v) : null })"
              />
            </div>
            <div class="text-sm text-gray-500 pb-2">Car type: <span class="font-medium text-gray-800">{{ estimate.category_name ?? '—' }}</span></div>
            <Button v-if="editable && estimate.package_id" variant="ghost" size="sm" class="ml-auto" title="Reload prices from the masters" @click="run(() => estimatesApi.refreshPlan(jobCardId, estimate!.id), 'Plan prices refreshed')">
              <ArrowPathIcon class="h-4 w-4" />
            </Button>
          </div>
        </template>

        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
              <tr>
                <th class="px-4 py-2 text-left">Item description</th>
                <th class="px-2 py-2 text-right w-20">Qty</th>
                <th class="px-2 py-2 text-right w-28">Rate</th>
                <th class="px-4 py-2 text-right w-28">Amount</th>
                <th class="w-10"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="line in estimate.plan_lines" :key="line.id" :class="line.kind === 'labour_balance' ? 'bg-gray-50' : ''">
                <td class="px-4 py-2">
                  {{ line.description }}
                  <span v-if="line.kind === 'plan_extra'" class="text-xs text-gray-400"> (added inside plan)</span>
                </td>
                <td class="px-2 py-2 text-right">
                  <input
                    v-if="editable && line.kind !== 'labour_balance'"
                    type="number"
                    step="0.5"
                    min="0"
                    class="w-16 rounded border-gray-300 px-1 py-0.5 text-right text-sm"
                    :value="line.quantity"
                    @change="(e) => saveLine(line, { quantity: Number((e.target as HTMLInputElement).value) })"
                  />
                  <span v-else-if="line.kind !== 'labour_balance'">{{ qty(line.quantity) }}</span>
                </td>
                <td class="px-2 py-2 text-right">
                  <input
                    v-if="editable && line.kind !== 'labour_balance'"
                    type="number"
                    step="1"
                    class="w-24 rounded border-gray-300 px-1 py-0.5 text-right text-sm"
                    :value="line.rate"
                    @change="(e) => saveLine(line, { rate: Number((e.target as HTMLInputElement).value) })"
                  />
                  <span v-else-if="line.kind !== 'labour_balance'">{{ money(line.rate) }}</span>
                </td>
                <td class="px-4 py-2 text-right font-medium" :class="line.amount < 0 ? 'text-red-600' : ''">{{ money(line.amount) }}</td>
                <td class="px-2 py-2 text-right">
                  <button v-if="editable && line.kind === 'plan_extra'" class="text-gray-400 hover:text-red-600" title="Remove" @click="removeLine(line)">
                    <TrashIcon class="h-4 w-4" />
                  </button>
                </td>
              </tr>
              <tr v-if="!estimate.plan_lines.length">
                <td colspan="5" class="px-4 py-4 text-center text-gray-500">No plan on this job card.</td>
              </tr>
            </tbody>
            <tfoot v-if="estimate.package_name">
              <tr class="font-semibold border-t border-gray-300">
                <td class="px-4 py-2" colspan="3">{{ estimate.package_name }} — Package Total</td>
                <td class="px-4 py-2 text-right">{{ money(estimate.package_price) }}</td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div v-if="editable && estimate.package_id" class="px-4 py-2 border-t border-gray-100">
          <button class="text-sm text-primary-600 hover:underline" @click="openLineModal({ kind: 'plan_extra' })">
            + Add an item inside the plan (labour reduces)
          </button>
        </div>
      </Card>

      <!-- Additional parts & labour -->
      <Card :padding="false" class="mb-4">
        <template #header>
          <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold">Additional Parts &amp; Labour <span class="font-normal text-sm text-gray-500">— needs the customer's approval</span></h3>
            <div class="flex gap-2">
              <Button v-if="can('workshop.spare-parts.create')" size="sm" variant="secondary" @click="openPartsModal">
                Ask parts price
              </Button>
              <Button v-if="editable" size="sm" @click="openLineModal()">
                <PlusIcon class="h-4 w-4 mr-1" /> Add
              </Button>
            </div>
          </div>
        </template>

        <!-- Parts the technician asked for -->
        <div v-if="technicianParts.length" class="px-4 py-3 border-b border-gray-100 bg-blue-50/50">
          <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <p class="text-xs font-medium text-gray-600">Parts requested by the technician</p>
            <Button v-if="can('workshop.spare-parts.create')" size="sm" :loading="busy" @click="forwardTechnicianParts">
              Send {{ forwardIds.length ? `${forwardIds.length} selected` : 'all' }} to coordinator
            </Button>
          </div>
          <ul class="space-y-1">
            <li v-for="r in technicianParts" :key="r.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
              <label class="inline-flex items-center gap-2">
                <input v-model="forwardIds" type="checkbox" :value="r.id" class="rounded border-gray-300" />
                <span class="font-medium text-gray-900">{{ r.part_name }}</span>
              </label>
              <span v-if="r.part_number" class="text-xs text-gray-500">{{ r.part_number }}</span>
              <span class="text-xs text-gray-500">× {{ qty(r.quantity) }}</span>
              <span v-if="r.additional_job" class="text-xs text-gray-500">for “{{ r.additional_job }}”</span>
              <span class="text-xs text-gray-400">{{ r.requested_by }}</span>
              <button v-if="can('workshop.spare-parts.create')" class="text-xs text-gray-400 hover:text-red-600 ml-auto" @click="cancelPartRequest(r)">
                Not needed
              </button>
            </li>
          </ul>
        </div>

        <!-- Parts priced by the spare parts coordinator -->
        <div v-if="openPartRequests.length" class="px-4 py-3 border-b border-gray-100 bg-amber-50/40">
          <p class="text-xs font-medium text-gray-600 mb-2">Parts prices from the spare parts coordinator</p>
          <ul class="space-y-2">
            <li v-for="r in openPartRequests" :key="r.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
              <span class="font-medium text-gray-900">{{ r.part_name }}</span>
              <span v-if="r.part_number" class="text-xs text-gray-500">{{ r.part_number }}</span>
              <span class="text-xs text-gray-500">× {{ qty(r.quantity) }}</span>
              <template v-if="r.status === 'requested'">
                <Badge size="sm" variant="warning">Waiting for price</Badge>
              </template>
              <template v-else-if="selectedQuote(r)">
                <span class="text-xs text-gray-600">
                  {{ selectedQuote(r)!.supplier }} · cost ₹ {{ money(selectedQuote(r)!.landed_unit_cost, 0) }}/unit incl. GST
                  <span v-if="selectedQuote(r)!.availability">· {{ selectedQuote(r)!.availability }}</span>
                </span>
                <template v-if="editable">
                  <label class="inline-flex items-center gap-1 text-xs text-gray-600">
                    margin
                    <input
                      v-model="marginInputs[r.id]"
                      type="number"
                      min="0"
                      class="w-14 rounded border-gray-300 px-1 py-0.5 text-right text-xs"
                      :placeholder="String(masters?.parts_default_margin_pct ?? '')"
                    />%
                  </label>
                  <span class="text-xs font-medium">
                    = ₹ {{ money(suggestedRate(r), 0) }}/unit<template v-if="r.quantity !== 1"> × {{ qty(r.quantity) }} = ₹ {{ money((suggestedRate(r) ?? 0) * r.quantity, 0) }}</template>
                  </span>
                  <Button size="sm" :loading="busy" @click="addPartToEstimate(r)">Add to estimate</Button>
                </template>
                <span v-else class="text-xs text-gray-500">Open a draft to add it.</span>
              </template>
              <button
                v-if="can('workshop.spare-parts.create')"
                class="text-xs text-gray-400 hover:text-red-600 ml-auto"
                @click="cancelPartRequest(r)"
              >
                Cancel request
              </button>
            </li>
          </ul>
        </div>

        <div v-if="editable && pullable.length" class="px-4 py-3 bg-gray-50 border-b border-gray-100">
          <p class="text-xs text-gray-500 mb-2">From the job sheet — tap to price and add:</p>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="p in pullable"
              :key="p.key"
              class="rounded-full border border-gray-300 bg-white px-3 py-1 text-xs hover:border-primary-400"
              :title="p.hint"
              @click="openLineModal(p.prefill)"
            >
              + {{ p.label }}
            </button>
          </div>
        </div>

        <ul class="divide-y divide-gray-100">
          <li v-for="line in activeAdditional" :key="line.id" class="px-4 py-3">
            <div class="flex flex-wrap items-start gap-3">
              <div class="flex-1 min-w-[12rem]">
                <p class="text-sm font-medium" :class="line.approval_status === 'declined' ? 'text-gray-400 line-through' : 'text-gray-900'">
                  {{ line.description }}
                </p>
                <p class="text-xs text-gray-500">
                  <span class="capitalize">{{ line.kind.replace('_', ' ') }}</span>
                  <span v-if="line.labour_service_id"> · labour chart</span>
                  <template v-if="line.unit_cost != null">
                    · cost ₹ {{ money(line.unit_cost, 0) }} · margin
                    <input
                      v-if="editable"
                      type="number"
                      min="0"
                      class="w-14 rounded border-gray-300 px-1 py-0 text-right text-xs"
                      :value="line.margin_pct ?? ''"
                      title="Margin % on the GST-inclusive cost"
                      @change="(e) => saveLine(line, { margin_pct: Number((e.target as HTMLInputElement).value) })"
                    />
                    <span v-else>{{ line.margin_pct ?? '—' }}</span>%
                    <span class="text-gray-400">(workshop only)</span>
                  </template>
                </p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                  <Badge size="sm" :variant="approvalVariant(line.approval_status)">{{ line.approval_label }}</Badge>
                  <span v-if="line.decided_at" class="text-xs text-gray-400">
                    {{ line.decided_via === 'advisor_on_behalf' ? `Recorded by ${line.decided_by}` : 'By customer' }} · {{ when(line.decided_at) }}
                  </span>
                </div>
                <div v-if="line.media.length" class="mt-2 flex gap-1">
                  <a v-for="m in line.media" :key="m.id" :href="m.url" target="_blank" class="block h-10 w-10 overflow-hidden rounded bg-gray-100">
                    <img v-if="m.thumbnail_url" :src="m.thumbnail_url" class="h-full w-full object-cover" alt="" />
                    <span v-else class="flex h-full items-center justify-center text-[10px] text-gray-500">{{ m.media_type }}</span>
                  </a>
                </div>
              </div>
              <div class="flex items-center gap-2 text-sm">
                <template v-if="editable">
                  <input
                    type="number"
                    min="0"
                    step="1"
                    class="w-14 rounded border-gray-300 px-1 py-0.5 text-right text-sm"
                    :value="line.quantity"
                    title="Qty"
                    @change="(e) => saveLine(line, { quantity: Number((e.target as HTMLInputElement).value) })"
                  />
                  ×
                  <input
                    type="number"
                    step="1"
                    class="w-24 rounded border-gray-300 px-1 py-0.5 text-right text-sm"
                    :value="line.rate"
                    title="Rate"
                    @change="(e) => saveLine(line, { rate: Number((e.target as HTMLInputElement).value) })"
                  />
                </template>
                <span v-else class="text-gray-500">{{ qty(line.quantity) }} × {{ money(line.rate) }}</span>
                <span class="w-24 text-right font-medium">{{ money(line.amount) }}</span>
                <button v-if="editable" class="text-gray-400 hover:text-red-600" title="Remove" @click="removeAdditionalLine(line)">
                  <TrashIcon class="h-4 w-4" />
                </button>
              </div>
            </div>
          </li>
          <li v-if="!activeAdditional.length" class="px-4 py-4 text-center text-sm text-gray-500">No additional work.</li>
        </ul>
        <!-- Not approved by the customer: shown under Observations on the estimate and report -->
        <div v-if="declinedAdditional.length" class="border-t border-gray-100 bg-gray-50 px-4 py-3">
          <p class="text-xs font-medium text-gray-600 mb-1">Not approved by the customer — listed under Observations</p>
          <ul class="space-y-1 text-sm text-gray-500">
            <li v-for="line in declinedAdditional" :key="line.id" class="flex justify-between gap-3">
              <span>{{ line.description }}</span>
              <span>{{ qty(line.quantity) }} × {{ money(line.rate) }}</span>
            </li>
          </ul>
        </div>
      </Card>

      <!-- Observations & remarks (as on the estimate and the report) -->
      <Card v-if="estimate.observations?.length" class="mb-4">
        <template #header>
          <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold">Observations &amp; Report</h3>
            <RouterLink :to="`/job-cards/${jobCardId}/sheet`" class="text-xs text-primary-600 hover:underline">Remarks on the job sheet</RouterLink>
          </div>
        </template>
        <table class="min-w-full text-sm">
          <thead class="text-xs text-gray-500">
            <tr><th class="py-1 pr-2 text-left w-8">#</th><th class="py-1 pr-2 text-left">Observation</th><th class="py-1 text-left">Remarks</th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(o, i) in estimate.observations" :key="o.id">
              <td class="py-1.5 pr-2 text-gray-500">{{ i + 1 }}</td>
              <td class="py-1.5 pr-2 text-gray-900">{{ o.text }}</td>
              <td class="py-1.5" :class="o.remark ? 'text-amber-800' : 'text-gray-400'">{{ o.remark ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </Card>

      <!-- Discounts & totals -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <Card>
          <template #header><h3 class="font-semibold">Discounts &amp; details</h3></template>
          <div class="grid grid-cols-2 gap-3">
            <Input
              label="Plan discount %"
              type="number"
              :model-value="estimate.plan_discount_pct"
              :disabled="!editable"
              @change="(e: Event) => saveHeader({ plan_discount_pct: numberOr((e.target as HTMLInputElement).value) })"
            />
            <Input
              label="Plan discount ₹"
              type="number"
              :model-value="estimate.plan_discount_flat"
              :disabled="!editable"
              @change="(e: Event) => saveHeader({ plan_discount_flat: numberOr((e.target as HTMLInputElement).value) })"
            />
            <Input
              label="Additional jobs discount %"
              type="number"
              :model-value="estimate.extras_discount_pct"
              :disabled="!editable"
              @change="(e: Event) => saveHeader({ extras_discount_pct: numberOr((e.target as HTMLInputElement).value) })"
            />
            <Input
              label="Additional jobs discount ₹"
              type="number"
              :model-value="estimate.extras_discount_flat"
              :disabled="!editable"
              @change="(e: Event) => saveHeader({ extras_discount_flat: numberOr((e.target as HTMLInputElement).value) })"
            />
            <Input
              label="Next service (km)"
              type="number"
              :model-value="estimate.next_service_km"
              :disabled="!editable"
              @change="(e: Event) => saveHeader({ next_service_km: numberOr((e.target as HTMLInputElement).value, null) })"
            />
            <Input
              label="Next service date"
              type="date"
              :model-value="estimate.next_service_date"
              :disabled="!editable"
              @change="(e: Event) => saveHeader({ next_service_date: (e.target as HTMLInputElement).value || null })"
            />
            <div class="col-span-2">
              <Select
                label="Payment mode"
                :model-value="estimate.payment_mode"
                :options="PAYMENT_MODES"
                placeholder="—"
                :disabled="!editable"
                @update:model-value="(v) => saveHeader({ payment_mode: v ? String(v) : null })"
              />
            </div>
          </div>
        </Card>

        <Card>
          <template #header><h3 class="font-semibold">Totals</h3></template>
          <dl class="space-y-2 text-sm">
            <div v-if="estimate.package_name" class="flex justify-between"><dt>Service plan total</dt><dd>{{ money(estimate.package_price) }}</dd></div>
            <div v-if="estimate.plan_discount > 0" class="flex justify-between text-green-700"><dt>Plan discount</dt><dd>− {{ money(estimate.plan_discount) }}</dd></div>
            <div class="flex justify-between"><dt>Additional jobs (excl. not approved)</dt><dd>{{ money(estimate.extras_subtotal) }}</dd></div>
            <div v-if="estimate.extras_discount > 0" class="flex justify-between text-green-700"><dt>Additional jobs discount</dt><dd>− {{ money(estimate.extras_discount) }}</dd></div>
            <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-bold"><dt>Grand total (₹)</dt><dd>{{ money(estimate.grand_total, 0) }}</dd></div>
            <p class="text-xs italic text-gray-500">{{ estimate.grand_total_words }} · prices include GST</p>
          </dl>
          <p v-if="estimate.pending_count" class="mt-3 text-sm text-amber-700">{{ estimate.pending_count }} item(s) waiting for the customer.</p>
          <p v-if="estimate.sent_at" class="mt-2 text-xs text-gray-500">
            Sent {{ when(estimate.sent_at) }}<span v-if="estimate.sent_by"> by {{ estimate.sent_by.name }}</span>
            · <span v-if="estimate.customer_viewed_at">seen by customer {{ when(estimate.customer_viewed_at) }}</span><span v-else>not opened yet</span>
          </p>
        </Card>
      </div>

      <!-- Notes & comments -->
      <Card class="mb-4">
        <template #header><h3 class="font-semibold">Notes &amp; customer comments</h3></template>
        <Textarea
          label="Note printed on the estimate"
          :model-value="estimate.notes ?? ''"
          :disabled="!editable"
          @change="(e: Event) => saveHeader({ notes: (e.target as HTMLTextAreaElement).value || null })"
        />
        <ul v-if="estimate.comments.length" class="mt-4 space-y-2">
          <li v-for="c in estimate.comments" :key="c.id" class="rounded-lg p-2 text-sm" :class="c.author_type === 'customer' ? 'bg-blue-50' : 'bg-gray-50'">
            <p class="text-xs text-gray-500">{{ c.author_type === 'customer' ? 'Customer' : c.author }} · {{ when(c.created_at) }}</p>
            <p class="whitespace-pre-wrap">{{ c.body }}</p>
          </li>
        </ul>
      </Card>

      <!-- Versions -->
      <Card v-if="(overview?.versions.length ?? 0) > 1">
        <template #header><h3 class="font-semibold">Versions</h3></template>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="v in overview!.versions" :key="v.id" class="flex items-center justify-between py-2">
            <button class="text-primary-600 hover:underline" @click="viewVersion(v.id)">v{{ v.version }}</button>
            <span class="text-gray-500">{{ v.status }}<span v-if="v.sent_at"> · {{ when(v.sent_at) }}</span></span>
            <span class="font-medium">₹ {{ money(v.grand_total, 0) }}</span>
          </li>
        </ul>
      </Card>
    </template>

    <!-- Add line -->
    <Modal :open="showLineModal" title="Add line" size="lg" @close="showLineModal = false">
      <div class="space-y-3">
        <Select v-model="lineForm.kind" label="Type" :options="KIND_OPTIONS" />
        <Select
          v-if="lineForm.kind === 'labor' && labourOptions.length"
          :model-value="lineForm.labour_service_id"
          label="From the labour chart (rate for this car)"
          :options="labourOptions"
          placeholder="Pick a service, or type below"
          @update:model-value="pickLabour"
        />
        <div class="relative">
          <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
          <div class="flex gap-2">
            <input
              class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              :value="lineForm.description"
              placeholder="e.g. Wiper blade set"
              @input="(e) => onDescriptionInput((e.target as HTMLInputElement).value)"
            />
            <VoiceInput @text="(t) => onDescriptionInput((lineForm.description ? lineForm.description + ' ' : '') + t)" />
          </div>
          <ul v-if="suggestions.length" class="absolute z-10 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg max-h-56 overflow-auto">
            <li v-for="s in suggestions" :key="s.source + s.description">
              <button class="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-gray-50" @click="pickSuggestion(s)">
                <span>
                  {{ s.description }}
                  <span class="text-xs text-gray-400"> · {{ SOURCE_LABEL[s.source] }}</span>
                </span>
                <span class="text-gray-500 whitespace-nowrap">
                  <template v-if="s.last_rate === null">not on chart</template>
                  <template v-else>₹ {{ money(s.last_rate, 0) }}<span v-if="s.unit === 'hour'" class="text-xs"> / hour</span></template>
                  <span v-if="s.times_used > 1" class="text-xs"> · used {{ s.times_used }}×</span>
                </span>
              </button>
            </li>
          </ul>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <Input v-model="lineForm.quantity" label="Qty" type="number" />
          <Input v-model="lineForm.rate" label="Rate (₹, incl. GST)" type="number" />
        </div>
        <div v-if="media.length && lineForm.kind !== 'plan_extra'">
          <p class="text-sm font-medium text-gray-700 mb-1">Photos / videos to show the customer</p>
          <div class="flex flex-wrap gap-2 max-h-40 overflow-auto">
            <button
              v-for="m in media"
              :key="m.id"
              type="button"
              class="relative h-16 w-16 overflow-hidden rounded border-2"
              :class="lineForm.media_ids.includes(m.id) ? 'border-primary-500' : 'border-transparent'"
              :title="m.description ?? ''"
              @click="toggleMedia(m.id)"
            >
              <img v-if="m.thumbnail_url" :src="m.thumbnail_url" class="h-full w-full object-cover" alt="" />
              <span v-else class="flex h-full items-center justify-center bg-gray-100 text-xs">{{ m.media_type }}</span>
            </button>
          </div>
          <p class="text-xs text-gray-500 mt-1">Selected media become visible on the customer's page.</p>
        </div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="showLineModal = false">Cancel</Button>
        <Button :loading="busy" :disabled="!lineForm.description.trim() || lineForm.rate === ''" @click="submitLine">Add</Button>
      </template>
    </Modal>

    <!-- After sending -->
    <Modal :open="!!sentMessage" title="Estimate sent" @close="sentMessage = null">
      <p v-if="movedTo === 'awaiting_approval'" class="text-sm text-gray-600 mb-3">The job is now <b>Awaiting Approval</b>.</p>
      <p v-else-if="movedTo === 'work_in_progress'" class="text-sm text-gray-600 mb-3">Nothing to approve: the job is back in progress.</p>
      <WhatsAppMessage v-if="sentMessage" :job-card-id="jobCardId" :message="sentMessage" @updated="(m) => (sentMessage = m.status === 'prepared' ? m : null)" />
    </Modal>

    <!-- Record decisions on behalf -->
    <Modal :open="showDecisionModal" title="Record the customer's decision" size="lg" @close="showDecisionModal = false">
      <p class="text-sm text-gray-600 mb-3">
        Use this when the customer decides by phone or in person. It is logged with your name and note, and the customer is
        sent a message listing what you recorded.
      </p>
      <div class="flex gap-2 mb-2">
        <Button size="sm" variant="secondary" @click="chooseAll('approved')">Approve all</Button>
        <Button size="sm" variant="secondary" @click="chooseAll('declined')">Decline all</Button>
      </div>
      <ul class="divide-y divide-gray-100 mb-3">
        <li v-for="line in estimate?.additional_lines ?? []" :key="line.line_key" class="flex flex-wrap items-center justify-between gap-2 py-2">
          <div class="text-sm">
            {{ line.description }} <span class="text-gray-500">· ₹ {{ money(line.amount, 0) }}</span>
            <div><Badge size="sm" :variant="approvalVariant(line.approval_status)">{{ line.approval_label }}</Badge></div>
          </div>
          <select v-model="decisionForm.choices[line.line_key]" class="rounded-lg border-gray-300 text-sm">
            <option value="">No change</option>
            <option value="approved">Approved</option>
            <option value="declined">Not approved</option>
          </select>
        </li>
      </ul>
      <div class="flex gap-2 items-start">
        <Textarea v-model="decisionForm.note" label="How did the customer decide? (required)" placeholder="e.g. Phone call at 11:30, approved wiper only" class="flex-1" />
        <VoiceInput class="mt-6" @text="(t) => (decisionForm.note = (decisionForm.note ? decisionForm.note + ' ' : '') + t)" />
      </div>
      <template #footer>
        <Button variant="secondary" @click="showDecisionModal = false">Cancel</Button>
        <Button
          :loading="busy"
          :disabled="!decisionForm.note.trim() || !Object.values(decisionForm.choices).some(Boolean)"
          @click="submitDecisions"
        >
          Record &amp; prepare message
        </Button>
      </template>
    </Modal>

    <!-- Ask the spare parts coordinator for prices -->
    <Modal :open="showPartsModal" title="Ask the spare parts coordinator for prices" size="lg" @close="showPartsModal = false">
      <p class="text-sm text-gray-600 mb-3">
        The coordinator gets the list, calls suppliers and sends back the cost. You'll get a notification, then add each part
        with your margin.
      </p>
      <div class="space-y-3">
        <div v-for="(row, i) in partRows" :key="i" class="grid grid-cols-12 gap-2 items-start">
          <div class="col-span-12 sm:col-span-5 flex gap-1">
            <input v-model="row.part_name" class="w-full rounded-lg border-gray-300 text-sm" placeholder="Part, e.g. Front brake pads" />
            <VoiceInput @text="(t) => (row.part_name = (row.part_name ? row.part_name + ' ' : '') + t)" />
          </div>
          <input v-model="row.part_number" class="col-span-5 sm:col-span-3 rounded-lg border-gray-300 text-sm" placeholder="Part no. (optional)" />
          <input v-model.number="row.quantity" type="number" min="1" class="col-span-3 sm:col-span-1 rounded-lg border-gray-300 text-sm" title="Qty" />
          <input v-model="row.note" class="col-span-4 sm:col-span-3 rounded-lg border-gray-300 text-sm" placeholder="Note (brand, OEM…)" />
        </div>
        <button class="text-sm text-primary-600 hover:underline" @click="partRows.push({ part_name: '', part_number: '', quantity: 1, note: '' })">
          + Another part
        </button>
      </div>
      <template #footer>
        <Button variant="secondary" @click="showPartsModal = false">Cancel</Button>
        <Button :loading="busy" :disabled="!partRows.some((p) => p.part_name.trim())" @click="sendPartRequests">Send</Button>
      </template>
    </Modal>

    <Modal :open="!!decisionMessage" title="Tell the customer" @close="decisionMessage = null">
      <p class="text-sm text-gray-600 mb-3">The customer must be told about decisions recorded for them. Send this message now.</p>
      <WhatsAppMessage v-if="decisionMessage" :job-card-id="jobCardId" :message="decisionMessage" @updated="(m) => (decisionMessage = m.status === 'prepared' ? m : null)" />
    </Modal>
  </div>
</template>
