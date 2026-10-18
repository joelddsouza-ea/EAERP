<script setup lang="ts">
/**
 * Runs the live-location tracker on every screen from punch in to punch out: checks with
 * the server when the app opens (and every few minutes), shows the one-time notice, and a
 * slim bar telling the employee whether their location is being shared.
 */
import { onMounted, onBeforeUnmount, computed, ref } from 'vue'
import { MapPinIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { hrApi } from '@/api/hr.api'
import { useAuthStore } from '@/stores/auth.store'
import { useLocationTracker } from '@/composables/useLocationTracker'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import Button from '@/components/ui/Button.vue'

const auth = useAuthStore()
const toast = useToast()
const tracker = useLocationTracker()
const accepting = ref(false)

const eligible = computed(() => auth.isAuthenticated && auth.hasFeature('hr') && auth.hasPermission('hr.self'))

async function check() {
  if (!eligible.value) {
    if (tracker.status.value !== 'off') await tracker.stop()
    return
  }
  try {
    await tracker.sync((await hrApi.today()).data.data.tracking)
  } catch {
    /* offline: keep doing what we were doing */
  }
}

async function accept() {
  accepting.value = true
  try {
    await tracker.acceptConsent()
  } catch (err) {
    toast.apiError(err)
  } finally {
    accepting.value = false
  }
}

function onVisible() {
  if (document.visibilityState === 'visible') {
    void check()
    void tracker.flush()
  }
}

let poll: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  void check()
  // Catches a punch made on another phone, or a manager's change
  poll = setInterval(check, 5 * 60 * 1000)
  document.addEventListener('visibilitychange', onVisible)
  window.addEventListener('online', onVisible)
})
onBeforeUnmount(() => {
  if (poll) clearInterval(poll)
  document.removeEventListener('visibilitychange', onVisible)
  window.removeEventListener('online', onVisible)
})
</script>

<template>
  <div v-if="tracker.status.value !== 'off'" class="mb-3">
    <div
      v-if="tracker.status.value === 'denied' || tracker.status.value === 'unavailable'"
      class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
    >
      <ExclamationTriangleIcon class="mt-0.5 h-4 w-4 shrink-0" />
      <div class="flex-1">
        <template v-if="tracker.status.value === 'denied'">
          Location is blocked for this app. You are punched in, so your manager needs your location: allow it in the phone settings
          ({{ tracker.native.value ? 'Location → Allow all the time' : 'site settings → Location → Allow' }}).
        </template>
        <template v-else>Turn on Location (GPS) on your phone. You are punched in and your location is not reaching the workshop.</template>
      </div>
      <button v-if="tracker.native.value" type="button" class="font-medium underline" @click="tracker.openLocationSettings()">Settings</button>
    </div>
    <div v-else class="flex items-center gap-1.5 text-xs text-gray-500">
      <span class="relative flex h-2 w-2">
        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75"></span>
        <span class="relative inline-flex h-2 w-2 rounded-full bg-green-500"></span>
      </span>
      <MapPinIcon class="h-3.5 w-3.5" />
      <span>
        Sharing your location until you punch out
        <template v-if="!tracker.native.value"> · keep this app open</template>
        <template v-if="tracker.queued.value > 0"> · {{ tracker.queued.value }} waiting for network</template>
      </span>
    </div>
  </div>

  <Modal :open="!!tracker.pendingConsent.value" title="Location sharing" :closeable="false">
    <div class="space-y-3 text-sm text-gray-700">
      <p>While you are <strong>punched in</strong>, this app shares your phone's location with the owner and managers, so they can plan pickups, home visits and jobs across branches.</p>
      <ul class="list-disc space-y-1 pl-5">
        <li>It starts when you punch in and <strong>stops when you punch out</strong>. Nothing is shared on your days off.</li>
        <li>Only the owner and managers can see it: where you are now, and your route for the day.</li>
        <li>Positions are deleted after {{ tracker.pendingConsent.value?.retention_days ?? 90 }} days.</li>
        <li v-if="!tracker.native.value">In the browser, location is shared only while the app is open. Install the Android app to share it with the screen locked.</li>
      </ul>
    </div>
    <template #footer>
      <Button :loading="accepting" @click="accept">I understand, continue</Button>
    </template>
  </Modal>
</template>
