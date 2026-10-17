<script setup lang="ts">
/**
 * Book a slot for a lead or a customer's car: day → free slots, what for, and how
 * (drive-in / pickup & drop / home service with the address).
 */
import { ref, watch } from 'vue'
import { crmApi, type BookingRow, type LeadOptions, type Slot } from '@/api/crm.api'
import { localDate } from '@/api/hr.api'
import { useBranchStore } from '@/stores/branch.store'
import { useToast } from '@/composables/useToast'
import Button from '@/components/ui/Button.vue'
import Modal from '@/components/ui/Modal.vue'

const props = defineProps<{
  open: boolean
  options: LeadOptions | null
  leadId?: number | null
  customerId?: number | null
  vehicleId?: number | null
  packageId?: number | null
  interest?: string | null
}>()
const emit = defineEmits<{ close: []; booked: [booking: BookingRow] }>()

const branchStore = useBranchStore()
const toast = useToast()

const form = ref({
  booking_date: localDate(new Date(Date.now() + 86400000)),
  slot_from: null as string | null,
  choice: '' as string,
  oil_type_id: null as number | null,
  service_mode: 'drive_in',
  address: '',
  landmark: '',
  notes: '',
})
const slots = ref<Slot[]>([])
const loadingSlots = ref(false)
const busy = ref(false)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    form.value.choice = props.packageId ? `plan:${props.packageId}` : props.interest && props.interest !== 'scheduled' && props.interest !== 'insurance' ? props.interest : ''
    firstFreeDay()
  }
)

/** Start on the first day (from tomorrow) that still has a free slot */
async function firstFreeDay() {
  for (let i = 1; i <= 10; i++) {
    const day = localDate(new Date(Date.now() + i * 86400000))
    try {
      const free = (await crmApi.availability(day, branchStore.currentBranchId)).data.data
      if (free.some((s) => s.free > 0)) {
        const unchanged = form.value.booking_date === day
        form.value.booking_date = day
        // A changed date reloads the slots through the watcher
        if (unchanged) loadSlots()
        return
      }
    } catch {
      break
    }
  }
  loadSlots()
}
watch(() => form.value.booking_date, loadSlots)

async function loadSlots() {
  loadingSlots.value = true
  form.value.slot_from = null
  try {
    slots.value = (await crmApi.availability(form.value.booking_date, branchStore.currentBranchId)).data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loadingSlots.value = false
  }
}

async function book() {
  busy.value = true
  try {
    const plan = form.value.choice.startsWith('plan:') ? Number(form.value.choice.slice(5)) : null
    const res = await crmApi.book({
      lead_id: props.leadId || null,
      customer_id: props.customerId || null,
      vehicle_id: props.vehicleId || null,
      branch_id: branchStore.currentBranchId,
      booking_date: form.value.booking_date,
      slot_from: form.value.slot_from,
      service_type: plan ? 'scheduled' : form.value.choice || 'general_repair',
      package_id: plan,
      oil_type_id: plan ? form.value.oil_type_id : null,
      service_mode: form.value.service_mode,
      address: form.value.service_mode === 'drive_in' ? null : form.value.address,
      landmark: form.value.landmark || null,
      notes: form.value.notes || null,
    })
    toast.success(res.data.message ?? 'Booked')
    emit('booked', res.data.data)
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

const REPAIRS: Record<string, string> = {
  general_repair: 'General repair',
  ac_service: 'AC service',
  body_shop: 'Body shop / accident',
  electrical: 'Electrical',
  detailing: 'Detailing',
}
</script>

<template>
  <Modal :open="open" title="Book a slot" size="lg" @close="emit('close')">
    <div class="space-y-4 text-sm">
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label">Day</label><input v-model="form.booking_date" type="date" class="input" :min="localDate()" /></div>
        <div>
          <label class="label">For</label>
          <select v-model="form.choice" class="input">
            <option value="" disabled>Choose…</option>
            <optgroup label="Service plans"><option v-for="p in options?.plans" :key="p.id" :value="`plan:${p.id}`">{{ p.name }}</option></optgroup>
            <optgroup label="Repairs"><option v-for="(label, k) in REPAIRS" :key="k" :value="k">{{ label }}</option></optgroup>
          </select>
        </div>
      </div>
      <div v-if="form.choice.startsWith('plan:')">
        <label class="label">Engine oil (if known)</label>
        <select v-model="form.oil_type_id" class="input"><option :value="null">Decide at the workshop</option><option v-for="o in options?.oil_types" :key="o.id" :value="o.id">{{ o.name }}</option></select>
      </div>

      <div>
        <label class="label">Time</label>
        <p v-if="loadingSlots" class="text-gray-500">Checking slots…</p>
        <p v-else-if="!slots.length" class="text-amber-700">No slots this day (closed or holiday). Choose another day.</p>
        <div v-else class="grid grid-cols-3 sm:grid-cols-5 gap-2">
          <button
            v-for="s in slots"
            :key="s.from"
            type="button"
            :disabled="s.free < 1"
            class="rounded-lg px-2 py-2 text-center ring-1 disabled:cursor-not-allowed disabled:opacity-40"
            :class="form.slot_from === s.from ? 'bg-primary-600 text-white ring-primary-600' : 'ring-gray-200 hover:ring-primary-300'"
            @click="form.slot_from = s.from"
          >
            <span class="block font-semibold">{{ s.from }}</span>
            <span class="block text-[11px]" :class="form.slot_from === s.from ? 'text-white/80' : 'text-gray-500'">{{ s.free < 1 ? 'Full' : `${s.free} free` }}</span>
          </button>
        </div>
      </div>

      <div>
        <label class="label">How</label>
        <div class="grid grid-cols-3 gap-2">
          <button v-for="(label, k) in { drive_in: 'Drive-in', pickup_drop: 'Pickup & drop', home: 'Home service' }" :key="k" type="button" class="rounded-lg px-2 py-2 ring-1" :class="form.service_mode === k ? 'bg-gray-900 text-white ring-gray-900' : 'ring-gray-200'" @click="form.service_mode = k">{{ label }}</button>
        </div>
      </div>
      <template v-if="form.service_mode !== 'drive_in'">
        <div><label class="label">Address</label><textarea v-model="form.address" rows="2" class="input" /></div>
        <div><label class="label">Landmark</label><input v-model="form.landmark" class="input" /></div>
        <p class="text-xs text-gray-500">Confirming opens the job card and the {{ form.service_mode === 'home' ? 'home visit' : 'pickup' }} for the driver board.</p>
      </template>
      <div><label class="label">Note</label><input v-model="form.notes" class="input" placeholder="What the customer said" /></div>
    </div>
    <template #footer>
      <Button variant="secondary" @click="emit('close')">Cancel</Button>
      <Button :loading="busy" :disabled="!form.choice || (slots.length > 0 && !form.slot_from) || (form.service_mode !== 'drive_in' && !form.address.trim())" @click="book">Book</Button>
    </template>
  </Modal>
</template>
