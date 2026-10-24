<script setup lang="ts">
/**
 * Masters › Fleet Cabs: how much extra work a fleet driver may approve on a job (above it the
 * fleet owner is asked) and when a cab counts as close to its service. A fleet can have its own
 * amount on its customer page. Saved with the Save bar.
 */
import { ref, computed, onMounted } from 'vue'
import { fleetApi, type FleetSettings } from '@/api/fleet.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Spinner from '@/components/ui/Spinner.vue'
import UnsavedChangesBar from '@/components/ui/UnsavedChangesBar.vue'

const { can } = usePermissions()
const toast = useToast()

const saved = ref<FleetSettings | null>(null)
const form = ref<FleetSettings | null>(null)
const saving = ref(false)
const canEdit = computed(() => can('workshop.fleet.settings'))

const LABELS: Record<keyof FleetSettings, string> = {
  driver_limit: 'Driver may approve per job',
  service_due_km_warning: 'Service-due warning',
}

const changed = computed(() => {
  if (!form.value || !saved.value) return [] as (keyof FleetSettings)[]
  return (Object.keys(LABELS) as (keyof FleetSettings)[]).filter((k) => String(form.value![k] ?? '') !== String(saved.value![k] ?? ''))
})

async function load() {
  try {
    saved.value = (await fleetApi.settings()).data.data
    form.value = { ...saved.value }
  } catch (err) {
    toast.apiError(err)
  }
}

async function save() {
  if (!form.value) return
  saving.value = true
  try {
    const payload = Object.fromEntries(changed.value.map((k) => [k, form.value![k]])) as Partial<FleetSettings>
    saved.value = (await fleetApi.saveSettings(payload)).data.data
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
      <h1 class="text-2xl font-bold text-gray-900">Fleet Cabs</h1>
      <p class="text-sm text-gray-500">For cab owners (customer type Fleet). Their drivers sign in to the customer app with their own number.</p>
    </div>

    <div v-if="!form" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <template v-else>
      <Card class="mb-4">
        <template #header><h3 class="text-lg font-semibold">Driver approval</h3></template>
        <label class="block text-sm sm:w-64">
          <span class="text-gray-600">{{ LABELS.driver_limit }} (₹, GST incl.)</span>
          <input v-model.number="form.driver_limit" type="number" min="0" step="100" class="input mt-1" :disabled="!canEdit" />
        </label>
        <p class="mt-2 text-xs text-gray-500">
          A driver may approve extra work up to this total per job; above it the fleet owner is asked. A driver can always decline.
          A fleet can have its own amount on its customer page (Fleet card); blank there means this amount.
        </p>
      </Card>

      <Card class="mb-4">
        <template #header><h3 class="text-lg font-semibold">Service due</h3></template>
        <label class="block text-sm sm:w-64">
          <span class="text-gray-600">Warn when a cab is this close to its service (km)</span>
          <input v-model.number="form.service_due_km_warning" type="number" min="0" step="100" class="input mt-1" :disabled="!canEdit" />
        </label>
        <p class="mt-2 text-xs text-gray-500">Shown on the fleet owner's dashboard and in the daily fleet reminders.</p>
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
