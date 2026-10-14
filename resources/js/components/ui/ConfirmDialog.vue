<script setup lang="ts">
import Modal from './Modal.vue'
import Button from './Button.vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

interface Props {
  open: boolean
  title?: string
  message?: string
  confirmText?: string
  cancelText?: string
  variant?: 'danger' | 'warning' | 'primary'
  loading?: boolean
}

withDefaults(defineProps<Props>(), {
  title: 'Confirm Action',
  message: 'Are you sure you want to proceed?',
  confirmText: 'Confirm',
  cancelText: 'Cancel',
  variant: 'danger',
  loading: false,
})

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const variantColors = {
  danger: 'bg-red-100 text-red-600',
  warning: 'bg-amber-100 text-amber-600',
  primary: 'bg-primary-100 text-primary-600',
}

const buttonVariants = {
  danger: 'danger',
  warning: 'primary',
  primary: 'primary',
} as const
</script>

<template>
  <Modal :open="open" size="sm" @close="emit('cancel')">
    <div class="sm:flex sm:items-start">
      <div
        :class="[
          'mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full sm:mx-0 sm:h-10 sm:w-10',
          variantColors[variant],
        ]"
      >
        <ExclamationTriangleIcon class="h-6 w-6" />
      </div>
      <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
        <h3 class="text-lg font-medium text-gray-900">{{ title }}</h3>
        <div class="mt-2">
          <p class="text-sm text-gray-500">{{ message }}</p>
          <slot />
        </div>
      </div>
    </div>

    <template #footer>
      <Button variant="secondary" @click="emit('cancel')">
        {{ cancelText }}
      </Button>
      <Button :variant="buttonVariants[variant]" :loading="loading" @click="emit('confirm')">
        {{ confirmText }}
      </Button>
    </template>
  </Modal>
</template>
