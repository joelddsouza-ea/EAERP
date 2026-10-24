<script setup lang="ts">
/**
 * Roles & Access: pick a role, tick what it may do (by module) and which dashboard blocks it
 * sees, then save. The owner role always has everything.
 */
import { ref, computed, onMounted } from 'vue'
import { rolesApi, permissionsApi } from '@/api/admin.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { Role, Permission } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'

const toast = useToast()
const { can } = usePermissions()

const loading = ref(true)
const roles = ref<Role[]>([])
const permissionsByModule = ref<Record<string, Permission[]>>({})
const blockLabels = ref<Record<string, string>>({})
const selectedRole = ref<Role | null>(null)
const tab = ref<'access' | 'dashboard'>('access')

// Editable copies of the selected role
const granted = ref<Set<string>>(new Set())
const savedGranted = ref<string[]>([])
const blocks = ref<string[]>([])
const saving = ref(false)

const canEdit = computed(() => can('user-admin.roles.update'))
const isOwnerRole = computed(() => selectedRole.value?.slug === 'owner')
const accessChanged = computed(() => {
  const now = [...granted.value].sort().join(',')
  return now !== [...savedGranted.value].sort().join(',')
})
const dashboardChanged = computed(() => (selectedRole.value?.dashboard_blocks ?? []).join(',') !== blocks.value.join(','))

