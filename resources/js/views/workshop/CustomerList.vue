<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { PlusIcon } from '@heroicons/vue/20/solid'
import { customersApi } from '@/api/workshop.api'
import { usePagination } from '@/composables/usePagination'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { Customer } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import DataTable from '@/components/ui/DataTable.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import type { Column } from '@/components/ui/DataTable.vue'

const router = useRouter()
const { can } = usePermissions()
const toast = useToast()
const pagination = usePagination()

const loading = ref(false)
const customers = ref<Customer[]>([])
const search = ref('')

const columns: Column<Customer>[] = [
  { key: 'name', label: 'Name' },
  { key: 'phone', label: 'Phone' },
  { key: 'email', label: 'Email' },
  { key: 'customer_type', label: 'Type' },
  { key: 'vehicles_count', label: 'Vehicles', align: 'center' },
  { key: 'job_cards_count', label: 'Jobs', align: 'center' },
]

async function fetchCustomers() {
  loading.value = true
  try {
    const response = await customersApi.list({
      search: search.value || undefined,
      page: pagination.currentPage.value,
      per_page: pagination.perPage.value,
    })
    customers.value = response.data.data
    pagination.setMeta(response.data.meta)
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

function handleSearch(value: string) {
  search.value = value
  pagination.reset()
  fetchCustomers()
}

function handlePageChange(page: number) {
  pagination.goToPage(page)
  fetchCustomers()
}

function handleRowClick(customer: Customer) {
  router.push(`/customers/${customer.id}`)
}

onMounted(fetchCustomers)

watch(() => pagination.currentPage.value, fetchCustomers)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Customers</h1>
        <p class="text-gray-500 mt-1">Manage your customer database</p>
      </div>
      <Button v-if="can('workshop.customers.create')" @click="router.push('/customers/create')">
        <PlusIcon class="h-5 w-5 mr-1" />
        Add Customer
      </Button>
    </div>

    <DataTable
      :columns="columns"
      :data="customers"
      :loading="loading"
      :pagination="pagination.totalItems.value > 0 ? {
        current_page: pagination.currentPage.value,
        last_page: pagination.lastPage.value,
        from: pagination.from.value,
        to: pagination.to.value,
        total: pagination.totalItems.value,
        per_page: pagination.perPage.value,
      } : null"
      empty-title="No customers found"
      empty-description="Get started by adding your first customer."
      @row-click="handleRowClick"
      @page-change="handlePageChange"
    >
      <template #header>
        <div class="flex items-center gap-4">
          <div class="w-64">
            <SearchInput
              v-model="search"
              placeholder="Search customers..."
              @search="handleSearch"
            />
          </div>
        </div>
      </template>

      <template #cell-customer_type="{ value }">
        <span class="capitalize">{{ value }}</span>
      </template>

      <template #empty>
        <Button v-if="can('workshop.customers.create')" @click="router.push('/customers/create')">
          <PlusIcon class="h-5 w-5 mr-1" />
          Add Customer
        </Button>
      </template>
    </DataTable>
  </div>
</template>
