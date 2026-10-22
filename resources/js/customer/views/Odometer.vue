<script setup lang="ts">
/**
 * Log the km on a cab (with a photo of the dashboard if you like). The next service date and
 * the "service due soon" reminders follow the latest reading.
 */
import { ref, onMounted } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ChevronLeftIcon, CameraIcon } from '@heroicons/vue/24/outline'
import api, { message, date } from '../api'
import { toast } from '../ui'

interface Reading { id: number; km: number; source: string; by: string; read_at: string | null; photo_url: string | null }

const route = useRoute()
const id = Number(route.params.id)
const readings = ref<Reading[]>([])
const km = ref<number | ''>('')
const photo = ref<File | null>(null)
const busy = ref(false)

async function load() {
  readings.value = (await api.get(`/vehicles/${id}/odometer`)).data.data
}

onMounted(() => load().catch((err) => toast(message(err), 'error')))

async function save() {
  busy.value = true
  try {
    const data = new FormData()
    data.append('km', String(km.value))
    if (photo.value) data.append('photo', photo.value)
    toast((await api.post(`/vehicles/${id}/odometer`, data)).data.message)
    km.value = ''
    photo.value = null
    await load()
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <RouterLink :to="`/cars/${id}`" class="-ml-1 mb-2 inline-flex items-center text-sm text-gray-600"><ChevronLeftIcon class="h-4 w-4" /> Cab</RouterLink>
    <h1 class="text-xl font-bold text-gray-900">Log km</h1>

    <form class="mt-4 space-y-3 rounded-2xl bg-white p-4 ring-1 ring-gray-200/80" @submit.prevent="save">
      <label class="block text-sm">
        <span class="font-medium text-gray-700">Km on the odometer now</span>
        <input v-model.number="km" required type="number" inputmode="numeric" min="0" class="mt-1 w-full rounded-xl border-0 bg-white px-3 py-2.5 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600 text-lg" :placeholder="readings[0] ? String(readings[0].km) : ''" />
      </label>
      <label class="inline-flex cursor-pointer items-center gap-1.5 text-sm text-gray-700">
        <CameraIcon class="h-5 w-5" /> {{ photo ? 'Photo added ✓' : 'Add a photo of the dashboard (optional)' }}
        <input type="file" accept="image/*" capture="environment" class="hidden" @change="photo = ($event.target as HTMLInputElement).files?.[0] ?? null" />
      </label>
      <button type="submit" :disabled="busy || km === ''" class="w-full rounded-xl bg-primary-600 py-2.5 font-semibold text-white disabled:opacity-60">{{ busy ? 'Saving…' : 'Save' }}</button>
    </form>

    <h2 class="mt-5 font-semibold text-gray-900">Earlier readings</h2>
    <p v-if="!readings.length" class="mt-2 text-sm text-gray-500">None yet.</p>
    <ul class="mt-2 divide-y divide-gray-100 rounded-2xl bg-white ring-1 ring-gray-200/80">
      <li v-for="r in readings" :key="r.id" class="flex items-center justify-between px-4 py-2.5 text-sm">
        <span><span class="font-semibold">{{ r.km.toLocaleString('en-IN') }} km</span> <span class="text-gray-500">· {{ r.by }}</span></span>
        <span class="text-gray-500"><a v-if="r.photo_url" :href="r.photo_url" target="_blank" class="mr-2 text-primary-700">photo</a>{{ date(r.read_at) }}</span>
      </li>
    </ul>
  </div>
</template>
