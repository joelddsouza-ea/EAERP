<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { PlusIcon, PencilIcon } from '@heroicons/vue/20/solid'
import { branchesApi } from '@/api/admin.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { Branch } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'

const router = useRouter()
const { can } = usePermissions()
const toast = useToast()

const loading = ref(true)
const branches = ref<Branch[]>([])

async function fetchBranches() {
  loading.value = true
  try {
    const response = await branchesApi.list()
    branches.value = response.data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

onMounted(fetchBranches)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4 sm:mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Branches</h1>
        <p class="text-gray-500 mt-1">Manage workshop branches</p>
      </div>
      <Button v-if="can('user-admin.branches.create')" @click="router.push('/branches/create')">
        <PlusIcon class="h-5 w-5 mr-1" />
        Add Branch
      </Button>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Spinner size="lg" />
    </div>

    <div v-else-if="branches.length === 0" class="text-center py-12">
      <p class="text-gray-500 mb-4">No branches configured yet.</p>
      <Button v-if="can('user-admin.branches.create')" @click="router.push('/branches/create')">
        <PlusIcon class="h-5 w-5 mr-1" />
        Add Branch
      </Button>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <Card v-for="branch in branches" :key="branch.id">
        <div class="flex items-start justify-between">
          <div>
            <h3 class="text-lg font-semibold text-gray-900">{{ branch.name }}</h3>
            <p class="text-sm text-gray-500">Code: {{ branch.code }}</p>
          </div>
          <div class="flex items-center gap-2">
            <Badge :variant="branch.is_active ? 'success' : 'danger'" size="sm">
              {{ branch.is_active ? 'Active' : 'Inactive' }}
            </Badge>
            <Button
              v-if="can('user-admin.branches.update')"
              size="sm"
              variant="ghost"
              @click="router.push(`/branches/${branch.id}/edit`)"
            >
              <PencilIcon class="h-4 w-4" />
            </Button>
          </div>
        </div>

        <dl class="mt-4 space-y-2 text-sm">
          <div v-if="branch.address">
            <dt class="text-gray-500">Address</dt>
            <dd class="text-gray-900">{{ branch.address }}</dd>
          </div>
          <div v-if="branch.phone">
            <dt class="text-gray-500">Phone</dt>
            <dd class="text-gray-900">{{ branch.phone }}</dd>
          </div>
          <div v-if="branch.email">
            <dt class="text-gray-500">Email</dt>
            <dd class="text-gray-900">{{ branch.email }}</dd>
          </div>
        </dl>
      </Card>
    </div>
  </div>
</template>
