<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { PlusIcon } from '@heroicons/vue/20/solid'
import { usersApi } from '@/api/admin.api'
import { usePagination } from '@/composables/usePagination'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { AdminUser } from '@/api/types'
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
const users = ref<AdminUser[]>([])
const search = ref('')

const columns: Column<AdminUser>[] = [
  { key: 'name', label: 'Name' },
  { key: 'email', label: 'Email' },
  { key: 'phone', label: 'Phone' },
  { key: 'roles.0.name', label: 'Role' },
  { key: 'is_active', label: 'Status' },
]

async function fetchUsers() {
  loading.value = true
  try {
    const response = await usersApi.list({
      search: search.value || undefined,
      page: pagination.currentPage.value,
      per_page: pagination.perPage.value,
    })
    users.value = response.data.data
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
  fetchUsers()
}

function handlePageChange(page: number) {
  pagination.goToPage(page)
  fetchUsers()
}

function handleRowClick(user: AdminUser) {
  if (can('user-admin.users.update')) {
    router.push(`/users/${user.id}/edit`)
  }
}

onMounted(fetchUsers)

watch(() => pagination.currentPage.value, fetchUsers)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Users</h1>
        <p class="text-gray-500 mt-1">Manage system users</p>
      </div>
      <Button v-if="can('user-admin.users.create')" @click="router.push('/users/create')">
        <PlusIcon class="h-5 w-5 mr-1" />
        Add User
      </Button>
    </div>

    <DataTable
      :columns="columns"
      :data="users"
      :loading="loading"
      :pagination="pagination.totalItems.value > 0 ? {
        current_page: pagination.currentPage.value,
        last_page: pagination.lastPage.value,
        from: pagination.from.value,
        to: pagination.to.value,
        total: pagination.totalItems.value,
        per_page: pagination.perPage.value,
      } : null"
      empty-title="No users found"
      empty-description="Get started by adding your first user."
      @row-click="handleRowClick"
      @page-change="handlePageChange"
    >
      <template #header>
        <div class="flex items-center gap-4">
          <div class="w-64">
            <SearchInput
              v-model="search"
              placeholder="Search users..."
              @search="handleSearch"
            />
          </div>
        </div>
      </template>

      <template #cell-is_active="{ value }">
        <Badge :variant="value ? 'success' : 'danger'" size="sm">
          {{ value ? 'Active' : 'Inactive' }}
        </Badge>
      </template>

      <template #empty>
        <Button v-if="can('user-admin.users.create')" @click="router.push('/users/create')">
          <PlusIcon class="h-5 w-5 mr-1" />
          Add User
        </Button>
      </template>
    </DataTable>
  </div>
</template>
