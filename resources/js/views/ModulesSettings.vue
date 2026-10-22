<script setup lang="ts">
/**
 * Settings › Modules (owner): switch optional modules on or off. A module that is off
 * leaves the menu and stops its background work; the rest keeps working without it.
 * Nothing is deleted — switching back on brings everything back.
 */
import { ref, computed, onMounted } from 'vue'
import apiClient from '@/api/client'
import { useAuthStore } from '@/stores/auth.store'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Spinner from '@/components/ui/Spinner.vue'
import UnsavedChangesBar from '@/components/ui/UnsavedChangesBar.vue'

type Key = 'purchases' | 'accounts' | 'crm' | 'hr' | 'customer_app' | 'fleet'
const auth = useAuthStore()
const toast = useToast()

const MODULES: { key: Key; name: string; on: string; off: string }[] = [
  {
    key: 'purchases',
    name: 'Purchases & stock',
    on: 'Parts arrive on a GRN with photos, the technician checks right / wrong part, consumables stock and plan kits, supplier dues.',
    off: 'Parts are marked "Received" on the Parts Desk as before.',
  },
  {
    key: 'accounts',
    name: 'Accounts',
    on: 'Bills, receipts, GRNs, expenses and payroll post to the books by themselves; P&L, balance sheet, GST summary, expenses.',
    off: 'Nothing is posted. When you switch it back on, use Settings › Chart of Accounts › "Bring the books up to date" to post what happened meanwhile.',
  },
  {
    key: 'crm',
    name: 'CRM & leads',
    on: 'Leads from ads / calls / referrals, follow-ups, WhatsApp journeys, bookings with slots, feedback, referrals, service-due reminders.',
    off: 'No leads, bookings or reminders; job cards are opened directly at the counter as before. Webhook leads are refused.',
  },
  {
    key: 'hr',
    name: 'Attendance & payroll',
    on: 'Selfie punch in / out, leave, payroll and payslips, salary advances, customer money collected by staff.',
    off: 'No punching or payroll; "Collected by staff" is not offered on payments.',
  },
  {
    key: 'customer_app',
    name: 'Customer app',
    on: 'Customers sign in (job link or mobile + code) to track jobs, approve work, pay by UPI, book, keep documents and ask the assistant.',
    off: 'The customer app is closed; job and CRM links sent on WhatsApp keep working as before.',
  },
  {
    key: 'fleet',
    name: 'Fleet cabs & drivers',
    on: 'Cab owners get a fleet dashboard; their drivers sign in to the app, report problems, log km and approve extra work up to a limit.',
    off: 'Fleet customers are ordinary customers; drivers cannot sign in.',
  },
]

const saved = ref<Record<Key, boolean> | null>(null)
const form = ref<Record<Key, boolean> | null>(null)
const busy = ref(false)
const changed = computed(() => (form.value && saved.value ? MODULES.filter((m) => form.value![m.key] !== saved.value![m.key]) : []))

onMounted(async () => {
  try {
    saved.value = (await apiClient.get('/settings/modules')).data.data
    form.value = { ...saved.value! }
  } catch (err) {
    toast.apiError(err)
  }
})

async function save() {
  busy.value = true
  try {
    const payload = Object.fromEntries(changed.value.map((m) => [m.key, form.value![m.key]]))
    saved.value = (await apiClient.put('/settings/modules', payload)).data.data
    form.value = { ...saved.value! }
    await auth.fetchUser()
    toast.success('Modules saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900">Modules</h1>
    <p class="mb-4 text-sm text-gray-500">Switch off what the business doesn't use yet. Nothing is deleted — switching back on brings it all back.</p>

    <div v-if="!form" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <div v-else class="space-y-3">
      <Card v-for="m in MODULES" :key="m.key">
        <label class="flex items-start gap-3">
          <input v-model="form[m.key]" type="checkbox" class="mt-1 h-5 w-5 rounded" />
          <span>
            <span class="block font-semibold text-gray-900">{{ m.name }} <span class="ml-1 text-xs font-medium" :class="form[m.key] ? 'text-green-700' : 'text-gray-400'">{{ form[m.key] ? 'On' : 'Off' }}</span></span>
            <span class="block text-sm text-gray-600">{{ form[m.key] ? m.on : m.off }}</span>
          </span>
        </label>
      </Card>
    </div>

    <UnsavedChangesBar v-if="form" :count="changed.length" :labels="changed.map((m) => `${m.name}: ${form![m.key] ? 'on' : 'off'}`)" :saving="busy" @save="save" @discard="form = { ...saved! }" />
  </div>
</template>
