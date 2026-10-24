<script setup lang="ts">
/**
 * Make → model → car class (filled from the model, as on Add Vehicle) → fuel, for forms that
 * describe a car without creating it yet (CRM leads).
 */
import { ref, watch, onMounted } from 'vue'
import { masterDataApi } from '@/api/workshop.api'
import type { VehicleMake, VehicleModel, VehicleCategory } from '@/api/types'

const model = defineModel<{ make_id: number | null; model_id: number | null; category_id: number | null; fuel_type: string | null }>({ required: true })

const makes = ref<VehicleMake[]>([])
const models = ref<VehicleModel[]>([])
const categories = ref<VehicleCategory[]>([])
const FUELS = [
  { value: 'petrol', label: 'Petrol' },
  { value: 'diesel', label: 'Diesel' },
  { value: 'cng', label: 'CNG' },
  { value: 'electric', label: 'Electric' },
  { value: 'hybrid', label: 'Hybrid' },
]

async function loadModels(makeId: number | null) {
  models.value = makeId ? (await masterDataApi.vehicleModels(makeId)).data.data : []
}

function setMake(id: number | null) {
  model.value = { ...model.value, make_id: id, model_id: null }
  loadModels(id)
}

function setModel(id: number | null) {
  const picked = models.value.find((m) => m.id === id)
  // The class comes with the model (it can still be changed)
  model.value = { ...model.value, model_id: id, category_id: picked?.default_category_id ?? model.value.category_id }
}

onMounted(async () => {
  const [m, c] = await Promise.all([masterDataApi.vehicleMakes(), masterDataApi.vehicleCategories()])
  makes.value = m.data.data
  categories.value = c.data.data
  await loadModels(model.value.make_id)
})

watch(() => model.value.make_id, (id, old) => {
  if (id !== old && !models.value.length) loadModels(id)
})
</script>

<template>
  <div class="grid grid-cols-2 gap-3">
    <div>
      <label class="label">Make</label>
      <select class="input" :value="model.make_id ?? ''" @change="setMake(Number(($event.target as HTMLSelectElement).value) || null)">
        <option value="">—</option>
        <option v-for="m in makes" :key="m.id" :value="m.id">{{ m.name }}</option>
      </select>
    </div>
    <div>
      <label class="label">Model</label>
      <select class="input" :value="model.model_id ?? ''" :disabled="!model.make_id" @change="setModel(Number(($event.target as HTMLSelectElement).value) || null)">
        <option value="">—</option>
        <option v-for="m in models" :key="m.id" :value="m.id">{{ m.name }}</option>
      </select>
    </div>
    <div>
      <label class="label">Car class</label>
      <select class="input" :value="model.category_id ?? ''" @change="model = { ...model, category_id: Number(($event.target as HTMLSelectElement).value) || null }">
        <option value="">—</option>
        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
    </div>
    <div>
      <label class="label">Fuel</label>
      <select class="input" :value="model.fuel_type ?? ''" @change="model = { ...model, fuel_type: ($event.target as HTMLSelectElement).value || null }">
        <option value="">—</option>
        <option v-for="f in FUELS" :key="f.value" :value="f.value">{{ f.label }}</option>
      </select>
    </div>
  </div>
</template>
