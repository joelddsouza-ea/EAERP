<script setup lang="ts">
/**
 * Under a job-sheet section: "Send update" once the section is finished, and for an outside
 * section (washing) the outside partner — "Send to washing" after the trial drive, the photos /
 * videos the washer sent on WhatsApp (from the gallery), "Car is back".
 */
import { ref, computed } from 'vue'
import { outsideWorkApi, type OutsideWork, type OutsideKind } from '@/api/outsideWork.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import SendUpdateButton from '@/components/jobcard/SendUpdateButton.vue'
import { prepareMedia } from './media'
import MediaPicker from '@/components/media/MediaPicker.vue'

const props = defineProps<{
  jobCardId: number
  sheetId: number
  section: { name: string; outside_kind?: OutsideKind | null; resolved?: boolean }
  /** Every section before this one is done (the trial drive comes before washing) */
  previousDone: boolean
  works: OutsideWork[]
  partners: { id: number; name: string }[]
  kindLabel: string
  /** May fill the sheet now (outside partner actions) */
  canWork: boolean
  /** May ask for a customer update (the job's technician, also after submitting) */
  canUpdate: boolean
}>()
const emit = defineEmits<{ changed: [] }>()
const { can } = usePermissions()
const toast = useToast()

const work = computed(() => props.works.filter((w) => w.kind === props.section.outside_kind && w.status !== 'cancelled').at(-1) ?? null)
const away = computed(() => !!work.value && ['sent', 'done'].includes(work.value.status))
const canSend = computed(() => props.canWork && can('workshop.outside-work.send'))

const choosing = ref(false)
const partnerId = ref<number | null>(null)
const note = ref('')
const busy = ref(false)

async function run(call: () => Promise<unknown>, message: string) {
  busy.value = true
  try {
    await call()
    toast.success(message)
    choosing.value = false
    emit('changed')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

function send() {
  if (!partnerId.value || !props.section.outside_kind) return
  run(() => outsideWorkApi.send(props.jobCardId, { kind: props.section.outside_kind!, service_partner_id: partnerId.value!, note: note.value || null }), 'Sent to the partner')
}

async function onFiles(e: Event) {
  const files = Array.from((e.target as HTMLInputElement).files ?? [])
  ;(e.target as HTMLInputElement).value = ''
  if (!work.value || !files.length) return
  busy.value = true
  try {
    for (const file of files) {
      const prepared = await prepareMedia(file)
      await outsideWorkApi.uploadMedia(props.jobCardId, work.value.id, prepared.file, prepared.fileName, {
        poster: prepared.poster, duration_seconds: prepared.durationSeconds,
      })
    }
    toast.success(files.length === 1 ? 'Uploaded' : `${files.length} uploaded`)
    emit('changed')
  } catch (err: any) {
    err?.response ? toast.apiError(err) : toast.error('Upload failed', err?.message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="mb-2 space-y-2">
    <!-- Outside partner (washing) -->
    <div v-if="section.outside_kind && (partners.length || work)" class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm">
      <template v-if="!work || work.status === 'cancelled'">
        <p class="text-sky-900">{{ kindLabel }} by an outside partner{{ previousDone ? '' : ' — after the trial drive' }}.</p>
        <Button v-if="canSend" class="mt-2" size="sm" :disabled="!previousDone" @click="choosing = true; partnerId = partners[0]?.id ?? null">
          Send to {{ kindLabel.toLowerCase() }}
        </Button>
      </template>
      <template v-else>
        <div class="flex flex-wrap items-center gap-2">
          <Badge size="sm" :variant="work.status === 'returned' ? 'success' : work.status === 'done' ? 'info' : 'warning'">
            {{ work.status === 'sent' ? 'At the partner' : work.status === 'done' ? 'Partner done' : 'Car is back' }}
          </Badge>
          <span class="text-sky-900">{{ work.partner?.name }}</span>
          <a v-if="work.partner_whatsapp_url && work.status === 'sent'" :href="work.partner_whatsapp_url" target="_blank" rel="noopener" class="text-xs text-green-700 underline">WhatsApp the partner</a>
        </div>
        <p v-if="work.partner_note" class="mt-1 text-xs text-gray-600">Partner: {{ work.partner_note }}</p>
        <div v-if="work.media.length" class="mt-2 flex flex-wrap gap-1.5">
          <a v-for="m in work.media" :key="m.id" :href="m.url" target="_blank" class="block h-12 w-12 overflow-hidden rounded ring-1 ring-gray-200">
            <img v-if="m.thumbnail_url" :src="m.thumbnail_url" class="h-full w-full object-cover" alt="Partner's photo" />
            <span v-else class="flex h-full w-full items-center justify-center text-[10px] text-gray-500">video</span>
          </a>
        </div>
        <div v-if="canSend && away" class="mt-2 flex flex-wrap gap-2">
          <!-- What the washer sent on WhatsApp: from the gallery -->
          <MediaPicker size="sm" :kinds="['photo', 'video']" gallery :disabled="busy" @change="onFiles" />
          <Button size="sm" :loading="busy" @click="run(() => outsideWorkApi.returned(jobCardId, work!.id), 'Car is back')">Car is back</Button>
          <Button v-if="work.status === 'sent'" size="sm" variant="ghost" :disabled="busy" @click="run(() => outsideWorkApi.cancel(jobCardId, work!.id), 'Cancelled')">Cancel</Button>
        </div>
      </template>
    </div>

    <!-- Finished section: the customer can be told -->
    <div v-if="section.resolved && canUpdate" class="flex justify-end">
      <SendUpdateButton :job-card-id="jobCardId" kind="section" :sheet-id="sheetId" :section="section.name" :label="`Send update: ${section.name}`" />
    </div>

    <Modal :open="choosing" :title="`Send to ${kindLabel.toLowerCase()}`" @close="choosing = false">
      <div class="space-y-3 text-sm">
        <label class="block">
          <span class="text-gray-600">Partner</span>
          <select v-model="partnerId" class="input mt-1">
            <option v-for="p in partners" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </label>
        <label class="block">
          <span class="text-gray-600">Note for the partner (optional)</span>
          <input v-model="note" class="input mt-1" maxlength="500" placeholder="e.g. Full wash and vacuum" />
        </label>
        <p class="text-xs text-gray-500">The partner sees the car in their login and uploads photos and videos. Tap "Car is back" when it returns, then finish this section.</p>
      </div>
      <template #footer>
        <Button variant="secondary" @click="choosing = false">Cancel</Button>
        <Button :loading="busy" :disabled="!partnerId" @click="send">Send</Button>
      </template>
    </Modal>
  </div>
</template>
