<script setup lang="ts">
/**
 * The fleet's drivers (owner): add a driver with their mobile number and the cabs they drive,
 * change cabs, switch a driver off (signs them out) or sign them out of a lost phone.
 */
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { ChevronLeftIcon, PlusIcon } from '@heroicons/vue/24/outline'
import api, { message, date, type FleetDriverRow } from '../api'
import { toast } from '../ui'

const drivers = ref<FleetDriverRow[]>([])
const cabs = ref<{ id: number; registration_no: string }[]>([])
const editing = ref<FleetDriverRow | 'new' | null>(null)
const form = ref({ name: '', phone: '', vehicle_ids: [] as number[] })
const busy = ref(false)

async function load() {
  const data = (await api.get('/fleet/drivers')).data.data
  drivers.value = data.drivers
  cabs.value = data.cabs
}

onMounted(() => load().catch((err) => toast(message(err), 'error')))

function open(d: FleetDriverRow | 'new') {
  editing.value = d
  form.value = d === 'new' ? { name: '', phone: '', vehicle_ids: [] } : { name: d.name, phone: d.phone, vehicle_ids: [...d.vehicle_ids] }
}

async function save() {
  busy.value = true
  try {
    const res = editing.value === 'new'
      ? await api.post('/fleet/drivers', form.value)
      : await api.put(`/fleet/drivers/${(editing.value as FleetDriverRow).id}`, form.value)
    toast(res.data.message)
    editing.value = null
    await load()
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    busy.value = false
  }
}

async function setActive(d: FleetDriverRow, active: boolean) {
  try {
    await api.put(`/fleet/drivers/${d.id}`, { is_active: active })
    toast(active ? `${d.name} can sign in again` : `${d.name} is switched off and signed out`)
    await load()
  } catch (err) {
    toast(message(err), 'error')
  }
}

async function signOut(d: FleetDriverRow) {
  try {
    toast((await api.post(`/fleet/drivers/${d.id}/sign-out`)).data.message)
  } catch (err) {
    toast(message(err), 'error')
  }
}
</script>

<template>
  <div>
    <RouterLink to="/fleet" class="-ml-1 mb-2 inline-flex items-center text-sm text-gray-600"><ChevronLeftIcon class="h-4 w-4" /> Fleet</RouterLink>
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-bold text-gray-900">Drivers</h1>
      <button v-if="!editing" type="button" class="inline-flex items-center gap-1 text-sm font-medium text-primary-700" @click="open('new')"><PlusIcon class="h-4 w-4" /> Add a driver</button>
    </div>
    <p class="mt-1 text-sm text-gray-500">Drivers sign in to this app with their own mobile number. They see only the cabs you link to them.</p>

    <form v-if="editing" class="mt-4 space-y-3 rounded-2xl bg-white p-4 ring-1 ring-gray-200/80" @submit.prevent="save">
      <h2 class="font-semibold">{{ editing === 'new' ? 'New driver' : `Edit ${editing.name}` }}</h2>
      <label class="block text-sm">
        <span class="text-gray-700">Name</span>
        <input v-model="form.name" required maxlength="120" class="mt-1 w-full rounded-xl border-0 bg-white px-3 py-2.5 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600" />
      </label>
      <label class="block text-sm">
        <span class="text-gray-700">Mobile number</span>
        <input v-model="form.phone" required type="tel" inputmode="numeric" maxlength="14" class="mt-1 w-full rounded-xl border-0 bg-white px-3 py-2.5 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600" />
      </label>
      <fieldset class="text-sm">
        <legend class="text-gray-700">Cabs this driver drives</legend>
        <p v-if="!cabs.length" class="mt-1 text-gray-500">Add your cabs first (Home › Add a car).</p>
        <div class="mt-1 grid grid-cols-2 gap-2">
          <label v-for="c in cabs" :key="c.id" class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2">
            <input v-model="form.vehicle_ids" type="checkbox" :value="c.id" class="rounded text-primary-600" /> {{ c.registration_no }}
          </label>
        </div>
      </fieldset>
      <div class="flex gap-2">
        <button type="submit" :disabled="busy" class="flex-1 rounded-xl bg-primary-600 py-2.5 font-semibold text-white disabled:opacity-60">{{ busy ? 'Saving…' : 'Save' }}</button>
        <button type="button" class="rounded-xl px-4 py-2.5 text-gray-700 ring-1 ring-gray-300" @click="editing = null">Cancel</button>
      </div>
    </form>

    <p v-if="!drivers.length && !editing" class="mt-4 rounded-2xl bg-white p-4 text-sm text-gray-500 ring-1 ring-gray-200/80">No drivers yet.</p>
    <ul class="mt-4 space-y-3">
      <li v-for="d in drivers" :key="d.id" class="rounded-2xl bg-white p-4 ring-1 ring-gray-200/80" :class="d.is_active ? '' : 'opacity-60'">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="font-semibold text-gray-900">{{ d.name }} <span v-if="!d.is_active" class="text-xs font-normal text-gray-500">(switched off)</span></p>
            <a :href="`tel:${d.phone}`" class="text-sm text-primary-700">{{ d.phone }}</a>
            <p class="mt-1 text-sm text-gray-600">{{ d.vehicles.length ? d.vehicles.map((v) => v.registration_no).join(', ') : 'No cab linked' }}</p>
            <p class="text-xs text-gray-400">{{ d.last_seen_at ? `Last used the app ${date(d.last_seen_at)}` : 'Not signed in yet' }}</p>
          </div>
          <button type="button" class="text-sm font-medium text-primary-700" @click="open(d)">Edit</button>
        </div>
        <div class="mt-2 flex gap-4 text-sm">
          <button v-if="d.is_active" type="button" class="text-gray-600" @click="signOut(d)">Sign out of all phones</button>
          <button type="button" :class="d.is_active ? 'text-red-700' : 'text-green-700'" @click="setActive(d, !d.is_active)">{{ d.is_active ? 'Switch off' : 'Switch on' }}</button>
        </div>
      </li>
    </ul>
  </div>
</template>
