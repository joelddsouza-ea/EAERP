<script setup lang="ts">
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon, ChevronDownIcon, PlusIcon } from '@heroicons/vue/20/solid'
import { jobCardsApi } from '@/api/workshop.api'
import { jobSheetsApi } from '@/api/jobSheets.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import { useUiStore } from '@/stores/ui.store'
import type { JobCard, JobSheet, JobSheetItem, SheetProblem } from '@/api/types'
import { statusLabel, statusVariant } from '@/constants/jobCardStatus'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'
import ActivityRow from '@/components/jobsheet/ActivityRow.vue'
import IssuesPanel from '@/components/jobsheet/IssuesPanel.vue'
import ObservationsPanel from '@/components/jobsheet/ObservationsPanel.vue'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const toast = useToast()
const uiStore = useUiStore()

const jobCardId = Number(route.params.id)

// Job statuses in which each kind of sheet can be edited (mirrors JobSheetService)
const EDITABLE: Record<string, string[]> = {
  work: ['intake_in_progress', 'work_in_progress', 'awaiting_parts', 'awaiting_approval', 'post_work_trial'],
  pre_delivery: ['post_work_trial', 'wash_pending', 'ready_for_delivery'],
  observation_report: ['intake_in_progress', 'work_in_progress', 'awaiting_parts', 'awaiting_approval', 'post_work_trial', 'wash_pending'],
}

const loading = ref(true)
const jobCard = ref<JobCard | null>(null)
const sheets = ref<JobSheet[]>([])
const activeTab = ref<string>('')
const collapsed = ref<Record<string, boolean>>({})
const observationsRefresh = ref(0)

// Submit
const submitting = ref(false)
const problems = ref<SheetProblem[]>([])
const showProblems = ref(false)
const overrideNotes = ref('')

// Reopen
const showReopen = ref(false)
const reopenNotes = ref('')

// Ad-hoc rows
const newRowName = ref('')

const activeSheet = computed(() => sheets.value.find((s) => `sheet-${s.id}` === activeTab.value) ?? null)
const hasIssuesTab = computed(() => sheets.value.some((s) => s.has_issues_tab))
const hasObservations = computed(() => sheets.value.some((s) => s.has_observations))

const tabs = computed(() => [
  ...sheets.value.map((s) => ({ key: `sheet-${s.id}`, label: shortName(s), done: s.status === 'completed' })),
  ...(hasIssuesTab.value ? [{ key: 'issues', label: 'Issues & Jobs', done: false }] : []),
  ...(hasObservations.value ? [{ key: 'observations', label: 'Observations', done: false }] : []),
])

const editableKey = (s: JobSheet) => (s.sheet_kind in EDITABLE ? s.sheet_kind : 'work')

const sheetPermission = computed(() => {
  const s = activeSheet.value
  if (!s) return false
  return s.sheet_kind === 'pre_delivery' ? can('workshop.pre-delivery.update') : can('workshop.job-sheets.update')
})

const statusAllowsEditing = computed(() => {
  const s = activeSheet.value
  return !!s && !!jobCard.value && EDITABLE[editableKey(s)].includes(jobCard.value.status)
})

const readonly = computed(() => !activeSheet.value || activeSheet.value.status === 'completed' || !sheetPermission.value || !statusAllowsEditing.value)

// Issues / observations are editable while the job is open and the user can fill sheets
const companionsReadonly = computed(
  () => !can('workshop.job-sheets.update') || !jobCard.value || ['delivered', 'cancelled'].includes(jobCard.value.status)
)

const canSubmit = computed(() => {
  const s = activeSheet.value
  if (!s || readonly.value) return false
  return s.sheet_kind === 'pre_delivery' ? can('workshop.pre-delivery.update') : can('workshop.job-sheets.complete')
})

const allowsAdhocRows = computed(() =>
  !!activeSheet.value && ['observation_report', 'body_shop', 'ac_service', 'general_repair'].includes(activeSheet.value.sheet_kind)
)

