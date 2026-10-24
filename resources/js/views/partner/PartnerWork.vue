<script setup lang="ts">
/**
 * One car at the outside partner: photos / videos of the work (camera or gallery), then Done.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'
import { partnerApi, type OutsideWork } from '@/api/outsideWork.api'
import type { JobSheetItem } from '@/api/types'
import { prepareMedia } from '@/components/jobsheet/media'
import MediaPicker from '@/components/media/MediaPicker.vue'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const id = Number(route.params.id)
const work = ref<OutsideWork | null>(null)
const busy = ref(false)
const progress = ref('')
const note = ref('')
const open = computed(() => work.value?.status === 'sent')

async function load() {
  try {
    work.value = (await partnerApi.work(id)).data.data
  } catch (err) {
    toast.apiError(err)
    router.push('/partner')
  }
}

async function onFiles(e: Event) {
  const files = Array.from((e.target as HTMLInputElement).files ?? [])
  ;(e.target as HTMLInputElement).value = ''
  if (!files.length) return
  busy.value = true
  try {
    for (const [i, file] of files.entries()) {
      progress.value = files.length > 1 ? `Uploading ${i + 1} of ${files.length}…` : 'Uploading…'
      const prepared = await prepareMedia(file)
      work.value = (await partnerApi.uploadMedia(id, prepared.file, prepared.fileName, {
        poster: prepared.poster, duration_seconds: prepared.durationSeconds,
      })).data.data
    }
    toast.success('Uploaded')
  } catch (err: any) {
    err?.response ? toast.apiError(err) : toast.error('Upload failed', err?.message)
  } finally {
    busy.value = false
    progress.value = ''
  }
}

// The washing points (as the technician's job sheet has them)
const itemBusy = ref<number | null>(null)
async function addItemMedia(itemId: number, e: Event) {
  const files = Array.from((e.target as HTMLInputElement).files ?? [])
  ;(e.target as HTMLInputElement).value = ''
  if (!files.length || !work.value) return
  itemBusy.value = itemId
  try {
    for (const file of files) {
      const prepared = await prepareMedia(file)
      const item = (await partnerApi.itemMedia(id, itemId, prepared.file, prepared.fileName, {
        poster: prepared.poster, duration_seconds: prepared.durationSeconds,
      })).data.data
      replaceItem(item)
    }
  } catch (err: any) {
    err?.response ? toast.apiError(err) : toast.error('Upload failed', err?.message)
  } finally {
    itemBusy.value = null
  }
}
async function markItem(itemId: number, status: 'done' | 'not_applicable' | 'pending') {
  itemBusy.value = itemId
  try {
    replaceItem((await partnerApi.updateItem(id, itemId, { status })).data.data)
  } catch (err) {
    toast.apiError(err)
  } finally {
    itemBusy.value = null
  }
}
function replaceItem(item: JobSheetItem) {
  if (!work.value?.items) return
  const i = work.value.items.findIndex((x) => x.id === item.id)
  if (i >= 0) work.value.items[i] = item
}
const itemsOpen = computed(() => (work.value?.items ?? []).filter((i) => i.status === 'pending').length)

async function done() {
  busy.value = true
  try {
    const res = (await partnerApi.done(id, note.value || null)).data
    work.value = res.data
    toast.success(res.message ?? 'Done')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-2xl">
    <button type="button" class="mb-3 inline-flex items-center gap-1 text-sm text-gray-600" @click="router.push('/partner')">
      <ArrowLeftIcon class="h-4 w-4" /> My cars
    </button>

    <div v-if="!work" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <template v-else>
      <Card class="mb-4">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ work.kind_label }} · {{ work.job?.job_number }}</p>
            <h1 class="text-2xl font-bold tracking-wide text-gray-900">{{ work.job?.registration_no }}</h1>
            <p class="text-sm text-gray-600">{{ work.job?.car }}</p>
          </div>
          <Badge :variant="open ? 'warning' : 'success'">{{ open ? 'To do' : work.status === 'done' ? 'Done' : 'Collected' }}</Badge>
        </div>
        <p v-if="work.note" class="mt-3 rounded bg-amber-50 p-2 text-sm text-amber-900">{{ work.note }}</p>
      </Card>

      <!-- The washing points, as on the technician's job sheet -->
      <Card v-if="work.items?.length" class="mb-4">
        <template #header><h3 class="text-lg font-semibold">What to do</h3></template>
        <ul class="space-y-3">
          <li v-for="item in work.items" :key="item.id" class="rounded-lg border p-3" :class="item.status === 'done' ? 'border-green-200 bg-green-50/50' : item.status === 'not_applicable' ? 'border-gray-200 bg-gray-50' : 'border-gray-200'">
            <div class="flex items-start justify-between gap-2">
              <div>
                <p class="font-medium text-gray-900">{{ item.item_name }}</p>
                <p v-if="item.media_shortfall" class="text-xs text-red-600">{{ item.media_shortfall }}</p>
                <p v-else-if="item.status === 'done'" class="text-xs text-green-700">Done</p>
              </div>
              <div v-if="open" class="flex shrink-0 gap-2">
                <Button v-if="item.status !== 'done'" size="sm" :loading="itemBusy === item.id" :disabled="!!item.media_shortfall" @click="markItem(item.id, 'done')">Done</Button>
                <Button v-else size="sm" variant="ghost" @click="markItem(item.id, 'pending')">Undo</Button>
                <Button v-if="item.na_allowed && item.status === 'pending'" size="sm" variant="secondary" @click="markItem(item.id, 'not_applicable')">N/A</Button>
              </div>
            </div>
            <div v-if="item.media?.length" class="mt-2 flex flex-wrap gap-1.5">
              <a v-for="m in item.media" :key="m.id" :href="m.url" target="_blank" class="block h-14 w-14 overflow-hidden rounded ring-1 ring-gray-200">
                <img v-if="m.thumbnail_url" :src="m.thumbnail_url" class="h-full w-full object-cover" alt="" />
                <span v-else class="flex h-full w-full items-center justify-center text-[10px] text-gray-500">video</span>
              </a>
            </div>
            <MediaPicker
              v-if="open && item.media_requirement !== 'none' && item.status !== 'not_applicable'"
              class="mt-2"
              size="sm"
              :kinds="item.media_requirement === 'photo' ? ['photo'] : item.media_requirement === 'video' ? ['video'] : ['photo', 'video']"
              gallery
              :disabled="itemBusy === item.id"
              @change="addItemMedia(item.id, $event)"
            />
          </li>
        </ul>
      </Card>

      <Card class="mb-4">
        <template #header><h3 class="text-lg font-semibold">{{ work.items?.length ? 'More photos & videos (optional)' : 'Photos &amp; videos of your work' }}</h3></template>
        <div v-if="work.media.length" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
          <a v-for="m in work.media" :key="m.id" :href="m.url" target="_blank" class="block aspect-square overflow-hidden rounded-lg ring-1 ring-gray-200">
            <img v-if="m.thumbnail_url" :src="m.thumbnail_url" class="h-full w-full object-cover" alt="Work photo" />
            <span v-else class="flex h-full w-full items-center justify-center text-xs text-gray-500">video</span>
          </a>
        </div>
        <p v-else class="text-sm text-gray-500">None yet. Before / after photos and a short video help the customer.</p>

        <template v-if="open">
          <MediaPicker class="mt-3" :kinds="['photo', 'video']" gallery :disabled="busy" @change="onFiles" />
          <p v-if="progress" class="mt-1 text-center text-xs text-gray-500">{{ progress }}</p>
        </template>
      </Card>

      <Card v-if="open">
        <label class="block text-sm">
          <span class="text-gray-600">Note for the workshop (optional)</span>
          <input v-model="note" class="input mt-1" maxlength="500" placeholder="e.g. Alignment corrected, front left tyre worn" />
        </label>
        <Button class="mt-3" size="lg" block :loading="busy && !progress" :disabled="work.items?.length ? itemsOpen > 0 : !work.media.length" @click="done">Done</Button>
        <p v-if="work.items?.length && itemsOpen" class="mt-1 text-center text-xs text-gray-500">{{ itemsOpen }} point(s) still to do.</p>
        <p v-else-if="!work.items?.length && !work.media.length" class="mt-1 text-center text-xs text-gray-500">Add at least one photo or video first.</p>
      </Card>
      <p v-else-if="work.partner_note" class="text-sm text-gray-600">Your note: {{ work.partner_note }}</p>
    </template>
  </div>
</template>
