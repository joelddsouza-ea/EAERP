<script setup lang="ts">
/**
 * Additional Issues: one line per problem — customer complaints from intake (the advisor
 * can add more), activities flagged ⚠ on the job sheet, and the technician's own findings.
 * The order of work on a line:
 *   1. photo/video of the faulty part (a finding is added together with it);
 *   2. the parts required (no prices) → "Submit to SA for Parts" → estimate, approval, procurement;
 *   3. when the new part arrives: photo/video of it fitted;
 *   4. Mark done (or Defer with a note).
 */
import { ref, reactive, computed, onMounted } from 'vue'
import { CameraIcon, TrashIcon, WrenchScrewdriverIcon } from '@heroicons/vue/20/solid'
import { jobCardIssuesApi } from '@/api/jobSheets.api'
import { purchasesApi } from '@/api/purchases.api'
import { useToast } from '@/composables/useToast'
import type { JobCardIssue, JobCardAdditionalJob, SparePartStatus, JobCardMedia } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import VoiceInput from '@/components/ui/VoiceInput.vue'
import MediaStrip from './MediaStrip.vue'
import { prepareMedia } from './media'
import MediaPicker from '@/components/media/MediaPicker.vue'

interface Props {
  jobCardId: number
  /** The advisor may still add customer complaint lines */
  readonly?: boolean
  /** Photos, parts, Done/Defer and findings: only the assigned technician */
  jobsReadonly?: boolean
}

const props = withDefaults(defineProps<Props>(), { readonly: false, jobsReadonly: false })
const emit = defineEmits<{ partsChanged: [] }>()
const toast = useToast()

// Purchases module: the technician checks a part received on a GRN against its photos
const checking = ref<{ lineId: number; grnLineId: number; partName: string; note: string } | null>(null)
async function checkPart(grnLineId: number, right: boolean, note: string | null = null) {
  saving.value = true
  try {
    const res = await purchasesApi.verify(props.jobCardId, grnLineId, right, note)
    toast.success(res.data.message ?? 'Saved')
    checking.value = null
    await load()
    emit('partsChanged')
  } catch (err) {
    toast.apiError(err)
  } finally {
    saving.value = false
  }
}

const lines = ref<JobCardIssue[]>([])
const legacyJobs = ref<JobCardAdditionalJob[]>([])
const loading = ref(true)
const saving = ref(false)
const newLine = ref('')
const newLineFiles = ref<File[]>([])
const addProgress = ref<number | null>(null)
const uploading = reactive<Record<number, number | null>>({})
const deferring = ref<{ line: JobCardIssue; notes: string } | null>(null)
const partForms = reactive<Record<number, { part_name: string; part_number: string; quantity: number | string; files: File[] } | null>>({})

const isTechnician = computed(() => !props.jobsReadonly)
const canAddComplaint = computed(() => !props.readonly && props.jobsReadonly)

const STATUS: Record<JobCardIssue['status'], { label: string; variant: 'default' | 'warning' | 'success' | 'info' }> = {
  open: { label: 'To do', variant: 'default' },
  in_progress: { label: 'In progress', variant: 'info' },
  resolved: { label: 'Done', variant: 'success' },
  deferred: { label: 'Deferred', variant: 'warning' },
}

const PART_STATUS: Record<SparePartStatus, { label: string; variant: 'default' | 'warning' | 'success' | 'danger' | 'info' }> = {
  draft: { label: 'Not sent yet', variant: 'default' },
  proposed: { label: 'With advisor', variant: 'warning' },
  requested: { label: 'Getting prices', variant: 'warning' },
  quoted: { label: 'Priced', variant: 'warning' },
  approved: { label: 'Approved — ordering', variant: 'info' },
  ordered: { label: 'Ordered', variant: 'info' },
  received: { label: 'Arrived', variant: 'success' },
  issued: { label: 'Issued', variant: 'success' },
  declined: { label: 'Customer declined', variant: 'danger' },
  returned: { label: 'Returned', variant: 'default' },
  cancelled: { label: 'Cancelled', variant: 'default' },
}

