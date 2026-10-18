<script setup lang="ts">
/**
 * Sign in: mobile number → a 6-digit code (on WhatsApp, or read out by the workshop).
 * Customers who open a job / booking link from WhatsApp are signed in without this screen.
 */
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { message } from '../api'
import { signIn } from '../session'

const route = useRoute()
const router = useRouter()

const step = ref<'phone' | 'code'>('phone')
const phone = ref('')
const code = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const info = ref<string | null>(null)
const via = ref<'interakt' | 'staff'>('staff')
const support = ref<string | null>(null)

async function sendCode() {
  error.value = null
  const digits = phone.value.replace(/\D/g, '')
  if (digits.length < 10) {
    error.value = 'Enter your 10-digit mobile number.'
    return
  }
  busy.value = true
  try {
    const res = await api.post('/auth/code', { phone: phone.value })
    via.value = res.data.data.via
    support.value = res.data.data.support_phone
    info.value = res.data.message
    step.value = 'code'
  } catch (err) {
    error.value = message(err)
  } finally {
    busy.value = false
  }
}

async function verify() {
  error.value = null
  busy.value = true
  try {
    const res = await api.post('/auth/verify', { phone: phone.value, code: code.value })
    signIn(res.data.data.token, res.data.data.customer)
    router.replace(String(route.query.next || '/'))
  } catch (err) {
    error.value = message(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col bg-white px-6 pt-[calc(3rem+env(safe-area-inset-top))]">
    <img src="/images/logo.png" alt="Express Autocare" class="mx-auto h-16 w-auto" />
    <h1 class="mt-6 text-center text-2xl font-bold text-gray-900">Your car, in your pocket</h1>
    <p class="mt-1 text-center text-sm text-gray-500">Track your service live, approve work, pay and book in a few taps.</p>

    <form v-if="step === 'phone'" class="mx-auto mt-8 w-full max-w-sm" @submit.prevent="sendCode">
      <label class="block text-sm font-medium text-gray-700" for="phone">Mobile number</label>
      <div class="mt-1 flex rounded-xl ring-1 ring-gray-300 focus-within:ring-2 focus-within:ring-primary-600">
        <span class="flex items-center pl-3 text-gray-500">+91</span>
        <input id="phone" v-model="phone" type="tel" inputmode="numeric" autocomplete="tel" maxlength="14" class="w-full rounded-xl border-0 px-3 py-3 text-lg focus:ring-0" placeholder="98xxxxxxxx" />
      </div>
      <p v-if="error" class="mt-2 text-sm text-red-700">{{ error }}</p>
      <button type="submit" :disabled="busy" class="mt-4 w-full rounded-xl bg-primary-600 py-3 text-base font-semibold text-white disabled:opacity-60">
        {{ busy ? 'Please wait…' : 'Get my sign-in code' }}
      </button>
      <p class="mt-6 text-center text-xs text-gray-500">Got a link from us on WhatsApp? Just open it: you'll be signed in.</p>
    </form>

    <form v-else class="mx-auto mt-8 w-full max-w-sm" @submit.prevent="verify">
      <p class="rounded-xl bg-gray-50 p-3 text-sm text-gray-700">{{ info }}</p>
      <a v-if="via === 'staff' && support" :href="`tel:${support}`" class="mt-2 block text-center text-sm font-medium text-primary-700">Call the workshop: {{ support }}</a>
      <label class="mt-5 block text-sm font-medium text-gray-700" for="code">6-digit code</label>
      <input id="code" v-model="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="mt-1 w-full rounded-xl border-0 px-3 py-3 text-center text-2xl tracking-[0.5em] ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600" />
      <p v-if="error" class="mt-2 text-sm text-red-700">{{ error }}</p>
      <button type="submit" :disabled="busy || code.length < 6" class="mt-4 w-full rounded-xl bg-primary-600 py-3 text-base font-semibold text-white disabled:opacity-60">
        {{ busy ? 'Checking…' : 'Sign in' }}
      </button>
      <button type="button" class="mt-3 w-full text-sm text-gray-600" @click="step = 'phone'; code = ''; error = null">Change number / send again</button>
    </form>
  </div>
</template>
