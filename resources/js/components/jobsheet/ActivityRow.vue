<script setup lang="ts">
import { ref, computed } from 'vue'
import {
  CameraIcon,
  CheckIcon,
  XMarkIcon,
  ExclamationTriangleIcon,
  ChatBubbleLeftEllipsisIcon,
} from '@heroicons/vue/20/solid'
import { jobSheetsApi, type ItemUpdate } from '@/api/jobSheets.api'
import { useToast } from '@/composables/useToast'
import type { JobSheet, JobSheetItem, JobCardMedia, PanelFlags } from '@/api/types'
import { acceptFor, mediaHint, prepareMedia } from './media'
import MediaStrip from './MediaStrip.vue'

interface Props {
  item: JobSheetItem
  jobCardId: number
  sheetId: number
  readonly?: boolean
}

const props = withDefaults(defineProps<Props>(), { readonly: false })
const emit = defineEmits<{
  updated: [item: JobSheetItem]
  progress: [progress: JobSheet['progress']]
}>()
const toast = useToast()

const fileInput = ref<HTMLInputElement | null>(null)
const saving = ref(false)
const uploadPercent = ref<number | null>(null)
const markDoneAfterUpload = ref(false)

// Remarks box: opened for ✗ / ⚠ (remarks required) or by the remark button
const remarksOpen = ref(false)
const remarksDraft = ref('')
const pendingChange = ref<ItemUpdate | null>(null)

const hint = computed(() => mediaHint(props.item))
const needsMedia = computed(() => props.item.media_requirement !== 'none')
const mediaShort = computed(() => needsMedia.value && !!props.item.media_shortfall)

const rowTone = computed(() => {
  if (props.item.status === 'not_done') return 'border-red-200 bg-red-50'
  if (props.item.needs_attention) return 'border-amber-200 bg-amber-50'
  if (props.item.status === 'done' && !(needsMedia.value && mediaShort.value)) return 'border-green-200 bg-green-50/50'
  if (props.item.status === 'not_applicable') return 'border-gray-200 bg-gray-50'
  return 'border-gray-200 bg-white'
})

const answerMismatch = computed(
  () =>
    props.item.input_type === 'yes_no' &&
    props.item.expected_answer &&
    (props.item.value === 'yes' || props.item.value === 'no') &&
    props.item.value !== props.item.expected_answer
)

const repairAction = computed(() =>
  props.item.value && typeof props.item.value === 'object' && 'action' in props.item.value ? props.item.value.action : null
)

const panelFlags = computed<PanelFlags>(() => {
  const v = props.item.value
  const flags = v && typeof v === 'object' && 'repair' in v ? v : null
  return {
    repair: !!flags?.repair,
    replace: !!flags?.replace,
    partial_paint: !!flags?.partial_paint,
    full_paint: !!flags?.full_paint,
  }
})

async function save(data: ItemUpdate) {
  saving.value = true
  try {
    const response = await jobSheetsApi.updateItem(props.jobCardId, props.sheetId, props.item.id, data)
    emit('updated', response.data.data.item)
    emit('progress', response.data.data.progress)
    return true
  } catch (err: any) {
    const errors = err.response?.data?.errors
    if (err.response?.status === 422 && errors) {
      const first = Object.values(errors).flat()[0] as string | undefined
      toast.error('Not saved', first ?? err.response.data.message)
    } else {
      toast.apiError(err)
    }
    return false
  } finally {
    saving.value = false
  }
}

function markDone() {
  if (props.item.status === 'done') {
    save({ status: 'pending' })
    return
  }
  // Required evidence missing: go straight to the camera, then mark done
  if (mediaShort.value) {
    markDoneAfterUpload.value = true
    fileInput.value?.click()
    return
  }
  save({ status: 'done' })
}

function markNa() {
  save({ status: props.item.status === 'not_applicable' ? 'pending' : 'not_applicable' })
}

function startRemarks(change: ItemUpdate | null) {
  pendingChange.value = change
  remarksDraft.value = props.item.remarks ?? ''
  remarksOpen.value = true
}

function markNotDone() {
  if (props.item.status === 'not_done') {
    save({ status: 'pending' })
  } else {
    startRemarks({ status: 'not_done' })
  }
}

function toggleAttention() {
  if (props.item.needs_attention) {
    save({ needs_attention: false })
  } else {
    startRemarks({ needs_attention: true })
  }
}

