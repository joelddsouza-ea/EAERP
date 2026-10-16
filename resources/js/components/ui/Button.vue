<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  type?: 'button' | 'submit' | 'reset'
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost' | 'link' | 'dark'
  size?: 'sm' | 'md' | 'lg'
  disabled?: boolean
  loading?: boolean
  block?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  type: 'button',
  variant: 'primary',
  size: 'md',
  disabled: false,
  loading: false,
  block: false,
})

const classes = computed(() => {
  const base =
    'inline-flex items-center justify-center gap-1.5 font-semibold rounded-lg select-none transition-all duration-150 active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:active:scale-100'

  const variants = {
    primary: 'bg-primary-600 text-white shadow-sm hover:bg-primary-700 focus-visible:ring-primary-500',
    secondary: 'bg-white text-gray-800 border border-gray-300 shadow-sm hover:bg-gray-50 hover:border-gray-400 focus-visible:ring-primary-500',
    // Destructive actions read differently from the red brand buttons
    danger: 'bg-white text-red-700 border border-red-300 shadow-sm hover:bg-red-50 focus-visible:ring-red-500',
    dark: 'bg-gray-800 text-white shadow-sm hover:bg-gray-900 focus-visible:ring-gray-500',
    ghost: 'text-gray-700 hover:bg-gray-100 focus-visible:ring-primary-500',
    link: 'text-primary-600 hover:text-primary-700 underline focus-visible:ring-primary-500',
  }

  // Phones get 40–44px tall targets; desktop keeps the compact sizes
  const sizes = {
    sm: 'px-3 py-2 sm:py-1.5 text-sm',
    md: 'px-4 py-2.5 sm:py-2 text-sm',
    lg: 'px-6 py-3 text-base',
  }

  return [
    base,
    variants[props.variant],
    sizes[props.size],
    props.block ? 'w-full' : '',
  ].join(' ')
})
</script>

<template>
  <button
    :type="type"
    :class="classes"
    :disabled="disabled || loading"
  >
    <svg
      v-if="loading"
      class="animate-spin -ml-0.5 h-4 w-4"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
    >
      <circle
        class="opacity-25"
        cx="12"
        cy="12"
        r="10"
        stroke="currentColor"
        stroke-width="4"
      />
      <path
        class="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
      />
    </svg>
    <slot />
  </button>
</template>
