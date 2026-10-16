<script setup lang="ts">
/**
 * Masters › Purchases & Stock: switch the optional purchases module on or off, and the
 * number of part photos a GRN needs.
 */
import { ref, onMounted } from 'vue'
import { purchasesApi } from '@/api/purchases.api'
import { useAuthStore } from '@/stores/auth.store'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const authStore = useAuthStore()
const { can } = usePermissions()
const toast = useToast()

const settings = ref<{ enabled: boolean; min_part_photos: number } | null>(null)
const confirm = ref<boolean | null>(null)
const busy = ref(false)

async function load() {
  try {
    settings.value = (await purchasesApi.settings()).data.data
  } catch (err) {
    toast.apiError(err)
  }
}

async function save(data: { enabled?: boolean; min_part_photos?: number }) {
  busy.value = true
  try {
    const res = await purchasesApi.updateSettings(data)
    settings.value = res.data.data
    toast.success(res.data.message ?? 'Saved')
    confirm.value = null
    // Menus and screens follow the switch
    await authStore.fetchUser()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">Purchases &amp; Stock</h1>
      <p class="text-sm text-gray-500">An optional module: GRNs for supplier bills, the technician's part check, consumables stock and supplier dues.</p>
    </div>

    <div v-if="!settings" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <template v-else>
      <Card class="mb-4">
        <div class="flex items-start gap-4">
          <div class="flex-1">
            <p class="font-semibold text-gray-900">Purchases &amp; stock module</p>
            <p class="mt-1 text-sm text-gray-600">
              <template v-if="settings.enabled">
                <b class="text-green-700">On.</b> Parts arrive only on a GRN with a photo; technicians check each part before fitting it; the
                final bill waits until every approved part is on a GRN; consumables are issued from stock.
              </template>
              <template v-else>
                <b>Off.</b> The coordinator taps "Mark received" on the Parts Desk; no stock or supplier dues are kept.
              </template>
            </p>
          </div>
          <button
            v-if="can('purchases.settings.manage')"
            type="button"
            role="switch"
            :aria-checked="settings.enabled"
            class="relative mt-1 inline-flex h-7 w-12 shrink-0 rounded-full transition-colors"
            :class="settings.enabled ? 'bg-primary-600' : 'bg-gray-300'"
            @click="confirm = !settings.enabled"
          >
            <span class="absolute top-0.5 h-6 w-6 rounded-full bg-white shadow transition-all" :class="settings.enabled ? 'left-[1.375rem]' : 'left-0.5'" />
          </button>
        </div>
      </Card>

      <Card v-if="settings.enabled">
        <label class="label">Photos of each job part on a GRN</label>
        <div class="flex items-center gap-3">
          <input
            v-model.number="settings.min_part_photos"
            type="number"
            min="0"
            max="5"
            class="input w-24"
            :disabled="!can('purchases.settings.manage')"
          />
          <Button v-if="can('purchases.settings.manage')" size="sm" variant="secondary" :loading="busy" @click="save({ min_part_photos: settings.min_part_photos })">Save</Button>
        </div>
        <p class="mt-2 text-xs text-gray-500">The technician compares the part in hand with these photos before accepting it.</p>
      </Card>
    </template>

    <Modal :open="confirm !== null" :title="confirm ? 'Switch the module on?' : 'Switch the module off?'" @close="confirm = null">
      <p class="text-sm text-gray-600">
        <template v-if="confirm">
          From now on parts arrive only on a GRN, technicians check each part, and bills wait for the GRNs. Parts already marked
          received stay as they are.
        </template>
        <template v-else>
          GRNs, stock and supplier dues already recorded are kept but hidden; parts go back to "Mark received" on the Parts Desk.
        </template>
      </p>
      <template #footer>
        <Button variant="secondary" @click="confirm = null">Cancel</Button>
        <Button :loading="busy" @click="save({ enabled: !!confirm })">{{ confirm ? 'Switch on' : 'Switch off' }}</Button>
      </template>
    </Modal>
  </div>
</template>