async function saveRemarks() {
  const ok = await save({ ...(pendingChange.value ?? {}), remarks: remarksDraft.value })
  if (ok) {
    remarksOpen.value = false
    pendingChange.value = null
  }
}

function setValue(value: unknown) {
  save({ value })
}

function togglePanel(key: keyof PanelFlags) {
  setValue({ ...panelFlags.value, [key]: !panelFlags.value[key] })
}

async function onFileChosen(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) {
    markDoneAfterUpload.value = false
    return
  }

  try {
    const prepared = await prepareMedia(file)
    uploadPercent.value = 0
    const response = await jobSheetsApi.uploadMedia(
      props.jobCardId,
      props.sheetId,
      props.item.id,
      prepared.file,
      prepared.fileName,
      { poster: prepared.poster, duration_seconds: prepared.durationSeconds },
      (p) => (uploadPercent.value = p)
    )
    const media = [...props.item.media, response.data.data.media]
    emit('updated', { ...props.item, media, media_shortfall: response.data.data.media_shortfall })

    if (markDoneAfterUpload.value && !response.data.data.media_shortfall) {
      await save({ status: 'done' })
    }
  } catch (err: any) {
    if (err instanceof Error && !('response' in err)) {
      toast.error('Cannot upload', err.message)
    } else {
      toast.apiError(err)
    }
  } finally {
    uploadPercent.value = null
    markDoneAfterUpload.value = false
  }
}

async function deleteMedia(media: JobCardMedia) {
  try {
    const response = await jobSheetsApi.deleteMedia(props.jobCardId, props.sheetId, props.item.id, media.id)
    emit('updated', response.data.data.item)
    if (response.data.data.reverted_to_pending) {
      toast.warning('Marked not done', 'This activity needs its photo or video again.')
    }
  } catch (err) {
    toast.apiError(err)
  }
}
</script>

