<script setup lang="ts">
/**
 * Report a problem on a cab: what kind, what is wrong, photos / videos, km, and — for a
 * breakdown — where the cab is (phone location). The workshop gets it straight away.
 */
import { ref, computed, onMounted } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ChevronLeftIcon, MapPinIcon, CameraIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import api, { message, type Home } from '../api'
import { toast } from '../ui'
import MediaPicker from '@/components/media/MediaPicker.vue'

// Camera first (Android Chrome): photo / video / gallery as separate choices
const picker = ref<InstanceType<typeof MediaPicker> | null>(null)

const route = useRoute()
const router = useRouter()
const MAX_MEDIA = 6

const cabs = ref<Home['vehicles']>([])
const kinds = ref<{ key: string; label: string }[]>([])
const form = ref({ vehicle_id: Number(route.query.vehicle) || 0, kind: '', description: '', odometer_km: '' as string | number, location_text: '' })
const coords = ref<{ lat: number; lng: number } | null>(null)
const locating = ref(false)
const files = ref<File[]>([])
const busy = ref(false)

const previews = computed(() => files.value.map((f) => ({ name: f.name, video: f.type.startsWith('video/'), url: f.type.startsWith('image/') ? URL.createObjectURL(f) : null })))

onMounted(async () => {
  try {
    const [home, reports] = await Promise.all([api.get('/home'), api.get('/reports')])
    cabs.value = home.data.data.vehicles
    kinds.value = reports.data.data.kinds
    if (!form.value.vehicle_id) form.value.vehicle_id = cabs.value[0]?.id ?? 0
  } catch (err) {
    toast(message(err), 'error')
  }
})

function addFiles(e: Event) {
  const input = e.target as HTMLInputElement
  files.value = [...files.value, ...Array.from(input.files ?? [])].slice(0, MAX_MEDIA)
  input.value = ''
}

function locate() {
  if (!navigator.geolocation) {
    toast('This phone cannot share its location. Type where the cab is.', 'error')
    return
  }
  locating.value = true
  navigator.geolocation.getCurrentPosition(
    (p) => {
      coords.value = { lat: p.coords.latitude, lng: p.coords.longitude }
      locating.value = false
    },
    () => {
      locating.value = false
      toast('Could not get the location. Type where the cab is.', 'error')
    },
    { enableHighAccuracy: true, timeout: 15000 },
  )
}

async function send() {
  if (!form.value.kind) {
    toast('Choose what kind of problem it is.', 'error')
    return
  }
  busy.value = true
  try {
    const data = new FormData()
    data.append('vehicle_id', String(form.value.vehicle_id))
    data.append('kind', form.value.kind)
    data.append('description', form.value.description)
    if (form.value.odometer_km !== '') data.append('odometer_km', String(form.value.odometer_km))
    if (form.value.location_text) data.append('location_text', form.value.location_text)
    if (coords.value) {
      data.append('lat', String(coords.value.lat))
      data.append('lng', String(coords.value.lng))
    }
    files.value.forEach((f) => data.append('media[]', f))
    const res = await api.post('/reports', data)
    toast(res.data.message)
    router.replace('/reports')
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <RouterLink to="/reports" class="-ml-1 mb-2 inline-flex items-center text-sm text-gray-600"><ChevronLeftIcon class="h-4 w-4" /> Reports</RouterLink>
    <h1 class="text-xl font-bold text-gray-900">Report a problem</h1>

    <form class="mt-4 space-y-4" @submit.prevent="send">
      <label class="block text-sm">
        <span class="font-medium text-gray-700">Cab</span>
        <select v-model.number="form.vehicle_id" required class="mt-1 w-full rounded-xl border-0 bg-white px-3 py-2.5 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600">
          <option v-for="c in cabs" :key="c.id" :value="c.id">{{ c.registration_no }} · {{ c.car }}</option>
        </select>
      </label>

      <fieldset>
        <legend class="text-sm font-medium text-gray-700">What is the problem?</legend>
        <div class="mt-1 grid grid-cols-2 gap-2">
          <button v-for="k in kinds" :key="k.key" type="button" class="rounded-xl px-3 py-2.5 text-left text-sm ring-1"
            :class="form.kind === k.key ? (k.key === 'breakdown' ? 'bg-red-600 text-white ring-red-600' : 'bg-primary-600 text-white ring-primary-600') : 'bg-white text-gray-800 ring-gray-300'"
            @click="form.kind = k.key">{{ k.label }}</button>
        </div>
      </fieldset>

      <label class="block text-sm">
        <span class="font-medium text-gray-700">Tell us more</span>
        <textarea v-model="form.description" required minlength="3" maxlength="2000" rows="3" class="mt-1 w-full rounded-xl border-0 bg-white px-3 py-2.5 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600" placeholder="What happened, any sound or warning light, since when…"></textarea>
      </label>

      <div>
        <p class="text-sm font-medium text-gray-700">Photos / videos <span class="font-normal text-gray-500">(up to {{ MAX_MEDIA }})</span></p>
        <div class="mt-1 flex flex-wrap gap-2">
          <div v-for="(p, i) in previews" :key="i" class="relative h-16 w-16 overflow-hidden rounded-lg bg-gray-200">
            <img v-if="p.url" :src="p.url" class="h-full w-full object-cover" alt="" />
            <span v-else class="flex h-full items-center justify-center text-xs text-gray-600">{{ p.video ? 'Video' : 'File' }}</span>
            <button type="button" class="absolute right-0 top-0 rounded-bl bg-black/60 p-0.5 text-white" aria-label="Remove" @click="files.splice(i, 1)"><XMarkIcon class="h-4 w-4" /></button>
          </div>
          <button v-if="files.length < MAX_MEDIA" type="button" class="flex h-16 w-16 flex-col items-center justify-center rounded-lg bg-white text-xs text-gray-600 ring-1 ring-gray-300" @click="picker?.open()">
            <CameraIcon class="h-6 w-6" /> Add
          </button>
          <MediaPicker ref="picker" variant="menu" :kinds="['photo', 'video']" gallery @change="addFiles" />
        </div>
      </div>

      <label class="block text-sm">
        <span class="font-medium text-gray-700">Km on the odometer <span class="font-normal text-gray-500">(optional)</span></span>
        <input v-model="form.odometer_km" type="number" inputmode="numeric" min="0" class="mt-1 w-full rounded-xl border-0 bg-white px-3 py-2.5 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600" />
      </label>

      <div v-if="form.kind === 'breakdown' || form.kind === 'tyre' || form.kind === 'accident'" class="rounded-2xl bg-white p-3 ring-1 ring-gray-200/80">
        <p class="text-sm font-medium text-gray-700">Where is the cab?</p>
        <button type="button" :disabled="locating" class="mt-2 inline-flex items-center gap-1.5 rounded-xl bg-gray-100 px-3 py-2 text-sm font-medium text-gray-800" @click="locate">
          <MapPinIcon class="h-5 w-5" /> {{ coords ? 'Location added ✓' : locating ? 'Finding…' : 'Use my location' }}
        </button>
        <input v-model="form.location_text" maxlength="255" class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600 text-sm" placeholder="Landmark / address" />
      </div>

      <button type="submit" :disabled="busy || !cabs.length" class="w-full rounded-xl py-3 font-semibold text-white disabled:opacity-60" :class="form.kind === 'breakdown' ? 'bg-red-600' : 'bg-primary-600'">
        {{ busy ? 'Sending…' : 'Send to the workshop' }}
      </button>
    </form>
  </div>
</template>