async function load() {
  loading.value = true
  try {
    const [i, j] = await Promise.all([jobCardIssuesApi.issues(props.jobCardId), jobCardIssuesApi.additionalJobs(props.jobCardId)])
    lines.value = i.data.data
    legacyJobs.value = j.data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

function replace(updated: JobCardIssue) {
  const index = lines.value.findIndex((l) => l.id === updated.id)
  if (index >= 0) lines.value[index] = updated
}

function chooseEvidence(event: Event) {
  const input = event.target as HTMLInputElement
  newLineFiles.value = [...newLineFiles.value, ...Array.from(input.files ?? [])]
  input.value = ''
}

/**
 * The technician's finding goes in with its photos/videos of the faulty part;
 * the advisor adds customer complaints as text.
 */
async function addLine() {
  if (!newLine.value.trim()) return
  saving.value = true
  try {
    if (isTechnician.value) {
      const form = new FormData()
      form.append('description', newLine.value.trim())
      for (const file of newLineFiles.value) {
        const prepared = await prepareMedia(file)
        form.append('files[]', prepared.file, prepared.fileName)
      }
      addProgress.value = 0
      lines.value.push((await jobCardIssuesApi.createIssueWithEvidence(props.jobCardId, form, (p) => (addProgress.value = p))).data.data)
    } else {
      lines.value.push((await jobCardIssuesApi.createIssue(props.jobCardId, { description: newLine.value.trim() })).data.data)
    }
    newLine.value = ''
    newLineFiles.value = []
  } catch (err: any) {
    if (err instanceof Error && !('response' in err)) toast.error('Cannot upload', err.message)
    else toast.apiError(err)
  } finally {
    saving.value = false
    addProgress.value = null
  }
}

async function removeLine(line: JobCardIssue) {
  if (!window.confirm(`Remove "${line.description}"?`)) return
  try {
    await jobCardIssuesApi.deleteIssue(props.jobCardId, line.id)
    lines.value = lines.value.filter((l) => l.id !== line.id)
  } catch (err) {
    toast.apiError(err)
  }
}

async function setStatus(line: JobCardIssue, status: JobCardIssue['status'], notes?: string) {
  try {
    replace((await jobCardIssuesApi.updateIssue(props.jobCardId, line.id, { status, resolution_notes: notes ?? line.resolution_notes })).data.data)
    deferring.value = null
  } catch (err) {
    toast.apiError(err)
  }
}

async function upload(line: JobCardIssue, event: Event, tag: string | null) {
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
      if (tag) form.append('tag', tag)
      form.append('captured_at', new Date().toISOString())
      uploading[line.id] = 0
      replace((await jobCardIssuesApi.uploadIssueMedia(props.jobCardId, line.id, form, (p) => (uploading[line.id] = p))).data.data)
    } catch (err: any) {
      if (err instanceof Error && !('response' in err)) toast.error('Cannot upload', err.message)
      else toast.apiError(err)
    } finally {
      uploading[line.id] = null
    }
  }
}

async function deleteMedia(line: JobCardIssue, media: JobCardMedia) {
  try {
    replace((await jobCardIssuesApi.deleteIssueMedia(props.jobCardId, line.id, media.id)).data.data)
  } catch (err) {
    toast.apiError(err)
  }
}

function problemMedia(line: JobCardIssue) {
  return (line.media ?? []).filter((m) => m.tag !== 'fitted_part')
}

const SOURCE_LABEL: Record<JobCardIssue['source'], string> = {
  customer: 'Customer complaint',
  technician: 'Found by technician',
  activity: 'Flagged on a service activity',
}

function fittedMedia(line: JobCardIssue) {
  return (line.media ?? []).filter((m) => m.tag === 'fitted_part')
}

