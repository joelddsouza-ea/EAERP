<script setup lang="ts">
/**
 * One GRN: the bill, its lines with photos and the technicians' checks; return a part
 * to the supplier, or cancel the GRN while nothing on it has been used.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { purchasesApi, BILL_TYPE_LABELS, GST_MODE_LABELS, money, type Grn, type GrnLine } from '@/api/purchases.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const toast = useToast()

const grn = ref<Grn | null>(null)
const busy = ref(false)
const KINDS: Record<string, string> = { job_part: 'Job part', stock: 'Into stock', third_party: 'Outside service' }

const cancelOpen = ref(false)
const cancelReason = ref('')
const returning = ref<GrnLine | null>(null)
const returnReason = ref('')

const posted = computed(() => grn.value?.status === 'posted')

async function load() {
  try {
    grn.value = (await purchasesApi.get(Number(route.params.id))).data.data
  } catch (err) {
    toast.apiError(err)
    router.push('/purchases/grns')
  }
}

async function cancel() {
  busy.value = true
  try {
    grn.value = (await purchasesApi.cancel(grn.value!.id, cancelReason.value)).data.data
    toast.success('GRN cancelled', 'Its parts are back to "ordered" and its stock is reversed.')
    cancelOpen.value = false
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function returnLine() {
  if (!returning.value) return
  busy.value = true
  try {
    grn.value = (await purchasesApi.returnLine(returning.value.id, returnReason.value)).data.data
    toast.success('Returned to the supplier')
    returning.value = null
    returnReason.value = ''
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

const canReturn = (l: GrnLine) => posted.value && can('purchases.grn.create') && !l.returned && l.kind !== 'stock' && l.verification !== 'verified'

onMounted(load)
</script>

<template>
  <div class="max-w-3xl mx-auto">
    <div v-if="!grn" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <template v-else>
      <div class="mb-4 rounded-2xl bg-white ring-1 ring-gray-200/80 shadow-sm p-4 sm:p-5 border-l-4" :class="posted ? 'border-l-primary-600' : 'border-l-gray-400'">
        <div class="flex flex-wrap items-center gap-2">
          <h1 class="text-xl font-bold text-gray-900">{{ grn.grn_number }}</h1>
          <Badge v-if="!posted" variant="danger">Cancelled</Badge>
          <Badge :variant="grn.payment_mode === 'credit' ? 'warning' : 'default'">{{ grn.payment_mode === 'credit' ? 'On credit' : 'Paid' }}</Badge>
        </div>
        <p class="mt-1 text-sm text-gray-700">{{ grn.supplier }}<template v-if="grn.supplier_gstin"> · GSTIN {{ grn.supplier_gstin }}</template></p>
        <p class="text-sm text-gray-500">
          {{ BILL_TYPE_LABELS[grn.bill_type] }}<template v-if="grn.supplier_bill_no"> {{ grn.supplier_bill_no }}</template> · {{ grn.bill_date }}
          <template v-if="grn.received_by"> · received by {{ grn.received_by }}</template>
        </p>
        <p v-if="!posted" class="mt-2 text-sm text-red-700">Cancelled by {{ grn.cancelled_by }}: {{ grn.cancel_reason }}</p>
        <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
          <div class="rounded-lg bg-gray-50 p-2"><dt class="text-xs text-gray-500">Taxable</dt><dd class="font-semibold">₹ {{ money(grn.taxable_amount) }}</dd></div>
          <div class="rounded-lg bg-gray-50 p-2"><dt class="text-xs text-gray-500">GST</dt><dd class="font-semibold">₹ {{ money(grn.gst_amount) }}</dd></div>
          <div class="rounded-lg bg-gray-50 p-2"><dt class="text-xs text-gray-500">Total</dt><dd class="font-semibold">₹ {{ money(grn.total_amount) }}</dd></div>
        </dl>
        <div v-if="grn.bill_photos.length" class="mt-3 flex flex-wrap gap-2">
          <a v-for="ph in grn.bill_photos" :key="ph.id" :href="ph.url" target="_blank" class="block h-16 w-16 overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-200">
            <img v-if="!ph.is_pdf" :src="ph.thumbnail_url" class="h-full w-full object-cover" alt="Bill" />
            <span v-else class="flex h-full items-center justify-center text-xs text-gray-500">PDF</span>
          </a>
        </div>
      </div>

      <Card :padding="false">
        <template #header><h3 class="font-semibold">Lines</h3></template>
        <ul class="divide-y divide-gray-100">
          <li v-for="l in grn.lines" :key="l.id" class="p-4" :class="l.returned ? 'opacity-60' : ''">
            <div class="flex flex-wrap items-start gap-2">
              <div class="min-w-0 flex-1">
                <p class="font-semibold text-gray-900">
                  {{ l.description }}
                  <span v-if="l.part_number || l.brand" class="font-normal text-gray-500">· {{ [l.brand, l.part_number].filter(Boolean).join(' ') }}</span>
                </p>
                <p class="text-xs text-gray-600">
                  {{ KINDS[l.kind] }}<template v-if="l.job_number"> · {{ l.job_number }} {{ l.registration_no }}</template>
                  · {{ l.quantity }} {{ l.unit }} × ₹ {{ money(l.unit_cost) }} ({{ GST_MODE_LABELS[l.gst_mode] }}<template v-if="l.gst_rate"> {{ l.gst_rate }}%</template>)
                  <template v-if="l.mrp"> · MRP ₹ {{ money(l.mrp, 0) }}</template>
                </p>
              </div>
              <span class="font-semibold">₹ {{ money(l.total) }}</span>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <Badge v-if="l.returned" size="sm" variant="default">Returned: {{ l.return_reason }}</Badge>
              <template v-else-if="l.kind === 'job_part'">
                <Badge v-if="l.verification === 'verified'" size="sm" variant="success">Right part — {{ l.verified_by }}</Badge>
                <Badge v-else-if="l.verification === 'wrong_part'" size="sm" variant="danger">Wrong part: {{ l.verification_note }}</Badge>
                <Badge v-else size="sm" variant="warning">Technician to check</Badge>
              </template>
              <a v-for="ph in l.photos" :key="ph.id" :href="ph.url" target="_blank" class="block h-12 w-12 overflow-hidden rounded ring-1 ring-gray-200">
                <img :src="ph.thumbnail_url" class="h-full w-full object-cover" alt="Part" />
              </a>
              <Button v-if="canReturn(l)" size="sm" variant="secondary" class="ml-auto" @click="returning = l">Return to supplier</Button>
            </div>
          </li>
        </ul>
      </Card>

      <p v-if="grn.notes" class="mt-3 text-sm text-gray-600">Note: {{ grn.notes }}</p>

      <div v-if="posted && can('purchases.grn.cancel')" class="mt-4">
        <Button variant="danger" @click="cancelOpen = true">Cancel this GRN…</Button>
      </div>
    </template>

    <Modal :open="cancelOpen" title="Cancel GRN" @close="cancelOpen = false">
      <p class="text-sm text-gray-600 mb-3">
        Parts on it go back to "ordered" and its stock comes off again. Not possible once a technician has accepted a part.
      </p>
      <textarea v-model="cancelReason" rows="2" class="input" placeholder="Why? e.g. Posted against the wrong supplier" />
      <template #footer>
        <Button variant="secondary" @click="cancelOpen = false">Keep it</Button>
        <Button variant="danger" :loading="busy" :disabled="cancelReason.trim().length < 5" @click="cancel">Cancel GRN</Button>
      </template>
    </Modal>

    <Modal :open="!!returning" title="Return to supplier" @close="returning = null">
      <p class="text-sm text-gray-600 mb-3">{{ returning?.description }}: its cost comes off the job{{ grn?.payment_mode === 'credit' ? " and off what the supplier is owed" : '' }}.</p>
      <textarea v-model="returnReason" rows="2" class="input" placeholder="Reason, e.g. Wrong part (rear pads)" />
      <template #footer>
        <Button variant="secondary" @click="returning = null">Cancel</Button>
        <Button :loading="busy" :disabled="returnReason.trim().length < 3" @click="returnLine">Return</Button>
      </template>
    </Modal>
  </div>
</template>
