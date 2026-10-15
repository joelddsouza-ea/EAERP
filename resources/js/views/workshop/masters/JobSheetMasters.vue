<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { ArrowUpIcon, ArrowDownIcon, PlusIcon } from '@heroicons/vue/20/solid'
import { jobSheetMastersApi } from '@/api/jobSheets.api'
import { intakeApi, type JobSheetMediaSettings } from '@/api/estimates.api'
import { masterDataApi } from '@/api/workshop.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { ChecklistMasterItem, ChecklistMasterSection, ChecklistMasterTemplate, MediaRequirement, SheetKind } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'
import UnsavedChangesBar from '@/components/ui/UnsavedChangesBar.vue'
import { usePendingChanges } from '@/composables/usePendingChanges'
import apiClient from '@/api/client'
import type { ObservationRemark, WorkflowStageRow } from '@/api/types'
import { workflowStagesApi } from '@/api/billing.api'

const { can } = usePermissions()
const toast = useToast()

const templates = ref<ChecklistMasterTemplate[]>([])
const selected = ref<ChecklistMasterTemplate | null>(null)
const loadingList = ref(true)
const loadingTemplate = ref(false)
const showInactive = ref(false)
const savingItemId = ref<number | null>(null)
const newItemNames = ref<Record<number, string>>({})
const newSectionName = ref('')

const canEdit = computed(() => can('workshop.job-sheet-masters.update'))

const KIND_LABELS: Record<SheetKind, string> = {
  service_activities: 'Service plan job sheets',
  ac_service: 'AC service',
  general_repair: 'General repairs',
  body_shop: 'Body shop',
  observation_report: 'Observation report',
  pre_delivery: 'Pre-delivery',
}

const MEDIA_OPTIONS: { value: MediaRequirement; label: string }[] = [
  { value: 'none', label: 'Not required' },
  { value: 'photo', label: 'Photo' },
  { value: 'video', label: 'Video' },
  { value: 'photo_or_video', label: 'Photo or video' },
]

const grouped = computed(() => {
  const groups: Record<string, ChecklistMasterTemplate[]> = {}
  for (const t of templates.value) {
    ;(groups[t.sheet_kind] ??= []).push(t)
  }
  return Object.entries(groups).map(([kind, list]) => ({ kind: kind as SheetKind, list }))
})

const totals = computed(() => {
  const items = selected.value?.sections?.flatMap((s) => s.items.filter((i) => i.is_active)) ?? []
  return {
    total: items.length,
    media: items.filter((i) => i.media_requirement !== 'none').length,
    na: items.filter((i) => i.na_allowed).length,
  }
})

async function loadTemplates() {
  loadingList.value = true
  try {
    templates.value = (await jobSheetMastersApi.templates(showInactive.value)).data.data
    if (!selected.value && templates.value.length) {
      await select(templates.value[0])
    }
  } catch (err) {
    toast.apiError(err)
  } finally {
    loadingList.value = false
  }
}

async function select(template: ChecklistMasterTemplate) {
  loadingTemplate.value = true
  editingPlans.value = false
  try {
    selected.value = (await jobSheetMastersApi.template(template.id)).data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loadingTemplate.value = false
  }
}

// ---- edits are held on screen and saved together ("Save changes", with a confirmation)

const pending = usePendingChanges()
const savingAll = ref(false)

function isChanged(item: ChecklistMasterItem, field: string): boolean {
  return pending.isChanged(`item:${item.id}:${field}`)
}

function updateItem(section: ChecklistMasterSection, item: ChecklistMasterItem, data: Partial<ChecklistMasterItem>) {
  Object.assign(item, data)
  for (const [field, val] of Object.entries(data)) {
    pending.queue(`item:${item.id}:${field}`, `${item.item_name}: ${field.replace(/_/g, ' ')} → ${val ?? '—'}`, () =>
      jobSheetMastersApi.updateItem(section.id, item.id, { [field]: val } as Partial<ChecklistMasterItem>)
    )
  }
}

