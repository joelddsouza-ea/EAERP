<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/20/solid'
import { jobCardsApi } from '@/api/workshop.api'
import { useToast } from '@/composables/useToast'
import type { GuardFailureInfo, StatusTransitionOption } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import Modal from '@/components/ui/Modal.vue'
import Textarea from '@/components/ui/Textarea.vue'

interface Props {
  jobCardId: number
  // Re-fetch transitions whenever the job card's status changes
  status: string
}

const props = defineProps<Props>()
const emit = defineEmits<{ changed: [] }>()
const toast = useToast()

const options = ref<StatusTransitionOption[]>([])
const loading = ref(false)

const selected = ref<StatusTransitionOption | null>(null)
const notes = ref('')
const override = ref(false)
const saving = ref(false)
const serverBlocks = ref<GuardFailureInfo[]>([])

// Forward moves first, cancel last; hide moves this user can't make at all
const visible = computed(() =>
  options.value
    .filter((o) => o.permitted)
    .sort((a, b) => Number(a.status === 'cancelled') - Number(b.status === 'cancelled'))
)

const blocked = computed(() => visible.value.filter((o) => !o.allowed && o.status !== 'cancelled'))

const needsModal = (o: StatusTransitionOption) => o.requires_notes || !o.allowed || o.status === 'cancelled'

async function fetchTransitions() {
  loading.value = true
  try {
    const response = await jobCardsApi.transitions(props.jobCardId)
    options.value = response.data.data
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

watch(() => props.status, fetchTransitions, { immediate: true })
</script>

<template>
  <div class="space-y-3">
    <div v-if="visible.length" class="flex flex-wrap gap-2">
      <Button
        v-for="option in visible"
        :key="option.status"
        :variant="option.status === 'cancelled' ? 'ghost' : option.allowed ? 'primary' : 'secondary'"
        size="sm"
        :loading="saving && selected?.status === option.status && !needsModal(option)"
        @click="choose(option)"
      >
        <ExclamationTriangleIcon v-if="!option.allowed && option.status !== 'cancelled'" class="h-4 w-4 mr-1 text-amber-500" />
        {{ option.status === 'cancelled' ? 'Cancel job' : `Move to ${option.label}` }}
      </Button>
    </div>
    <p v-else-if="!loading" class="text-sm text-gray-500">No further status changes are available to you.</p>

    <!-- Why forward moves are blocked -->
    <div v-for="option in blocked" :key="`b-${option.status}`" class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm">
      <p class="font-medium text-amber-800">Before moving to {{ option.label }}:</p>
      <ul class="mt-1 list-disc list-inside text-amber-700 space-y-0.5">
        <li v-for="g in option.blocked_by" :key="g.code">{{ g.message }}</li>
      </ul>
    </div>

    <Modal :open="!!selected && needsModal(selected)" :title="selected?.status === 'cancelled' ? 'Cancel Job Card' : `Move to ${selected?.label}`" @close="selected = null">
      <div class="space-y-4">
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
          <p v-else class="mt-2 text-xs text-amber-800">A manager can override these checks.</p>
        </div>

        <Textarea
          v-model="notes"
          :label="selected?.status === 'cancelled' ? 'Reason for cancelling' : 'Note'"
          :required="selected?.requires_notes || override"
          :rows="3"
          :placeholder="selected?.status === 'work_in_progress' ? 'What needs fixing?' : ''"
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