function shortName(s: JobSheet): string {
  if (s.sheet_kind === 'pre_delivery') return 'Pre-Delivery'
  if (s.sheet_kind === 'observation_report') return 'Observation Report'
  return (s.name ?? 'Job Sheet').replace(/\s+—\s+.*$/, '')
}

function sectionProgress(items: JobSheetItem[]) {
  const done = items.filter((i) => (i.status === 'done' && !i.media_shortfall) || i.status === 'not_applicable').length
  return { done, total: items.length, issues: items.filter((i) => i.status === 'not_done' || i.needs_attention).length }
}

async function load() {
  loading.value = true
  try {
    const [jc, sh] = await Promise.all([jobCardsApi.get(jobCardId), jobSheetsApi.list(jobCardId)])
    jobCard.value = jc.data.data
    sheets.value = sh.data.data

    if (!activeTab.value) {
      const requested = route.query.sheet ? `sheet-${route.query.sheet}` : null
      const preDelivery = route.query.kind === 'pre_delivery' ? sheets.value.find((s) => s.sheet_kind === 'pre_delivery') : null
      activeTab.value = preDelivery
        ? `sheet-${preDelivery.id}`
        : requested && tabs.value.some((t) => t.key === requested)
          ? requested
          : tabs.value[0]?.key ?? ''
    }
  } catch (err) {
    toast.apiError(err)
    router.push(`/job-cards/${jobCardId}`)
  } finally {
    loading.value = false
  }
}

async function reloadSheet(sheetId: number) {
  const response = await jobSheetsApi.get(jobCardId, sheetId)
  const index = sheets.value.findIndex((s) => s.id === sheetId)
  if (index >= 0) sheets.value[index] = response.data.data
}

function onItemUpdated(sheet: JobSheet, updated: JobSheetItem) {
  for (const section of sheet.sections) {
    const index = section.items.findIndex((i) => i.id === updated.id)
    if (index >= 0) {
      const before = section.items[index]
      section.items[index] = updated
      if (before.needs_attention !== updated.needs_attention || before.remarks !== updated.remarks) {
        observationsRefresh.value++
      }
      return
    }
  }
}

async function submit(override = false) {
  const sheet = activeSheet.value
  if (!sheet) return
  submitting.value = true
  try {
    await jobSheetsApi.submit(jobCardId, sheet.id, override, overrideNotes.value)
    await reloadSheet(sheet.id)
    showProblems.value = false
    overrideNotes.value = ''
    toast.success('Sheet submitted', `${shortName(sheet)} is complete.`)
  } catch (err: any) {
    const items = err.response?.data?.errors?.items as SheetProblem[] | undefined
    if (err.response?.status === 422 && items) {
      problems.value = items
      showProblems.value = true
    } else {
      toast.apiError(err)
    }
  } finally {
    submitting.value = false
  }
}

