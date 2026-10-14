<script setup lang="ts">
import { computed } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js'
import type { JobCardStatus } from '@/api/types'
import { statusLabel, statusColor } from '@/constants/jobCardStatus'

ChartJS.register(ArcElement, Tooltip, Legend)

interface Props {
  data: Partial<Record<JobCardStatus, number>>
  loading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  loading: false,
})


const chartData = computed(() => {
  const labels: string[] = []
  const values: number[] = []
  const colors: string[] = []

  Object.entries(props.data).forEach(([status, count]) => {
    if (count && count > 0) {
      labels.push(statusLabel(status))
      values.push(count)
      colors.push(statusColor(status))
    }
  })

  return {
    labels,
    datasets: [
      {
        data: values,
        backgroundColor: colors,
        borderWidth: 0,
      },
    ],
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'right' as const,
      labels: {
        usePointStyle: true,
        padding: 12,
        font: {
          size: 12,
        },
      },
    },
  },
  cutout: '65%',
}

const total = computed(() => {
  return Object.values(props.data).reduce((sum: number, count) => sum + (count ?? 0), 0)
})
</script>

<template>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">Jobs by Status</h3>

    <div v-if="loading" class="h-64 flex items-center justify-center">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600" />
    </div>

    <div v-else-if="total === 0" class="h-64 flex items-center justify-center text-gray-500">
      No job cards found
    </div>

    <div v-else class="h-64 relative">
      <Doughnut :data="chartData" :options="chartOptions" />
      <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
        <div class="text-center">
          <p class="text-3xl font-bold text-gray-900">{{ total }}</p>
          <p class="text-sm text-gray-500">Total</p>
        </div>
      </div>
    </div>
  </div>
</template>
