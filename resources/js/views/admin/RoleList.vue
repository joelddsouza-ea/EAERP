<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { rolesApi, permissionsApi } from '@/api/admin.api'
import { useToast } from '@/composables/useToast'
import type { Role, Permission } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'

const toast = useToast()

const loading = ref(true)
const roles = ref<Role[]>([])
const permissionsByModule = ref<Record<string, Permission[]>>({})
const selectedRole = ref<Role | null>(null)
const selectedRolePermissions = ref<string[]>([])

async function fetchData() {
  loading.value = true
  try {
    const [rolesRes, permsRes] = await Promise.all([
      rolesApi.list(),
      permissionsApi.byModule(),
    ])
    roles.value = rolesRes.data.data
    permissionsByModule.value = permsRes.data.data

    if (roles.value.length > 0) {
      await selectRole(roles.value[0])
    }
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

// The roles list only carries counts, so fetch the selected role's permission slugs
async function selectRole(role: Role) {
  selectedRole.value = role
  selectedRolePermissions.value = []
  try {
    const response = await rolesApi.permissions(role.id)
    selectedRolePermissions.value = response.data.data.permissions
  } catch (err) {
    toast.apiError(err)
  }
}

function hasPermission(permissionSlug: string): boolean {
  return selectedRolePermissions.value.includes(permissionSlug)
}

onMounted(fetchData)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Roles & Permissions</h1>
      <p class="text-gray-500 mt-1">View system roles and their permissions</p>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-4 gap-6">
      <!-- Roles List -->
      <Card :padding="false">
        <template #header>
          <h3 class="text-lg font-semibold">Roles</h3>
        </template>

        <ul class="divide-y divide-gray-200">
          <li
            v-for="role in roles"
            :key="role.id"
            class="px-4 py-3 cursor-pointer hover:bg-gray-50 transition-colors"
            :class="selectedRole?.id === role.id ? 'bg-primary-50' : ''"
            @click="selectRole(role)"
          >
            <p class="font-medium text-sm">{{ role.name }}</p>
            <p class="text-xs text-gray-500">{{ role.name }}</p>
          </li>
        </ul>
      </Card>

      <!-- Permissions -->
      <div class="lg:col-span-3">
        <Card v-if="selectedRole">
          <template #header>
            <div>
              <h3 class="text-lg font-semibold">{{ selectedRole.name }}</h3>
              <p v-if="selectedRole.description" class="text-sm text-gray-500 mt-1">
                {{ selectedRole.description }}
              </p>
            </div>
          </template>

          <div class="space-y-6">
            <div
              v-for="(permissions, module) in permissionsByModule"
              :key="module"
              class="border-b border-gray-200 pb-4 last:border-0 last:pb-0"
            >
              <h4 class="text-sm font-semibold text-gray-700 capitalize mb-3">
                {{ String(module).replace(/[_-]/g, ' ') }}
              </h4>
              <div class="flex flex-wrap gap-2">
                <Badge
                  v-for="permission in permissions"
                  :key="permission.id"
                  :variant="hasPermission(permission.slug) ? 'success' : 'default'"
                  size="sm"
                >
                  {{ permission.name }}
                </Badge>
              </div>
            </div>
          </div>
        </Card>

        <Card v-else class="text-center py-12">
          <p class="text-gray-500">Select a role to view its permissions</p>
        </Card>
      </div>
    </div>
  </div>
</template>
