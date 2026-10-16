<script setup lang="ts" generic="T">
import { computed } from 'vue'
import { ChevronRightIcon } from '@heroicons/vue/20/solid'
import Spinner from './Spinner.vue'
import EmptyState from './EmptyState.vue'
import Pagination from './Pagination.vue'
import type { PaginationMeta } from '@/composables/usePagination'

export interface Column<T> {
  key: string
  label: string
  sortable?: boolean
  width?: string
  align?: 'left' | 'center' | 'right'
  render?: (row: T) => string
}

interface Props {
  columns: Column<T>[]
  data: T[]
  loading?: boolean
  emptyTitle?: string
  emptyDescription?: string
  pagination?: PaginationMeta | null
  rowKey?: string | ((row: T) => string | number)
}

const props = withDefaults(defineProps<Props>(), {
  loading: false,
  emptyTitle: 'No data',
  emptyDescription: 'No items found.',
  rowKey: 'id',
})

const emit = defineEmits<{
  'row-click': [row: T]
  'page-change': [page: number]
  sort: [key: string, direction: 'asc' | 'desc']
}>()

// Columns shown under the title on phones (the first is the title; empty-label columns are row actions)
const mobileColumns = computed(() => props.columns.slice(1).filter((c) => c.label))

const getRowKey = (row: T, index: number): string | number => {
  if (typeof props.rowKey === 'function') {
    return props.rowKey(row)
  }
  return (row as any)[props.rowKey] ?? index
}

const getCellValue = (row: T, column: Column<T>): any => {
  if (column.render) {
    return column.render(row)
  }
  const keys = column.key.split('.')
  let value: any = row
  for (const key of keys) {
    value = value?.[key]
  }
  return value ?? '-'
}

const alignClasses = {
  left: 'text-left',
  center: 'text-center',
  right: 'text-right',
}

function handleRowClick(row: T) {
  emit('row-click', row)
}

function handlePageChange(page: number) {
  emit('page-change', page)
}
</script>

<template>
  <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-200/80 overflow-hidden">
    <!-- Header slot -->
    <div v-if="$slots.header" class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-100">
      <slot name="header" />
    </div>

    <!-- Phones: each row is a card (first column as the title, the rest as label / value) -->
    <div class="md:hidden">
      <div v-if="loading" class="flex items-center justify-center py-12">
        <Spinner size="lg" />
      </div>
      <div v-else-if="data.length === 0" class="px-4 py-4">
        <EmptyState :title="emptyTitle" :description="emptyDescription">
          <template v-if="$slots.empty" #action>
            <slot name="empty" />
          </template>
        </EmptyState>
      </div>
      <ul v-else class="divide-y divide-gray-100">
        <li
          v-for="(row, index) in data"
          :key="getRowKey(row, index)"
          class="px-4 py-3 active:bg-gray-50 cursor-pointer"
          @click="handleRowClick(row)"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 font-semibold text-gray-900 text-[15px]">
              <slot :name="`cell-${columns[0].key}`" :row="row" :value="getCellValue(row, columns[0])">
                {{ getCellValue(row, columns[0]) }}
              </slot>
            </div>
            <ChevronRightIcon class="h-5 w-5 shrink-0 text-gray-300 mt-0.5" />
          </div>
          <dl class="mt-1.5 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
            <div v-for="column in mobileColumns" :key="column.key" class="min-w-0">
              <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ column.label }}</dt>
              <dd class="text-gray-700 truncate">
                <slot :name="`cell-${column.key}`" :row="row" :value="getCellValue(row, column)">
                  {{ getCellValue(row, column) }}
                </slot>
              </dd>
            </div>
          </dl>
        </li>
      </ul>
    </div>

    <!-- Table container -->
    <div class="hidden md:block overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50/80">
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              :style="column.width ? { width: column.width } : {}"
              :class="[
                'px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider',
                alignClasses[column.align || 'left'],
              ]"
            >
              {{ column.label }}
            </th>
          </tr>
        </thead>

        <tbody class="bg-white divide-y divide-gray-100">
          <!-- Loading state -->
          <tr v-if="loading">
            <td :colspan="columns.length" class="px-6 py-12">
              <div class="flex items-center justify-center">
                <Spinner size="lg" />
                <span class="ml-3 text-gray-500">Loading...</span>
              </div>
            </td>
          </tr>

          <!-- Empty state -->
          <tr v-else-if="data.length === 0">
            <td :colspan="columns.length" class="px-6 py-4">
              <EmptyState :title="emptyTitle" :description="emptyDescription">
                <template v-if="$slots.empty" #action>
                  <slot name="empty" />
                </template>
              </EmptyState>
            </td>
          </tr>

          <!-- Data rows -->
          <tr
            v-else
            v-for="(row, index) in data"
            :key="getRowKey(row, index)"
            class="hover:bg-primary-50/40 cursor-pointer transition-colors"
            @click="handleRowClick(row)"
          >
            <td
              v-for="column in columns"
              :key="column.key"
              :class="[
                'px-6 py-4 whitespace-nowrap text-sm',
                alignClasses[column.align || 'left'],
              ]"
            >
              <slot :name="`cell-${column.key}`" :row="row" :value="getCellValue(row, column)">
                {{ getCellValue(row, column) }}
              </slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div
      v-if="pagination && pagination.total > 0"
      class="px-4 sm:px-6 py-3 sm:py-4 border-t border-gray-100 bg-gray-50/70"
    >
      <Pagination
        :current-page="pagination.current_page"
        :last-page="pagination.last_page"
        :from="pagination.from"
        :to="pagination.to"
        :total="pagination.total"
        @page-change="handlePageChange"
      />
    </div>

    <!-- Footer slot -->
    <div v-if="$slots.footer" class="px-4 sm:px-6 py-3 sm:py-4 border-t border-gray-100">
      <slot name="footer" />
    </div>
  </div>
</template>
