<script setup lang="ts">
/**
 * Masters › Pickup, Drop & Home Service: the charges offered when a trip is booked
 * (GST-inclusive, ticked per trip), the policy sent with the booking message, and the
 * photos needed to hand a car back / close a home visit. Saved with the Save bar.
 */
import { ref, computed, onMounted } from 'vue'
import { tripsApi, type TripSettings } from '@/api/trips.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Spinner from '@/components/ui/Spinner.vue'
import UnsavedChangesBar from '@/components/ui/UnsavedChangesBar.vue'

const { can } = usePermissions()
const toast = useToast()

const saved = ref<TripSettings | null>(null)
const form = ref<TripSettings | null>(null)
const saving = ref(false)
const canEdit = computed(() => can('workshop.trips.settings'))

const LABELS: Record<keyof TripSettings, string> = {
  pickup_charge: 'Pickup charge',
  drop_charge: 'Drop charge',
  home_visit_charge: 'Home visit charge',
  policy_text: 'Pickup & drop policy',
  min_drop_photos: 'Photos at the drop',
  min_home_close_photos: 'Photos to close a home visit',
}

const changed = computed(() => {
  if (!form.value || !saved.value) return [] as (keyof TripSettings)[]
  return (Object.keys(LABELS) as (keyof TripSettings)[]).filter((k) => String(form.value![k] ?? '') !== String(saved.value![k] ?? ''))
})

async function load() {
  try {
    saved.value = (await tripsApi.settings()).data.data
    form.value = { ...saved.value }
  } catch (err) {
    toast.apiError(err)
  }
}

async function save() {
  if (!form.value) return
  saving.value = true
  try {
    const payload = Object.fromEntries(changed.value.map((k) => [k, form.value![k]])) as Partial<TripSettings>
    saved.value = (await tripsApi.saveSettings(payload)).data.data
    form.value = { ...saved.value }
    toast.success('Settings saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">Pickup, Drop &amp; Home Service</h1>
      <p class="text-sm text-gray-500">Charges, the policy the customer gets with the booking, and the photos drivers must take.</p>
    </div>

    <div v-if="!form" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <template v-else>
      <Card class="mb-4">
        <template #header><h3 class="text-lg font-semibold">Charges (Rs, incl. GST)</h3></template>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
          <label v-for="k in (['pickup_charge', 'drop_charge', 'home_visit_charge'] as const)" :key="k" class="block">
            <span class="text-gray-600">{{ LABELS[k] }}</span>
            <input v-model.number="form[k]" type="number" min="0" class="input mt-1" :disabled="!canEdit" />
          </label>
        </div>
        <p class="mt-2 text-xs text-gray-500">Offered when the advisor ticks "Charge" on a trip; it goes on the bill as a service line with 18% GST extracted.</p>
      </Card>

      <Card class="mb-4">
        <template #header><h3 class="text-lg font-semibold">Policy sent with the booking</h3></template>
        <textarea v-model="form.policy_text" rows="5" class="input" :disabled="!canEdit" />
        <p class="mt-2 text-xs text-gray-500">Used as :policy in the Pickup Scheduled / Home Visit Scheduled messages (Job Stages &amp; Messages).</p>
      </Card>

      <Card class="mb-4">
        <template #header><h3 class="text-lg font-semibold">Photos</h3></template>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <label class="block">
            <span class="text-gray-600">{{ LABELS.min_drop_photos }}</span>
            <input v-model.number="form.min_drop_photos" type="number" min="0" max="20" class="input mt-1" :disabled="!canEdit" />
          </label>
          <label class="block">
            <span class="text-gray-600">{{ LABELS.min_home_close_photos }}</span>
            <input v-model.number="form.min_home_close_photos" type="number" min="0" max="20" class="input mt-1" :disabled="!canEdit" />
          </label>
        </div>
        <p class="mt-2 text-xs text-gray-500">
          Pickup photos and videos follow the intake minimums (Job Sheet Masters) and have no upper limit — the driver's pickup is the intake.
        </p>
      </Card>

      <UnsavedChangesBar
        v-if="canEdit"
        :count="changed.length"
        :labels="changed.map((k) => LABELS[k])"
        :saving="saving"
        @save="save"
        @discard="form = { ...saved! }"
      />
    </template>
  </div>
</template>
