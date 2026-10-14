<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { PlusIcon } from '@heroicons/vue/20/solid'
import { vehiclesApi } from '@/api/workshop.api'
import { usePagination } from '@/composables/usePagination'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { Vehicle } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import DataTable from '@/components/ui/DataTable.vue'
import SearchInput from '@/components/ui/SearchInput.vue'
import Badge from '@/components/ui/Badge.vue'
import type { Column } from '@/components/ui/DataTable.vue'

const router = useRouter()
const { can } = usePermissions()
const toast = useToast()
const pagination = usePagination()

const loading = ref(false)
const vehicles = ref<Vehicle[]>([])
const search = ref('')

const columns: Column<Vehicle>[] = [
  { key: 'registration_no', label: 'Reg. Number' },
  { key: 'customer.name', label: 'Customer' },
  { key: 'make.name', label: 'Make' },
  { key: 'model.name', label: 'Model' },
  { key: 'year', label: 'Year' },
  { key: 'fuel_type', label: 'Fuel' },
]

async function fetchVehicles() {
  loading.value = true
  try {
    const response = await vehiclesApi.list({
      search: search.value || undefined,
      page: pagination.currentPage.value,
      per_page: pagination.perPage.value,
    })
    vehicles.value = response.data.data
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
  fetchVehicles()
}

function handlePageChange(page: number) {
  pagination.goToPage(page)
  fetchVehicles()
}

function handleRowClick(vehicle: Vehicle) {
  router.push(`/vehicles/${vehicle.id}/edit`)
}

onMounted(fetchVehicles)

watch(() => pagination.currentPage.value, fetchVehicles)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Vehicles</h1>
        <p class="text-gray-500 mt-1">Manage registered vehicles</p>
      </div>
      <Button v-if="can('workshop.vehicles.create')" @click="router.push('/vehicles/create')">
        <PlusIcon class="h-5 w-5 mr-1" />
        Add Vehicle
      </Button>
    </div>

    <DataTable
      :columns="columns"
      :data="vehicles"
      :loading="loading"
      :pagination="pagination.totalItems.value > 0 ? {
        current_page: pagination.currentPage.value,
        last_page: pagination.lastPage.value,
        from: pagination.from.value,
        to: pagination.to.value,
        total: pagination.totalItems.value,
        per_page: pagination.perPage.value,
      } : null"
      empty-title="No vehicles found"
      empty-description="Get started by adding your first vehicle."
      @row-click="handleRowClick"
      @page-change="handlePageChange"
    >
      <template #header>
        <div class="flex items-center gap-4">
          <div class="w-64">
            <SearchInput
              v-model="search"
              placeholder="Search vehicles..."
              @search="handleSearch"
            />
          </div>
        </div>
      </template>

      <template #cell-fuel_type="{ value }">
        <Badge size="sm">{{ value }}</Badge>
      </template>

      <template #empty>
        <Button v-if="can('workshop.vehicles.create')" @click="router.push('/vehicles/create')">
          <PlusIcon class="h-5 w-5 mr-1" />
          Add Vehicle
        </Button>
      </template>
    </DataTable>
  </div>
</template>
