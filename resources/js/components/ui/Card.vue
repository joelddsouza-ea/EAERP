<script setup lang="ts">
interface Props {
  padding?: boolean
}

withDefaults(defineProps<Props>(), {
  padding: true,
})
</script>

<template>
  <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-200/80">
    <div v-if="$slots.header" class="px-4 py-3 sm:px-6 sm:py-4 border-b border-gray-100">
      <slot name="header" />
    </div>
    <div :class="padding ? 'p-4 sm:p-6' : ''">
      <slot />
    </div>
    <div v-if="$slots.footer" class="px-4 py-3 sm:px-6 sm:py-4 border-t border-gray-100 bg-gray-50/70 rounded-b-2xl">
      <slot name="footer" />
    </div>
  </div>
</template>
