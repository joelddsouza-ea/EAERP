<script setup lang="ts">
import { ref, computed } from 'vue'
import { PlayIcon, XMarkIcon, EyeSlashIcon } from '@heroicons/vue/20/solid'
import type { JobCardMedia } from '@/api/types'
import apiClient from '@/api/client'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'

interface Props {
  media: JobCardMedia[]
  canDelete?: boolean
  // Given when the photos can appear in the customer's gallery: advisors may hide one
  jobCardId?: number
}

const props = withDefaults(defineProps<Props>(), { canDelete: false })
const emit = defineEmits<{ delete: [media: JobCardMedia] }>()
const { can } = usePermissions()
const toast = useToast()

const viewing = ref<JobCardMedia | null>(null)
const canHide = computed(() => !!props.jobCardId && can('workshop.estimates.manage'))

async function toggleCustomer(m: JobCardMedia) {
  const visible = !m.customer_visible
  try {
    await apiClient.patch(`/workshop/job-cards/${props.jobCardId}/media/${m.id}`, { customer_visible: visible })
    m.customer_visible = visible
    toast.success(visible ? 'Shown to the customer' : 'Hidden from the customer')
  } catch (err) {
    toast.apiError(err)
  }
}
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
        <img v-if="m.thumbnail_url" :src="m.thumbnail_url" alt="" class="h-full w-full object-cover" :class="jobCardId && !m.customer_visible ? 'opacity-50' : ''" />
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
      <span
        v-if="jobCardId && !m.customer_visible && !canHide"
        class="absolute bottom-0.5 left-0.5 rounded bg-white/90 p-0.5"
        title="Not shown to the customer"
      >
        <EyeSlashIcon class="h-3.5 w-3.5 text-gray-600" />
      </span>
    </div>

    <Modal :open="!!viewing" :title="viewing?.description ?? ''" size="lg" @close="viewing = null">
      <div v-if="viewing" class="flex justify-center bg-black rounded-lg">
        <video v-if="viewing.media_type === 'video'" :src="viewing.url" controls playsinline class="max-h-[70vh] w-full" />
        <img v-else :src="viewing.url" alt="" class="max-h-[70vh] object-contain" />
      </div>
      <label v-if="viewing && canHide" class="mt-3 flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" class="h-4 w-4 rounded border-gray-300" :checked="viewing.customer_visible" @change="toggleCustomer(viewing!)" />
        Show in the customer's photo gallery
      </label>
    </Modal>
  </div>
</template>
