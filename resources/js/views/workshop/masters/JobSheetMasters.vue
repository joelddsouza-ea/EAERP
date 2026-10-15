<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { ArrowUpIcon, ArrowDownIcon, PlusIcon } from '@heroicons/vue/20/solid'
import { jobSheetMastersApi } from '@/api/jobSheets.api'
import { intakeApi } from '@/api/estimates.api'
import { masterDataApi } from '@/api/workshop.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { ChecklistMasterItem, ChecklistMasterSection, ChecklistMasterTemplate, MediaRequirement, SheetKind } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'

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

async function updateItem(section: ChecklistMasterSection, item: ChecklistMasterItem, data: Partial<ChecklistMasterItem>) {
  savingItemId.value = item.id
  const before = { ...item }
  Object.assign(item, data)
  try {
    const response = await jobSheetMastersApi.updateItem(section.id, item.id, data)
    Object.assign(item, response.data.data)
  } catch (err) {
    Object.assign(item, before)
    toast.apiError(err)
  } finally {
    savingItemId.value = null
  }
}

async function move(section: ChecklistMasterSection, index: number, delta: number) {
  const target = index + delta
  if (target < 0 || target >= section.items.length) return
  const items = [...section.items]
  ;[items[index], items[target]] = [items[target], items[index]]
  section.items = items
  try {
    await jobSheetMastersApi.reorderItems(section.id, items.map((i) => i.id))
  } catch (err) {
    toast.apiError(err)
  }
}

async function addItem(section: ChecklistMasterSection) {
  const name = newItemNames.value[section.id]?.trim()
  if (!name || !selected.value) return
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

const intakeReq = ref<{ min_photos: number; min_videos: number } | null>(null)

async function loadIntakeRequirements() {
  try {
    intakeReq.value = (await intakeApi.requirements()).data.data
  } catch {
    intakeReq.value = null
  }
}

async function saveIntakeRequirements() {
  if (!intakeReq.value) return
  try {
    intakeReq.value = (
      await intakeApi.setRequirements({
        min_photos: Number(intakeReq.value.min_photos) || 0,
        min_videos: Number(intakeReq.value.min_videos) || 0,
      })
    ).data.data
    toast.success('Intake requirements saved')
  } catch (err) {
    toast.apiError(err)
  }
}

async function toggleTemplateActive() {
  if (!selected.value) return
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
          @change="saveIntakeRequirements"
        />
        <span>photos and</span>
        <input
          v-model.number="intakeReq.min_videos"
          type="number"
          min="0"
          max="10"
          class="w-16 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
          :disabled="!canEdit"
          @change="saveIntakeRequirements"
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

          <Card v-for="section in selected.sections" :key="section.id" :padding="false">
            <template #header>
              <h3 class="text-base font-semibold">{{ section.name }}</h3>
            </template>

            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-4 py-2 text-left font-medium text-gray-500">Activity</th>
                    <th v-if="section.items[0]?.input_type === 'tick'" class="px-3 py-2 text-left font-medium text-gray-500">Photo / video</th>
                    <th v-if="section.items[0]?.input_type === 'tick'" class="px-3 py-2 text-center font-medium text-gray-500">Min</th>
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
                    <template v-if="item.input_type === 'tick'">
                      <td class="px-3 py-2">
                        <select
                          :value="item.media_requirement"
                          class="input py-1"
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
                      <td class="px-3 py-2 text-center">
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
  </div>
</template>