function move(section: ChecklistMasterSection, index: number, delta: number) {
  const target = index + delta
  if (target < 0 || target >= section.items.length) return
  const items = [...section.items]
  ;[items[index], items[target]] = [items[target], items[index]]
  section.items = items
  // Saves the order as it is on screen when "Save changes" is pressed
  pending.queue(`section:${section.id}:order`, `${section.name}: new activity order`, () =>
    jobSheetMastersApi.reorderItems(section.id, section.items.map((i) => i.id))
  )
}

async function saveAll() {
  savingAll.value = true
  const { saved, errors } = await pending.saveAll()
  savingAll.value = false
  if (errors.length) {
    toast.apiError(errors[0])
    toast.error('Some changes were not saved', `${saved} saved, ${errors.length} still pending.`)
  } else {
    toast.success('Saved', `${saved} change${saved === 1 ? '' : 's'} saved.`)
  }
  await reloadAll()
}

async function discardAll() {
  pending.clear()
  await reloadAll()
}

async function reloadAll() {
  await Promise.all([
    selected.value ? select(selected.value) : Promise.resolve(),
    loadIntakeRequirements(),
    loadRemarks(),
    loadStages(),
  ])
}

// ---- job stages: automatic moves and the customer's WhatsApp message per stage

const stages = ref<WorkflowStageRow[]>([])
const placeholders = ref<string[]>([])

const NOTIFY_OPTIONS = [
  { value: 'off', label: 'No message' },
  { value: 'first_entry', label: 'First time only' },
  { value: 'always', label: 'Every time' },
] as const

async function loadStages() {
  try {
    const data = (await workflowStagesApi.list()).data.data
    stages.value = data.stages
    placeholders.value = data.placeholders
  } catch {
    stages.value = []
  }
}

/** One queued change per stage, sending the row as it is on screen (message and on/off are checked together) */
function updateStage(stage: WorkflowStageRow, data: Partial<WorkflowStageRow>) {
  Object.assign(stage, data)
  pending.queue(`stage:${stage.id}`, `Job stage "${stage.label}"`, () =>
    workflowStagesApi.update(stage.id, {
      auto: stage.auto,
      waiting_for: stage.waiting_for,
      notify_customer: stage.notify_customer,
      message: stage.message,
      gallery_update: stage.gallery_update,
    })
  )
}

// ---- observation remarks (master list)

const remarks = ref<ObservationRemark[]>([])
const newRemark = ref('')

async function loadRemarks() {
  try {
    remarks.value = (await apiClient.get('/workshop/job-sheet-masters/observation-remarks')).data.data
  } catch {
    remarks.value = []
  }
}

function updateRemark(remark: ObservationRemark, data: Partial<ObservationRemark>) {
  Object.assign(remark, data)
  for (const [field, val] of Object.entries(data)) {
    pending.queue(`remark:${remark.id}:${field}`, `Remark "${remark.label}": ${field.replace(/_/g, ' ')} → ${val}`, () =>
      apiClient.put(`/workshop/job-sheet-masters/observation-remarks/${remark.id}`, { [field]: val })
    )
  }
}

async function addRemark() {
  const label = newRemark.value.trim()
  if (!label) return
  if (!window.confirm(`Add the remark "${label}"?`)) return
  try {
    await apiClient.post('/workshop/job-sheet-masters/observation-remarks', { label })
    newRemark.value = ''
    remarks.value = (await apiClient.get('/workshop/job-sheet-masters/observation-remarks')).data.data
    toast.success('Remark added')
  } catch (err) {
    toast.apiError(err)
  }
}


async function addItem(section: ChecklistMasterSection) {
  const name = newItemNames.value[section.id]?.trim()
  if (!name || !selected.value) return
  if (!window.confirm(`Add "${name}" to ${section.name}?`)) return
  try {
    const inputType = section.items[0]?.input_type ?? 'tick'
    const response = await jobSheetMastersApi.createItem(section.id, { item_name: name, input_type: inputType })
    section.items.push(response.data.data)
    newItemNames.value[section.id] = ''
  } catch (err) {
    toast.apiError(err)
  }
}

