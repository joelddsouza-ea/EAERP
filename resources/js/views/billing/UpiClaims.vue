<script setup lang="ts">
/**
 * Billing › UPI payments to confirm: customers who paid by UPI in the app send the reference
 * (UTR). Check it in the bank, then Confirm (records the payment on the job, the customer is
 * told) or Reject with a reason the customer sees.
 */
import { ref, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import apiClient from '@/api/client'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'
import Modal from '@/components/ui/Modal.vue'

interface Claim {
  id: number
  amount: number
  utr: string
  status: 'pending' | 'confirmed' | 'rejected'
  reason: string | null
  created_at: string
  decided_at: string | null
  job: { id: number; job_number: string } | null
  customer: { id: number; name: string; phone: string } | null
  decided_by: string | null
  payment_number: string | null
  screenshot_url: string | null
}

const toast = useToast()
const status = ref<'pending' | 'confirmed' | 'rejected' | 'all'>('pending')
const rows = ref<Claim[]>([])
const pending = ref(0)
const loading = ref(true)
const busy = ref<number | null>(null)
const rejecting = ref<Claim | null>(null)
const reason = ref('')

async function load() {
  loading.value = true
  try {
    const data = (await apiClient.get('/customer-app/upi-claims', { params: { status: status.value } })).data.data
    rows.value = data.rows
    pending.value = data.pending
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}
watch(status, load)
onMounted(load)

async function confirm(c: Claim) {
  busy.value = c.id
  try {
    const res = await apiClient.post(`/customer-app/upi-claims/${c.id}/confirm`)
    toast.success(res.data.message)
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = null
  }
}

async function reject() {
  if (!rejecting.value) return
  busy.value = rejecting.value.id
  try {
    const res = await apiClient.post(`/customer-app/upi-claims/${rejecting.value.id}/reject`, { reason: reason.value })
    toast.success(res.data.message)
    rejecting.value = null
    reason.value = ''
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = null
  }
}

const inr = (n: number) => '₹ ' + new Intl.NumberFormat('en-IN').format(n)
const when = (d: string | null) => (d ? new Date(d).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '')
</script>

<template>
  <div class="mx-auto max-w-4xl">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">UPI payments to confirm</h1>
        <p class="text-sm text-gray-500">Paid by customers in the app. Check the UPI reference in the bank statement before confirming.</p>
      </div>
      <div class="flex rounded-xl bg-gray-100 p-1 text-sm font-medium">
        <button v-for="s in (['pending', 'confirmed', 'rejected', 'all'] as const)" :key="s" type="button" class="rounded-lg px-3 py-1.5 capitalize" :class="status === s ? 'bg-white shadow-sm' : 'text-gray-600'" @click="status = s">
          {{ s }}<template v-if="s === 'pending' && pending"> ({{ pending }})</template>
        </button>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><Spinner size="lg" /></div>
    <Card v-else :padding="false">
      <p v-if="!rows.length" class="p-4 text-sm text-gray-500">Nothing here.</p>
      <ul class="divide-y divide-gray-100">
        <li v-for="c in rows" :key="c.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">{{ inr(c.amount) }} <span class="font-mono text-sm font-normal text-gray-600">UTR {{ c.utr }}</span></p>
            <p class="text-sm text-gray-600">
              {{ c.customer?.name }} · {{ c.customer?.phone }} ·
              <RouterLink v-if="c.job" :to="`/job-cards/${c.job.id}`" class="text-primary-700 hover:underline">{{ c.job.job_number }}</RouterLink>
            </p>
            <p class="text-xs text-gray-400">
              Sent {{ when(c.created_at) }}
              <template v-if="c.status !== 'pending'"> · {{ c.status }} by {{ c.decided_by }} {{ when(c.decided_at) }}</template>
              <template v-if="c.payment_number"> · receipt {{ c.payment_number }}</template>
              <template v-if="c.reason"> · {{ c.reason }}</template>
            </p>
          </div>
          <a v-if="c.screenshot_url" :href="c.screenshot_url" target="_blank" rel="noopener" class="text-sm text-primary-700 hover:underline">Screenshot</a>
          <template v-if="c.status === 'pending'">
            <Button size="sm" variant="secondary" :disabled="busy === c.id" @click="rejecting = c; reason = ''">Reject</Button>
            <Button size="sm" :loading="busy === c.id" @click="confirm(c)">Confirm &amp; record</Button>
          </template>
        </li>
      </ul>
    </Card>

    <Modal :open="!!rejecting" title="Reject this UPI payment" @close="rejecting = null">
      <p class="text-sm text-gray-600">The customer sees this reason in the app.</p>
      <textarea v-model="reason" rows="3" class="input mt-2" placeholder="e.g. Not in the bank statement; please share the screenshot on WhatsApp"></textarea>
      <template #footer>
        <Button variant="secondary" @click="rejecting = null">Cancel</Button>
        <Button variant="danger" :disabled="!reason.trim()" :loading="busy === rejecting?.id" @click="reject">Reject</Button>
      </template>
    </Modal>
  </div>
</template>
