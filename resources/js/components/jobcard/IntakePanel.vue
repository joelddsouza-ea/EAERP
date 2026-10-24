<script setup lang="ts">
/**
 * Walk-in intake on the job card: the advisor's trial drive (or why it was
 * skipped) and the inventory photos/videos. Work can't start until both meet
 * the owner's minimums (enforced again by the workflow guard).
 */
import { ref, computed, onMounted } from 'vue'
import { CameraIcon, VideoCameraIcon, CheckCircleIcon } from '@heroicons/vue/20/solid'
import { intakeApi } from '@/api/estimates.api'
import { useToast } from '@/composables/useToast'
import { usePermissions } from '@/composables/usePermissions'
import type { IntakeInfo, JobCardMedia } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'
import VoiceInput from '@/components/ui/VoiceInput.vue'
import MediaStrip from '@/components/jobsheet/MediaStrip.vue'
import { prepareMedia } from '@/components/jobsheet/media'

const props = defineProps<{ jobCardId: number }>()
const emit = defineEmits<{ changed: [] }>()

const toast = useToast()
const { can } = usePermissions()

const intake = ref<IntakeInfo | null>(null)
const loading = ref(true)
const uploadPercent = ref<number | null>(null)
const skipping = ref(false)
const notes = ref('')
const photoInput = ref<HTMLInputElement | null>(null)
const videoInput = ref<HTMLInputElement | null>(null)

const canEdit = computed(() => !!intake.value?.editable && can('workshop.job-cards.update'))
const photosOk = computed(() => !!intake.value && intake.value.counts.photo >= intake.value.requirements.min_photos)
const videosOk = computed(() => !!intake.value && intake.value.counts.video >= intake.value.requirements.min_videos)
const complete = computed(() => !!intake.value?.trial.status && photosOk.value && videosOk.value)

