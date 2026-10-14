<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { authApi } from '@/api/auth.api'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'

const router = useRouter()
const toast = useToast()

const loading = ref(false)
const form = ref({
  current_password: '',
  password: '',
  password_confirmation: '',
})
const errors = ref<Record<string, string>>({})

async function handleSubmit() {
  errors.value = {}

  // Validation
  if (!form.value.current_password) {
    errors.value.current_password = 'Current password is required'
  }
  if (!form.value.password) {
    errors.value.password = 'New password is required'
  } else if (form.value.password.length < 8) {
    errors.value.password = 'Password must be at least 8 characters'
  }
  if (form.value.password !== form.value.password_confirmation) {
    errors.value.password_confirmation = 'Passwords do not match'
  }

  if (Object.keys(errors.value).length > 0) {
    return
  }

  loading.value = true
  try {
    await authApi.changePassword(form.value)
    toast.success('Password Changed', 'Your password has been updated successfully.')
    router.push('/')
  } catch (err: any) {
    if (err.response?.status === 422) {
      const validationErrors = err.response.data.errors || {}
      Object.keys(validationErrors).forEach((key) => {
        errors.value[key] = validationErrors[key][0]
      })
    } else {
      toast.apiError(err)
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="max-w-md mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Change Password</h1>

    <Card>
      <form @submit.prevent="handleSubmit" class="space-y-4">
        <Input
          v-model="form.current_password"
          type="password"
          label="Current Password"
          :error="errors.current_password"
          required
          autocomplete="current-password"
        />

        <Input
          v-model="form.password"
          type="password"
          label="New Password"
          :error="errors.password"
          hint="Must be at least 8 characters"
          required
          autocomplete="new-password"
        />

        <Input
          v-model="form.password_confirmation"
          type="password"
          label="Confirm New Password"
          :error="errors.password_confirmation"
          required
          autocomplete="new-password"
        />

        <div class="flex gap-3 pt-4">
          <Button type="button" variant="secondary" @click="router.back()">Cancel</Button>
          <Button type="submit" :loading="loading">Update Password</Button>
        </div>
      </form>
    </Card>
  </div>
</template>
