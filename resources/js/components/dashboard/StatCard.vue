<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  title: string
  value: string | number
  icon?: any
  trend?: {
    value: number
    label: string
  }
  loading?: boolean
  color?: 'blue' | 'green' | 'amber' | 'red' | 'purple'
}

const props = withDefaults(defineProps<Props>(), {
  loading: false,
  color: 'blue',
})

const colorClasses = computed(() => {
  const colors = {
    blue: 'bg-gray-100 text-gray-700',
    green: 'bg-green-50 text-green-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-primary-50 text-primary-600',
    purple: 'bg-purple-50 text-purple-600',
  }
  return colors[props.color]
})

const trendColor = computed(() => {
  if (!props.trend) return ''
  return props.trend.value >= 0 ? 'text-green-600' : 'text-red-600'
})
</script>

<template>
  <div class="relative overflow-hidden bg-white rounded-2xl shadow-sm ring-1 ring-gray-200/80 p-4 sm:p-5">
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0 flex-1">
        <p class="text-xs sm:text-sm font-medium text-gray-500 leading-tight">{{ title }}</p>
        <div v-if="loading" class="mt-2 h-7 w-20 bg-gray-200 rounded animate-pulse" />
        <p v-else class="mt-1.5 text-xl sm:text-3xl font-bold tracking-tight text-gray-900 truncate">{{ value }}</p>
        <p v-if="trend" :class="['mt-1 text-sm', trendColor]">
          {{ trend.value >= 0 ? '+' : '' }}{{ trend.value }}% {{ trend.label }}
        </p>
      </div>
      <div v-if="icon" :class="['flex-shrink-0 p-2 sm:p-3 rounded-xl', colorClasses]">
        <component :is="icon" class="h-5 w-5 sm:h-6 sm:w-6" />
      </div>
    </div>
  </div>
</template>
