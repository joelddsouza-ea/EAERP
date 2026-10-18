<script setup lang="ts">
/**
 * Home: jobs in progress (with what needs the customer: approval, payment, driver on the
 * way), their cars with service / insurance due dates, and quick actions.
 */
import { ref, onMounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { WrenchScrewdriverIcon, TruckIcon, ExclamationCircleIcon, PlusIcon, CalendarDaysIcon, SparklesIcon, DocumentTextIcon, WalletIcon, PhoneIcon } from '@heroicons/vue/24/outline'
import api, { message, money, date, type Home } from '../api'
import { session } from '../session'
import { ui, toast, enablePush } from '../ui'

const home = ref<Home | null>(null)
const loading = ref(true)

const greeting = computed(() => {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'
})

function daysTo(d: string | null): number | null {
  if (!d) return null
  return Math.ceil((new Date(d).getTime() - Date.now()) / 86400000)
}

async function load() {
  try {
    home.value = (await api.get('/home')).data.data
    ui.unread = home.value!.unread
    // Phone notifications: asked once, quietly skipped where not supported
    if (home.value!.push_key && localStorage.getItem('ea_push_asked') !== '1') {
      try { localStorage.setItem('ea_push_asked', '1') } catch { /* blocked */ }
      enablePush(home.value!.push_key, (sub) => api.post('/push-subscriptions', sub))
    }
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <h1 class="text-xl font-bold text-gray-900">{{ greeting }}, {{ (home?.customer.name ?? session.customer?.name ?? '').split(' ')[0] }}</h1>

    <div v-if="loading" class="mt-10 text-center text-gray-500">Loading…</div>

    <template v-else-if="home">
      <!-- Jobs in progress -->
      <section v-if="home.active_jobs.length" class="mt-4 space-y-3">
        <RouterLink v-for="j in home.active_jobs" :key="j.id" :to="`/jobs/${j.id}`" class="block rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200/80">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-primary-600">In service</p>
              <p class="text-lg font-bold text-gray-900">{{ j.registration_no }}</p>
              <p class="text-sm text-gray-500">{{ j.car }}<template v-if="j.plan"> · {{ j.plan }}</template></p>
            </div>
            <WrenchScrewdriverIcon class="h-7 w-7 text-primary-600" />
          </div>
          <p class="mt-3 inline-flex rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-800">{{ j.status_label }}</p>
          <div class="mt-3 space-y-1.5 text-sm">
            <p v-if="j.approvals_pending" class="flex items-center gap-2 font-medium text-amber-700"><ExclamationCircleIcon class="h-5 w-5" /> {{ j.approvals_pending }} item(s) need your approval</p>
            <p v-if="j.driver_on_the_way" class="flex items-center gap-2 font-medium text-green-700"><TruckIcon class="h-5 w-5" /> Our team is on the way: track live</p>
            <p v-if="j.balance" class="flex items-center gap-2 font-medium text-gray-800"><WalletIcon class="h-5 w-5" /> {{ money(j.balance) }} to pay</p>
          </div>
        </RouterLink>
      </section>

      <!-- Quick actions -->
      <section class="mt-4 grid grid-cols-3 gap-2 text-center text-xs font-medium text-gray-700">
        <RouterLink to="/book" class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-gray-200/80"><CalendarDaysIcon class="mx-auto mb-1 h-6 w-6 text-primary-600" />Book a service</RouterLink>
        <RouterLink v-if="home.assistant.enabled" to="/assistant" class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-gray-200/80"><SparklesIcon class="mx-auto mb-1 h-6 w-6 text-primary-600" />What's wrong with my car?</RouterLink>
        <RouterLink v-else to="/documents" class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-gray-200/80"><DocumentTextIcon class="mx-auto mb-1 h-6 w-6 text-primary-600" />My documents</RouterLink>
        <a v-if="home.support_phone" :href="`tel:${home.support_phone}`" class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-gray-200/80"><PhoneIcon class="mx-auto mb-1 h-6 w-6 text-primary-600" />Call us</a>
        <RouterLink v-else to="/wallet" class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-gray-200/80"><WalletIcon class="mx-auto mb-1 h-6 w-6 text-primary-600" />Wallet</RouterLink>
      </section>

      <!-- Cars -->
      <section class="mt-5">
        <div class="mb-2 flex items-center justify-between">
          <h2 class="font-semibold text-gray-900">My cars</h2>
          <RouterLink to="/cars/new" class="inline-flex items-center gap-1 text-sm font-medium text-primary-700"><PlusIcon class="h-4 w-4" /> Add a car</RouterLink>
        </div>
        <p v-if="!home.vehicles.length" class="rounded-2xl bg-white p-4 text-sm text-gray-500 ring-1 ring-gray-200/80">No cars yet. Add your car to book and get reminders.</p>
        <div class="space-y-3">
          <RouterLink v-for="v in home.vehicles" :key="v.id" :to="`/cars/${v.id}`" class="block rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200/80">
            <div class="flex items-center justify-between">
              <div>
                <p class="font-bold text-gray-900">{{ v.registration_no }}</p>
                <p class="text-sm text-gray-500">{{ v.car }}<template v-if="v.year"> · {{ v.year }}</template></p>
              </div>
              <span class="text-xs font-medium text-primary-700">Service book ›</span>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
              <div class="rounded-lg p-2" :class="(daysTo(v.next_service_due) ?? 99) <= 7 ? 'bg-amber-50 text-amber-800' : 'bg-gray-50 text-gray-700'">
                <p class="text-[10px] uppercase text-gray-500">Service due</p>
                <p class="font-semibold">{{ v.next_service_due ? date(v.next_service_due, false) : '-' }}</p>
              </div>
              <div class="rounded-lg p-2" :class="(daysTo(v.insurance_expiry) ?? 99) <= 30 ? 'bg-amber-50 text-amber-800' : 'bg-gray-50 text-gray-700'">
                <p class="text-[10px] uppercase text-gray-500">Insurance</p>
                <p class="font-semibold">{{ v.insurance_expiry ? date(v.insurance_expiry, false) : 'Add' }}</p>
              </div>
              <div class="rounded-lg p-2" :class="(daysTo(v.puc_expiry) ?? 99) <= 30 ? 'bg-amber-50 text-amber-800' : 'bg-gray-50 text-gray-700'">
                <p class="text-[10px] uppercase text-gray-500">PUC</p>
                <p class="font-semibold">{{ v.puc_expiry ? date(v.puc_expiry, false) : 'Add' }}</p>
              </div>
            </div>
          </RouterLink>
        </div>
      </section>

      <RouterLink to="/jobs" class="mt-4 block text-center text-sm font-medium text-gray-600">All my visits ›</RouterLink>
      <p v-if="home.credit > 0" class="mt-3 rounded-xl bg-green-50 p-3 text-center text-sm text-green-800">You have {{ money(home.credit) }} credit for your next visit.</p>
    </template>
  </div>
</template>
