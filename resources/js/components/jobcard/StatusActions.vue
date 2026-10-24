<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { ExclamationTriangleIcon, ArrowPathIcon } from '@heroicons/vue/20/solid'
import { jobCardsApi } from '@/api/workshop.api'
import { useToast } from '@/composables/useToast'
import type { GuardFailureInfo, StatusTransitionOption, StatusTransitions } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import Modal from '@/components/ui/Modal.vue'
import Textarea from '@/components/ui/Textarea.vue'
import SendUpdateButton from '@/components/jobcard/SendUpdateButton.vue'

/**
 * Next step for a job. Most stages move by themselves (Job stages master); people
 * record events here (QC result, send back, cancel). Owners/managers can still move
 * a stuck job by hand, with a reason that is logged.
 */
interface Props {
  jobCardId: number
  // Re-fetch transitions whenever the job card's status changes
  status: string
}

const props = defineProps<Props>()
const emit = defineEmits<{ changed: [] }>()
const toast = useToast()

const options = ref<StatusTransitionOption[]>([])
const stage = ref<StatusTransitions['stage'] | null>(null)
const loading = ref(false)
const showManual = ref(false)

const selected = ref<StatusTransitionOption | null>(null)
const notes = ref('')
const override = ref(false)
const saving = ref(false)
const serverBlocks = ref<GuardFailureInfo[]>([])

const terminal = computed(() => ['delivered', 'cancelled'].includes(props.status))

// Events people record (QC result, send back, moving on after a send-back), then cancel.
// A failed trial drive is recorded below with its note instead.
const events = computed(() =>
  options.value
    .filter((o) => o.permitted && !o.manual && !(props.status === 'post_work_trial' && o.status === 'work_in_progress'))
    .sort((a, b) => Number(a.status === 'cancelled') - Number(b.status === 'cancelled'))
)
// Corrections for a stuck job: owner/manager only
const manual = computed(() => options.value.filter((o) => o.permitted && o.manual))

const blocked = computed(() => events.value.filter((o) => !o.allowed && o.status !== 'cancelled'))

const needsModal = (o: StatusTransitionOption) => o.requires_notes || !o.allowed || o.status === 'cancelled'

const EVENT_LABELS: Record<string, string> = {
  'post_work_trial>work_in_progress': 'Send back to work',
  'wash_pending>work_in_progress': 'Send back to work',
  'qc_pending>work_in_progress': 'QC failed — back to work',
  'ready_for_delivery>work_in_progress': 'Send back to work',
}

function buttonLabel(o: StatusTransitionOption) {
  if (o.status === 'cancelled') return 'Cancel job'
  if (o.manual) return o.label
  return EVENT_LABELS[`${props.status}>${o.status}`] ?? `Move to ${o.label}`
}

const isBackward = (o: StatusTransitionOption) => (EVENT_LABELS[`${props.status}>${o.status}`] ?? '').includes('back')

