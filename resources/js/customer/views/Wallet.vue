<script setup lang="ts">
/**
 * Wallet: credit for the next visit (refunds kept, referral rewards), the referral link to
 * share, and the current offer.
 */
import { ref, onMounted } from 'vue'
import { GiftIcon, ShareIcon } from '@heroicons/vue/24/outline'
import api, { message, money, date } from '../api'
import { toast } from '../ui'

interface Wallet {
  balance: number
  entries: { amount: number; note: string | null; date: string | null }[]
  referral: { code: string | null; url: string; reward: number } | null
  offer: string | null
}

const wallet = ref<Wallet | null>(null)

onMounted(async () => {
  try {
    wallet.value = (await api.get('/wallet')).data.data
  } catch (err) {
    toast(message(err), 'error')
  }
})

async function share() {
  const r = wallet.value?.referral
  if (!r) return
  const text = `I get my car serviced at Express Autocare: fixed prices, photos of every job, pickup & drop. Book with my link: ${r.url}`
  try {
    if (navigator.share) await navigator.share({ title: 'Express Autocare', text })
    else {
      await navigator.clipboard.writeText(text)
      toast('Link copied. Paste it in WhatsApp.')
    }
  } catch {
    /* share sheet closed */
  }
}
</script>

<template>
  <div>
    <h1 class="text-xl font-bold text-gray-900">Wallet</h1>
    <p v-if="!wallet" class="mt-8 text-center text-gray-500">Loading…</p>
    <template v-else>
      <section class="mt-4 rounded-2xl bg-gradient-to-br from-primary-600 to-primary-800 p-5 text-white shadow">
        <p class="text-sm opacity-90">Credit for your next visit</p>
        <p class="mt-1 text-3xl font-bold">{{ money(wallet.balance) }}</p>
        <p class="mt-1 text-xs opacity-80">Used automatically on your bill when you ask at the counter.</p>
      </section>

      <section v-if="wallet.referral" class="mt-4 rounded-2xl bg-white p-4 ring-1 ring-gray-200/80">
        <div class="flex items-start gap-3">
          <GiftIcon class="h-8 w-8 shrink-0 text-primary-600" />
          <div>
            <p class="font-semibold text-gray-900">Refer a friend<template v-if="wallet.referral.reward">, get {{ money(wallet.referral.reward) }}</template></p>
            <p class="text-sm text-gray-600">When they get their car serviced with your link, the reward lands in your wallet.</p>
            <p v-if="wallet.referral.code" class="mt-2 text-sm">Your code: <span class="rounded bg-gray-100 px-2 py-0.5 font-mono font-semibold">{{ wallet.referral.code }}</span></p>
          </div>
        </div>
        <button type="button" class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 py-2.5 font-semibold text-white" @click="share"><ShareIcon class="h-5 w-5" /> Share my link</button>
      </section>

      <p v-if="wallet.offer" class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">{{ wallet.offer }}</p>

      <section v-if="wallet.entries.length" class="mt-4">
        <h2 class="mb-2 font-semibold text-gray-900">History</h2>
        <ul class="divide-y divide-gray-100 rounded-2xl bg-white ring-1 ring-gray-200/80">
          <li v-for="(e, i) in wallet.entries" :key="i" class="flex items-center justify-between px-4 py-3 text-sm">
            <span><span class="block text-gray-800">{{ e.note || (e.amount > 0 ? 'Credit added' : 'Credit used') }}</span><span class="text-xs text-gray-400">{{ date(e.date) }}</span></span>
            <span class="font-semibold" :class="e.amount > 0 ? 'text-green-700' : 'text-gray-700'">{{ e.amount > 0 ? '+' : '' }}{{ money(e.amount) }}</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
