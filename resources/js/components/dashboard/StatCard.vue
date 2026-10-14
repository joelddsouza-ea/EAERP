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
    blue: 'bg-blue-50 text-blue-600',
    green: 'bg-green-50 text-green-600',
    amber: 'bg-amber-50 text-amber-600',
    red: 'bg-red-50 text-red-600',
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
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <div class="flex items-center justify-between">
      <div class="flex-1">
        <p class="text-sm font-medium text-gray-500">{{ title }}</p>
        <div v-if="loading" class="mt-2 h-8 w-24 bg-gray-200 rounded animate-pulse" />
        <p v-else class="mt-2 text-3xl font-bold text-gray-900">{{ value }}</p>
        <p v-if="trend" :class="['mt-1 text-sm', trendColor]">
          {{ trend.value >= 0 ? '+' : '' }}{{ trend.value }}% {{ trend.label }}
        </p>
      </div>
      <div
        v-if="icon"
        :class="['flex-shrink-0 p-3 rounded-lg', colorClasses]"
      >
        <component :is="icon" class="h-6 w-6" />
      </div>
    </div>
  </div>
</template>
