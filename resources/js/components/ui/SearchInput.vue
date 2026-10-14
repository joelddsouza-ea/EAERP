<script setup lang="ts">
import { ref, watch } from 'vue'
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/20/solid'
import { useDebounceFn } from '@vueuse/core'

interface Props {
  modelValue?: string
  placeholder?: string
  debounce?: number
}

const props = withDefaults(defineProps<Props>(), {
  placeholder: 'Search...',
  debounce: 300,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  search: [value: string]
}>()

const localValue = ref(props.modelValue || '')

const debouncedSearch = useDebounceFn((value: string) => {
  emit('search', value)
}, props.debounce)

watch(
  () => props.modelValue,
  (newValue) => {
    localValue.value = newValue || ''
  }
)

function handleInput(event: Event) {
  const value = (event.target as HTMLInputElement).value
  localValue.value = value
  emit('update:modelValue', value)
  debouncedSearch(value)
}

function clear() {
  localValue.value = ''
  emit('update:modelValue', '')
  emit('search', '')
}
</script>

<template>
  <div class="relative">
    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
      <MagnifyingGlassIcon class="h-5 w-5 text-gray-400" />
    </div>
    <input
      type="text"
      :value="localValue"
      :placeholder="placeholder"
      class="block w-full pl-10 pr-10 py-2 text-sm border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
      @input="handleInput"
    />
    <button
      v-if="localValue"
      type="button"
      class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-500"
      @click="clear"
    >
      <XMarkIcon class="h-5 w-5" />
    </button>
  </div>
</template>
