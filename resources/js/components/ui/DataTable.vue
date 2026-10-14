<script setup lang="ts" generic="T">
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
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <!-- Header slot -->
    <div v-if="$slots.header" class="px-6 py-4 border-b border-gray-200">
      <slot name="header" />
    </div>

    <!-- Table container -->
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              :style="column.width ? { width: column.width } : {}"
              :class="[
                'px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider',
                alignClasses[column.align || 'left'],
              ]"
            >
              {{ column.label }}
            </th>
          </tr>
        </thead>

        <tbody class="bg-white divide-y divide-gray-200">
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
            class="hover:bg-gray-50 cursor-pointer"
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
      class="px-6 py-4 border-t border-gray-200 bg-gray-50"
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
    <div v-if="$slots.footer" class="px-6 py-4 border-t border-gray-200">
      <slot name="footer" />
    </div>
  </div>
</template>
