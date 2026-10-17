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
  <div class="min-h-screen flex flex-col lg:flex-row bg-white">
    <!-- Brand panel (desktop) -->
    <aside class="hidden lg:flex lg:w-[46%] relative flex-col justify-between overflow-hidden bg-gray-800 p-12 text-white">
      <div class="absolute inset-x-0 top-0 h-1.5 bg-primary-600" />
      <div class="absolute -right-24 -bottom-24 h-96 w-96 rounded-full bg-primary-600/20" />
      <div class="absolute -right-10 bottom-28 h-48 w-48 rounded-full bg-primary-600/30" />
      <div class="relative inline-flex w-max rounded-2xl bg-white p-4 shadow-lg">
        <img src="/images/logo.png" alt="Express Autocare" class="h-16 w-auto" />
      </div>
      <div class="relative">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-primary-300">Workshop ERP</p>
        <h1 class="mt-3 text-4xl font-bold leading-tight">Every car, every job,<br />every rupee — in one place.</h1>
        <p class="mt-4 max-w-md text-gray-300">
          Job cards, digital job sheets, estimates, parts, billing and delivery for all Express Autocare locations.
        </p>
      </div>
      <p class="relative text-xs text-gray-400">Trusted since 2015 · Multi-brand car service center</p>
    </aside>

    <!-- Sign in -->
    <main class="flex-1 flex flex-col">
      <div class="h-1 bg-primary-600 lg:hidden" />
      <div class="flex-1 flex items-center justify-center px-5 py-10 sm:px-8">
        <div class="w-full max-w-sm">
          <div class="lg:hidden flex justify-center mb-8">
            <img src="/images/logo.png" alt="Express Autocare" class="h-20 w-auto" />
          </div>

          <h2 class="text-2xl font-bold text-gray-900">Sign in</h2>
          <p class="mt-1 text-sm text-gray-500">Use your Express Autocare staff account.</p>

          <form class="mt-8 space-y-5" @submit.prevent="handleSubmit">
            <div v-if="error" class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">
              {{ error }}
            </div>

            <Input
              v-model="form.email"
              type="text"
              label="Mobile number or email"
              placeholder="98xxxxxxxx or name@expressautocare.in"
              :error="errors.email"
              required
              autocomplete="username"
              inputmode="email"
            />

            <Input
              v-model="form.password"
              type="password"
              label="Password"
              placeholder="Your password"
              :error="errors.password"
              required
              autocomplete="current-password"
            />

            <Button type="submit" size="lg" block :loading="loading">Sign in</Button>
          </form>
        </div>
      </div>
      <p class="pb-[max(1.5rem,env(safe-area-inset-bottom))] text-center text-xs text-gray-400">© Express Autocare, Kalyan (W)</p>
    </main>
  </div>
</template>
