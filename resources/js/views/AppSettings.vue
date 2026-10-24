<script setup lang="ts">
/**
 * Settings › App address & customer app (owner): the address phones and customers reach the
 * ERP at (every link sent out uses it), a QR code to set up the staff Android app, and the
 * customer app's UPI details.
 */
import { ref, computed, onMounted, watch } from 'vue'
import QRCode from 'qrcode'
import apiClient from '@/api/client'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'

interface State {
  settings: Record<string, any>
  effective_url: string
  customer_app_url: string
}

const toast = useToast()
const state = ref<State | null>(null)
const form = ref<Record<string, any>>({})
const busy = ref(false)
const qr = ref<string | null>(null)
const customerQr = ref<string | null>(null)

const changed = computed(() => Object.keys(form.value).filter((k) => (form.value[k] ?? '') !== (state.value?.settings[k] ?? '')))

async function load() {
  try {
    state.value = (await apiClient.get('/settings/app')).data.data
    form.value = { ...state.value!.settings }
  } catch (err) {
    toast.apiError(err)
  }
}

async function save() {
  busy.value = true
  try {
    const payload = Object.fromEntries(changed.value.map((k) => [k, form.value[k]]))
    state.value = (await apiClient.put('/settings/app', { settings: payload })).data.data
    form.value = { ...state.value!.settings }
    toast.success('Saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

watch(
  () => state.value?.effective_url,
  async (url) => {
    if (!url) return
    qr.value = await QRCode.toDataURL(url, { margin: 1, width: 220, color: { dark: '#111827' } })
    customerQr.value = await QRCode.toDataURL(state.value!.customer_app_url, { margin: 1, width: 220, color: { dark: '#c4161c' } })
  },
)

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-3xl">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">App address &amp; customer app</h1>
      <p class="text-sm text-gray-500">Where phones and customers reach this system, and how customers pay in the app.</p>
    </div>

    <div v-if="!state" class="flex justify-center py-10"><Spinner size="lg" /></div>
    <template v-else>
      <Card class="mb-4">
        <h2 class="mb-1 font-semibold text-gray-900">Public address</h2>
        <p class="mb-3 text-sm text-gray-500">
          Every link sent to customers (job pages, bookings, feedback), lead webhooks and WhatsApp use this address.
          Leave it empty while testing on this computer; when the system is online, enter its web address.
        </p>
        <label class="block text-sm">
          <span class="text-gray-600">Address</span>
          <input v-model.trim="form['app.public_url']" class="input mt-1" placeholder="https://erp.expressautocare.in" inputmode="url" />
        </label>
        <div class="mt-4 flex flex-col items-center gap-4 sm:flex-row sm:items-start">
          <img v-if="qr" :src="qr" alt="QR code of the address" class="h-40 w-40 rounded-lg ring-1 ring-gray-200" />
          <div class="text-sm text-gray-600">
            <p class="font-medium text-gray-900">Set up a staff phone (EA Workshop Android app)</p>
            <ol class="mt-1 list-decimal space-y-1 pl-5">
              <li>Install the app and open it.</li>
              <li>Enter this address: <code class="rounded bg-gray-100 px-1 break-all">{{ state.effective_url }}</code></li>
              <li>Sign in as usual. To change it later: Settings › Change server address (in the app).</li>
            </ol>
            <p v-if="!state.settings['app.public_url']" class="mt-2 text-xs text-amber-700">
              No public address yet: phones must be on the same Wi-Fi as this computer, and links sent to customers only open on that Wi-Fi.
            </p>
          </div>
        </div>
      </Card>

      <Card class="mb-4">
        <h2 class="mb-1 font-semibold text-gray-900">Customer app</h2>
        <p class="mb-3 text-sm text-gray-500">Customers sign in with any job / booking link you send them, or with their mobile number and a code.</p>
        <div class="grid gap-3 text-sm sm:grid-cols-2">
          <label class="block">
            <span class="text-gray-600">Workshop UPI ID (for "Pay by UPI")</span>
            <input v-model.trim="form['customer_app.upi_id']" class="input mt-1" placeholder="expressautocare@okicici" />
          </label>
          <label class="block">
            <span class="text-gray-600">Name shown in the customer's UPI app</span>
            <input v-model.trim="form['customer_app.upi_name']" class="input mt-1" placeholder="Express Autocare" />
          </label>
          <label class="block">
            <span class="text-gray-600">Customer helpline (call button in the app)</span>
            <input v-model.trim="form['customer_app.support_phone']" class="input mt-1" inputmode="tel" />
          </label>
          <label class="block">
            <span class="text-gray-600">Sign-in code valid for (minutes)</span>
            <input v-model.number="form['customer_app.otp_minutes']" type="number" min="3" max="30" class="input mt-1" />
          </label>
          <label class="flex items-center gap-2 sm:col-span-2">
            <input v-model="form['customer_app.push_enabled']" type="checkbox" class="h-4 w-4 rounded" />
            Phone notifications for customers who install the web app (job updates, reminders)
          </label>
        </div>
        <div class="mt-4 flex flex-col items-center gap-4 sm:flex-row">
          <img v-if="customerQr" :src="customerQr" alt="QR code of the customer app" class="h-32 w-32 rounded-lg ring-1 ring-gray-200" />
          <p class="text-sm text-gray-600">
            Customer app: <code class="rounded bg-gray-100 px-1 break-all">{{ state.customer_app_url }}</code><br />
            Print this QR at the counter so customers can open and install the app.
          </p>
        </div>
      </Card>

      <div class="flex justify-end gap-2">
        <Button variant="secondary" :disabled="!changed.length" @click="form = { ...state.settings }">Discard</Button>
        <Button :loading="busy" :disabled="!changed.length" @click="save">Save changes ({{ changed.length }})</Button>
      </div>
    </template>
  </div>
</template>
