<script setup lang="ts">
import { ref } from 'vue'
import { PlayIcon, XMarkIcon } from '@heroicons/vue/20/solid'
import type { JobCardMedia } from '@/api/types'
import Modal from '@/components/ui/Modal.vue'

interface Props {
  media: JobCardMedia[]
  canDelete?: boolean
}

withDefaults(defineProps<Props>(), { canDelete: false })
const emit = defineEmits<{ delete: [media: JobCardMedia] }>()

const viewing = ref<JobCardMedia | null>(null)
</script>

<template>
  <div v-if="media.length" class="flex flex-wrap gap-2">
    <div v-for="m in media" :key="m.id" class="relative">
      <button
        type="button"
        class="h-16 w-16 rounded-lg overflow-hidden bg-gray-100 border border-gray-200 flex items-center justify-center"
        :aria-label="`View ${m.media_type}`"
        @click="viewing = m"
      >
        <img v-if="m.thumbnail_url" :src="m.thumbnail_url" alt="" class="h-full w-full object-cover" />
        <span v-else class="text-xs text-gray-500">{{ m.media_type }}</span>
        <span v-if="m.media_type === 'video'" class="absolute inset-0 flex items-center justify-center">
          <PlayIcon class="h-6 w-6 text-white drop-shadow" />
        </span>
      </button>
      <button
        v-if="canDelete"
        type="button"
        class="absolute -top-2 -right-2 h-6 w-6 rounded-full bg-white border border-gray-300 shadow flex items-center justify-center"
        aria-label="Delete"
        @click="emit('delete', m)"
      >
        <XMarkIcon class="h-4 w-4 text-gray-600" />
      </button>
    </div>

    <Modal :open="!!viewing" :title="viewing?.description ?? ''" size="lg" @close="viewing = null">
      <div v-if="viewing" class="flex justify-center bg-black rounded-lg">
        <video v-if="viewing.media_type === 'video'" :src="viewing.url" controls playsinline class="max-h-[70vh] w-full" />
        <img v-else :src="viewing.url" alt="" class="max-h-[70vh] object-contain" />
      </div>
    </Modal>
  </div>
</template>
