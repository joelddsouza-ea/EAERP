<script setup lang="ts">
import { FolderIcon } from '@heroicons/vue/24/outline'

interface Props {
  title?: string
  description?: string
  icon?: any
}

withDefaults(defineProps<Props>(), {
  title: 'No data',
  description: 'No items found.',
})
</script>

<template>
  <div class="text-center py-12">
    <component
      :is="icon || FolderIcon"
      class="mx-auto h-12 w-12 text-gray-400"
    />
    <h3 class="mt-4 text-sm font-semibold text-gray-900">{{ title }}</h3>
    <p class="mt-1 text-sm text-gray-500">{{ description }}</p>
    <div v-if="$slots.action" class="mt-6">
      <slot name="action" />
    </div>
  </div>
</template>
