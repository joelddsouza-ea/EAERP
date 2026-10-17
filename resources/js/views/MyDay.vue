<script setup lang="ts">
/**
 * My Day: what this person should do now — punch in, call new leads, follow-ups,
 * messages to send, cars arriving, jobs waiting on them, approvals — one tap each.
 */
import { ref, onMounted } from 'vue'
import { ChevronRightIcon } from '@heroicons/vue/20/solid'
import apiClient from '@/api/client'
import { useAuthStore } from '@/stores/auth.store'
import { useToast } from '@/composables/useToast'
import Spinner from '@/components/ui/Spinner.vue'

interface DayCard {
  key: string
  title: string
  count: number | null
  tone: 'ok' | 'action' | 'urgent'
  to: string
}

const auth = useAuthStore()
const toast = useToast()
const cards = ref<DayCard[]>([])
const loading = ref(true)

onMounted(async () => {
  try {
    cards.value = (await apiClient.get('/my-day')).data.data.cards
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
})

const TONES: Record<DayCard['tone'], string> = {
  urgent: 'bg-red-50 ring-red-200',
  action: 'bg-white ring-amber-200',
  ok: 'bg-white ring-gray-200',
}
</script>

<template>
  <div class="max-w-xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900">My day</h1>
    <p class="mb-4 text-sm text-gray-500">{{ new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' }) }} · {{ auth.userName }}</p>

    <div v-if="loading" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <div v-else class="space-y-2">
      <RouterLink v-for="c in cards" :key="c.key" :to="c.to" class="flex items-center gap-3 rounded-2xl p-4 shadow-sm ring-1" :class="TONES[c.tone]">
        <span v-if="c.count !== null" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-lg font-bold" :class="c.tone === 'urgent' ? 'bg-red-600 text-white' : c.count ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-400'">{{ c.count }}</span>
        <span v-else class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-xl" :class="c.tone === 'ok' ? 'bg-green-100' : 'bg-primary-100'">{{ c.tone === 'ok' ? '✓' : '⏱' }}</span>
        <span class="flex-1 font-medium text-gray-900">{{ c.title }}</span>
        <ChevronRightIcon class="h-5 w-5 text-gray-400" />
      </RouterLink>
      <p v-if="!cards.length" class="rounded-xl bg-green-50 p-4 text-sm text-green-800">Nothing waiting for you.</p>
    </div>
  </div>
</template>
