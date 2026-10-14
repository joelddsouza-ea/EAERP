<script setup lang="ts">
import { useRouter } from 'vue-router'
import Button from '@/components/ui/Button.vue'

const router = useRouter()
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
    <div class="text-center">
      <h1 class="text-9xl font-bold text-gray-200">404</h1>
      <h2 class="mt-4 text-2xl font-bold text-gray-900">Page Not Found</h2>
      <p class="mt-2 text-gray-500 max-w-md mx-auto">
        The page you're looking for doesn't exist or has been moved.
      </p>
      <div class="mt-8 flex justify-center gap-4">
        <Button variant="secondary" @click="router.back()">Go Back</Button>
        <Button @click="router.push('/')">Go to Dashboard</Button>
      </div>
    </div>
  </div>
</template>
