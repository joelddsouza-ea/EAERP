<script setup lang="ts">
/**
 * Who works on the job and where: several technicians (one lead; all can fill the job
 * sheets) and the bay, which can change during the job (e.g. to the wash bay) with the
 * moves kept as history.
 */
import { ref, computed, onMounted } from 'vue'
import { format } from 'date-fns'
import { StarIcon, XMarkIcon } from '@heroicons/vue/20/solid'
import { jobCardsApi } from '@/api/workshop.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { JobCardAssignments } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'

const props = defineProps<{ jobCardId: number }>()
const emit = defineEmits<{ changed: [] }>()

const { can } = usePermissions()
const toast = useToast()

const data = ref<JobCardAssignments | null>(null)
const busy = ref(false)
const newTechnician = ref<number | ''>('')
const newBay = ref<number | ''>('')
const bayNote = ref('')

const canEdit = computed(() => can('workshop.job-cards.update'))
const available = computed(() =>
  (data.value?.candidates.technicians ?? []).filter((t) => !data.value?.technicians.some((a) => a.id === t.id))
)

async function load() {
  try {
    data.value = (await jobCardsApi.assignments(props.jobCardId)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}

async function run(action: () => Promise<{ data: { data: JobCardAssignments } }>, ok: string) {
  busy.value = true
  try {
    data.value = (await action()).data.data
    toast.success(ok)
    emit('changed')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

function addTechnician() {
  if (!newTechnician.value) return
  const id = Number(newTechnician.value)
  newTechnician.value = ''
  run(() => jobCardsApi.addTechnician(props.jobCardId, id), 'Technician assigned')
}

function makeLead(id: number) {
  run(() => jobCardsApi.addTechnician(props.jobCardId, id, true), 'Lead technician changed')
}

function removeTechnician(id: number, name: string) {
  if (!window.confirm(`Take ${name} off this job?`)) return
  run(() => jobCardsApi.removeTechnician(props.jobCardId, id), 'Technician removed')
}

function moveBay() {
  if (!newBay.value) return
  const id = Number(newBay.value)
  const note = bayNote.value.trim() || null
  newBay.value = ''
  bayNote.value = ''
  run(() => jobCardsApi.moveBay(props.jobCardId, id, note), data.value?.bay ? 'Moved to the new bay' : 'Bay assigned')
}

onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <Card>
    <template #header>
      <h3 class="text-lg font-semibold">Technicians & Bay</h3>
    </template>

    <div v-if="!data" class="flex justify-center py-4"><Spinner /></div>

    <div v-else class="space-y-5">
      <!-- Technicians -->
      <section>
        <h4 class="text-sm font-medium text-gray-700 mb-2">Technicians</h4>
        <p v-if="!data.technicians.length" class="text-sm text-amber-700">No technician assigned yet.</p>
        <ul class="space-y-1">
          <li v-for="t in data.technicians" :key="t.id" class="flex items-center gap-2 text-sm">
            <StarIcon v-if="t.is_lead" class="h-4 w-4 text-amber-500" />
            <span class="font-medium text-gray-900">{{ t.name }}</span>
            <span v-if="t.is_lead" class="text-xs text-gray-500">lead</span>
            <span v-if="canEdit" class="ml-auto flex items-center gap-1">
              <button v-if="!t.is_lead" type="button" class="text-xs text-primary-600 hover:underline" :disabled="busy" @click="makeLead(t.id)">
                Make lead
              </button>
              <button type="button" class="p-1 text-gray-400 hover:text-red-600" :disabled="busy" :aria-label="`Remove ${t.name}`" @click="removeTechnician(t.id, t.name)">
                <XMarkIcon class="h-4 w-4" />
              </button>
            </span>
          </li>
        </ul>
        <div v-if="canEdit && available.length" class="mt-2 flex gap-2">
          <select v-model="newTechnician" class="input flex-1">
            <option value="">{{ data.technicians.length ? 'Add another technician…' : 'Assign a technician…' }}</option>
            <option v-for="t in available" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
          <Button size="sm" :disabled="!newTechnician" :loading="busy" @click="addTechnician">Assign</Button>
        </div>
      </section>

      <!-- Bay -->
      <section>
        <h4 class="text-sm font-medium text-gray-700 mb-2">Bay</h4>
        <p class="text-sm" :class="data.bay ? 'font-medium text-gray-900' : 'text-amber-700'">
          {{ data.bay?.name ?? 'No bay assigned yet.' }}
        </p>
        <div v-if="canEdit" class="mt-2 space-y-2">
          <div class="flex gap-2">
            <select v-model="newBay" class="input flex-1">
              <option value="">{{ data.bay ? 'Move to another bay…' : 'Choose a bay…' }}</option>
              <option v-for="b in data.candidates.bays" :key="b.id" :value="b.id" :disabled="b.id === data.bay?.id">
                {{ b.name }}{{ b.occupied_by ? ` — in use (${b.occupied_by})` : '' }}
              </option>
            </select>
            <Button size="sm" :disabled="!newBay" :loading="busy" @click="moveBay">{{ data.bay ? 'Move' : 'Assign' }}</Button>
          </div>
          <input v-if="newBay && data.bay" v-model="bayNote" class="input" placeholder="Why the move? e.g. For washing (optional)" />
        </div>
        <ul v-if="data.bay_moves.length > 1 || (data.bay_moves.length === 1 && data.bay_moves[0].from)" class="mt-3 space-y-1 text-xs text-gray-500">
          <li v-for="(m, i) in data.bay_moves" :key="i">
            {{ m.at ? format(new Date(m.at), 'dd MMM, h:mm a') : '' }} · {{ m.from ?? '—' }} → {{ m.to }}
            <span v-if="m.notes">({{ m.notes }})</span>
            <span v-if="m.by"> · {{ m.by }}</span>
          </li>
        </ul>
      </section>
    </div>
  </Card>
</template>
