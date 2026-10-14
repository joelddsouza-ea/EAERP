<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { usersApi, rolesApi, branchesApi } from '@/api/admin.api'
import { useToast } from '@/composables/useToast'
import type { Role, Branch } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const userId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEditing = computed(() => !!userId.value)

const loading = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})

const roles = ref<Role[]>([])
const branches = ref<Branch[]>([])

const form = ref({
  name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
  role: null as string | null,
  branch_ids: [] as number[],
  primary_branch_id: null as number | null,
  is_active: true,
})

const roleOptions = computed(() =>
  roles.value.map((r) => ({ value: r.slug, label: r.name }))
)

const primaryBranchOptions = computed(() =>
  branches.value
    .filter((b) => form.value.branch_ids.includes(b.id))
    .map((b) => ({ value: b.id, label: b.name }))
)

async function fetchData() {
  loading.value = true
  try {
    const [rolesRes, branchesRes] = await Promise.all([
      rolesApi.list(),
      branchesApi.list(),
    ])
    roles.value = rolesRes.data.data
    branches.value = branchesRes.data.data

    if (userId.value) {
      const userRes = await usersApi.get(userId.value)
      const user = userRes.data.data
      form.value = {
        name: user.name || '',
        email: user.email || '',
        phone: user.phone || '',
        password: '',
        password_confirmation: '',
        role: user.roles?.[0]?.slug || null,
        branch_ids: user.branches?.map((b) => b.id) || [],
        primary_branch_id: user.primary_branch_id,
        is_active: user.is_active,
      }
    }
  } catch (err) {
    toast.apiError(err)
    router.push('/users')
  } finally {
    loading.value = false
  }
}

function toggleBranch(branchId: number) {
  const index = form.value.branch_ids.indexOf(branchId)
  if (index === -1) {
    form.value.branch_ids.push(branchId)
  } else {
    form.value.branch_ids.splice(index, 1)
  }

  // Keep the primary branch within the selected branches
  if (!form.value.branch_ids.includes(form.value.primary_branch_id ?? -1)) {
    form.value.primary_branch_id = form.value.branch_ids[0] ?? null
  }
}

async function handleSubmit() {
  errors.value = {}

  // Validation
  if (!form.value.name.trim()) {
    errors.value.name = 'Name is required'
  }
  if (!form.value.email.trim()) {
    errors.value.email = 'Email is required'
  }
  if (!isEditing.value && !form.value.password) {
    errors.value.password = 'Password is required'
  }
  if (form.value.password && form.value.password.length < 8) {
    errors.value.password = 'Password must be at least 8 characters'
  }
  if (form.value.password && form.value.password !== form.value.password_confirmation) {
    errors.value.password_confirmation = 'Passwords do not match'
  }
  if (!form.value.role) {
    errors.value.roles = 'Role is required'
  }
  if (form.value.branch_ids.length === 0) {
    errors.value.branch_ids = 'At least one branch is required'
  }

  if (Object.keys(errors.value).length > 0) {
    return
  }

  saving.value = true
  try {
    const payload = {
      name: form.value.name,
      email: form.value.email,
      phone: form.value.phone || null,
      roles: [form.value.role!],
      branches: form.value.branch_ids,
      primary_branch_id: form.value.primary_branch_id ?? form.value.branch_ids[0],
      is_active: form.value.is_active,
    }

    if (isEditing.value) {
      await usersApi.update(userId.value!, payload)
      // The update endpoint ignores passwords; changes go through reset-password
      if (form.value.password) {
        await usersApi.resetPassword(userId.value!, form.value.password, form.value.password_confirmation)
      }
      toast.success('User Updated', 'User has been updated successfully.')
    } else {
      await usersApi.create({
        ...payload,
        password: form.value.password,
        password_confirmation: form.value.password_confirmation,
      })
      toast.success('User Created', 'New user has been created.')
    }
    router.push('/users')
  } catch (err: any) {
    if (err.response?.status === 422) {
      const validationErrors = err.response.data.errors || {}
      Object.keys(validationErrors).forEach((key) => {
        // Map array-field errors (e.g. "roles.0") back to their form field
        const field = key.split('.')[0] === 'branches' ? 'branch_ids' : key.split('.')[0]
        errors.value[field] = validationErrors[key][0]
      })
    } else {
      toast.apiError(err)
    }
  } finally {
    saving.value = false
  }
}

onMounted(fetchData)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">
        {{ isEditing ? 'Edit User' : 'New User' }}
      </h1>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <form v-else @submit.prevent="handleSubmit" class="max-w-2xl">
      <Card>
        <div class="space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Input
              v-model="form.name"
              label="Full Name"
              :error="errors.name"
              required
            />

            <Input
              v-model="form.email"
              type="email"
              label="Email"
              :error="errors.email"
              required
            />

            <Input
              v-model="form.phone"
              label="Phone"
              :error="errors.phone"
            />

            <Select
              v-model="form.role"
              label="Role"
              :options="roleOptions"
              :error="errors.roles"
              placeholder="Select role"
              required
            />
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Input
              v-model="form.password"
              type="password"
              label="Password"
              :error="errors.password"
              :hint="isEditing ? 'Leave blank to keep current password' : 'At least 8 characters'"
              :required="!isEditing"
            />

            <Input
              v-model="form.password_confirmation"
              type="password"
              label="Confirm Password"
              :error="errors.password_confirmation"
              :required="!isEditing && !!form.password"
            />
          </div>

          <!-- Branch Selection -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Branches <span class="text-red-500">*</span>
            </label>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
              <label
                v-for="branch in branches"
                :key="branch.id"
                class="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50"
                :class="form.branch_ids.includes(branch.id) ? 'border-primary-500 bg-primary-50' : 'border-gray-200'"
              >
                <input
                  type="checkbox"
                  :checked="form.branch_ids.includes(branch.id)"
                  class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
                  @change="toggleBranch(branch.id)"
                />
                <span class="ml-2 text-sm">{{ branch.name }}</span>
              </label>
            </div>
            <p v-if="errors.branch_ids" class="mt-1 text-sm text-red-600">
              {{ errors.branch_ids }}
            </p>
          </div>

          <Select
            v-if="form.branch_ids.length > 1"
            v-model="form.primary_branch_id"
            label="Primary Branch"
            :options="primaryBranchOptions"
            :error="errors.primary_branch_id"
            required
          />

          <!-- Active Status -->
          <div v-if="isEditing" class="flex items-center">
            <input
              id="is_active"
              v-model="form.is_active"
              type="checkbox"
              class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
            />
            <label for="is_active" class="ml-2 text-sm text-gray-700">
              User is active
            </label>
          </div>
        </div>

        <template #footer>
          <div class="flex gap-3">
            <Button type="button" variant="secondary" @click="router.back()">
              Cancel
            </Button>
            <Button type="submit" :loading="saving">
              {{ isEditing ? 'Update User' : 'Create User' }}
            </Button>
          </div>
        </template>
      </Card>
    </form>
  </div>
</template>
