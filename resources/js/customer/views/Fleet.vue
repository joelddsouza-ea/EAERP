<script setup lang="ts">
/**
 * Fleet dashboard (owner): every cab — at the workshop (stage, approvals, money due) or on
 * the road (km, next service, open problems) — and what the fleet spent this month and year.
 */
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { ChevronLeftIcon, WrenchScrewdriverIcon, ExclamationCircleIcon, UserGroupIcon } from '@heroicons/vue/24/outline'
import api, { message, money, date, type FleetDashboard } from '../api'
import { toast } from '../ui'

const dash = ref<FleetDashboard | null>(null)
const filter = ref<'all' | 'workshop' | 'attention'>('all')

const cabs = computed(() => {
  const rows = dash.value?.cabs ?? []
  if (filter.value === 'workshop') return rows.filter((c) => c.state === 'workshop')
  if (filter.value === 'attention') return rows.filter((c) => c.job?.approvals_pending || c.job?.balance || c.open_reports || c.service_due_soon)
  return rows
})

onMounted(async () => {
  try {
    dash.value = (await api.get('/fleet')).data.data
  } catch (err) {
    toast(message(err), 'error')
  }
})
</script>

<template>
  <div>
    <RouterLink to="/" class="-ml-1 mb-2 inline-flex items-center text-sm text-gray-600"><ChevronLeftIcon class="h-4 w-4" /> Home</RouterLink>
    <div v-if="!dash" class="mt-10 text-center text-gray-500">Loading…</div>

    <template v-else>
      <div class="flex items-start justify-between">
        <div>
          <h1 class="text-xl font-bold text-gray-900">{{ dash.fleet.name }}</h1>
          <p class="text-sm text-gray-500">Drivers can approve up to {{ money(dash.fleet.driver_limit) }} per job</p>
        </div>
        <RouterLink to="/fleet/drivers" class="inline-flex items-center gap-1 rounded-full bg-white px-3 py-1.5 text-sm font-medium text-primary-700 ring-1 ring-gray-200"><UserGroupIcon class="h-4 w-4" /> Drivers</RouterLink>
      </div>

      <section class="mt-4 grid grid-cols-2 gap-2 text-sm">
        <div class="rounded-2xl bg-white p-3 ring-1 ring-gray-200/80">
          <p class="text-xs text-gray-500">At the workshop</p>
          <p class="text-lg font-bold">{{ dash.totals.at_workshop }} <span class="text-sm font-normal text-gray-500">of {{ dash.totals.cabs }} cabs</span></p>
        </div>
        <div class="rounded-2xl p-3 ring-1" :class="dash.totals.approvals_waiting ? 'bg-amber-50 ring-amber-200' : 'bg-white ring-gray-200/80'">
          <p class="text-xs text-gray-500">Waiting for your approval</p>
          <p class="text-lg font-bold">{{ dash.totals.approvals_waiting }} <span class="text-sm font-normal text-gray-500">item(s)</span></p>
        </div>
        <div class="rounded-2xl bg-white p-3 ring-1 ring-gray-200/80">
          <p class="text-xs text-gray-500">To pay now</p>
          <p class="text-lg font-bold">{{ money(dash.totals.balance_due) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-3 ring-1 ring-gray-200/80">
          <p class="text-xs text-gray-500">Spent this month · year</p>
          <p class="text-lg font-bold">{{ money(dash.totals.spend_month) }} <span class="text-sm font-normal text-gray-500">· {{ money(dash.totals.spend_year) }}</span></p>
        </div>
      </section>
      <p v-if="dash.totals.open_reports || dash.totals.service_due_soon" class="mt-2 text-sm text-gray-600">
        <template v-if="dash.totals.open_reports">{{ dash.totals.open_reports }} open problem report(s). </template>
        <template v-if="dash.totals.service_due_soon">{{ dash.totals.service_due_soon }} cab(s) due for service soon.</template>
      </p>

      <div class="mt-4 flex gap-2 text-sm">
        <button v-for="f in [{ k: 'all', l: 'All cabs' }, { k: 'workshop', l: 'At workshop' }, { k: 'attention', l: 'Need attention' }] as const" :key="f.k" type="button"
          class="rounded-full px-3 py-1.5 font-medium" :class="filter === f.k ? 'bg-primary-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200'" @click="filter = f.k">{{ f.l }}</button>
      </div>

      <p v-if="!cabs.length" class="mt-3 rounded-2xl bg-white p-4 text-sm text-gray-500 ring-1 ring-gray-200/80">Nothing here.</p>
      <div class="mt-3 space-y-3">
        <RouterLink v-for="c in cabs" :key="c.id" :to="c.job ? `/jobs/${c.job.id}` : `/cars/${c.id}`" class="block rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200/80">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="font-bold text-gray-900">{{ c.registration_no }}</p>
              <p class="text-sm text-gray-500">{{ c.car }}<template v-if="c.drivers.length"> · {{ c.drivers.map((d) => d.name.split(' ')[0]).join(', ') }}</template></p>
            </div>
            <span v-if="c.state === 'workshop'" class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-700"><WrenchScrewdriverIcon class="h-4 w-4" /> {{ c.job?.status_label }}</span>
            <span v-else class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">On the road</span>
          </div>
          <div class="mt-2 space-y-1 text-sm">
            <p v-if="c.job?.approvals_pending" class="flex items-center gap-1.5 font-medium text-amber-700"><ExclamationCircleIcon class="h-4 w-4" /> {{ c.job.approvals_pending }} item(s) need approval</p>
            <p v-if="c.job?.balance" class="font-medium text-gray-800">{{ money(c.job.balance) }} to pay</p>
            <p v-if="c.open_reports" class="text-red-700">{{ c.open_reports }} problem report(s) open</p>
          </div>
          <div class="mt-2 grid grid-cols-3 gap-2 text-center text-xs">
            <div class="rounded-lg bg-gray-50 p-2">
              <p class="text-[10px] uppercase text-gray-500">Km</p>
              <p class="font-semibold">{{ c.km !== null ? c.km.toLocaleString('en-IN') : '-' }}</p>
            </div>
            <div class="rounded-lg p-2" :class="c.service_due_soon ? 'bg-amber-50 text-amber-800' : 'bg-gray-50'">
              <p class="text-[10px] uppercase text-gray-500">Service</p>
              <p class="font-semibold">{{ c.km_to_service !== null ? (c.km_to_service <= 0 ? 'Due now' : `in ${c.km_to_service.toLocaleString('en-IN')} km`) : c.next_service_due ? date(c.next_service_due, false) : '-' }}</p>
            </div>
            <div class="rounded-lg bg-gray-50 p-2">
              <p class="text-[10px] uppercase text-gray-500">This year</p>
              <p class="font-semibold">{{ money(c.spend_year) }}</p>
            </div>
          </div>
        </RouterLink>
      </div>
    </template>
  </div>
</template>
