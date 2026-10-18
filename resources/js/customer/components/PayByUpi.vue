<script setup lang="ts">
/**
 * Pay the balance by UPI: one tap opens the customer's UPI app with the amount filled in
 * (or scan the QR from another phone). Then they enter the UPI reference so the workshop
 * can match it; it shows as "Received" once back office confirms.
 */
import { ref, computed, onMounted, watch } from 'vue'
import QRCode from 'qrcode'
import { CheckCircleIcon, ClockIcon, XCircleIcon } from '@heroicons/vue/24/solid'
import api, { message, money, date, type JobScreen } from '../api'
import { toast } from '../ui'

const props = defineProps<{ jobId: number; jobNumber: string; payment: JobScreen['payment'] }>()
const emit = defineEmits<{ claimed: [] }>()

const pending = computed(() => props.payment.claims.filter((c) => c.status === 'pending').reduce((s, c) => s + c.amount, 0))
const due = computed(() => Math.max(0, (props.payment.balance ?? 0) - pending.value))
const amount = ref<number>(0)
const utr = ref('')
const screenshot = ref<File | null>(null)
const showForm = ref(false)
const busy = ref(false)
const qr = ref<string | null>(null)

const upiLink = computed(() => {
  if (!props.payment.upi_id || !amount.value) return null
  const q = new URLSearchParams({ pa: props.payment.upi_id, pn: props.payment.upi_name, am: amount.value.toFixed(2), cu: 'INR', tn: `Job ${props.jobNumber}` })
  return `upi://pay?${q.toString()}`
})

onMounted(() => (amount.value = due.value))
watch(upiLink, async (link) => (qr.value = link ? await QRCode.toDataURL(link, { margin: 1, width: 200 }) : null), { immediate: true })

async function submit() {
  busy.value = true
  try {
    const form = new FormData()
    form.append('amount', String(amount.value))
    form.append('utr', utr.value)
    if (screenshot.value) form.append('screenshot', screenshot.value)
    const res = await api.post(`/jobs/${props.jobId}/payment-claims`, form)
    toast(res.data.message)
    showForm.value = false
    utr.value = ''
    emit('claimed')
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200/80">
    <div class="flex items-center justify-between">
      <h2 class="font-semibold text-gray-900">Payment</h2>
      <span class="text-sm text-gray-500">Paid {{ money(payment.paid) }}</span>
    </div>
    <p class="mt-1 text-2xl font-bold text-gray-900">{{ money(payment.balance) }} <span class="text-sm font-normal text-gray-500">to pay</span></p>

    <ul v-if="payment.claims.length" class="mt-3 space-y-1.5 text-sm">
      <li v-for="c in payment.claims" :key="c.id" class="flex items-center gap-2">
        <CheckCircleIcon v-if="c.status === 'confirmed'" class="h-5 w-5 text-green-600" />
        <ClockIcon v-else-if="c.status === 'pending'" class="h-5 w-5 text-amber-500" />
        <XCircleIcon v-else class="h-5 w-5 text-red-600" />
        <span class="flex-1">{{ money(c.amount) }} · UPI {{ c.utr }} · {{ date(c.created_at, false) }}</span>
        <span class="text-xs font-medium" :class="c.status === 'confirmed' ? 'text-green-700' : c.status === 'pending' ? 'text-amber-700' : 'text-red-700'">
          {{ c.status === 'confirmed' ? 'Received' : c.status === 'pending' ? 'Being checked' : 'Not found' }}
        </span>
      </li>
      <li v-for="c in payment.claims.filter((x) => x.status === 'rejected' && x.reason)" :key="'r' + c.id" class="text-xs text-red-700">{{ c.reason }}</li>
    </ul>

    <template v-if="due > 0">
      <template v-if="payment.upi_id">
        <p v-if="pending > 0" class="mt-3 text-xs text-amber-700">{{ money(pending) }} you sent is being checked, so the rest is {{ money(due) }}.</p>
        <label class="mt-4 block text-sm text-gray-600">Amount to pay now
          <input v-model.number="amount" type="number" min="1" :max="due" step="1" class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 text-lg ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600" />
        </label>
        <a v-if="upiLink" :href="upiLink" class="mt-3 block rounded-xl bg-primary-600 py-3 text-center text-base font-semibold text-white">Pay {{ money(amount) }} by UPI</a>
        <p class="mt-2 text-center text-xs text-gray-500">Opens GPay / PhonePe / Paytm / BHIM with the amount filled in. UPI ID: {{ payment.upi_id }}</p>
        <details v-if="qr" class="mt-2 text-center text-sm text-gray-600">
          <summary class="cursor-pointer">Pay from another phone (QR)</summary>
          <img :src="qr" alt="UPI QR code" class="mx-auto mt-2 h-44 w-44" />
        </details>

        <button v-if="!showForm" type="button" class="mt-4 w-full rounded-xl py-2.5 text-sm font-semibold text-primary-700 ring-1 ring-primary-600" @click="showForm = true">I've paid: send the UPI reference</button>
        <form v-else class="mt-4 space-y-3 rounded-xl bg-gray-50 p-3" @submit.prevent="submit">
          <label class="block text-sm text-gray-700">UPI reference / UTR (12 digits, in your UPI app's payment details)
            <input v-model.trim="utr" inputmode="numeric" maxlength="22" class="mt-1 w-full rounded-lg border-0 px-3 py-2 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600" required />
          </label>
          <label class="block text-sm text-gray-700">Screenshot (optional)
            <input type="file" accept="image/*" class="mt-1 block w-full text-sm" @change="screenshot = ($event.target as HTMLInputElement).files?.[0] ?? null" />
          </label>
          <button type="submit" :disabled="busy || utr.length < 10" class="w-full rounded-xl bg-gray-900 py-2.5 font-semibold text-white disabled:opacity-50">{{ busy ? 'Sending…' : `Send — I paid ${money(amount)}` }}</button>
        </form>
      </template>
      <p v-else class="mt-3 text-sm text-gray-600">Pay at the workshop by cash, card or UPI.</p>
    </template>
    <p v-else-if="(payment.balance ?? 0) <= 0 && payment.bill_total !== null" class="mt-2 text-sm font-medium text-green-700">Fully paid. Thank you!</p>
  </section>
</template>