async function goToProblem(problem: SheetProblem) {
  showProblems.value = false
  const sheet = activeSheet.value
  const section = sheet?.sections.find((s) => s.items.some((i) => i.id === problem.item_id))
  if (section) collapsed.value[section.name] = false
  await nextTick()
  document.getElementById(`item-${problem.item_id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function reopen() {
  const sheet = activeSheet.value
  if (!sheet || !reopenNotes.value.trim()) return
  try {
    await jobSheetsApi.reopen(jobCardId, sheet.id, reopenNotes.value.trim())
    await reloadSheet(sheet.id)
    showReopen.value = false
    reopenNotes.value = ''
  } catch (err) {
    toast.apiError(err)
  }
}

async function addRow() {
  const sheet = activeSheet.value
  if (!sheet || !newRowName.value.trim()) return
  try {
    await jobSheetsApi.addItem(jobCardId, sheet.id, { item_name: newRowName.value.trim() })
    newRowName.value = ''
    await reloadSheet(sheet.id)
  } catch (err) {
    toast.apiError(err)
  }
}

watch(activeTab, (tab) => {
  const id = tab.startsWith('sheet-') ? tab.slice(6) : undefined
  router.replace({ query: { ...route.query, sheet: id, kind: undefined } })
})

onMounted(load)
</script>

<template>
  <div class="-mx-4 sm:mx-0 pb-28">
    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <template v-else-if="jobCard">
      <!-- Header -->
      <div class="sticky top-0 z-20 bg-gray-50/95 backdrop-blur px-4 sm:px-0 pt-2 pb-3 border-b border-gray-200">
        <div class="flex items-center gap-2">
          <button type="button" class="p-2 -ml-2 text-gray-500" aria-label="Back to job card" @click="router.push(`/job-cards/${jobCardId}`)">
            <ArrowLeftIcon class="h-5 w-5" />
          </button>
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900 truncate">
              {{ jobCard.job_number }} · {{ jobCard.vehicle?.registration_no }}
            </p>
            <p class="text-xs text-gray-500 truncate">
              {{ jobCard.vehicle?.make?.name }} {{ jobCard.vehicle?.model?.name }} · {{ jobCard.technician?.name ?? 'No technician' }}
            </p>
          </div>
          <Badge :variant="statusVariant(jobCard.status)" size="sm">{{ statusLabel(jobCard.status) }}</Badge>
        </div>

        <!-- Tabs -->
        <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            class="shrink-0 min-h-10 px-3 rounded-full text-sm font-medium border"
            :class="activeTab === tab.key ? 'bg-primary-600 border-primary-600 text-white' : 'bg-white border-gray-300 text-gray-700'"
            @click="activeTab = tab.key"
          >
            {{ tab.label }}<span v-if="tab.done"> ✓</span>
          </button>
        </div>

        <!-- Progress -->
        <div v-if="activeSheet?.progress" class="mt-3">
          <div class="flex justify-between text-xs text-gray-600 mb-1">
            <span>{{ activeSheet.progress.resolved }} of {{ activeSheet.progress.total }} done</span>
            <span v-if="activeSheet.progress.needs_media" class="text-red-600">{{ activeSheet.progress.needs_media }} need photo/video</span>
          </div>
          <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
            <div
              class="h-full bg-primary-500 transition-all"
              :style="{ width: `${activeSheet.progress.total ? Math.round((activeSheet.progress.resolved / activeSheet.progress.total) * 100) : 0}%` }"
            />
          </div>
        </div>
      </div>

      <div class="px-4 sm:px-0 pt-4 space-y-4">
        <!-- Sheet -->
        <template v-if="activeSheet">
          <div v-if="activeSheet.status === 'completed'" class="rounded-lg bg-green-50 border border-green-200 p-3 text-sm text-green-800">
            Submitted{{ activeSheet.completed_by ? ` by ${activeSheet.completed_by.name}` : '' }}.
            <span v-if="activeSheet.override" class="text-amber-700">Submitted with a manager override.</span>
          </div>
          <div v-else-if="!statusAllowsEditing" class="rounded-lg bg-gray-100 border border-gray-200 p-3 text-sm text-gray-700">
            This sheet can't be filled while the job is {{ statusLabel(jobCard.status) }}.
          </div>
          <div v-else-if="!sheetPermission" class="rounded-lg bg-gray-100 border border-gray-200 p-3 text-sm text-gray-700">
            You can view this sheet but not fill it.
          </div>

          <p v-if="activeSheet.sheet_kind === 'general_repair' && !activeSheet.sections.length" class="text-sm text-gray-600">
            Record the customer's issues and the jobs done on the <button type="button" class="text-primary-600 underline" @click="activeTab = 'issues'">Issues & Jobs</button> tab.
          </p>

          <section v-for="section in activeSheet.sections" :key="section.name">
            <button
              type="button"
              class="w-full flex items-center justify-between py-2"
              @click="collapsed[section.name] = !collapsed[section.name]"
            >
              <h2 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">{{ section.name }}</h2>
              <span class="flex items-center gap-2 text-xs text-gray-500">
                <span v-if="sectionProgress(section.items).issues" class="h-2 w-2 rounded-full bg-amber-500" />
                {{ sectionProgress(section.items).done }}/{{ sectionProgress(section.items).total }}
                <ChevronDownIcon class="h-4 w-4 transition-transform" :class="collapsed[section.name] ? '-rotate-90' : ''" />
              </span>
            </button>
            <div v-show="!collapsed[section.name]" class="space-y-2">
              <ActivityRow
                v-for="item in section.items"
                :key="item.id"
                :item="item"
                :job-card-id="jobCardId"
                :sheet-id="activeSheet.id"
                :readonly="readonly"
                @updated="onItemUpdated(activeSheet, $event)"
                @progress="activeSheet.progress = $event"
              />
            </div>
          </section>

          <form v-if="allowsAdhocRows && !readonly" class="flex gap-2" @submit.prevent="addRow">
            <input
              v-model="newRowName"
              class="input flex-1"
              :placeholder="activeSheet.sheet_kind === 'body_shop' ? 'Other panel' : activeSheet.sheet_kind === 'observation_report' ? 'Other part' : 'Other activity'"
            />
            <Button type="submit" size="sm" variant="secondary" :disabled="!newRowName.trim()">
              <PlusIcon class="h-4 w-4 mr-1" /> Add
            </Button>
          </form>
        </template>

        <IssuesPanel v-else-if="activeTab === 'issues'" :job-card-id="jobCardId" :readonly="companionsReadonly" />
        <ObservationsPanel
          v-else-if="activeTab === 'observations'"
          :job-card-id="jobCardId"
          :readonly="companionsReadonly"
          :refresh-key="observationsRefresh"
        />
      </div>

      <!-- Submit bar -->
      <div
        v-if="activeSheet && (canSubmit || (activeSheet.status === 'completed' && can('workshop.job-sheets.reopen')))"
        class="fixed bottom-0 inset-x-0 z-30 bg-white border-t border-gray-200 px-4 py-3"
        :class="uiStore.sidebarCollapsed ? 'lg:pl-24' : 'lg:pl-68'"
      >
        <Button v-if="canSubmit" block :loading="submitting" @click="submit(false)">
          Submit {{ shortName(activeSheet) }}
        </Button>
        <Button v-else block variant="secondary" @click="showReopen = true">Reopen sheet</Button>
      </div>

      <!-- Unfinished items -->
      <Modal :open="showProblems" title="Not finished yet" @close="showProblems = false">
        <p class="text-sm text-gray-600 mb-3">Complete these before submitting:</p>
        <ul class="divide-y divide-gray-100 max-h-80 overflow-y-auto">
          <li v-for="p in problems" :key="p.item_id">
            <button type="button" class="w-full text-left py-2 hover:bg-gray-50" @click="goToProblem(p)">
              <p class="text-sm font-medium text-gray-900">{{ p.item_name }}</p>
              <p class="text-xs text-red-600">{{ p.message }}</p>
            </button>
          </li>
        </ul>
        <div v-if="can('workshop.job-cards.override-guards')" class="mt-4 space-y-2">
          <label class="label">Manager override note</label>
          <textarea v-model="overrideNotes" rows="2" class="input" placeholder="Why can this sheet be submitted anyway?" />
        </div>
        <template #footer>
          <Button variant="secondary" @click="showProblems = false">Keep working</Button>
          <Button
            v-if="can('workshop.job-cards.override-guards')"
            variant="danger"
            :disabled="!overrideNotes.trim()"
            :loading="submitting"
            @click="submit(true)"
          >
            Override & submit
          </Button>
        </template>
      </Modal>

      <Modal :open="showReopen" title="Reopen sheet" @close="showReopen = false">
        <label class="label">Reason</label>
        <textarea v-model="reopenNotes" rows="3" class="input" placeholder="What needs to change?" />
        <template #footer>
          <Button variant="secondary" @click="showReopen = false">Cancel</Button>
          <Button :disabled="!reopenNotes.trim()" @click="reopen">Reopen</Button>
        </template>
      </Modal>
    </template>
  </div>
</template>
