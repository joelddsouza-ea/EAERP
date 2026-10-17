<script setup lang="ts">
/**
 * CRM insights: the funnel (leads → contacted → booked → arrived → won) by source,
 * campaign and executive, cost per lead / booking and ROI from the ad spend, speed to
 * lead, lost reasons, retention, and customer feedback with the unhappy ones to call back.
 */
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { crmApi, fmtDateTime, type Insights } from '@/api/crm.api'
import { inr } from '@/api/accounts.api'
import { localDate } from '@/api/hr.api'
import { useBranchStore } from '@/stores/branch.store'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const branchStore = useBranchStore()
const toast = useToast()

const tab = ref<'funnel' | 'retention' | 'feedback'>((route.query.tab as any) || 'funnel')
watch(tab, (t) => router.replace({ query: { tab: t } }))
const today = localDate()
const from = ref(today.slice(0, 8) + '01')
const to = ref(today)
const data = ref<Insights | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = (await crmApi.insights({ from: from.value, to: to.value, branch_id: branchStore.currentBranchId })).data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}
watch([from, to, () => branchStore.currentBranchId], load)

// ---------------------------------------------------------------- feedback
const feedback = ref<{ average: number; rows: any[] } | null>(null)
const fbFilter = ref('open')
async function loadFeedback() {
  try {
    feedback.value = (await crmApi.feedback(fbFilter.value || undefined)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}
watch(fbFilter, loadFeedback)
const resolving = ref<any | null>(null)
const resolveNote = ref('')
async function resolve() {
  try {
    await crmApi.resolveFeedback(resolving.value.id, resolveNote.value)
    toast.success('Marked as resolved')
    resolving.value = null
    loadFeedback()
  } catch (err) {
    toast.apiError(err)
  }
}

onMounted(() => {
  load()
  loadFeedback()
})

const pct = (a: number, b: number) => (b ? Math.round((a / b) * 100) : 0)
const STEPS = [
  ['leads', 'Leads'], ['contacted', 'Contacted'], ['booked', 'Booked'], ['arrived', 'Arrived'], ['won', 'Billed'],
] as const
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
      <h1 class="text-2xl font-bold text-gray-900">CRM insights</h1>
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <input v-model="from" type="date" class="input w-auto" /><span class="text-gray-400">→</span><input v-model="to" type="date" class="input w-auto" />
      </div>
    </div>
    <div class="mb-4 flex gap-1 rounded-xl bg-gray-100 p-1 text-sm font-medium max-w-md">
      <button v-for="t in (['funnel', 'retention', 'feedback'] as const)" :key="t" type="button" class="flex-1 rounded-lg px-3 py-1.5 capitalize" :class="tab === t ? 'bg-white shadow-sm' : 'text-gray-600'" @click="tab = t">{{ t }}</button>
    </div>

    <div v-if="loading && !data" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <template v-else-if="data && tab === 'funnel'">
      <!-- Funnel -->
      <div class="mb-4 grid grid-cols-2 sm:grid-cols-5 gap-2">
        <div v-for="([k, label], i) in STEPS" :key="k" class="rounded-xl bg-white p-3 ring-1 ring-gray-200">
          <p class="text-xs text-gray-500">{{ label }}</p>
          <p class="text-2xl font-bold">{{ data.funnel[k] }}</p>
          <p v-if="i > 0" class="text-xs text-gray-500">{{ pct(data.funnel[k], data.funnel.leads) }}% of leads</p>
        </div>
      </div>
      <div class="mb-4 grid grid-cols-2 sm:grid-cols-5 gap-2 text-sm">
        <div class="rounded-xl bg-green-50 p-3"><p class="text-xs text-gray-500">Revenue from leads</p><p class="font-semibold">{{ inr(data.funnel.revenue) }}</p></div>
        <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Ad spend</p><p class="font-semibold">{{ inr(data.funnel.spend) }}</p></div>
        <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Cost per lead / booking</p><p class="font-semibold">{{ inr(data.funnel.cost_per_lead) }} / {{ inr(data.funnel.cost_per_booking) }}</p></div>
        <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">ROI</p><p class="font-semibold">{{ data.funnel.roi === null ? '—' : data.funnel.roi + '%' }}</p></div>
        <div class="rounded-xl p-3" :class="data.speed.sla_missed ? 'bg-amber-50' : 'bg-white ring-1 ring-gray-200'"><p class="text-xs text-gray-500">First call (median)</p><p class="font-semibold">{{ data.speed.median_minutes === null ? '—' : data.speed.median_minutes + ' min' }}</p><p class="text-xs text-gray-500">{{ data.speed.sla_missed }} late</p></div>
      </div>

      <Card v-for="block in ([['by_source', 'By source'], ['by_campaign', 'By campaign'], ['by_executive', 'By executive']] as const)" :key="block[0]" :padding="false" class="mb-4">
        <template #header><h3 class="font-semibold">{{ block[1] }}</h3></template>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500">
              <tr>
                <th class="px-3 py-2 text-left">Name</th><th class="px-3 py-2 text-right">Leads</th><th class="px-3 py-2 text-right">Booked</th><th class="px-3 py-2 text-right">Won</th>
                <th class="px-3 py-2 text-right">Conv.</th><th class="px-3 py-2 text-right">Revenue</th>
                <template v-if="block[0] !== 'by_executive'"><th class="px-3 py-2 text-right">Spend</th><th class="px-3 py-2 text-right">₹/lead</th><th class="px-3 py-2 text-right">ROI</th></template>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(r, i) in (data[block[0]] as any[])" :key="i">
                <td class="px-3 py-1.5">{{ r.name }}<span v-if="r.source" class="block text-xs text-gray-400">{{ r.source }}</span></td>
                <td class="px-3 py-1.5 text-right">{{ r.leads }}</td>
                <td class="px-3 py-1.5 text-right">{{ r.booked }}</td>
                <td class="px-3 py-1.5 text-right">{{ r.won }}</td>
                <td class="px-3 py-1.5 text-right">{{ r.conversion }}%</td>
                <td class="px-3 py-1.5 text-right">{{ inr(r.revenue) }}</td>
                <template v-if="block[0] !== 'by_executive'">
                  <td class="px-3 py-1.5 text-right">{{ r.spend ? inr(r.spend) : '—' }}</td>
                  <td class="px-3 py-1.5 text-right">{{ inr(r.cost_per_lead) }}</td>
                  <td class="px-3 py-1.5 text-right">{{ r.roi === null ? '—' : r.roi + '%' }}</td>
                </template>
              </tr>
            </tbody>
          </table>
          <p v-if="!(data[block[0]] as any[]).length" class="px-4 py-3 text-sm text-gray-500">No leads in these dates.</p>
        </div>
      </Card>

      <Card v-if="data.lost_reasons.length">
        <template #header><h3 class="font-semibold">Why leads were lost</h3></template>
        <div v-for="r in data.lost_reasons" :key="r.name" class="mb-1 flex items-center gap-2 text-sm">
          <span class="w-48 truncate">{{ r.name }}</span>
          <span class="h-2 rounded bg-gray-300" :style="{ width: `${Math.max(4, pct(r.count, data.lost_reasons[0].count) * 2)}px` }" />
          <span class="text-gray-500">{{ r.count }}</span>
        </div>
      </Card>
      <p class="mt-3 text-xs text-gray-500">Ad spend is entered per campaign and month in Settings › CRM › Campaigns.</p>
    </template>

    <template v-else-if="data && tab === 'retention'">
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm">
        <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Services due this month</p><p class="text-xl font-bold">{{ data.retention.service_due }}</p><p class="text-xs text-gray-500">{{ data.retention.service_due_booked }} came / booked</p></div>
        <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Cars delivered</p><p class="text-xl font-bold">{{ data.retention.delivered }}</p></div>
        <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Repeat customers</p><p class="text-xl font-bold">{{ data.retention.repeat_pct }}%</p><p class="text-xs text-gray-500">{{ data.retention.repeat_customers }} customers</p></div>
        <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Average rating</p><p class="text-xl font-bold">{{ data.retention.avg_rating || '—' }} ★</p><p class="text-xs text-gray-500">{{ data.retention.feedback_count }} ratings</p></div>
        <div class="rounded-xl p-3" :class="data.retention.low_ratings_open ? 'bg-red-50' : 'bg-white ring-1 ring-gray-200'"><p class="text-xs text-gray-500">Unhappy, not called back</p><p class="text-xl font-bold">{{ data.retention.low_ratings_open }}</p></div>
        <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200"><p class="text-xs text-gray-500">Referrals</p><p class="text-xl font-bold">{{ data.retention.referrals }}</p><p class="text-xs text-gray-500">{{ data.retention.referrals_rewarded }} rewarded</p></div>
      </div>
    </template>

    <template v-else-if="tab === 'feedback'">
      <div class="mb-3 flex items-center gap-3">
        <select v-model="fbFilter" class="input w-auto"><option value="open">Unhappy — to call back</option><option value="low">All 1–3 ★</option><option value="">All</option></select>
        <span v-if="feedback" class="text-sm text-gray-600">Last 90 days: <b>{{ feedback.average || '—' }} ★</b></span>
      </div>
      <Card :padding="false">
        <p v-if="!feedback?.rows.length" class="p-4 text-sm text-gray-500">Nothing here.</p>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="f in feedback?.rows" :key="f.id" class="px-4 py-3">
            <div class="flex items-center gap-2">
              <span class="font-semibold" :class="f.rating <= 3 ? 'text-red-700' : 'text-green-700'">{{ '★'.repeat(f.rating) }}<span class="text-gray-300">{{ '★'.repeat(5 - f.rating) }}</span></span>
              <RouterLink :to="`/customers/${f.customer_id}`" class="font-medium">{{ f.customer }}</RouterLink>
              <RouterLink :to="`/job-cards/${f.job_card_id}`" class="text-xs text-primary-700">{{ f.job_number }}</RouterLink>
              <span class="ml-auto text-xs text-gray-500">{{ fmtDateTime(f.at) }}</span>
            </div>
            <p v-if="f.comments" class="mt-1 text-gray-700">“{{ f.comments }}”</p>
            <p v-if="f.resolved_at" class="mt-1 text-xs text-green-700">Resolved by {{ f.resolved_by }}: {{ f.resolution_note }}</p>
            <div v-else-if="f.rating <= 3" class="mt-2 flex gap-2">
              <a :href="`tel:${f.phone}`" class="text-xs font-medium text-green-700">Call</a>
              <Button size="sm" variant="secondary" @click="resolving = f; resolveNote = ''">Mark resolved</Button>
            </div>
          </li>
        </ul>
      </Card>
    </template>

    <Modal :open="!!resolving" title="What was done?" size="sm" @close="resolving = null">
      <textarea v-model="resolveNote" rows="3" class="input" placeholder="Called, re-did the wash free of charge…" />
      <template #footer>
        <Button variant="secondary" @click="resolving = null">Cancel</Button>
        <Button :disabled="!resolveNote.trim()" @click="resolve">Save</Button>
      </template>
    </Modal>
  </div>
</template>