async function fetchTransitions() {
  loading.value = true
  try {
    const response = await jobCardsApi.transitions(props.jobCardId)
    options.value = response.data.data.options
    stage.value = response.data.data.stage
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

function choose(option: StatusTransitionOption) {
  selected.value = option
  notes.value = ''
  override.value = false
  serverBlocks.value = []
  if (!needsModal(option)) {
    submit()
  }
}

async function submit() {
  const option = selected.value
  if (!option) return

  saving.value = true
  try {
    await jobCardsApi.updateStatus(props.jobCardId, option.status, notes.value, override.value)
    toast.success('Status Updated', `Moved to ${option.label}.`)
    selected.value = null
    showManual.value = false
    emit('changed')
  } catch (err: any) {
    const guards = err.response?.data?.errors?.guards as GuardFailureInfo[] | undefined
    if (err.response?.status === 422 && guards?.length) {
      // Show why inside the dialog instead of a transient toast
      serverBlocks.value = guards
    } else {
      toast.apiError(err)
    }
  } finally {
    saving.value = false
  }
}

const modalBlocks = computed(() => (serverBlocks.value.length ? serverBlocks.value : selected.value?.blocked_by ?? []))
const canSubmit = computed(() => {
  const o = selected.value
  if (!o) return false
  if (o.requires_notes && !notes.value.trim()) return false
  if (modalBlocks.value.length && !(override.value && notes.value.trim())) return false
  return true
})

const modalTitle = computed(() => {
  const o = selected.value
  if (!o) return ''
  if (o.status === 'cancelled') return 'Cancel Job Card'
  return o.manual ? `Move by hand to ${o.label}` : buttonLabel(o)
})

const notesLabel = computed(() => {
  const o = selected.value
  if (o?.status === 'cancelled') return 'Reason for cancelling'
  if (o?.manual) return 'Why are you moving it by hand? (logged)'
  return isBackward(o!) ? 'What needs fixing?' : 'Note'
})

watch(() => props.status, fetchTransitions, { immediate: true })

// The page asks for fresh moves after intake or assignments change (guards depend on them)
defineExpose({ reload: fetchTransitions })
</script>

<template>
  <div class="space-y-3">
    <!-- What the job is waiting for before it moves on by itself -->
    <div v-if="!terminal && stage" class="rounded-lg bg-blue-50 border border-blue-100 p-3 text-sm">
      <p class="flex items-center gap-1.5 font-medium text-blue-900">
        <ArrowPathIcon class="h-4 w-4" />
        {{ stage.sent_back ? 'Sent back — move it on below when the rework is done' : 'Moves on by itself' }}
      </p>
      <p v-if="stage.waiting_for && !stage.sent_back" class="mt-1 text-blue-800">Waiting for: {{ stage.waiting_for }}</p>
    </div>

    <div v-if="!terminal && !['intake_pending'].includes(status)" class="flex justify-end">
      <SendUpdateButton :key="status" :job-card-id="jobCardId" kind="stage" label="Send update to customer" />
    </div>
    <p v-if="status === 'qc_pending'" class="text-sm text-gray-700">
      Fill and submit the <b>pre-delivery QC checklist</b> (Job sheets section below): the car becomes Ready for Delivery and billing can start.
    </p>

    <div v-if="events.length" class="flex flex-wrap gap-2">
      <Button
        v-for="option in events"
        :key="option.status"
        :variant="option.status === 'cancelled' ? 'ghost' : option.allowed ? (isBackward(option) ? 'secondary' : 'primary') : 'secondary'"
        size="sm"
        :loading="saving && selected?.status === option.status && !needsModal(option)"
        @click="choose(option)"
      >
        <ExclamationTriangleIcon v-if="!option.allowed && option.status !== 'cancelled'" class="h-4 w-4 mr-1 text-amber-500" />
        {{ buttonLabel(option) }}
      </Button>
    </div>

    <!-- Why recorded events are blocked -->
    <div v-for="option in blocked" :key="`b-${option.status}`" class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm">
      <p class="font-medium text-amber-800">Before "{{ buttonLabel(option) }}":</p>
      <ul class="mt-1 list-disc list-inside text-amber-700 space-y-0.5">
        <li v-for="g in option.blocked_by" :key="g.code">{{ g.message }}</li>
      </ul>
    </div>

    <!-- Owner/manager: correct a stuck job -->
    <div v-if="manual.length">
      <button type="button" class="text-xs font-medium text-gray-500 hover:text-gray-800 underline" @click="showManual = !showManual">
        {{ showManual ? 'Hide manual moves' : 'Move manually… (owner/manager)' }}
      </button>
      <div v-if="showManual" class="mt-2 flex flex-wrap gap-2">
        <Button v-for="option in manual" :key="`m-${option.status}`" variant="secondary" size="sm" @click="choose(option)">
          <ExclamationTriangleIcon v-if="!option.allowed" class="h-4 w-4 mr-1 text-amber-500" />
          {{ option.label }}
        </Button>
      </div>
    </div>

    <Modal :open="!!selected && needsModal(selected)" :title="modalTitle" @close="selected = null">
      <div class="space-y-4">
        <p v-if="selected?.manual" class="text-sm text-gray-600">
          This stage is normally reached by itself. Moving it by hand is logged with your reason.
        </p>
        <div v-if="modalBlocks.length" class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm">
          <p class="font-medium text-amber-800">These checks have not passed:</p>
          <ul class="mt-1 space-y-2 text-amber-700">
            <li v-for="g in modalBlocks" :key="g.code">
              {{ g.message }}
              <ul v-if="g.details?.items?.length" class="mt-1 ml-4 list-disc text-xs max-h-40 overflow-y-auto">
                <li v-for="item in g.details.items" :key="`${item.sheet_id}-${item.item_id}`">
                  {{ item.item_name }} — {{ item.message }}
                </li>
              </ul>
            </li>
          </ul>
          <label v-if="selected?.can_override" class="mt-3 flex items-center gap-2 text-amber-900">
            <input v-model="override" type="checkbox" class="h-4 w-4 rounded border-gray-300" />
            Override these checks (logged with your note)
          </label>
          <p v-else-if="!modalBlocks.some((g) => g.code === 'gate_pass_missing')" class="mt-2 text-xs text-amber-800">
            A manager can override these checks.
          </p>
        </div>

        <Textarea
          v-model="notes"
          :label="notesLabel"
          :required="selected?.requires_notes || override"
          :rows="3"
        />
      </div>

      <template #footer>
        <Button variant="secondary" @click="selected = null">Close</Button>
        <Button
          :variant="selected?.status === 'cancelled' ? 'danger' : 'primary'"
          :loading="saving"
          :disabled="!canSubmit"
          @click="submit"
        >
          {{ selected?.status === 'cancelled' ? 'Cancel Job' : 'Confirm' }}
        </Button>
      </template>
    </Modal>
  </div>
</template>