<template>
  <div :id="`item-${item.id}`" class="rounded-xl border p-3 transition-colors" :class="rowTone">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-sm font-medium text-gray-900">
          {{ item.item_name }}
          <span v-if="!item.is_required && item.input_type === 'tick'" class="text-xs font-normal text-gray-400">(optional)</span>
        </p>
        <p v-if="hint && item.input_type === 'tick'" class="mt-0.5 text-xs" :class="mediaShort ? 'text-red-600' : 'text-green-700'">
          <CameraIcon class="inline h-3.5 w-3.5 -mt-0.5" />
          {{ mediaShort ? hint : `${hint.replace(' required', '')} added` }}
        </p>
        <p v-if="item.remarks && !remarksOpen" class="mt-1 text-xs text-gray-700">
          <ChatBubbleLeftEllipsisIcon class="inline h-3.5 w-3.5 -mt-0.5 text-gray-400" /> {{ item.remarks }}
        </p>
        <p v-if="answerMismatch" class="mt-1 text-xs font-medium text-red-600">Expected "{{ item.expected_answer }}" — fix before delivery</p>
      </div>

      <!-- Camera -->
      <div v-if="!readonly && item.input_type !== 'yes_no'" class="shrink-0">
        <input
          ref="fileInput"
          type="file"
          class="hidden"
          :accept="acceptFor(item.media_requirement)"
          capture="environment"
          @change="onFileChosen"
        />
        <button
          type="button"
          class="h-11 w-11 rounded-lg border flex items-center justify-center"
          :class="mediaShort ? 'border-red-300 bg-white text-red-600' : 'border-gray-300 bg-white text-gray-600'"
          :disabled="uploadPercent !== null"
          aria-label="Add photo or video"
          @click="fileInput?.click()"
        >
          <span v-if="uploadPercent !== null" class="text-xs font-medium">{{ uploadPercent }}%</span>
          <CameraIcon v-else class="h-5 w-5" />
        </button>
      </div>
    </div>

    <MediaStrip v-if="item.media.length" class="mt-2" :media="item.media" :can-delete="!readonly" @delete="deleteMedia" />

    <!-- Controls by input type -->
    <div v-if="!readonly" class="mt-3 flex flex-wrap gap-2">
      <template v-if="item.input_type === 'tick'">
        <button
          type="button"
          class="min-h-11 px-4 rounded-lg border text-sm font-medium flex items-center gap-1"
          :class="item.status === 'done' ? 'bg-green-600 border-green-600 text-white' : 'bg-white border-gray-300 text-gray-700'"
          :disabled="saving"
          @click="markDone"
        >
          <CheckIcon class="h-4 w-4" /> Done
        </button>
        <button
          v-if="item.na_allowed"
          type="button"
          class="min-h-11 px-4 rounded-lg border text-sm font-medium"
          :class="item.status === 'not_applicable' ? 'bg-gray-700 border-gray-700 text-white' : 'bg-white border-gray-300 text-gray-700'"
          :disabled="saving"
          @click="markNa"
        >
          N/A
        </button>
        <button
          type="button"
          class="min-h-11 px-3 rounded-lg border text-sm"
          :class="item.status === 'not_done' ? 'bg-red-600 border-red-600 text-white' : 'bg-white border-gray-300 text-gray-600'"
          :disabled="saving"
          aria-label="Not done"
          @click="markNotDone"
        >
          <XMarkIcon class="h-4 w-4" />
        </button>
      </template>

      <template v-else-if="item.input_type === 'yes_no'">
        <button
          v-for="answer in ['yes', 'no'] as const"
          :key="answer"
          type="button"
          class="min-h-11 px-5 rounded-lg border text-sm font-medium capitalize"
          :class="item.value === answer
            ? (answer === item.expected_answer || !item.expected_answer ? 'bg-green-600 border-green-600 text-white' : 'bg-red-600 border-red-600 text-white')
            : 'bg-white border-gray-300 text-gray-700'"
          :disabled="saving"
          @click="setValue(item.value === answer ? null : answer)"
        >
          {{ answer }}
        </button>
      </template>

      <template v-else-if="item.input_type === 'repair_replace'">
        <button
          v-for="action in ['ok', 'repair', 'replace'] as const"
          :key="action"
          type="button"
          class="min-h-11 px-4 rounded-lg border text-sm font-medium capitalize"
          :class="repairAction === action
            ? (action === 'ok' ? 'bg-green-600 border-green-600 text-white' : 'bg-amber-500 border-amber-500 text-white')
            : 'bg-white border-gray-300 text-gray-700'"
          :disabled="saving"
          @click="setValue(repairAction === action ? null : { action })"
        >
          {{ action === 'ok' ? 'OK' : action }}
        </button>
      </template>

      <template v-else-if="item.input_type === 'panel_matrix'">
        <button
          v-for="(label, key) in { repair: 'Repair', replace: 'Replace', partial_paint: 'Partial paint', full_paint: 'Full paint' }"
          :key="key"
          type="button"
          class="min-h-11 px-3 rounded-lg border text-sm font-medium"
          :class="panelFlags[key] ? 'bg-primary-600 border-primary-600 text-white' : 'bg-white border-gray-300 text-gray-700'"
          :disabled="saving"
          @click="togglePanel(key)"
        >
          {{ label }}
        </button>
      </template>

      <button
        type="button"
        class="min-h-11 px-3 rounded-lg border text-sm"
        :class="item.needs_attention ? 'bg-amber-500 border-amber-500 text-white' : 'bg-white border-gray-300 text-amber-600'"
        :disabled="saving"
        :aria-label="item.needs_attention ? 'Clear attention flag' : 'Needs attention'"
        @click="toggleAttention"
      >
        <ExclamationTriangleIcon class="h-4 w-4" />
      </button>
      <button
        v-if="!remarksOpen"
        type="button"
        class="min-h-11 px-3 rounded-lg border border-gray-300 bg-white text-sm text-gray-600"
        aria-label="Add remark"
        @click="startRemarks(null)"
      >
        <ChatBubbleLeftEllipsisIcon class="h-4 w-4" />
      </button>
    </div>

    <!-- Remarks -->
    <div v-if="remarksOpen" class="mt-3 space-y-2">
      <textarea
        v-model="remarksDraft"
        rows="2"
        class="input"
        :placeholder="pendingChange?.status === 'not_done'
          ? 'Why couldn\'t this be done?'
          : pendingChange?.needs_attention ? 'What needs attention? (goes to observations)' : 'Remark'"
      />
      <div class="flex gap-2">
        <button
          type="button"
          class="min-h-10 px-4 rounded-lg bg-primary-600 text-white text-sm font-medium disabled:opacity-50"
          :disabled="saving || (!!pendingChange && !remarksDraft.trim())"
          @click="saveRemarks"
        >
          Save
        </button>
        <button type="button" class="min-h-10 px-4 rounded-lg border border-gray-300 text-sm" @click="remarksOpen = false">
          Cancel
        </button>
      </div>
    </div>
  </div>
</template>
