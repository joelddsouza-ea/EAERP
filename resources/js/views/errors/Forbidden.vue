<script setup lang="ts">
import { useRouter } from 'vue-router'
import { ShieldExclamationIcon } from '@heroicons/vue/24/outline'
import Button from '@/components/ui/Button.vue'

const router = useRouter()
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
    <div class="text-center">
      <ShieldExclamationIcon class="mx-auto h-24 w-24 text-red-400" />
      <h1 class="mt-4 text-4xl font-bold text-gray-900">403</h1>
      <h2 class="mt-2 text-2xl font-bold text-gray-900">Access Denied</h2>
      <p class="mt-2 text-gray-500 max-w-md mx-auto">
        You don't have permission to access this page. Please contact your administrator if you believe this is an error.
      </p>
      <div class="mt-8 flex justify-center gap-4">
        <Button variant="secondary" @click="router.back()">Go Back</Button>
        <Button @click="router.push('/')">Go to Dashboard</Button>
      </div>
    </div>
  </div>
</template>
