<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'

const route = useRoute()
const { login, loading, error } = useAuth()

const form = ref({
  email: '',
  password: '',
})

const errors = ref<Record<string, string>>({})

const redirectTo = computed(() => (route.query.redirect as string) || '/')

async function handleSubmit() {
  errors.value = {}

  // Basic validation
  if (!form.value.email) {
    errors.value.email = 'Email is required'
  }
  if (!form.value.password) {
    errors.value.password = 'Password is required'
  }

  if (Object.keys(errors.value).length > 0) {
    return
  }

  try {
    await login(form.value, redirectTo.value)
  } catch (err: any) {
    if (err.response?.status === 422) {
      const validationErrors = err.response.data.errors || {}
      Object.keys(validationErrors).forEach((key) => {
        errors.value[key] = validationErrors[key][0]
      })
    }
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      <!-- Logo and title -->
      <div class="text-center">
        <div
          class="mx-auto w-16 h-16 flex items-center justify-center rounded-xl bg-primary-600 text-white text-2xl font-bold"
        >
          EA
        </div>
        <h2 class="mt-6 text-3xl font-bold text-gray-900">Welcome back</h2>
        <p class="mt-2 text-sm text-gray-600">Sign in to your EAERP account</p>
      </div>

      <!-- Login form -->
      <form class="mt-8 space-y-6" @submit.prevent="handleSubmit">
        <!-- Error message -->
        <div
          v-if="error"
          class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm"
        >
          {{ error }}
        </div>

        <div class="space-y-4">
          <Input
            v-model="form.email"
            type="email"
            label="Email address"
            placeholder="Enter your email"
            :error="errors.email"
            required
            autocomplete="email"
          />

          <Input
            v-model="form.password"
            type="password"
            label="Password"
            placeholder="Enter your password"
            :error="errors.password"
            required
            autocomplete="current-password"
          />
        </div>

        <Button type="submit" block :loading="loading"> Sign in </Button>
      </form>
    </div>
  </div>
</template>
