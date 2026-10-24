<script setup lang="ts">
/**
 * On the customer's page: is this customer a fleet (cab owner), how much a driver may approve
 * per job (blank = the default in Masters › Fleet Cabs), the drivers and the
 * cabs they drive, and the problems they reported.
 */
import { ref, computed, onMounted } from 'vue'
import { TruckIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { fleetApi, type FleetDriver, type FleetState } from '@/api/fleet.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Input from '@/components/ui/Input.vue'

const props = defineProps<{ customerId: number }>()
const { can } = usePermissions()
const toast = useToast()

const state = ref<FleetState | null>(null)
const limit = ref<string>('')
const busy = ref(false)
const editing = ref<FleetDriver | 'new' | null>(null)
const form = ref({ name: '', phone: '', notes: '', vehicle_ids: [] as number[], is_active: true })
const canEdit = computed(() => can('workshop.customers.update'))

async function load() {
  try {
    state.value = (await fleetApi.show(props.customerId)).data.data
    limit.value = state.value.fleet_driver_limit !== null ? String(state.value.fleet_driver_limit) : ''
  } catch {
    state.value = null
  }
}

onMounted(load)

async function saveFleet(data: { is_fleet?: boolean; fleet_driver_limit?: number | null }) {
  busy.value = true
  try {
    const res = await fleetApi.update(props.customerId, data)
    state.value = res.data.data
    limit.value = state.value.fleet_driver_limit !== null ? String(state.value.fleet_driver_limit) : ''
    toast.success(res.data.message ?? 'Saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

function open(d: FleetDriver | 'new') {
  editing.value = d
  form.value = d === 'new'
    ? { name: '', phone: '', notes: '', vehicle_ids: [], is_active: true }
    : { name: d.name, phone: d.phone, notes: d.notes ?? '', vehicle_ids: [...d.vehicle_ids], is_active: d.is_active }
}

async function saveDriver() {
  busy.value = true
  try {
    if (editing.value === 'new') await fleetApi.addDriver(props.customerId, form.value)
    else if (editing.value) await fleetApi.updateDriver(props.customerId, editing.value.id, form.value)
    toast.success('Driver saved')
    editing.value = null
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function signOut(d: FleetDriver) {
  try {
    await fleetApi.signOutDriver(props.customerId, d.id)
    toast.success(`${d.name} is signed out on every phone`)
  } catch (err) {
    toast.apiError(err)
  }
}

const rupees = (n: number) => '₹ ' + Math.round(n).toLocaleString('en-IN')
</script>

<template>
  <Card v-if="state" class="lg:col-span-3">
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="flex items-center gap-2 text-lg font-semibold"><TruckIcon class="h-5 w-5 text-gray-400" /> Fleet</h3>
        <label v-if="canEdit" class="flex items-center gap-2 text-sm">
          <input type="checkbox" :checked="state.is_fleet" :disabled="busy" class="rounded text-primary-600" @change="saveFleet({ is_fleet: ($event.target as HTMLInputElement).checked })" />
          Cab fleet (drivers use the app)
        </label>
        <Badge v-else :variant="state.is_fleet ? 'info' : 'default'">{{ state.is_fleet ? 'Fleet' : 'Not a fleet' }}</Badge>
      </div>
    </template>

    <p v-if="!state.is_fleet" class="text-sm text-gray-600">
      Tick "Cab fleet" for a cab owner: their drivers sign in to the customer app, see only their cabs, report problems and approve extra work up to a limit.
    </p>

    <template v-else>
      <div class="flex flex-wrap items-end gap-3">
        <div class="w-48">
          <Input v-model="limit" type="number" label="Driver may approve per job (₹)" :placeholder="`Default ${rupees(state.default_limit)}`" :disabled="!canEdit" />
        </div>
        <Button v-if="canEdit" size="sm" variant="secondary" :loading="busy" @click="saveFleet({ fleet_driver_limit: limit === '' ? null : Number(limit) })">Save limit</Button>
        <p class="text-sm text-gray-500">Now {{ rupees(state.limit) }} per job{{ state.fleet_driver_limit === null ? ' (default)' : '' }}. Above it, the fleet owner is asked to approve.</p>
      </div>

      <div class="mt-5 flex items-center justify-between">
        <h4 class="font-semibold text-gray-900">Drivers ({{ state.drivers.filter((d) => d.is_active).length }})</h4>
        <Button v-if="canEdit" size="sm" @click="open('new')"><PlusIcon class="mr-1 h-4 w-4" /> Add driver</Button>
      </div>
      <p v-if="!state.drivers.length" class="mt-2 text-sm text-gray-500">No drivers yet. The fleet owner can also add them in the app.</p>
      <ul class="mt-2 divide-y divide-gray-100 text-sm">
        <li v-for="d in state.drivers" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 py-2" :class="d.is_active ? '' : 'opacity-60'">
          <div>
            <p class="font-medium text-gray-900">{{ d.name }} · {{ d.phone }} <Badge v-if="!d.is_active" size="sm">Off</Badge><Badge v-if="d.created_via === 'owner_app'" size="sm" variant="purple">Added by owner</Badge></p>
            <p class="text-gray-500">{{ d.vehicles.length ? d.vehicles.map((v) => v.registration_no).join(', ') : 'No cab linked' }}<template v-if="d.last_seen_at"> · last in the app {{ new Date(d.last_seen_at).toLocaleDateString('en-IN') }}</template></p>
          </div>
          <div v-if="canEdit" class="flex gap-3">
            <button type="button" class="text-primary-700 hover:underline" @click="open(d)">Edit</button>
            <button v-if="d.is_active" type="button" class="text-gray-600 hover:underline" @click="signOut(d)">Sign out</button>
          </div>
        </li>
      </ul>

      <div v-if="state.reports.length" class="mt-5">
        <div class="flex items-center justify-between">
          <h4 class="font-semibold text-gray-900">Problem reports</h4>
          <RouterLink v-if="can('workshop.fleet.reports')" :to="`/fleet/reports?customer=${customerId}`" class="text-sm text-primary-700">All reports ›</RouterLink>
        </div>
        <ul class="mt-2 divide-y divide-gray-100 text-sm">
          <li v-for="r in state.reports.slice(0, 5)" :key="r.id" class="flex items-center justify-between gap-2 py-2">
            <span class="min-w-0"><span class="font-medium">{{ r.vehicle.registration_no }}</span> · {{ r.kind_label }} <span class="text-gray-500">— {{ r.description }}</span></span>
            <Badge size="sm" :variant="r.status === 'open' ? 'warning' : r.status === 'closed' ? 'default' : 'success'">{{ r.status_label }}</Badge>
          </li>
        </ul>
      </div>
    </template>

    <Modal :open="editing !== null" :title="editing === 'new' ? 'Add driver' : 'Edit driver'" @close="editing = null">
      <form class="space-y-3" @submit.prevent="saveDriver">
        <Input v-model="form.name" label="Name" required />
        <Input v-model="form.phone" label="Mobile number" type="tel" required hint="They sign in to the customer app with this number." />
        <Input v-model="form.notes" label="Notes" />
        <fieldset>
          <legend class="label">Cabs this driver drives</legend>
          <p v-if="!state.cabs.length" class="text-sm text-gray-500">Add the fleet's cabs (Vehicles) first.</p>
          <div class="mt-1 grid grid-cols-2 gap-2 text-sm">
            <label v-for="c in state.cabs" :key="c.id" class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2">
              <input v-model="form.vehicle_ids" type="checkbox" :value="c.id" class="rounded text-primary-600" /> {{ c.registration_no }}
            </label>
          </div>
        </fieldset>
        <label v-if="editing !== 'new'" class="flex items-center gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="rounded text-primary-600" /> Active (switching off signs the driver out)
        </label>
        <div class="flex justify-end gap-2 pt-2">
          <Button variant="secondary" type="button" @click="editing = null">Cancel</Button>
          <Button type="submit" :loading="busy">Save</Button>
        </div>
      </form>
    </Modal>
  </Card>
</template>
