<script setup lang="ts">
import { computed } from 'vue'
import {
  CheckCircleIcon,
  ExclamationCircleIcon,
  ExclamationTriangleIcon,
  InformationCircleIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import type { Toast } from '@/stores/ui.store'

interface Props {
  toast: Toast
}

const props = defineProps<Props>()

const emit = defineEmits<{
  dismiss: []
}>()

const icons = {
  success: CheckCircleIcon,
  error: ExclamationCircleIcon,
  warning: ExclamationTriangleIcon,
  info: InformationCircleIcon,
}

const colors = {
  success: 'text-green-500',
  error: 'text-red-500',
  warning: 'text-amber-500',
  info: 'text-blue-500',
}

const icon = computed(() => icons[props.toast.type])
const iconColor = computed(() => colors[props.toast.type])
</script>

<template>
  <div
    class="max-w-sm w-full bg-white shadow-lg rounded-lg pointer-events-auto ring-1 ring-black ring-opacity-5 overflow-hidden"
  >
    <div class="p-4">
      <div class="flex items-start">
        <div class="flex-shrink-0">
          <component :is="icon" :class="['h-6 w-6', iconColor]" />
        </div>
        <div class="ml-3 w-0 flex-1 pt-0.5">
          <p class="text-sm font-medium text-gray-900">{{ toast.title }}</p>
          <p v-if="toast.message" class="mt-1 text-sm text-gray-500">
            {{ toast.message }}
          </p>
        </div>
        <div class="ml-4 flex-shrink-0 flex">
          <button
            type="button"
            class="bg-white rounded-md inline-flex text-gray-400 hover:text-gray-500 focus:outline-none"
            @click="emit('dismiss')"
          >
            <XMarkIcon class="h-5 w-5" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
