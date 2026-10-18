<script setup lang="ts">
/**
 * On the customer's page: their customer-app sign-in code to read out (while codes aren't sent
 * by WhatsApp), whether they use the app, and the documents they keep in it.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { DevicePhoneMobileIcon, DocumentTextIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'
import apiClient from '@/api/client'
import Card from '@/components/ui/Card.vue'

const props = defineProps<{ customerId: number }>()

interface CodeState { code: { code: string; phone: string; valid_till: string } | null; sessions: number }
interface Doc { id: number; type_label: string; title: string | null; number: string | null; expiry_date: string | null; expired: boolean; vehicle: string | null; url: string }

const state = ref<CodeState | null>(null)
const docs = ref<Doc[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    state.value = (await apiClient.get(`/customer-app/customers/${props.customerId}/sign-in-code`)).data.data
    docs.value = (await apiClient.get(`/customer-app/customers/${props.customerId}/documents`)).data.data
  } catch {
    state.value = null
  } finally {
    loading.value = false
  }
}

// A code asked for while this page is open shows up by itself
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  load()
  timer = setInterval(() => document.visibilityState === 'visible' && load(), 15_000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <Card v-if="state">
    <template #header>
      <div class="flex items-center justify-between">
        <h3 class="flex items-center gap-2 text-lg font-semibold"><DevicePhoneMobileIcon class="h-5 w-5 text-gray-400" /> Customer app</h3>
        <button type="button" class="text-gray-400 hover:text-gray-700" aria-label="Refresh" @click="load"><ArrowPathIcon class="h-4 w-4" :class="loading ? 'animate-spin' : ''" /></button>
      </div>
    </template>

    <div v-if="state.code" class="rounded-xl bg-primary-50 p-3 text-center ring-1 ring-primary-200">
      <p class="text-xs text-primary-800">Sign-in code to read out ({{ state.code.phone }})</p>
      <p class="mt-1 font-mono text-3xl font-bold tracking-[0.3em] text-primary-700">{{ state.code.code }}</p>
      <p class="text-xs text-primary-800">Valid till {{ state.code.valid_till }}. Only give it to the customer on their own number.</p>
    </div>
    <p v-else class="text-sm text-gray-600">
      {{ state.sessions ? `Signed in on ${state.sessions} phone(s).` : 'Not using the app yet.' }}
      A code they ask for in the app shows here.
    </p>

    <div v-if="docs.length" class="mt-4">
      <p class="text-xs font-semibold uppercase text-gray-400">Documents in the app</p>
      <ul class="mt-1 space-y-1 text-sm">
        <li v-for="d in docs" :key="d.id" class="flex items-center gap-2">
          <DocumentTextIcon class="h-4 w-4 text-gray-400" />
          <a :href="d.url" target="_blank" rel="noopener" class="flex-1 text-primary-700 hover:underline">{{ d.title || d.type_label }}<template v-if="d.vehicle"> · {{ d.vehicle }}</template></a>
          <span class="text-xs" :class="d.expired ? 'font-semibold text-red-600' : 'text-gray-500'">{{ d.expiry_date ?? '' }}</span>
        </li>
      </ul>
    </div>
  </Card>
</template>
