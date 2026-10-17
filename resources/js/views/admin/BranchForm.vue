<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { branchesApi } from '@/api/admin.api'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const branchId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEditing = computed(() => !!branchId.value)

const loading = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const form = ref({
  name: '',
  code: '',
  address: '',
  phone: '',
  email: '',
  is_active: true,
  // Attendance: where the workshop is and how close a punch must be
  lat: null as number | null,
  lng: null as number | null,
  geofence_radius_m: 150,
  weekly_off_day: 0,
})

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
const locating = ref(false)
const mapLink = computed(() => (form.value.lat !== null && form.value.lng !== null ? `https://maps.google.com/?q=${form.value.lat},${form.value.lng}` : null))

/** Stand at the workshop gate and tap: the phone's GPS position becomes the workshop's */
function useMyLocation() {
  if (!('geolocation' in navigator)) {
    toast.error('Location not available on this device')
    return
  }
  locating.value = true
  navigator.geolocation.getCurrentPosition(
    (p) => {
      form.value.lat = Number(p.coords.latitude.toFixed(7))
      form.value.lng = Number(p.coords.longitude.toFixed(7))
      locating.value = false
      toast.success('Location set', `Accurate to about ${Math.round(p.coords.accuracy)} m`)
    },
    () => {
      locating.value = false
      toast.error('Could not get the location', 'Allow location for this site and turn on GPS.')
    },
    { enableHighAccuracy: true, timeout: 30000 },
  )
}

/** Paste a Google Maps link or "lat, lng" */
function pasteLocation(text: string) {
  const m = text.match(/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/)
  if (m) {
    form.value.lat = Number(m[1])
    form.value.lng = Number(m[2])
  }
}

async function fetchBranch() {
  if (!branchId.value) return

  loading.value = true
  try {
    const response = await branchesApi.get(branchId.value)
    const branch = response.data.data
    form.value = {
      name: branch.name || '',
      code: branch.code || '',
      address: branch.address || '',
      phone: branch.phone || '',
      email: branch.email || '',
      is_active: branch.is_active,
      lat: branch.lat ?? null,
      lng: branch.lng ?? null,
      geofence_radius_m: branch.geofence_radius_m ?? 150,
      weekly_off_day: branch.weekly_off_day ?? 0,
    }
  } catch (err) {
    toast.apiError(err)
    router.push('/branches')
  } finally {
    loading.value = false
  }
}

async function handleSubmit() {
  errors.value = {}

  // Validation
  if (!form.value.name.trim()) {
    errors.value.name = 'Name is required'
  }
  if (!form.value.code.trim()) {
    errors.value.code = 'Code is required'
  }
  if (form.value.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email)) {
    errors.value.email = 'Invalid email format'
  }

  if (Object.keys(errors.value).length > 0) {
    return
  }

  saving.value = true
  try {
    if (isEditing.value) {
      await branchesApi.update(branchId.value!, form.value)
      toast.success('Branch Updated', 'Branch has been updated successfully.')
    } else {
      await branchesApi.create(form.value)
      toast.success('Branch Created', 'New branch has been created.')
    }
    router.push('/branches')
  } catch (err: any) {
    if (err.response?.status === 422) {
      const validationErrors = err.response.data.errors || {}
      Object.keys(validationErrors).forEach((key) => {
        errors.value[key] = validationErrors[key][0]
      })
    } else {
      toast.apiError(err)
    }
  } finally {
    saving.value = false
  }
}

onMounted(fetchBranch)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">
        {{ isEditing ? 'Edit Branch' : 'New Branch' }}
      </h1>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <form v-else @submit.prevent="handleSubmit" class="max-w-xl">
      <Card>
        <div class="space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Input
              v-model="form.name"
              label="Branch Name"
              :error="errors.name"
              required
            />

            <Input
              v-model="form.code"
              label="Branch Code"
              placeholder="e.g., HQ, BR1"
              :error="errors.code"
              required
            />
          </div>

          <Textarea
            v-model="form.address"
            label="Address"
            :error="errors.address"
            :rows="3"
          />

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Input
              v-model="form.phone"
              label="Phone"
              :error="errors.phone"
            />

            <Input
              v-model="form.email"
              type="email"
              label="Email"
              :error="errors.email"
            />
          </div>

          <div class="rounded-xl bg-gray-50 p-4 space-y-3">
            <div>
              <p class="text-sm font-semibold text-gray-800">Attendance — workshop location</p>
              <p class="text-xs text-gray-500">Staff punch in / out with a selfie only within this distance of the workshop.</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div><label class="label">Latitude</label><input v-model.number="form.lat" type="number" step="0.0000001" class="input" /></div>
              <div><label class="label">Longitude</label><input v-model.number="form.lng" type="number" step="0.0000001" class="input" /></div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <Button type="button" size="sm" variant="secondary" :loading="locating" @click="useMyLocation">Use my current location</Button>
              <input class="input flex-1 min-w-[12rem]" placeholder="…or paste a Google Maps link / “lat, lng”" @change="pasteLocation(($event.target as HTMLInputElement).value)" />
              <a v-if="mapLink" :href="mapLink" target="_blank" rel="noopener" class="text-sm text-primary-700">Check on map</a>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div><label class="label">Punch radius (metres)</label><input v-model.number="form.geofence_radius_m" type="number" min="20" max="5000" class="input" /></div>
              <div>
                <label class="label">Weekly off</label>
                <select v-model.number="form.weekly_off_day" class="input">
                  <option v-for="(d, i) in WEEKDAYS" :key="i" :value="i">{{ d }}</option>
                </select>
              </div>
            </div>
          </div>

          <div v-if="isEditing" class="flex items-center">
            <input
              id="is_active"
              v-model="form.is_active"
              type="checkbox"
              class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
            />
            <label for="is_active" class="ml-2 text-sm text-gray-700">
              Branch is active
            </label>
          </div>
        </div>

        <template #footer>
          <div class="flex gap-3">
            <Button type="button" variant="secondary" @click="router.back()">
              Cancel
            </Button>
            <Button type="submit" :loading="saving">
              {{ isEditing ? 'Update Branch' : 'Create Branch' }}
            </Button>
          </div>
        </template>
      </Card>
    </form>
  </div>
</template>
