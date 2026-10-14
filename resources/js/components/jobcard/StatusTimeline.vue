<script setup lang="ts">
import { computed } from 'vue'
import { format, formatDistanceStrict } from 'date-fns'
import type { StatusHistoryEntry } from '@/api/types'
import { statusLabel, statusColor } from '@/constants/jobCardStatus'

interface Props {
  history: StatusHistoryEntry[]
}

const props = defineProps<Props>()

// Oldest first, with the time spent in each status
const entries = computed(() => {
  const sorted = [...props.history].sort((a, b) => a.changed_at.localeCompare(b.changed_at))
  return sorted.map((entry, i) => {
    const next = sorted[i + 1]
    return {
      ...entry,
      duration: next ? formatDistanceStrict(new Date(entry.changed_at), new Date(next.changed_at)) : null,
      // Moving back (QA fail, etc.) is worth highlighting
      backwards: entry.from_status === 'post_work_trial' || entry.from_status === 'wash_pending'
        ? entry.to_status === 'work_in_progress'
        : false,
    }
  })
})
</script>

<template>
  <ol class="relative border-l border-gray-200 ml-2">
    <li v-for="(entry, i) in entries" :key="i" class="mb-4 ml-4 last:mb-0">
      <span
        class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full ring-2 ring-white"
        :style="{ backgroundColor: statusColor(entry.to_status) }"
      />
      <div class="flex flex-wrap items-baseline justify-between gap-x-2">
        <p class="text-sm font-medium" :class="entry.backwards ? 'text-red-700' : 'text-gray-900'">
          {{ statusLabel(entry.to_status) }}
          <span v-if="entry.backwards" class="text-xs font-normal">(sent back)</span>
          <span v-if="entry.override" class="ml-1 text-xs font-normal text-amber-700">override</span>
        </p>
        <time class="text-xs text-gray-500">{{ format(new Date(entry.changed_at), 'dd MMM, h:mm a') }}</time>
      </div>
      <p v-if="entry.changed_by" class="text-xs text-gray-500">by {{ entry.changed_by }}</p>
      <p v-if="entry.notes" class="mt-1 text-xs text-gray-700 bg-gray-50 rounded px-2 py-1">{{ entry.notes }}</p>
      <p v-if="entry.duration" class="mt-1 text-xs text-gray-400">{{ entry.duration }} in this status</p>
    </li>
  </ol>
</template>
