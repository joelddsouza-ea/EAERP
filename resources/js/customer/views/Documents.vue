<script setup lang="ts">
/**
 * Document locker: insurance, PUC, RC, licence — a photo or PDF with its expiry; reminders
 * come before they expire, and the car's insurance / PUC dates follow the latest one.
 */
import { ref, onMounted } from 'vue'
import { DocumentTextIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import api, { message, date, type Home } from '../api'
import { toast } from '../ui'
import MediaPicker from '@/components/media/MediaPicker.vue'

interface Doc { id: number; type: string; type_label: string; title: string | null; number: string | null; expiry_date: string | null; expired: boolean; vehicle: string | null; mime: string | null; url: string }

const docs = ref<Doc[]>([])
const cars = ref<Home['vehicles']>([])
const adding = ref(false)
const busy = ref(false)
const form = ref({ type: 'insurance', vehicle_id: null as number | null, number: '', expiry_date: '', title: '' })
const file = ref<File | null>(null)
const confirmDelete = ref<number | null>(null)

const TYPES = [
  { key: 'insurance', label: 'Insurance policy' },
  { key: 'puc', label: 'PUC certificate' },
  { key: 'rc', label: 'Registration (RC)' },
  { key: 'licence', label: 'Driving licence' },
  { key: 'other', label: 'Other' },
]

async function load() {
  docs.value = (await api.get('/documents')).data.data
}

onMounted(async () => {
  try {
    await load()
    cars.value = (await api.get('/home')).data.data.vehicles
    form.value.vehicle_id = cars.value[0]?.id ?? null
  } catch (err) {
    toast(message(err), 'error')
  }
})

async function save() {
  if (!file.value) {
    toast('Take a photo or choose the file.', 'error')
    return
  }
  busy.value = true
  try {
    const data = new FormData()
    data.append('type', form.value.type)
    if (form.value.vehicle_id && form.value.type !== 'licence') data.append('vehicle_id', String(form.value.vehicle_id))
    if (form.value.number) data.append('number', form.value.number)
    if (form.value.expiry_date) data.append('expiry_date', form.value.expiry_date)
    if (form.value.title) data.append('title', form.value.title)
    data.append('file', file.value)
    await api.post('/documents', data)
    toast('Saved. We will remind you before it expires.')
    adding.value = false
    file.value = null
    form.value.number = ''
    form.value.expiry_date = ''
    await load()
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    busy.value = false
  }
}

async function remove(d: Doc) {
  if (confirmDelete.value !== d.id) {
    confirmDelete.value = d.id
    return
  }
  try {
    await api.delete(`/documents/${d.id}`)
    confirmDelete.value = null
    await load()
  } catch (err) {
    toast(message(err), 'error')
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-bold text-gray-900">My documents</h1>
      <button v-if="!adding" type="button" class="inline-flex items-center gap-1 text-sm font-medium text-primary-700" @click="adding = true"><PlusIcon class="h-4 w-4" /> Add</button>
    </div>

    <form v-if="adding" class="mt-4 space-y-3 rounded-2xl bg-white p-4 ring-1 ring-gray-200/80" @submit.prevent="save">
      <label class="block text-sm text-gray-700">Document
        <select v-model="form.type" class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 ring-1 ring-gray-300">
          <option v-for="t in TYPES" :key="t.key" :value="t.key">{{ t.label }}</option>
        </select>
      </label>
      <label v-if="cars.length && form.type !== 'licence'" class="block text-sm text-gray-700">Car
        <select v-model="form.vehicle_id" class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 ring-1 ring-gray-300">
          <option v-for="c in cars" :key="c.id" :value="c.id">{{ c.registration_no }}</option>
        </select>
      </label>
      <label v-if="form.type === 'other'" class="block text-sm text-gray-700">Name
        <input v-model="form.title" class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 ring-1 ring-gray-300" />
      </label>
      <div class="grid grid-cols-2 gap-3">
        <label class="block text-sm text-gray-700">Number
          <input v-model="form.number" class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 ring-1 ring-gray-300" />
        </label>
        <label class="block text-sm text-gray-700">Valid till
          <input v-model="form.expiry_date" type="date" class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 ring-1 ring-gray-300" />
        </label>
      </div>
      <label class="block text-sm text-gray-700">Photo or PDF
        <MediaPicker class="mt-1" size="sm" :kinds="['photo']" files @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
        <span v-if="file" class="mt-1 block text-xs text-gray-500">{{ file.name }}</span>
      </label>
      <div class="flex gap-2">
        <button type="button" class="flex-1 rounded-xl py-2.5 text-sm font-medium ring-1 ring-gray-300" @click="adding = false">Cancel</button>
        <button type="submit" :disabled="busy" class="flex-1 rounded-xl bg-primary-600 py-2.5 font-semibold text-white disabled:opacity-60">{{ busy ? 'Saving…' : 'Save' }}</button>
      </div>
    </form>

    <p v-if="!docs.length && !adding" class="mt-4 rounded-2xl bg-white p-4 text-sm text-gray-500 ring-1 ring-gray-200/80">Keep your insurance, PUC and RC here: they're always with you, and we remind you before they expire.</p>
    <ul class="mt-4 space-y-2">
      <li v-for="d in docs" :key="d.id" class="flex items-center gap-3 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-gray-200/80">
        <DocumentTextIcon class="h-8 w-8 shrink-0 text-primary-600" />
        <a :href="d.url" target="_blank" rel="noopener" class="min-w-0 flex-1">
          <span class="block font-medium text-gray-900">{{ d.title || d.type_label }}<template v-if="d.vehicle"> · {{ d.vehicle }}</template></span>
          <span class="block text-xs" :class="d.expired ? 'font-semibold text-red-700' : 'text-gray-500'">
            <template v-if="d.number">{{ d.number }} · </template>{{ d.expiry_date ? (d.expired ? 'Expired ' : 'Valid till ') + date(d.expiry_date) : 'No expiry' }}
          </span>
        </a>
        <button type="button" class="rounded-full p-2 text-gray-400" :aria-label="confirmDelete === d.id ? 'Tap again to delete' : 'Delete'" @click="remove(d)">
          <TrashIcon class="h-5 w-5" :class="confirmDelete === d.id ? 'text-red-600' : ''" />
        </button>
      </li>
    </ul>
  </div>
</template>
