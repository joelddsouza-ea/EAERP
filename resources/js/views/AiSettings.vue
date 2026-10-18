<script setup lang="ts">
/**
 * Settings › AI assistant (owner): the Anthropic API key, the model, limits that keep the cost
 * in check, the body-shop price guide the assistant may quote, and what it was asked this month.
 */
import { ref, computed, onMounted } from 'vue'
import apiClient from '@/api/client'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'

interface State {
  enabled: boolean
  api_key_masked: string | null
  model: string
  models: { id: string; label: string }[]
  daily_limit: number
  monthly_budget_inr: number
  usd_inr: number
  body_shop_guide: string
  usage: {
    month_cost_inr: number
    month_requests: number
    by_feature: { feature: string; count: number; cost_inr: number }[]
    booked: number
    leads: number
    recent: { id: number; customer: string | null; feature: string; question: string; summary: string | null; urgency: string | null; cost_inr: number; error: string | null; outcome: string | null; created_at: string }[]
  }
}

const toast = useToast()
const state = ref<State | null>(null)
const form = ref<Record<string, any>>({})
const apiKey = ref('')
const busy = ref(false)

const FEATURES: Record<string, string> = { diagnose: "What's wrong?", explain: 'Estimate explanations', accident: 'Accident help', nudge: 'Reminders' }
const inr = (n: number) => '₹ ' + new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(n)
const budgetUsed = computed(() => (state.value && state.value.monthly_budget_inr > 0 ? Math.min(100, Math.round((state.value.usage.month_cost_inr / state.value.monthly_budget_inr) * 100)) : 0))

function reset() {
  if (!state.value) return
  const { enabled, model, daily_limit, monthly_budget_inr, usd_inr, body_shop_guide } = state.value
  form.value = { enabled, model, daily_limit, monthly_budget_inr, usd_inr, body_shop_guide }
}

async function load() {
  try {
    state.value = (await apiClient.get('/customer-app/ai-settings')).data.data
    reset()
  } catch (err) {
    toast.apiError(err)
  }
}

async function save(extra: Record<string, unknown> = {}) {
  busy.value = true
  try {
    state.value = (await apiClient.put('/customer-app/ai-settings', { ...form.value, api_key: apiKey.value || null, ...extra })).data.data
    apiKey.value = ''
    reset()
    toast.success('Saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-4xl">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">AI assistant (customer app)</h1>
      <p class="text-sm text-gray-500">Answers customers from your own price lists: what's wrong with the car, why an estimate item is needed, accident help.</p>
    </div>

    <div v-if="!state" class="flex justify-center py-10"><Spinner size="lg" /></div>
    <template v-else>
      <Card class="mb-4">
        <div class="grid gap-4 text-sm sm:grid-cols-2">
          <label class="flex items-center gap-2 font-medium text-gray-800 sm:col-span-2">
            <input v-model="form.enabled" type="checkbox" class="h-4 w-4 rounded" /> Assistant on in the customer app
          </label>
          <label class="block">
            <span class="text-gray-600">Anthropic API key (console.anthropic.com › API keys)</span>
            <input v-model="apiKey" type="password" autocomplete="off" class="input mt-1" :placeholder="state.api_key_masked ?? 'sk-ant-…'" />
            <button v-if="state.api_key_masked" type="button" class="mt-1 text-xs text-red-700 underline" @click="save({ clear_api_key: true, enabled: false })">Remove the key</button>
          </label>
          <label class="block">
            <span class="text-gray-600">Model</span>
            <select v-model="form.model" class="input mt-1">
              <option v-for="m in state.models" :key="m.id" :value="m.id">{{ m.label }}</option>
            </select>
          </label>
          <label class="block">
            <span class="text-gray-600">Questions per customer per day</span>
            <input v-model.number="form.daily_limit" type="number" min="1" max="100" class="input mt-1" />
          </label>
          <label class="block">
            <span class="text-gray-600">Monthly budget (₹), the assistant pauses when reached (0 = none)</span>
            <input v-model.number="form.monthly_budget_inr" type="number" min="0" class="input mt-1" />
          </label>
          <label class="block sm:col-span-2">
            <span class="text-gray-600">Body-shop price guide (the assistant quotes ranges only from this and your price masters)</span>
            <textarea v-model="form.body_shop_guide" rows="5" class="input mt-1 font-mono text-xs" placeholder="Bumper repaint (per panel): 3,500–5,000&#10;Door dent + paint: 4,000–7,000&#10;Scratch touch-up: 1,200–2,500&#10;Headlamp (aftermarket, fitted): 2,500–6,000"></textarea>
          </label>
        </div>
        <div class="mt-4 flex justify-end">
          <Button :loading="busy" @click="save()">Save</Button>
        </div>
      </Card>

      <Card class="mb-4">
        <h2 class="mb-2 font-semibold text-gray-900">This month</h2>
        <div class="grid grid-cols-2 gap-3 text-center text-sm sm:grid-cols-4">
          <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">Cost</p><p class="text-lg font-semibold">{{ inr(state.usage.month_cost_inr) }}</p></div>
          <div class="rounded-xl bg-gray-50 p-3"><p class="text-xs text-gray-500">Questions</p><p class="text-lg font-semibold">{{ state.usage.month_requests }}</p></div>
          <div class="rounded-xl bg-green-50 p-3"><p class="text-xs text-gray-500">Led to a booking</p><p class="text-lg font-semibold">{{ state.usage.booked }}</p></div>
          <div class="rounded-xl bg-blue-50 p-3"><p class="text-xs text-gray-500">Body-shop leads</p><p class="text-lg font-semibold">{{ state.usage.leads }}</p></div>
        </div>
        <div v-if="state.monthly_budget_inr > 0" class="mt-3">
          <div class="h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full" :class="budgetUsed >= 90 ? 'bg-red-500' : 'bg-primary-600'" :style="{ width: budgetUsed + '%' }"></div></div>
          <p class="mt-1 text-xs text-gray-500">{{ budgetUsed }}% of the {{ inr(state.monthly_budget_inr) }} budget</p>
        </div>
        <p v-if="state.usage.by_feature.length" class="mt-2 text-xs text-gray-500">
          <template v-for="(f, i) in state.usage.by_feature" :key="f.feature">{{ i ? ' · ' : '' }}{{ FEATURES[f.feature] ?? f.feature }}: {{ f.count }} ({{ inr(f.cost_inr) }})</template>
        </p>
      </Card>

      <Card :padding="false">
        <h2 class="px-4 pt-4 font-semibold text-gray-900">Recent questions</h2>
        <p v-if="!state.usage.recent.length" class="p-4 text-sm text-gray-500">None yet.</p>
        <ul class="divide-y divide-gray-100">
          <li v-for="r in state.usage.recent" :key="r.id" class="px-4 py-3 text-sm">
            <p class="text-gray-900"><span class="font-medium">{{ r.customer ?? 'Customer' }}</span> · {{ FEATURES[r.feature] ?? r.feature }}: “{{ r.question }}”</p>
            <p v-if="r.summary" class="text-gray-600">{{ r.summary }}</p>
            <p class="text-xs text-gray-400">
              {{ new Date(r.created_at).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) }} · {{ inr(r.cost_inr) }}
              <template v-if="r.urgency"> · {{ r.urgency.replace(/_/g, ' ') }}</template>
              <template v-if="r.outcome"> · {{ r.outcome === 'booked' ? 'booked' : 'body-shop lead' }}</template>
              <span v-if="r.error" class="text-red-600"> · {{ r.error }}</span>
            </p>
          </li>
        </ul>
      </Card>
    </template>
  </div>
</template>
