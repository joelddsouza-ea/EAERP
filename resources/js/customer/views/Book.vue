<script setup lang="ts">
/**
 * Book in three taps: the car → a plan at that car's price (or a repair) → how and when.
 * Arriving from the assistant, the suggested work is carried in the notes.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { CheckIcon } from '@heroicons/vue/24/solid'
import api, { message, money, type Home } from '../api'
import { toast } from '../ui'

interface Options {
  available: boolean
  vehicle: { id: number; registration_no: string; category_known: boolean } | null
  plans: { id: number; name: string; description: string | null; activity_points: number | null; home_service: boolean; price_from: number; exact: boolean; oils: { id: number; name: string; price: number }[] }[]
  repair_types: { key: string; label: string }[]
  modes: { key: string; label: string }[]
  branches: { id: number; name: string; address: string | null; city: string | null }[]
  default_branch_id: number | null
  address: string | null
}

const route = useRoute()
const router = useRouter()
const cars = ref<Home['vehicles']>([])
const vehicleId = ref<number | null>(route.query.vehicle ? Number(route.query.vehicle) : null)
const options = ref<Options | null>(null)
const step = ref(1)

const choice = ref<{ kind: 'plan' | 'repair'; planId?: number; oilId?: number | null; repair?: string } | null>(null)
const mode = ref('drive_in')
const branchId = ref<number | null>(null)
const day = ref('')
const slots = ref<{ from: string; to: string; free: number }[]>([])
const slot = ref<string | null>(null)
const address = ref('')
const landmark = ref('')
const notes = ref(String(route.query.notes ?? ''))
const aiRequestId = route.query.ai ? Number(route.query.ai) : null
const busy = ref(false)

const days = computed(() => Array.from({ length: 10 }, (_, i) => {
  const d = new Date()
  d.setDate(d.getDate() + i + (new Date().getHours() >= 17 ? 1 : 0))
  return { value: d.toISOString().slice(0, 10), label: i === 0 && new Date().getHours() < 17 ? 'Today' : d.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' }) }
}))

const selectedPlan = computed(() => options.value?.plans.find((p) => p.id === choice.value?.planId) ?? null)
const price = computed(() => {
  if (!selectedPlan.value) return null
  return selectedPlan.value.oils.find((o) => o.id === choice.value?.oilId)?.price ?? selectedPlan.value.price_from
})

onMounted(async () => {
  try {
    cars.value = (await api.get('/home')).data.data.vehicles
    if (!vehicleId.value && cars.value.length === 1) vehicleId.value = cars.value[0].id
    if (route.query.service) {
      choice.value = { kind: 'repair', repair: String(route.query.service) }
    }
    await loadOptions()
  } catch (err) {
    toast(message(err), 'error')
  }
})

async function loadOptions() {
  options.value = (await api.get('/booking-options', { params: vehicleId.value ? { vehicle_id: vehicleId.value } : {} })).data.data
  branchId.value ??= options.value!.default_branch_id ?? options.value!.branches[0]?.id ?? null
  address.value ||= options.value!.address ?? ''
  if (vehicleId.value) step.value = Math.max(step.value, 2)
}
watch(vehicleId, () => loadOptions().catch(() => {}))

async function loadSlots() {
  slot.value = null
  if (!branchId.value || !day.value) return
  try {
    slots.value = (await api.get('/slots', { params: { branch_id: branchId.value, date: day.value } })).data.data
  } catch (err) {
    slots.value = []
    toast(message(err), 'error')
  }
}
watch([branchId, day], loadSlots)

function pickPlan(id: number) {
  const plan = options.value?.plans.find((p) => p.id === id)
  choice.value = { kind: 'plan', planId: id, oilId: plan?.oils[0]?.id ?? null }
}

async function submit() {
  busy.value = true
  try {
    const res = await api.post('/bookings', {
      vehicle_id: vehicleId.value,
      branch_id: branchId.value,
      booking_date: day.value,
      slot_from: slot.value,
      package_id: choice.value?.kind === 'plan' ? choice.value.planId : null,
      oil_type_id: choice.value?.kind === 'plan' ? choice.value.oilId : null,
      service_type: choice.value?.kind === 'repair' ? choice.value.repair : null,
      service_mode: mode.value,
      address: mode.value === 'drive_in' ? null : address.value,
      landmark: landmark.value || null,
      notes: notes.value || null,
      ai_request_id: aiRequestId,
    })
    toast(res.data.message)
    router.replace('/bookings')
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="text-xl font-bold text-gray-900">Book a service</h1>

    <p v-if="options && !options.available" class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">Booking in the app isn't open right now. Please call us to book.</p>

    <!-- 1. Car -->
    <section class="mt-4 rounded-2xl bg-white p-4 ring-1 ring-gray-200/80">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">1 · Car</h2>
      <div class="mt-2 flex flex-wrap gap-2">
        <button v-for="c in cars" :key="c.id" type="button" class="rounded-xl px-3 py-2 text-sm font-medium ring-1" :class="vehicleId === c.id ? 'bg-primary-600 text-white ring-primary-600' : 'bg-white text-gray-800 ring-gray-300'" @click="vehicleId = c.id; step = 2">
          {{ c.registration_no }}
        </button>
        <RouterLink to="/cars/new" class="rounded-xl px-3 py-2 text-sm font-medium text-primary-700 ring-1 ring-dashed ring-primary-300">+ Add a car</RouterLink>
      </div>
    </section>

    <!-- 2. What -->
    <section v-if="options && vehicleId && step >= 2" class="mt-3 rounded-2xl bg-white p-4 ring-1 ring-gray-200/80">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">2 · What do you need?</h2>
      <p v-if="options.vehicle && !options.vehicle.category_known" class="mt-1 text-xs text-gray-500">Prices shown "from": the exact price depends on your car's model.</p>
      <ul class="mt-2 space-y-2">
        <li v-for="p in options.plans" :key="p.id">
          <button type="button" class="w-full rounded-xl p-3 text-left ring-1" :class="choice?.planId === p.id ? 'bg-primary-50 ring-primary-600' : 'ring-gray-200'" @click="pickPlan(p.id)">
            <span class="flex items-center justify-between">
              <span class="font-semibold text-gray-900">{{ p.name }}</span>
              <span class="font-semibold text-gray-900">{{ p.exact ? '' : 'from ' }}{{ money(p.price_from) }}</span>
            </span>
            <span class="block text-xs text-gray-500"><template v-if="p.activity_points">{{ p.activity_points }} checks · </template>{{ p.description }}</span>
          </button>
          <div v-if="choice?.planId === p.id && p.oils.length > 1" class="mt-2 flex flex-wrap gap-2 pl-2">
            <button v-for="o in p.oils" :key="o.id" type="button" class="rounded-lg px-2.5 py-1.5 text-xs ring-1" :class="choice.oilId === o.id ? 'bg-gray-900 text-white ring-gray-900' : 'ring-gray-300'" @click="choice.oilId = o.id">
              {{ o.name }} · {{ money(o.price) }}
            </button>
          </div>
        </li>
      </ul>
      <p class="mt-3 text-xs font-semibold uppercase text-gray-400">Or a repair</p>
      <div class="mt-1 flex flex-wrap gap-2">
        <button v-for="r in options.repair_types" :key="r.key" type="button" class="rounded-xl px-3 py-2 text-sm ring-1" :class="choice?.repair === r.key ? 'bg-primary-600 text-white ring-primary-600' : 'ring-gray-300'" @click="choice = { kind: 'repair', repair: r.key }">
          {{ r.label }}
        </button>
      </div>
      <button v-if="choice" type="button" class="mt-4 w-full rounded-xl bg-gray-900 py-2.5 font-semibold text-white" @click="step = 3">
        Next<template v-if="price"> · {{ money(price) }}</template>
      </button>
    </section>

    <!-- 3. How and when -->
    <section v-if="options && choice && step >= 3" class="mt-3 rounded-2xl bg-white p-4 ring-1 ring-gray-200/80">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">3 · How and when</h2>
      <div class="mt-2 space-y-2">
        <button v-for="m in options.modes" :key="m.key" type="button" class="flex w-full items-center gap-2 rounded-xl p-3 text-left text-sm ring-1" :class="mode === m.key ? 'bg-primary-50 ring-primary-600' : 'ring-gray-200'" @click="mode = m.key">
          <CheckIcon v-if="mode === m.key" class="h-4 w-4 text-primary-600" /><span v-else class="h-4 w-4"></span> {{ m.label }}
        </button>
      </div>
      <template v-if="mode !== 'drive_in'">
        <label class="mt-3 block text-sm text-gray-700">Address
          <textarea v-model="address" rows="2" class="mt-1 w-full rounded-xl border-0 px-3 py-2 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600"></textarea>
        </label>
        <label class="mt-2 block text-sm text-gray-700">Landmark
          <input v-model="landmark" class="mt-1 w-full rounded-xl border-0 px-3 py-2 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600" />
        </label>
      </template>
      <label v-if="options.branches.length > 1" class="mt-3 block text-sm text-gray-700">Workshop
        <select v-model="branchId" class="mt-1 w-full rounded-xl border-0 px-3 py-2 ring-1 ring-gray-300">
          <option v-for="b in options.branches" :key="b.id" :value="b.id">{{ b.name }}<template v-if="b.city"> · {{ b.city }}</template></option>
        </select>
      </label>
      <p class="mt-3 text-sm text-gray-700">Day</p>
      <div class="mt-1 flex gap-2 overflow-x-auto pb-1">
        <button v-for="d in days" :key="d.value" type="button" class="shrink-0 rounded-xl px-3 py-2 text-sm ring-1" :class="day === d.value ? 'bg-primary-600 text-white ring-primary-600' : 'ring-gray-300'" @click="day = d.value">{{ d.label }}</button>
      </div>
      <template v-if="day">
        <p class="mt-3 text-sm text-gray-700">Time</p>
        <p v-if="!slots.length" class="mt-1 text-sm text-gray-500">No slots that day (holiday or weekly off). Pick another day.</p>
        <div class="mt-1 grid grid-cols-3 gap-2">
          <button v-for="s in slots" :key="s.from" type="button" :disabled="!s.free" class="rounded-xl px-2 py-2 text-sm ring-1 disabled:opacity-40" :class="slot === s.from ? 'bg-primary-600 text-white ring-primary-600' : 'ring-gray-300'" @click="slot = s.from">
            {{ s.from }}–{{ s.to }}
          </button>
        </div>
      </template>
      <label class="mt-3 block text-sm text-gray-700">Anything we should know? (optional)
        <textarea v-model="notes" rows="2" class="mt-1 w-full rounded-xl border-0 px-3 py-2 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600"></textarea>
      </label>
      <button type="button" :disabled="busy || !day || (slots.length > 0 && !slot) || (mode !== 'drive_in' && !address.trim())" class="mt-4 w-full rounded-xl bg-primary-600 py-3 font-semibold text-white disabled:opacity-50" @click="submit">
        {{ busy ? 'Booking…' : 'Book' }}
      </button>
    </section>

    <RouterLink to="/bookings" class="mt-4 block text-center text-sm font-medium text-gray-600">My bookings ›</RouterLink>
  </div>
</template>