async function load() {
  loading.value = true
  try {
    intake.value = (await intakeApi.get(props.jobCardId)).data.data
    notes.value = intake.value.trial.notes ?? ''
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

async function setTrial(status: 'done' | 'skipped') {
  if (status === 'skipped' && !notes.value.trim()) {
    toast.error('Add a reason', 'Say why the trial drive was skipped, e.g. the car is not drivable.')
    return
  }
  try {
    intake.value = (await intakeApi.setTrial(props.jobCardId, status, notes.value.trim() || null)).data.data
    skipping.value = false
    emit('changed')
  } catch (err) {
    toast.apiError(err)
  }
}

async function onFileChosen(event: Event) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''

  for (const file of files) {
    try {
      const prepared = await prepareMedia(file)
      const form = new FormData()
      form.append('file', prepared.file, prepared.fileName)
      if (prepared.poster) form.append('poster', prepared.poster, 'poster.jpg')
      if (prepared.durationSeconds != null) form.append('duration_seconds', String(prepared.durationSeconds))
      form.append('captured_at', new Date().toISOString())

      uploadPercent.value = 0
      const response = await intakeApi.upload(props.jobCardId, form, (p) => (uploadPercent.value = p))
      intake.value = response.data.data.intake
    } catch (err: any) {
      if (err instanceof Error && !('response' in err)) {
        toast.error('Cannot upload', err.message)
      } else {
        toast.apiError(err)
      }
    } finally {
      uploadPercent.value = null
    }
  }
  emit('changed')
}

async function remove(media: JobCardMedia) {
  try {
    intake.value = (await intakeApi.remove(props.jobCardId, media.id)).data.data
    emit('changed')
  } catch (err) {
    toast.apiError(err)
  }
}

onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <Card>
    <template #header>
      <div class="flex items-center justify-between gap-3">
        <h3 class="text-lg font-semibold">Intake</h3>
        <span
          v-if="intake"
          class="inline-flex items-center gap-1 text-xs font-medium"
          :class="complete ? 'text-green-700' : 'text-amber-700'"
        >
          <CheckCircleIcon v-if="complete" class="h-4 w-4" />
          {{ complete ? 'Complete' : 'Needed before work starts' }}
        </span>
      </div>
    </template>

    <div v-if="loading && !intake" class="flex justify-center py-6"><Spinner /></div>

    <div v-else-if="intake" class="space-y-6">
      <!-- Trial drive -->
      <section>
        <h4 class="text-sm font-medium text-gray-700 mb-2">Trial drive (advisor, or the pickup driver)</h4>

        <p v-if="intake.trial.status === 'done'" class="text-sm text-gray-700">
          Done<span v-if="intake.trial.by"> by {{ intake.trial.by }}</span>.
          <span v-if="intake.trial.notes" class="block text-gray-500 whitespace-pre-wrap">{{ intake.trial.notes }}</span>
        </p>
        <p v-else-if="intake.trial.status === 'skipped'" class="text-sm text-gray-700">
          Skipped<span v-if="intake.trial.by"> by {{ intake.trial.by }}</span>:
          <span class="whitespace-pre-wrap">{{ intake.trial.notes }}</span>
        </p>
        <p v-else class="text-sm text-gray-500">Not recorded yet.</p>

        <div v-if="canEdit" class="mt-3 space-y-2">
          <div class="flex items-start gap-2">
            <textarea
              v-model="notes"
              rows="2"
              class="flex-1 rounded-lg border-gray-300 text-sm"
              :placeholder="skipping ? 'Why was it skipped? e.g. battery dead, car towed in' : 'What you noticed on the drive (optional)'"
            />
            <VoiceInput @text="(t: string) => (notes = notes ? `${notes} ${t}` : t)" />
          </div>
          <div class="flex flex-wrap gap-2">
            <Button size="sm" @click="setTrial('done')">Trial drive done</Button>
            <Button v-if="!skipping" size="sm" variant="secondary" @click="skipping = true">Skip trial drive…</Button>
            <Button v-else size="sm" variant="secondary" @click="setTrial('skipped')">Save as skipped</Button>
          </div>
        </div>
      </section>

      <!-- Inventory media -->
      <section>
        <h4 class="text-sm font-medium text-gray-700 mb-1">Inventory photos & videos</h4>
        <p class="text-xs text-gray-500 mb-3">
          All four sides, interior, odometer and valuables left in the car.
          <span :class="photosOk ? 'text-green-700' : 'text-amber-700'">
            Photos {{ intake.counts.photo }}/{{ intake.requirements.min_photos }}
          </span>
          ·
          <span :class="videosOk ? 'text-green-700' : 'text-amber-700'">
            Videos {{ intake.counts.video }}/{{ intake.requirements.min_videos }}
          </span>
        </p>

        <MediaStrip :media="intake.media" :can-delete="canEdit" :job-card-id="jobCardId" @delete="remove" />

        <div v-if="canEdit" class="mt-3 flex flex-wrap items-center gap-2">
          <input ref="photoInput" type="file" accept="image/*" capture="environment" class="hidden" @change="onFileChosen" />
          <input ref="videoInput" type="file" accept="video/*" capture="environment" class="hidden" @change="onFileChosen" />
          <Button size="sm" variant="secondary" :disabled="uploadPercent !== null" @click="photoInput?.click()">
            <CameraIcon class="h-4 w-4 mr-1" /> Take photo
          </Button>
          <Button size="sm" variant="secondary" :disabled="uploadPercent !== null" @click="videoInput?.click()">
            <VideoCameraIcon class="h-4 w-4 mr-1" /> Record video
          </Button>
          <span v-if="uploadPercent !== null" class="text-xs text-gray-500">Uploading… {{ uploadPercent }}%</span>
        </div>
        <p v-else-if="!intake.editable" class="mt-2 text-xs text-gray-400">Intake is locked once work has started.</p>
      </section>
    </div>
  </Card>
</template>
