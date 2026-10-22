<script setup lang="ts">
/**
 * Opened from a WhatsApp link (/app/link/job/<token>): signs in and goes to that job
 * (or home for a CRM link). An expired link falls back to the sign-in screen.
 */
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { message } from '../api'
import { signIn } from '../session'

const route = useRoute()
const router = useRouter()
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    const res = await api.post('/auth/link', { kind: route.params.kind, token: route.params.token })
    signIn(res.data.data.token, res.data.data.customer, res.data.data.viewer)
    const job = res.data.data.job_id
    router.replace(job ? `/jobs/${job}` : '/')
  } catch (err) {
    error.value = message(err)
  }
})
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-white px-6 text-center">
    <img src="/images/logo.png" alt="Express Autocare" class="h-14 w-auto" />
    <p v-if="!error" class="mt-6 text-gray-600">Signing you in…</p>
    <template v-else>
      <p class="mt-6 text-gray-800">{{ error }}</p>
      <RouterLink to="/login" class="mt-4 rounded-xl bg-primary-600 px-6 py-3 font-semibold text-white">Sign in with my number</RouterLink>
    </template>
  </div>
</template>