async function addSection() {
  if (!selected.value || !newSectionName.value.trim()) return
  if (!window.confirm(`Add the section "${newSectionName.value.trim()}"?`)) return
  try {
    const response = await jobSheetMastersApi.createSection(selected.value.id, newSectionName.value.trim())
    selected.value.sections = [...(selected.value.sections ?? []), { ...response.data.data, items: [] }]
    newSectionName.value = ''
  } catch (err) {
    toast.apiError(err)
  }
}

// ---- plans a sheet is used for (new plans need a sheet linked here)

const plans = ref<{ id: number; name: string }[]>([])
const editingPlans = ref(false)
const planSelection = ref<number[]>([])

const linksToPlans = computed(() => ['service_activities', 'observation_report'].includes(selected.value?.sheet_kind ?? ''))

function startPlanEdit() {
  planSelection.value = selected.value?.packages.map((p) => p.id) ?? []
  editingPlans.value = true
}

async function savePlans() {
  if (!selected.value) return
  if (!window.confirm('Save the plans this job sheet is used for?')) return
  try {
    const response = await jobSheetMastersApi.updateTemplate(selected.value.id, { package_ids: planSelection.value })
    selected.value.packages = response.data.data.packages
    editingPlans.value = false
    toast.success('Plans linked')
  } catch (err) {
    toast.apiError(err)
  }
}

// ---- intake minimums (inventory photos / videos before work starts)

const intakeReq = ref<JobSheetMediaSettings | null>(null)

async function loadIntakeRequirements() {
  try {
    intakeReq.value = (await intakeApi.requirements()).data.data
  } catch {
    intakeReq.value = null
  }
}

/** Queued like the other edits; sends the values on screen when saved */
function saveIntakeRequirements(label = 'Intake / Additional Issues photo & video minimums') {
  if (!intakeReq.value) return
  pending.queue('settings:media-minimums', label, () => {
    const r = intakeReq.value!
    return intakeApi.setRequirements({
      min_photos: Number(r.min_photos) || 0,
      min_videos: Number(r.min_videos) || 0,
      issue_min_problem_media: Number(r.issue_min_problem_media) || 0,
      issue_min_fitted_media: Number(r.issue_min_fitted_media) || 0,
    })
  })
}

async function toggleTemplateActive() {
  if (!selected.value) return
  if (!window.confirm(`${selected.value.is_active ? 'Deactivate' : 'Activate'} "${selected.value.name}"?`)) return
  try {
    const response = await jobSheetMastersApi.updateTemplate(selected.value.id, { is_active: !selected.value.is_active })
    selected.value.is_active = response.data.data.is_active
    await loadTemplates()
  } catch (err) {
    toast.apiError(err)
  }
}

