<script setup lang="ts">
/**
 * Add a car: registration number, make and model (for the right prices), year and fuel.
 */
import { ref, onMounted, watch } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { ChevronLeftIcon } from '@heroicons/vue/24/outline'
import api, { message } from '../api'
import { toast } from '../ui'

const router = useRouter()
const makes = ref<{ id: number; name: string }[]>([])
const models = ref<{ id: number; name: string }[]>([])
const form = ref({ registration_no: '', make_id: null as number | null, model_id: null as number | null, year: null as number | null, fuel_type: 'petrol' })
const busy = ref(false)

onMounted(async () => {
  makes.value = (await api.get('/makes')).data.data
})
watch(() => form.value.make_id, async (make) => {
  form.value.model_id = null
  models.value = make ? (await api.get(`/makes/${make}/models`)).data.data : []
})

async function save() {
  busy.value = true
  try {
    const res = await api.post('/vehicles', form.value)
    toast(res.data.message)
    router.replace(`/cars/${res.data.data.id}`)
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <RouterLink to="/" class="-ml-1 mb-2 inline-flex items-center text-sm text-gray-600"><ChevronLeftIcon class="h-4 w-4" /> Home</RouterLink>
    <h1 class="text-xl font-bold text-gray-900">Add a car</h1>
    <form class="mt-4 space-y-4 rounded-2xl bg-white p-4 ring-1 ring-gray-200/80" @submit.prevent="save">
      <label class="block text-sm text-gray-700">Registration number
        <input v-model="form.registration_no" required class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 uppercase ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600" placeholder="MH 04 AB 1234" />
      </label>
      <label class="block text-sm text-gray-700">Make
        <select v-model="form.make_id" class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600">
          <option :value="null">Choose…</option>
          <option v-for="m in makes" :key="m.id" :value="m.id">{{ m.name }}</option>
        </select>
      </label>
      <label class="block text-sm text-gray-700">Model
        <select v-model="form.model_id" :disabled="!models.length" class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600">
          <option :value="null">Choose…</option>
          <option v-for="m in models" :key="m.id" :value="m.id">{{ m.name }}</option>
        </select>
      </label>
      <div class="grid grid-cols-2 gap-3">
        <label class="block text-sm text-gray-700">Year
          <input v-model.number="form.year" type="number" inputmode="numeric" min="1980" class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600" />
        </label>
        <label class="block text-sm text-gray-700">Fuel
          <select v-model="form.fuel_type" class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600">
            <option value="petrol">Petrol</option>
            <option value="diesel">Diesel</option>
            <option value="cng">CNG</option>
            <option value="electric">Electric</option>
            <option value="hybrid">Hybrid</option>
          </select>
        </label>
      </div>
      <button type="submit" :disabled="busy" class="w-full rounded-xl bg-primary-600 py-3 font-semibold text-white disabled:opacity-60">{{ busy ? 'Saving…' : 'Add car' }}</button>
    </form>
  </div>
</template>
