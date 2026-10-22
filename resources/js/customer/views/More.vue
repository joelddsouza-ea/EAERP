<script setup lang="ts">
/**
 * More: visits, bookings, wallet, documents, install the app, call us, sign out.
 */
import { ref, computed, onMounted } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { ClipboardDocumentListIcon, CalendarDaysIcon, WalletIcon, DocumentTextIcon, ArrowDownOnSquareIcon, PhoneIcon, ArrowRightOnRectangleIcon, ChartBarIcon, UserGroupIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import api from '../api'
import { session, signOutLocally } from '../session'

const router = useRouter()
const support = ref<string | null>(null)
let installPrompt: any = null
const canInstall = ref(false)

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault()
  installPrompt = e
  canInstall.value = true
})

onMounted(async () => {
  try {
    support.value = (await api.get('/home')).data.data.support_phone
  } catch {
    /* optional */
  }
})

async function install() {
  installPrompt?.prompt()
  canInstall.value = false
}

async function signOut() {
  try {
    await api.post('/auth/logout')
  } catch {
    /* signed out locally anyway */
  }
  signOutLocally()
  router.replace('/login')
}

// Drivers: no wallet; fleet owner: fleet dashboard and drivers; both: problem reports
const links = computed(() => {
  const role = session.viewer?.role ?? 'customer'
  return [
    ...(role === 'fleet_owner' ? [{ to: '/fleet', label: 'Fleet dashboard', icon: ChartBarIcon }, { to: '/fleet/drivers', label: 'Drivers', icon: UserGroupIcon }] : []),
    ...(role !== 'customer' ? [{ to: '/reports', label: 'Problem reports', icon: ExclamationTriangleIcon }] : []),
    { to: '/jobs', label: role === 'customer' ? 'My visits' : 'Visits', icon: ClipboardDocumentListIcon },
    { to: '/bookings', label: role === 'customer' ? 'My bookings' : 'Bookings', icon: CalendarDaysIcon },
    ...(role !== 'driver' ? [{ to: '/wallet', label: 'Wallet & referrals', icon: WalletIcon }] : []),
    { to: '/documents', label: role === 'driver' ? 'Cab documents' : 'My documents', icon: DocumentTextIcon },
  ]
})
</script>

<template>
  <div>
    <h1 class="text-xl font-bold text-gray-900">{{ session.customer?.name }}</h1>
    <p class="text-sm text-gray-500">{{ session.customer?.phone }}<template v-if="session.viewer?.role === 'driver'"> · driver for {{ session.viewer.fleet_name }}</template></p>

    <ul class="mt-4 divide-y divide-gray-100 rounded-2xl bg-white ring-1 ring-gray-200/80">
      <li v-for="l in links" :key="l.to">
        <RouterLink :to="l.to" class="flex items-center gap-3 px-4 py-3.5 text-gray-800"><component :is="l.icon" class="h-5 w-5 text-gray-500" /> {{ l.label }}</RouterLink>
      </li>
      <li v-if="canInstall">
        <button type="button" class="flex w-full items-center gap-3 px-4 py-3.5 text-left text-gray-800" @click="install"><ArrowDownOnSquareIcon class="h-5 w-5 text-gray-500" /> Install the app on this phone</button>
      </li>
      <li v-if="support">
        <a :href="`tel:${support}`" class="flex items-center gap-3 px-4 py-3.5 text-gray-800"><PhoneIcon class="h-5 w-5 text-gray-500" /> Call us · {{ support }}</a>
      </li>
      <li>
        <button type="button" class="flex w-full items-center gap-3 px-4 py-3.5 text-left text-red-700" @click="signOut"><ArrowRightOnRectangleIcon class="h-5 w-5" /> Sign out</button>
      </li>
    </ul>
    <p class="mt-6 text-center text-xs text-gray-400">On iPhone: Safari › Share › Add to Home Screen to install.</p>
  </div>
</template>