function startPart(line: JobCardIssue) {
  partForms[line.id] = { part_name: '', part_number: '', quantity: 1, files: [] }
}

function choosePartEvidence(line: JobCardIssue, event: Event) {
  const input = event.target as HTMLInputElement
  const f = partForms[line.id]
  if (f) f.files = [...f.files, ...Array.from(input.files ?? [])]
  input.value = ''
}

/**
 * Adds the part required. A line with no photo of the faulty part yet takes it here first.
 */
async function addPart(line: JobCardIssue) {
  const f = partForms[line.id]
  if (!f?.part_name.trim()) return
  if (!line.can_list_parts && !f.files.length) {
    toast.error('Photo needed', 'Take a photo or video of the faulty part first.')
    return
  }
  try {
    for (const file of f.files) {
      const prepared = await prepareMedia(file)
      const form = new FormData()
      form.append('file', prepared.file, prepared.fileName)
      if (prepared.poster) form.append('poster', prepared.poster, 'poster.jpg')
      if (prepared.durationSeconds != null) form.append('duration_seconds', String(prepared.durationSeconds))
      form.append('captured_at', new Date().toISOString())
      uploading[line.id] = 0
      replace((await jobCardIssuesApi.uploadIssueMedia(props.jobCardId, line.id, form, (p) => (uploading[line.id] = p))).data.data)
      uploading[line.id] = null
    }
    f.files = []
    const current = lines.value.find((l) => l.id === line.id) ?? line
    const part = (
      await jobCardIssuesApi.addIssuePart(props.jobCardId, line.id, {
        part_name: f.part_name.trim(),
        part_number: f.part_number.trim() || null,
        quantity: Number(f.quantity) || 1,
      })
    ).data.data
    current.part_requests = [...(current.part_requests ?? []), { ...part, quantity: Number(part.quantity), status: part.status as SparePartStatus }]
    partForms[line.id] = null
    emit('partsChanged')
  } catch (err: any) {
    uploading[line.id] = null
    if (err instanceof Error && !('response' in err)) toast.error('Cannot upload', err.message)
    else toast.apiError(err)
  }
}

async function removePart(line: JobCardIssue, partId: number) {
  try {
    await jobCardIssuesApi.removePart(props.jobCardId, partId)
    line.part_requests = (line.part_requests ?? []).filter((p) => p.id !== partId)
    emit('partsChanged')
  } catch (err) {
    toast.apiError(err)
  }
}

onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-gray-500">
      Customer complaints, activities flagged ⚠ and anything else found on the car. For each line: photo/video of the
      faulty part → part required → “Submit to SA for Parts”. When the new part arrives: photo/video of it fitted →
      Mark done.
    </p>

    <p v-if="!loading && !lines.length" class="text-sm text-gray-500">No additional issues.</p>

    <ol class="space-y-3">
      <li
        v-for="(line, index) in lines"
        :key="line.id"
        class="rounded-xl border bg-white p-3"
        :class="line.status === 'resolved' ? 'border-green-200 bg-green-50/40' : line.status === 'deferred' ? 'border-amber-200 bg-amber-50/40' : 'border-gray-200'"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="text-sm font-medium text-gray-900">{{ index + 1 }}. {{ line.description }}</p>
            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">
              <span>{{ SOURCE_LABEL[line.source] }}</span>
              <Badge size="sm" :variant="STATUS[line.status].variant">{{ STATUS[line.status].label }}</Badge>
              <Badge v-if="line.declined_by_customer" size="sm" variant="danger">Customer declined — see Observations</Badge>
            </p>
            <p v-if="line.status === 'deferred' && line.resolution_notes" class="mt-1 text-xs text-amber-800">{{ line.resolution_notes }}</p>
          </div>
          <button
            v-if="!line.media?.length && ((isTechnician && line.source === 'technician') || (canAddComplaint && line.source === 'customer'))"
            type="button"
            class="p-2 text-gray-400 hover:text-red-600 shrink-0"
            aria-label="Remove line"
            @click="removeLine(line)"
          >
            <TrashIcon class="h-4 w-4" />
          </button>
        </div>

        <!-- 1. Faulty part -->
        <div v-if="problemMedia(line).length || line.activity_media?.length" class="mt-2">
          <p class="text-xs font-medium text-gray-600 mb-1">Faulty part</p>
          <div class="flex flex-wrap gap-2">
            <MediaStrip v-if="line.activity_media?.length" :media="line.activity_media" />
            <MediaStrip :media="problemMedia(line)" :can-delete="isTechnician && line.status !== 'deferred'" :job-card-id="jobCardId" @delete="(m) => deleteMedia(line, m)" />
          </div>
        </div>
        <div v-if="fittedMedia(line).length" class="mt-2">
          <p class="text-xs font-medium text-green-700 mb-1">New part fitted</p>
          <MediaStrip :media="fittedMedia(line)" :can-delete="isTechnician && line.status !== 'deferred'" :job-card-id="jobCardId" @delete="(m) => deleteMedia(line, m)" />
        </div>

        <!-- Parts required -->
        <div class="mt-2">
          <p v-if="line.part_requests?.length || isTechnician" class="text-xs font-medium text-gray-600">Parts required</p>
          <ul v-if="line.part_requests?.length" class="mt-1 space-y-1">
            <li v-for="part in line.part_requests" :key="part.id" class="flex flex-wrap items-center gap-2 text-sm">
              <span class="text-gray-800">{{ part.part_name }}</span>
              <span v-if="part.part_number" class="text-xs text-gray-500">{{ part.part_number }}</span>
              <span class="text-xs text-gray-500">× {{ Number(part.quantity) }}</span>
              <Badge size="sm" :variant="PART_STATUS[part.status]?.variant ?? 'default'">{{ PART_STATUS[part.status]?.label ?? part.status }}</Badge>
              <button
                v-if="isTechnician && part.status === 'draft'"
                type="button"
                class="p-1 text-gray-400 hover:text-red-600"
                aria-label="Remove part"
                @click="removePart(line, part.id)"
              >
                <TrashIcon class="h-4 w-4" />
              </button>
              <!-- Received on a GRN: the technician checks the part in person, then accepts or reports it -->
              <div v-if="part.grn" class="w-full rounded-lg border p-2" :class="part.grn.verification === 'pending' ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-gray-50'">
                <p class="text-xs text-gray-700">
                  Arrived: <b>{{ part.grn.description }}</b>
                  <template v-if="part.grn.brand || part.grn.part_number"> · {{ [part.grn.brand, part.grn.part_number].filter(Boolean).join(' ') }}</template>
                  · qty {{ part.grn.quantity }}
                </p>
                <div v-if="part.grn.photos.length && !isTechnician" class="mt-1 flex flex-wrap gap-1.5">
                  <a v-for="ph in part.grn.photos" :key="ph.id" :href="ph.url" target="_blank" class="block h-14 w-14 overflow-hidden rounded ring-1 ring-gray-200">
                    <img :src="ph.thumbnail_url" class="h-full w-full object-cover" alt="Part as received" />
                  </a>
                </div>
                <div v-if="part.grn.verification === 'pending' && isTechnician" class="mt-2 flex flex-wrap gap-2">
                  <Button size="sm" :loading="saving" @click="checkPart(part.grn.line_id, true)">Right part</Button>
                  <Button size="sm" variant="danger" @click="checking = { lineId: line.id, grnLineId: part.grn.line_id, partName: part.part_name, note: '' }">Wrong part</Button>
                </div>
                <p v-else-if="part.grn.verification === 'pending'" class="mt-1 text-xs text-amber-700">The technician is to check this part.</p>
                <p v-else-if="part.grn.verification === 'verified'" class="mt-1 text-xs text-green-700">Checked: right part.</p>
                <p v-else-if="part.grn.verification === 'wrong_part'" class="mt-1 text-xs text-red-700">Wrong part: {{ part.grn.verification_note }}</p>
              </div>
            </li>
          </ul>
          <template v-if="isTechnician && !['resolved', 'deferred'].includes(line.status)">
            <form v-if="partForms[line.id]" class="mt-2 grid grid-cols-6 gap-2" @submit.prevent="addPart(line)">
              <!-- No photo of the faulty part yet: take it with the part -->
              <div v-if="!line.can_list_parts" class="col-span-6 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1 text-xs font-medium text-primary-700"><CameraIcon class="h-4 w-4" /> Faulty part:</span>
                <MediaPicker size="sm" :kinds="['photo', 'video']" @change="(e) => choosePartEvidence(line, e)" />
                <span class="text-xs" :class="partForms[line.id]!.files.length ? 'text-green-700' : 'text-amber-700'">
                  {{ partForms[line.id]!.files.length ? `${partForms[line.id]!.files.length} added` : 'Required first' }}
                </span>
              </div>
              <input v-model="partForms[line.id]!.part_name" class="input col-span-6 sm:col-span-3" placeholder="Part, e.g. Front brake pads" />
              <input v-model="partForms[line.id]!.part_number" class="input col-span-4 sm:col-span-2" placeholder="Part no." />
              <input v-model="partForms[line.id]!.quantity" type="number" min="1" class="input col-span-2 sm:col-span-1" title="Qty" />
              <div class="col-span-6 flex gap-2">
                <Button
                  type="submit"
                  size="sm"
                  :disabled="!partForms[line.id]!.part_name.trim() || (!line.can_list_parts && !partForms[line.id]!.files.length)"
                >
                  Add part
                </Button>
                <Button type="button" size="sm" variant="secondary" @click="partForms[line.id] = null">Cancel</Button>
              </div>
            </form>
            <Button v-else type="button" size="sm" variant="secondary" class="mt-1" @click="startPart(line)">+ Part required</Button>
            <p v-if="line.part_requests?.some((p) => p.status === 'draft')" class="mt-1 text-xs text-gray-500">
              Use “Submit to SA for Parts” at the bottom to send the parts to your advisor.
            </p>
          </template>
        </div>

        <!-- Technician actions -->
        <div v-if="isTechnician" class="mt-3 flex flex-wrap items-center gap-2">
          <template v-if="line.status !== 'deferred'">
            <span class="inline-flex items-center gap-1 text-xs font-medium" :class="line.can_list_parts ? 'text-gray-700' : 'text-primary-700'">
              <CameraIcon class="h-4 w-4" /> Faulty part:
            </span>
            <MediaPicker size="sm" :kinds="['photo', 'video']" @change="(e) => upload(line, e, null)" />
            <Badge v-if="line.parts_awaited && !line.parts_arrived" size="sm" variant="info">Waiting for the new part</Badge>
            <Badge v-if="line.parts_to_verify" size="sm" variant="warning">Check the new part</Badge>
            <template v-if="line.parts_arrived">
              <span class="inline-flex items-center gap-1 text-xs font-medium" :class="fittedMedia(line).length ? 'text-green-700' : 'text-primary-700'">
                <WrenchScrewdriverIcon class="h-4 w-4" /> New part fitted:
              </span>
              <MediaPicker size="sm" :kinds="['photo', 'video']" @change="(e) => upload(line, e, 'fitted_part')" />
            </template>
            <span v-if="uploading[line.id] != null" class="text-xs text-gray-500">Uploading… {{ uploading[line.id] }}%</span>
          </template>

          <span class="ml-auto flex flex-wrap gap-2">
            <template v-if="['open', 'in_progress'].includes(line.status)">
              <Button size="sm" variant="secondary" @click="deferring = { line, notes: '' }">Defer</Button>
              <Button size="sm" :disabled="!!line.done_shortfall" :title="line.done_shortfall ?? ''" @click="setStatus(line, 'resolved')">
                Mark done
              </Button>
            </template>
            <Button v-else size="sm" variant="secondary" @click="setStatus(line, 'in_progress')">Re-open</Button>
          </span>
          <p v-if="line.done_shortfall && ['open', 'in_progress'].includes(line.status)" class="w-full text-xs text-amber-700">
            {{ line.done_shortfall }}
          </p>
        </div>

        <div v-if="deferring?.line.id === line.id" class="mt-2 space-y-2">
          <div class="flex gap-2">
            <textarea v-model="deferring.notes" rows="2" class="input flex-1" placeholder="Why is it deferred? (goes to Observations)" />
            <VoiceInput @text="(t: string) => deferring && (deferring.notes = deferring.notes ? `${deferring.notes} ${t}` : t)" />
          </div>
          <div class="flex gap-2">
            <Button size="sm" :disabled="!deferring.notes.trim()" @click="setStatus(line, 'deferred', deferring.notes.trim())">Defer</Button>
            <Button size="sm" variant="secondary" @click="deferring = null">Cancel</Button>
          </div>
        </div>
      </li>
    </ol>

    <form v-if="isTechnician || canAddComplaint" class="rounded-xl border border-dashed border-gray-300 p-3 space-y-2" @submit.prevent="addLine">
      <div class="flex gap-2">
        <input
          v-model="newLine"
          class="input flex-1"
          :placeholder="isTechnician ? 'What did you find? e.g. Front brake pads worn to 2 mm' : 'Add a customer complaint'"
        />
        <VoiceInput @text="(t: string) => (newLine = newLine ? `${newLine} ${t}` : t)" />
      </div>
      <div v-if="isTechnician" class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center gap-1 text-xs font-medium text-primary-700"><CameraIcon class="h-4 w-4" /> Faulty part:</span>
        <MediaPicker size="sm" :kinds="['photo', 'video']" @change="chooseEvidence" />
        <span class="text-xs" :class="newLineFiles.length ? 'text-green-700' : 'text-amber-700'">
          {{ newLineFiles.length ? `${newLineFiles.length} added` : 'Required' }}
        </span>
        <button v-if="newLineFiles.length" type="button" class="text-xs text-gray-500 underline" @click="newLineFiles = []">clear</button>
        <span v-if="addProgress !== null" class="text-xs text-gray-500">Uploading… {{ addProgress }}%</span>
      </div>
      <div class="flex justify-end">
        <Button type="submit" size="sm" :loading="saving" :disabled="!newLine.trim() || (isTechnician && !newLineFiles.length)">
          Add line
        </Button>
      </div>
    </form>

    <!-- Jobs recorded before Additional Issues lines (read only) -->
    <section v-if="legacyJobs.length" class="pt-2">
      <h3 class="text-sm font-semibold text-gray-900 mb-2">Earlier additional jobs</h3>
      <ul class="space-y-1 text-sm text-gray-700">
        <li v-for="job in legacyJobs" :key="job.id">
          {{ job.description }}
          <span v-if="job.part_requests?.length" class="text-xs text-gray-500">
            · parts: {{ job.part_requests.map((p) => p.part_name).join(', ') }}
          </span>
        </li>
      </ul>
    </section>

    <!-- Wrong part: say what is wrong (the coordinator returns it and gets the right one) -->
    <Modal :open="!!checking" title="Wrong part" @close="checking = null">
      <p class="text-sm text-gray-600 mb-2">{{ checking?.partName }}: what is wrong with it?</p>
      <textarea v-if="checking" v-model="checking.note" rows="2" class="input" placeholder="e.g. Rear pads sent, front needed" />
      <template #footer>
        <Button variant="secondary" @click="checking = null">Cancel</Button>
        <Button variant="danger" :loading="saving" :disabled="!checking?.note.trim()" @click="checkPart(checking!.grnLineId, false, checking!.note)">Report wrong part</Button>
      </template>
    </Modal>
  </div>
</template>