onMounted(async () => {
  await loadTemplates()
  loadIntakeRequirements()
  loadRemarks()
  loadStages()
  try {
    plans.value = (await masterDataApi.servicePackages()).data.data
  } catch {
    plans.value = []
  }
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Job Sheet Masters</h1>
      <p class="text-gray-500 mt-1">
        Activities on each technician job sheet, and whether a photo or video is required or N/A is allowed.
      </p>
    </div>

    <Card v-if="intakeReq" class="mb-6">
      <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="font-medium text-gray-700">Intake inventory: at least</span>
        <input
          v-model.number="intakeReq.min_photos"
          type="number"
          min="0"
          max="30"
          class="w-16 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
          :disabled="!canEdit"
          @change="saveIntakeRequirements()"
        />
        <span>photos and</span>
        <input
          v-model.number="intakeReq.min_videos"
          type="number"
          min="0"
          max="10"
          class="w-16 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
          :disabled="!canEdit"
          @change="saveIntakeRequirements()"
        />
        <span>videos before work can start, plus the advisor's trial drive.</span>
      </div>
    </Card>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
      <!-- Template list -->
      <Card :padding="false" class="lg:col-span-1 self-start">
        <div class="p-3 border-b border-gray-200">
          <label class="flex items-center gap-2 text-sm text-gray-600">
            <input v-model="showInactive" type="checkbox" class="h-4 w-4 rounded border-gray-300" @change="loadTemplates" />
            Show inactive sheets
          </label>
        </div>
        <div v-if="loadingList" class="p-6 flex justify-center"><Spinner /></div>
        <div v-for="group in grouped" :key="group.kind" class="py-2">
          <p class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ KIND_LABELS[group.kind] ?? group.kind }}</p>
          <button
            v-for="t in group.list"
            :key="t.id"
            type="button"
            class="w-full text-left px-4 py-2 text-sm hover:bg-gray-50"
            :class="selected?.id === t.id ? 'bg-primary-50 text-primary-700 font-medium' : 'text-gray-700'"
            @click="select(t)"
          >
            {{ t.name.replace(/\s+—\s+.*$/, '') }}
            <span class="text-xs text-gray-400">({{ t.items_count }})</span>
            <span v-if="!t.is_active" class="ml-1 text-xs text-red-500">inactive</span>
          </button>
        </div>
      </Card>

      <!-- Editor -->
      <div class="lg:col-span-3 space-y-4">
        <div v-if="loadingTemplate" class="flex justify-center py-12"><Spinner size="lg" /></div>

        <template v-else-if="selected">
          <div class="rounded-lg bg-blue-50 border border-blue-200 p-3 text-sm text-blue-800">
            Changes apply to job cards created from now on. Job cards already in the workshop keep the rules they started with.
          </div>

          <Card>
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ selected.name }}</h2>
                <p class="text-sm text-gray-500 mt-1">
                  <template v-if="selected.packages.length">Plans: {{ selected.packages.map((p) => p.name).join(', ') }}</template>
                  <template v-else-if="selected.service_type">Service type: {{ selected.service_type.replace('_', ' ') }}</template>
                  <template v-else-if="selected.applies_to_all">Every job card</template>
                  · Filled by {{ selected.filled_by }}
                </p>
                <p class="text-sm text-gray-600 mt-2">
                  {{ totals.total }} activities · {{ totals.media }} need a photo/video · {{ totals.na }} allow N/A
                </p>
                <div v-if="linksToPlans && canEdit" class="mt-2">
                  <Button v-if="!editingPlans" size="sm" variant="ghost" @click="startPlanEdit">Change plans…</Button>
                  <div v-else class="mt-1 rounded-lg border border-gray-200 p-3">
                    <p class="text-xs text-gray-500 mb-2">New job cards on these plans get this sheet.</p>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                      <label v-for="p in plans" :key="p.id" class="inline-flex items-center gap-2 text-sm">
                        <input v-model="planSelection" type="checkbox" :value="p.id" class="rounded border-gray-300" />
                        {{ p.name }}
                      </label>
                    </div>
                    <div class="mt-2 flex gap-2">
                      <Button size="sm" @click="savePlans">Save</Button>
                      <Button size="sm" variant="secondary" @click="editingPlans = false">Cancel</Button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <Badge :variant="selected.is_active ? 'success' : 'danger'">{{ selected.is_active ? 'Active' : 'Inactive' }}</Badge>
                <Button v-if="canEdit" size="sm" variant="secondary" @click="toggleTemplateActive">
                  {{ selected.is_active ? 'Deactivate' : 'Activate' }}
                </Button>
              </div>
            </div>
          </Card>

          <Card v-if="selected.sheet_kind === 'general_repair' && intakeReq">
            <h3 class="text-base font-semibold mb-2">Additional Issues lines — photos / videos</h3>
            <div class="flex flex-wrap items-center gap-3 text-sm">
              <span>A problem the technician finds needs at least</span>
              <input
                v-model.number="intakeReq.issue_min_problem_media"
                type="number"
                min="0"
                max="10"
                class="w-16 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
                :disabled="!canEdit"
                @change="saveIntakeRequirements()"
              />
              <span>photo(s)/video(s); a line whose parts were approved needs</span>
              <input
                v-model.number="intakeReq.issue_min_fitted_media"
                type="number"
                min="0"
                max="10"
                class="w-16 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
                :disabled="!canEdit"
                @change="saveIntakeRequirements()"
              />
              <span>of the fitted new part before it is done.</span>
            </div>
            <p class="mt-2 text-xs text-gray-500">These lines appear on every job sheet's Additional Issues tab (customer complaints + technician findings).</p>
          </Card>

          <Card v-for="section in selected.sections" :key="section.id" :padding="false">
            <template #header>
              <h3 class="text-base font-semibold">{{ section.name }}</h3>
            </template>

            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-4 py-2 text-left font-medium text-gray-500">Activity</th>
                    <th v-if="section.items[0]?.input_type !== 'yes_no'" class="px-3 py-2 text-left font-medium text-gray-500">Photo / video</th>
                    <th v-if="section.items[0]?.input_type !== 'yes_no'" class="px-3 py-2 text-center font-medium text-gray-500">Min</th>
                    <th v-if="section.items[0]?.input_type === 'tick'" class="px-3 py-2 text-center font-medium text-gray-500">N/A</th>
                    <th v-if="section.items[0]?.input_type === 'yes_no'" class="px-3 py-2 text-left font-medium text-gray-500">Passing answer</th>
                    <th class="px-3 py-2 text-center font-medium text-gray-500">Required</th>
                    <th class="px-3 py-2 text-center font-medium text-gray-500">Active</th>
                    <th v-if="canEdit" class="px-3 py-2" />
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <tr
                    v-for="(item, index) in section.items"
                    :key="item.id"
                    :class="[!item.is_active ? 'opacity-50' : '', savingItemId === item.id ? 'bg-yellow-50' : '']"
                  >
                    <td class="px-4 py-2">
                      <input
                        v-if="canEdit"
                        :value="item.item_name"
                        class="input py-1"
                        @change="updateItem(section, item, { item_name: ($event.target as HTMLInputElement).value })"
                      />
                      <span v-else>{{ item.item_name }}</span>
                      <p v-if="item.source_marker || item.rules_customized_at" class="mt-0.5 text-xs text-gray-400">
                        <template v-if="item.source_marker">Sheet: "{{ item.source_marker }}"</template>
                        <template v-if="item.rules_customized_at"> · edited</template>
                      </p>
                    </td>
                    <template v-if="item.input_type !== 'yes_no'">
                      <td class="px-3 py-2">
                        <select
                          :value="item.media_requirement"
                          class="input py-1"
                          :class="isChanged(item, 'media_requirement') ? 'border-amber-400 bg-amber-50' : ''"
                          :disabled="!canEdit"
                          @change="updateItem(section, item, { media_requirement: ($event.target as HTMLSelectElement).value as MediaRequirement })"
                        >
                          <option v-for="o in MEDIA_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                      </td>
                      <td class="px-3 py-2 text-center">
                        <input
                          type="number"
                          min="1"
                          max="5"
                          :value="item.min_media_count"
                          class="input py-1 w-16 text-center"
                          :disabled="!canEdit || item.media_requirement === 'none'"
                          @change="updateItem(section, item, { min_media_count: Number(($event.target as HTMLInputElement).value) || 1 })"
                        />
                      </td>
                      <td v-if="item.input_type === 'tick'" class="px-3 py-2 text-center">
                        <input
                          type="checkbox"
                          class="h-4 w-4 rounded border-gray-300"
                          :checked="item.na_allowed"
                          :disabled="!canEdit"
                          @change="updateItem(section, item, { na_allowed: ($event.target as HTMLInputElement).checked })"
                        />
                      </td>
                    </template>
                    <td v-else-if="item.input_type === 'yes_no'" class="px-3 py-2">
                      <select
                        :value="item.expected_answer ?? ''"
                        class="input py-1"
                        :disabled="!canEdit"
                        @change="updateItem(section, item, { expected_answer: (($event.target as HTMLSelectElement).value || null) as 'yes' | 'no' | null })"
                      >
                        <option value="yes">Yes</option>
                        <option value="no">No</option>
                        <option value="">Either</option>
                      </select>
                    </td>
                    <td class="px-3 py-2 text-center">
                      <input
                        type="checkbox"
                        class="h-4 w-4 rounded border-gray-300"
                        :checked="item.is_required"
                        :disabled="!canEdit"
                        @change="updateItem(section, item, { is_required: ($event.target as HTMLInputElement).checked })"
                      />
                    </td>
                    <td class="px-3 py-2 text-center">
                      <input
                        type="checkbox"
                        class="h-4 w-4 rounded border-gray-300"
                        :checked="item.is_active"
                        :disabled="!canEdit"
                        @change="updateItem(section, item, { is_active: ($event.target as HTMLInputElement).checked })"
                      />
                    </td>
                    <td v-if="canEdit" class="px-3 py-2 whitespace-nowrap">
                      <button type="button" class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="index === 0" aria-label="Move up" @click="move(section, index, -1)">
                        <ArrowUpIcon class="h-4 w-4" />
                      </button>
                      <button
                        type="button"
                        class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30"
                        :disabled="index === section.items.length - 1"
                        aria-label="Move down"
                        @click="move(section, index, 1)"
                      >
                        <ArrowDownIcon class="h-4 w-4" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <template v-if="canEdit" #footer>
              <form class="flex gap-2" @submit.prevent="addItem(section)">
                <input v-model="newItemNames[section.id]" class="input flex-1" placeholder="New activity" />
                <Button type="submit" size="sm" variant="secondary" :disabled="!newItemNames[section.id]?.trim()">
                  <PlusIcon class="h-4 w-4 mr-1" /> Add activity
                </Button>
              </form>
            </template>
          </Card>

          <form v-if="canEdit" class="flex gap-2" @submit.prevent="addSection">
            <input v-model="newSectionName" class="input flex-1" placeholder="New section name" />
            <Button type="submit" size="sm" variant="secondary" :disabled="!newSectionName.trim()">
              <PlusIcon class="h-4 w-4 mr-1" /> Add section
            </Button>
          </form>
        </template>
      </div>
    </div>

    <!-- Remarks for observations (on the report) -->
    <Card class="mt-6" :padding="false">
      <template #header>
        <h3 class="text-base font-semibold">Observation remarks</h3>
        <p class="text-xs text-gray-500">Chosen per observation on the job sheet and printed on the report. Use “_” for a number of days, e.g. “Fix within _ days”.</p>
      </template>
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Remark</th>
            <th class="px-3 py-2 text-center font-medium text-gray-500">Asks for days</th>
            <th class="px-3 py-2 text-center font-medium text-gray-500">Active</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="r in remarks" :key="r.id" :class="r.is_active ? '' : 'opacity-50'">
            <td class="px-4 py-2">
              <input
                v-if="canEdit"
                :value="r.label"
                class="input py-1"
                :class="pending.isChanged(`remark:${r.id}:label`) ? 'border-amber-400 bg-amber-50' : ''"
                @change="updateRemark(r, { label: ($event.target as HTMLInputElement).value.trim() })"
              />
              <span v-else>{{ r.label }}</span>
            </td>
            <td class="px-3 py-2 text-center">
              <input type="checkbox" class="h-4 w-4 rounded border-gray-300" :checked="r.needs_days" :disabled="!canEdit"
                @change="updateRemark(r, { needs_days: ($event.target as HTMLInputElement).checked })" />
            </td>
            <td class="px-3 py-2 text-center">
              <input type="checkbox" class="h-4 w-4 rounded border-gray-300" :checked="r.is_active" :disabled="!canEdit"
                @change="updateRemark(r, { is_active: ($event.target as HTMLInputElement).checked })" />
            </td>
          </tr>
        </tbody>
      </table>
      <template v-if="canEdit" #footer>
        <form class="flex gap-2" @submit.prevent="addRemark">
          <input v-model="newRemark" class="input flex-1" placeholder="New remark, e.g. Replace within _ km" />
          <Button type="submit" size="sm" variant="secondary" :disabled="!newRemark.trim()">
            <PlusIcon class="h-4 w-4 mr-1" /> Add remark
          </Button>
        </form>
      </template>
    </Card>

    <!-- Job stages: what moves a job on by itself, and the WhatsApp message on each stage -->
    <Card v-if="stages.length" class="mt-6" :padding="false">
      <template #header>
        <h3 class="text-base font-semibold">Job stages &amp; customer messages</h3>
        <p class="text-xs text-gray-500">
          Stages marked automatic are entered by the system as soon as what the previous stage waits for has happened.
          Switch one to manual and the advisor moves it on as before. Owners and managers can always move a stuck job by hand, with a reason.
          Message placeholders: {{ placeholders.join(' ') }}.
          Photos and videos appear on the customer's page by themselves (final-QC evidence stays internal); the page refreshes itself,
          so tick only the stages where the customer should get a WhatsApp nudge to look.
        </p>
      </template>
      <div class="divide-y divide-gray-100">
        <div v-for="stage in stages" :key="stage.id" class="p-4 grid grid-cols-1 lg:grid-cols-12 gap-3 text-sm"
          :class="pending.isChanged(`stage:${stage.id}`) ? 'bg-amber-50' : ''">
          <div class="lg:col-span-3">
            <p class="font-medium text-gray-900">{{ stage.label }}</p>
            <label v-if="stage.auto_capable" class="mt-1 flex items-center gap-2 text-gray-700">
              <input type="checkbox" class="h-4 w-4 rounded border-gray-300" :checked="stage.auto" :disabled="!canEdit"
                @change="updateStage(stage, { auto: ($event.target as HTMLInputElement).checked })" />
              Entered automatically
            </label>
            <p v-else class="mt-1 text-xs text-gray-500">{{ stage.entered_by }}</p>
          </div>
          <div class="lg:col-span-4">
            <label class="block text-xs text-gray-500 mb-1">Waits for (shown on the job card)</label>
            <textarea :value="stage.waiting_for ?? ''" rows="2" class="input w-full py-1 text-sm" :disabled="!canEdit"
              @change="updateStage(stage, { waiting_for: ($event.target as HTMLTextAreaElement).value.trim() || null })" />
          </div>
          <div class="lg:col-span-5">
            <div class="flex items-center gap-2 mb-1">
              <label class="text-xs text-gray-500">WhatsApp to customer</label>
              <select :value="stage.notify_customer" class="input py-0.5 text-xs w-auto" :disabled="!canEdit"
                @change="updateStage(stage, { notify_customer: ($event.target as HTMLSelectElement).value as WorkflowStageRow['notify_customer'] })">
                <option v-for="o in NOTIFY_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </div>
            <textarea :value="stage.message ?? ''" rows="3" class="input w-full py-1 text-sm font-mono"
              :class="stage.notify_customer === 'off' ? 'opacity-60' : ''" :disabled="!canEdit"
              placeholder="Hello :name, ..."
              @change="updateStage(stage, { message: ($event.target as HTMLTextAreaElement).value.trim() || null })" />
            <label class="mt-1 flex items-start gap-2 text-xs text-gray-700">
              <input type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300" :checked="stage.gallery_update" :disabled="!canEdit"
                @change="updateStage(stage, { gallery_update: ($event.target as HTMLInputElement).checked })" />
              <span>Tell the customer about new photos/videos here (all sections since the last update, in one line — added to this stage's message, or sent alone if this stage has none)</span>
            </label>
          </div>
        </div>
      </div>
    </Card>

    <UnsavedChangesBar
      :count="pending.count.value"
      :labels="pending.labels.value"
      :saving="savingAll"
      @save="saveAll"
      @discard="discardAll"
    />
  </div>
</template>