async function fetchData() {
  loading.value = true
  try {
    const [rolesRes, permsRes, blocksRes] = await Promise.all([rolesApi.list(), permissionsApi.byModule(), rolesApi.dashboardBlocks()])
    roles.value = rolesRes.data.data
    permissionsByModule.value = permsRes.data.data
    blockLabels.value = blocksRes.data.data
    if (roles.value.length > 0) await selectRole(roles.value[0])
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

// The roles list only carries counts, so fetch the selected role's permission slugs
async function selectRole(role: Role) {
  selectedRole.value = role
  granted.value = new Set()
  savedGranted.value = []
  blocks.value = Object.keys(blockLabels.value).filter((k) => role.dashboard_blocks?.includes(k))
  try {
    const response = await rolesApi.permissions(role.id)
    savedGranted.value = response.data.data.permissions
    granted.value = new Set(savedGranted.value)
  } catch (err) {
    toast.apiError(err)
  }
}

function toggle(slug: string) {
  if (!canEdit.value || isOwnerRole.value) return
  const next = new Set(granted.value)
  next.has(slug) ? next.delete(slug) : next.add(slug)
  granted.value = next
}

function toggleModule(perms: Permission[], on: boolean) {
  if (!canEdit.value || isOwnerRole.value) return
  const next = new Set(granted.value)
  perms.forEach((p) => (on ? next.add(p.slug) : next.delete(p.slug)))
  granted.value = next
}

function toggleBlock(key: string) {
  if (!canEdit.value) return
  const list = new Set(blocks.value)
  list.has(key) ? list.delete(key) : list.add(key)
  // Keep the dashboard's order
  blocks.value = Object.keys(blockLabels.value).filter((k) => list.has(k))
}

async function saveAccess() {
  if (!selectedRole.value) return
  saving.value = true
  try {
    await rolesApi.syncPermissions(selectedRole.value.id, [...granted.value])
    savedGranted.value = [...granted.value]
    toast.success('Access saved', `${selectedRole.value.name}: people with this role get the change when they next open the app.`)
  } catch (err) {
    toast.apiError(err)
  } finally {
    saving.value = false
  }
}

async function saveDashboard() {
  if (!selectedRole.value) return
  saving.value = true
  try {
    const role = (await rolesApi.updateDashboard(selectedRole.value.id, blocks.value)).data.data
    selectedRole.value.dashboard_blocks = role.dashboard_blocks
    const listed = roles.value.find((r) => r.id === role.id)
    if (listed) listed.dashboard_blocks = role.dashboard_blocks
    toast.success('Dashboard saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    saving.value = false
  }
}

onMounted(fetchData)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Roles &amp; Access</h1>
      <p class="mt-1 text-gray-500">What each role may do, and what it sees on the dashboard.</p>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-4">
      <!-- Roles -->
      <Card :padding="false">
        <template #header><h3 class="text-lg font-semibold">Roles</h3></template>
        <ul class="divide-y divide-gray-200">
          <li
            v-for="role in roles"
            :key="role.id"
            class="cursor-pointer px-4 py-3 transition-colors hover:bg-gray-50"
            :class="selectedRole?.id === role.id ? 'bg-primary-50' : ''"
            @click="selectRole(role)"
          >
            <p class="text-sm font-medium">{{ role.name }}</p>
            <p class="text-xs text-gray-500">{{ role.users_count ?? 0 }} people · {{ role.permissions_count ?? 0 }} permissions</p>
          </li>
        </ul>
      </Card>

      <div class="lg:col-span-3">
        <Card v-if="selectedRole">
          <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h3 class="text-lg font-semibold">{{ selectedRole.name }}</h3>
                <p v-if="selectedRole.description" class="mt-1 text-sm text-gray-500">{{ selectedRole.description }}</p>
              </div>
              <div class="flex gap-1 rounded-lg bg-gray-100 p-1 text-sm">
                <button type="button" class="rounded-md px-3 py-1.5" :class="tab === 'access' ? 'bg-white font-medium shadow-sm' : 'text-gray-600'" @click="tab = 'access'">Access</button>
                <button type="button" class="rounded-md px-3 py-1.5" :class="tab === 'dashboard' ? 'bg-white font-medium shadow-sm' : 'text-gray-600'" @click="tab = 'dashboard'">Dashboard</button>
              </div>
            </div>
          </template>

          <!-- What the role may do -->
          <template v-if="tab === 'access'">
            <p v-if="isOwnerRole" class="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-800">The owner role always has every permission.</p>
            <div class="space-y-6">
              <div v-for="(permissions, module) in permissionsByModule" :key="module" class="border-b border-gray-200 pb-4 last:border-0 last:pb-0">
                <div class="mb-3 flex items-center justify-between gap-2">
                  <h4 class="text-sm font-semibold capitalize text-gray-700">{{ String(module).replace(/[_-]/g, ' ') }}</h4>
                  <div v-if="canEdit && !isOwnerRole" class="flex gap-3 text-xs">
                    <button type="button" class="text-primary-700 hover:underline" @click="toggleModule(permissions, true)">All</button>
                    <button type="button" class="text-gray-600 hover:underline" @click="toggleModule(permissions, false)">None</button>
                  </div>
                </div>
                <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                  <label v-for="permission in permissions" :key="permission.id" class="flex items-start gap-2 text-sm" :class="canEdit && !isOwnerRole ? 'cursor-pointer' : ''">
                    <input
                      type="checkbox"
                      class="mt-0.5 rounded text-primary-600"
                      :checked="isOwnerRole || granted.has(permission.slug)"
                      :disabled="!canEdit || isOwnerRole"
                      @change="toggle(permission.slug)"
                    />
                    <span>{{ permission.name }}</span>
                  </label>
                </div>
              </div>
            </div>
            <div v-if="canEdit && !isOwnerRole" class="sticky bottom-0 mt-4 flex items-center justify-end gap-3 border-t border-gray-100 bg-white pt-3">
              <Badge v-if="accessChanged" variant="warning" size="sm">Not saved</Badge>
              <Button variant="secondary" :disabled="!accessChanged" @click="granted = new Set(savedGranted)">Discard</Button>
              <Button :loading="saving" :disabled="!accessChanged" @click="saveAccess">Save access</Button>
            </div>
          </template>

          <!-- What the role sees on the dashboard -->
          <template v-else>
            <p class="mb-3 text-sm text-gray-600">Tick what people with this role see on their dashboard. Money figures are best kept to the owner, managers and back office.</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <label v-for="(label, key) in blockLabels" :key="key" class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm" :class="canEdit ? 'cursor-pointer' : ''">
                <input type="checkbox" class="rounded text-primary-600" :checked="blocks.includes(String(key))" :disabled="!canEdit" @change="toggleBlock(String(key))" />
                {{ label }}
              </label>
            </div>
            <div v-if="canEdit" class="mt-4 flex justify-end gap-3">
              <Button :loading="saving" :disabled="!dashboardChanged" @click="saveDashboard">Save dashboard</Button>
            </div>
          </template>
        </Card>

        <Card v-else class="py-12 text-center">
          <p class="text-gray-500">Select a role</p>
        </Card>
      </div>
    </div>
  </div>
</template>
