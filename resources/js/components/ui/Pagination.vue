<script setup lang="ts">
import { computed } from 'vue'
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/20/solid'

interface Props {
  currentPage: number
  lastPage: number
  from: number
  to: number
  total: number
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'page-change': [page: number]
}>()

const hasNext = computed(() => props.currentPage < props.lastPage)
const hasPrev = computed(() => props.currentPage > 1)

const pageNumbers = computed(() => {
  const pages: (number | string)[] = []
  const maxVisible = 7

  if (props.lastPage <= maxVisible) {
    for (let i = 1; i <= props.lastPage; i++) {
      pages.push(i)
    }
  } else {
    pages.push(1)

    if (props.currentPage > 3) {
      pages.push('...')
    }

    const start = Math.max(2, props.currentPage - 1)
    const end = Math.min(props.lastPage - 1, props.currentPage + 1)

    for (let i = start; i <= end; i++) {
      pages.push(i)
    }

    if (props.currentPage < props.lastPage - 2) {
      pages.push('...')
    }

    pages.push(props.lastPage)
  }

  return pages
})

function goToPage(page: number | string) {
  if (typeof page === 'number' && page !== props.currentPage) {
    emit('page-change', page)
  }
}

function prevPage() {
  if (hasPrev.value) {
    emit('page-change', props.currentPage - 1)
  }
}

function nextPage() {
  if (hasNext.value) {
    emit('page-change', props.currentPage + 1)
  }
}
</script>

<template>
  <div class="flex items-center justify-between">
    <div class="text-sm text-gray-500">
      Showing <span class="font-medium">{{ from }}</span> to
      <span class="font-medium">{{ to }}</span> of
      <span class="font-medium">{{ total }}</span> results
    </div>

    <nav class="flex items-center gap-1">
      <button
        type="button"
        :disabled="!hasPrev"
        class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
        @click="prevPage"
      >
        <ChevronLeftIcon class="h-5 w-5" />
      </button>

      <button
        v-for="page in pageNumbers"
        :key="page"
        type="button"
        :disabled="page === '...'"
        :class="[
          'min-w-[2.25rem] px-3 py-2 text-sm rounded-lg',
          page === currentPage
            ? 'bg-primary-600 text-white font-medium'
            : page === '...'
            ? 'text-gray-400 cursor-default'
            : 'text-gray-700 hover:bg-gray-100',
        ]"
        @click="goToPage(page)"
      >
        {{ page }}
      </button>

      <button
        type="button"
        :disabled="!hasNext"
        class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
        @click="nextPage"
      >
        <ChevronRightIcon class="h-5 w-5" />
      </button>
    </nav>
  </div>
</template>
