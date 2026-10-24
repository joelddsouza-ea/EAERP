<script setup lang="ts">
/**
 * The outside partner's home (washing, wheel alignment & balancing): the cars the workshop
 * sent them. Tap a car to add photos/videos and mark it done.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { partnerApi, type OutsideWork } from '@/api/outsideWork.api'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'

const router = useRouter()
const toast = useToast()
const works = ref<OutsideWork[] | null>(null)
let timer: ReturnType<typeof setInterval> | undefined

async function load() {
  try {
    works.value = (await partnerApi.works()).data.data
  } catch (err) {
    toast.apiError(err)
    works.value ??= []
  }
}

const STATUS: Record<string, { label: string; variant: 'warning' | 'info' | 'success' }> = {
  sent: { label: 'To do', variant: 'warning' },
  done: { label: 'Done', variant: 'info' },
  returned: { label: 'Collected', variant: 'success' },
}

onMounted(() => {
  load()
  timer = setInterval(load, 60_000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div class="mx-auto max-w-2xl">
    <h1 class="mb-1 text-2xl font-bold text-gray-900">My cars</h1>
    <p class="mb-4 text-sm text-gray-500">Cars the workshop sent you. Add photos and videos of your work, then tap Done.</p>

    <div v-if="works === null" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <p v-else-if="!works.length" class="rounded-lg bg-white p-6 text-center text-sm text-gray-500">No cars right now.</p>

    <div v-else class="space-y-3">
      <Card v-for="w in works" :key="w.id" class="cursor-pointer active:bg-gray-50" @click="router.push(`/partner/works/${w.id}`)">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="text-xl font-bold tracking-wide text-gray-900">{{ w.job?.registration_no }}</p>
            <p class="text-sm text-gray-600">{{ w.job?.car }} · {{ w.kind_label }}</p>
            <p v-if="w.note" class="mt-1 text-sm text-gray-700">{{ w.note }}</p>
          </div>
          <Badge :variant="STATUS[w.status]?.variant ?? 'info'">{{ STATUS[w.status]?.label ?? w.status }}</Badge>
        </div>
        <p class="mt-2 text-xs text-gray-500">
          Sent {{ w.sent_at ? new Date(w.sent_at).toLocaleString('en-IN') : '' }} · {{ w.media.length }} photo(s)/video(s)
        </p>
      </Card>
    </div>
  </div>
</template>
