<script setup lang="ts">
/**
 * Car classification master: the car classes prices are set by, and which class each
 * make/model belongs to (picked up automatically when a vehicle's model is chosen).
 * Edits are queued on the parent page's "Save changes" bar; adding saves at once.
 */
import { ref, computed, onMounted, watch } from 'vue'
import apiClient from '@/api/client'
import { useToast } from '@/composables/useToast'
import type { usePendingChanges } from '@/composables/usePendingChanges'
import type { ApiResponse, CarClassMasters } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'

const props = defineProps<{
  pending: ReturnType<typeof usePendingChanges>
  canEdit: boolean
  /** Bumped by the page after saving or discarding */
  reloadKey: number
}>()

const toast = useToast()
const base = '/workshop/pricing-masters'

const data = ref<CarClassMasters | null>(null)
const makeFilter = ref<number | ''>('')
const classFilter = ref<number | ''>('')
const search = ref('')
const newClass = ref('')
const newModel = ref({ make_id: '' as number | '', name: '', class_id: '' as number | '' })
const newMake = ref('')

async function load() {
  try {
    data.value = (await apiClient.get<ApiResponse<CarClassMasters>>(`${base}/car-classes`)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}

const className = (id: number | null) => data.value?.classes.find((c) => c.id === id)?.name ?? '—'

const models = computed(() =>
  (data.value?.models ?? []).filter(
    (m) =>
      (!makeFilter.value || m.make_id === makeFilter.value) &&
      (!classFilter.value || m.class_id === classFilter.value) &&
      (!search.value.trim() || `${m.make} ${m.name}`.toLowerCase().includes(search.value.trim().toLowerCase()))
  )
)

const hl = (key: string) => (props.pending.isChanged(key) ? 'border-amber-400 bg-amber-50' : '')

type ClassRow = CarClassMasters['classes'][number]
type ModelRow = CarClassMasters['models'][number]

function editClass(c: ClassRow, field: 'name' | 'description' | 'is_active', value: string | boolean) {
  ;(c as any)[field] = value
  props.pending.queue(`class:${c.id}:${field}`, `Car class "${c.name}": ${field.replace('is_', '')} → ${value}`, () =>
    apiClient.put(`${base}/car-classes/${c.id}`, { [field]: value })
  )
}

function editModel(m: ModelRow, field: 'name' | 'class_id' | 'is_active', value: string | number | boolean) {
  ;(m as any)[field] = value
  const shown = field === 'class_id' ? className(Number(value)) : value
  props.pending.queue(`model:${m.id}:${field}`, `${m.make} ${m.name}: ${field === 'class_id' ? 'class' : field.replace('is_', '')} → ${shown}`, () =>
    apiClient.put(`${base}/car-models/${m.id}`, { [field]: value })
  )
}

async function addNow(action: () => Promise<unknown>, question: string, ok: string) {
  if (props.pending.count.value) {
    toast.error('Unsaved changes', 'Save or discard your other changes first.')
    return
  }
  if (!window.confirm(question)) return
  try {
    await action()
    toast.success(ok)
    await load()
  } catch (err) {
    toast.apiError(err)
  }
}

function addClass() {
  const name = newClass.value.trim()
  if (!name) return
  addNow(() => apiClient.post(`${base}/car-classes`, { name }), `Add the car class "${name}"?`, 'Car class added — now set its prices').then(() => {
    newClass.value = ''
  })
}

function addMake() {
  const name = newMake.value.trim()
  if (!name) return
  addNow(() => apiClient.post(`${base}/car-makes`, { name }), `Add the make "${name}"?`, 'Make added').then(() => {
    newMake.value = ''
  })
}

function addModel() {
  const m = newModel.value
  if (!m.make_id || !m.name.trim() || !m.class_id) return
  addNow(
    () => apiClient.post(`${base}/car-models`, { make_id: m.make_id, name: m.name.trim(), class_id: m.class_id }),
    `Add ${m.name.trim()} as ${className(Number(m.class_id))}?`,
    'Car model added'
  ).then(() => {
    newModel.value = { make_id: m.make_id, name: '', class_id: '' }
  })
}

onMounted(load)
watch(() => props.reloadKey, load)
</script>

<template>
  <div v-if="!data" class="flex justify-center py-12"><Spinner size="lg" /></div>

  <div v-else class="space-y-4">
    <!-- Classes -->
    <Card :padding="false">
      <template #header>
        <h3 class="font-semibold">Car classes</h3>
        <p class="text-xs text-gray-500">Prices are set per class. A new class needs its prices in Package prices, Plan items, Engine oil and Labour charges.</p>
      </template>
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500">
          <tr>
            <th class="px-4 py-2 text-left">Class</th>
            <th class="px-2 py-2 text-left">Description</th>
            <th class="px-2 py-2 text-right">Vehicles</th>
            <th class="px-2 py-2">Active</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="c in data.classes" :key="c.id" :class="c.is_active ? '' : 'opacity-50'">
            <td class="px-4 py-1.5">
              <input
                class="w-full rounded border-gray-300 px-1 py-0.5 text-sm disabled:bg-gray-50"
                :class="hl(`class:${c.id}:name`)"
                :value="c.name"
                :disabled="!canEdit"
                @change="(e) => editClass(c, 'name', (e.target as HTMLInputElement).value.trim())"
              />
              <p v-if="!c.has_prices" class="text-xs text-amber-700">⚠ no package prices yet</p>
            </td>
            <td class="px-2 py-1.5">
              <input
                class="w-full rounded border-gray-300 px-1 py-0.5 text-sm disabled:bg-gray-50"
                :class="hl(`class:${c.id}:description`)"
                :value="c.description ?? ''"
                :disabled="!canEdit"
                @change="(e) => editClass(c, 'description', (e.target as HTMLInputElement).value.trim())"
              />
            </td>
            <td class="px-2 py-1.5 text-right text-gray-600">{{ c.vehicles_count }}</td>
            <td class="px-2 py-1.5 text-center">
              <input
                type="checkbox"
                class="rounded border-gray-300"
                :checked="c.is_active"
                :disabled="!canEdit"
                @change="(e) => editClass(c, 'is_active', (e.target as HTMLInputElement).checked)"
              />
            </td>
          </tr>
        </tbody>
      </table>
      <form v-if="canEdit" class="flex gap-2 px-4 py-3 border-t border-gray-100" @submit.prevent="addClass">
        <input v-model="newClass" class="flex-1 rounded-lg border-gray-300 text-sm" placeholder="New class, e.g. Electric SUV" />
        <Button type="submit" size="sm" :disabled="!newClass.trim()">Add class</Button>
      </form>
    </Card>

    <!-- Models -->
    <Card :padding="false">
      <template #header>
        <div class="flex flex-wrap items-center gap-2">
          <h3 class="font-semibold mr-auto">Car models</h3>
          <input v-model="search" class="rounded-lg border-gray-300 text-sm" placeholder="Search model" />
          <select v-model="makeFilter" class="rounded-lg border-gray-300 text-sm">
            <option value="">All makes</option>
            <option v-for="mk in data.makes" :key="mk.id" :value="mk.id">{{ mk.name }}</option>
          </select>
          <select v-model="classFilter" class="rounded-lg border-gray-300 text-sm">
            <option value="">All classes</option>
            <option v-for="c in data.classes" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <p class="text-xs text-gray-500">When a vehicle's model is chosen, its class is filled in from here.</p>
      </template>
      <div class="max-h-[32rem] overflow-auto">
        <table class="min-w-full text-sm">
          <thead class="sticky top-0 bg-gray-50 text-xs text-gray-500">
            <tr>
              <th class="px-4 py-2 text-left">Make</th>
              <th class="px-2 py-2 text-left">Model</th>
              <th class="px-2 py-2 text-left">Class</th>
              <th class="px-2 py-2">Active</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="m in models" :key="m.id" :class="m.is_active ? '' : 'opacity-50'">
              <td class="px-4 py-1.5 text-gray-700">{{ m.make }}</td>
              <td class="px-2 py-1.5">
                <input
                  class="w-full rounded border-gray-300 px-1 py-0.5 text-sm disabled:bg-gray-50"
                  :class="hl(`model:${m.id}:name`)"
                  :value="m.name"
                  :disabled="!canEdit"
                  @change="(e) => editModel(m, 'name', (e.target as HTMLInputElement).value.trim())"
                />
              </td>
              <td class="px-2 py-1.5">
                <select
                  class="w-full rounded border-gray-300 py-0.5 text-sm disabled:bg-gray-50"
                  :class="[hl(`model:${m.id}:class_id`), m.class_id ? '' : 'text-amber-700']"
                  :value="m.class_id ?? ''"
                  :disabled="!canEdit"
                  @change="(e) => editModel(m, 'class_id', Number((e.target as HTMLSelectElement).value))"
                >
                  <option value="" disabled>Not classified</option>
                  <option v-for="c in data.classes" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
              </td>
              <td class="px-2 py-1.5 text-center">
                <input
                  type="checkbox"
                  class="rounded border-gray-300"
                  :checked="m.is_active"
                  :disabled="!canEdit"
                  @change="(e) => editModel(m, 'is_active', (e.target as HTMLInputElement).checked)"
                />
              </td>
            </tr>
            <tr v-if="!models.length">
              <td colspan="4" class="px-4 py-4 text-center text-gray-500">No models match.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="canEdit" class="space-y-2 px-4 py-3 border-t border-gray-100">
        <form class="grid grid-cols-12 gap-2" @submit.prevent="addModel">
          <select v-model="newModel.make_id" class="col-span-12 sm:col-span-3 rounded-lg border-gray-300 text-sm">
            <option value="">Make…</option>
            <option v-for="mk in data.makes" :key="mk.id" :value="mk.id">{{ mk.name }}</option>
          </select>
          <input v-model="newModel.name" class="col-span-12 sm:col-span-4 rounded-lg border-gray-300 text-sm" placeholder="New model, e.g. Grand Vitara" />
          <select v-model="newModel.class_id" class="col-span-8 sm:col-span-3 rounded-lg border-gray-300 text-sm">
            <option value="">Class…</option>
            <option v-for="c in data.classes.filter((x) => x.is_active)" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <Button type="submit" size="sm" class="col-span-4 sm:col-span-2" :disabled="!newModel.make_id || !newModel.name.trim() || !newModel.class_id">
            Add model
          </Button>
        </form>
        <form class="flex gap-2" @submit.prevent="addMake">
          <input v-model="newMake" class="flex-1 rounded-lg border-gray-300 text-sm" placeholder="Make not in the list? e.g. BYD" />
          <Button type="submit" size="sm" variant="secondary" :disabled="!newMake.trim()">Add make</Button>
        </form>
      </div>
    </Card>
  </div>
</template>
