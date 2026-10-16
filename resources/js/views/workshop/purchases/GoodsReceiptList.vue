<script setup lang="ts">
/**
 * GRNs and the purchase register: what was bought in a period, with and without GST
 * bills, cash or on credit.
 */
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { PlusIcon } from '@heroicons/vue/20/solid'
import { purchasesApi, BILL_TYPE_LABELS, money, type PurchaseRegister } from '@/api/purchases.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'

const router = useRouter()
const { can } = usePermissions()
const toast = useToast()

const today = new Date().toISOString().slice(0, 10)
const monthStart = today.slice(0, 8) + '01'
const from = ref(monthStart)
const to = ref(today)
const data = ref<PurchaseRegister | null>(null)
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    data.value = (await purchasesApi.list({ from: from.value, to: to.value })).data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">GRNs &amp; purchase register</h1>
        <p class="text-sm text-gray-500">Goods received from suppliers.</p>
      </div>
      <Button v-if="can('purchases.grn.create')" @click="router.push('/purchases/grns/new')">
        <PlusIcon class="h-5 w-5" /> Receive goods
      </Button>
    </div>

    <Card class="mb-4">
      <div class="flex flex-wrap items-end gap-3">
        <div><label class="label">From</label><input v-model="from" type="date" class="input" /></div>
        <div><label class="label">To</label><input v-model="to" type="date" class="input" /></div>
        <Button variant="secondary" @click="load">Show</Button>
      </div>
      <dl v-if="data" class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
        <div class="rounded-lg bg-gray-50 p-3"><dt class="text-gray-500">Purchases</dt><dd class="font-semibold">₹ {{ money(data.totals.total, 0) }}</dd></div>
        <div class="rounded-lg bg-gray-50 p-3"><dt class="text-gray-500">Input GST</dt><dd class="font-semibold">₹ {{ money(data.totals.gst, 0) }}</dd></div>
        <div class="rounded-lg bg-gray-50 p-3"><dt class="text-gray-500">Without GST bill</dt><dd class="font-semibold">₹ {{ money(data.totals.without_gst_bill, 0) }}</dd></div>
        <div class="rounded-lg bg-gray-50 p-3"><dt class="text-gray-500">On credit</dt><dd class="font-semibold">₹ {{ money(data.totals.credit, 0) }}</dd></div>
      </dl>
    </Card>

    <div v-if="loading" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <Card v-else-if="data && !data.grns.length"><p class="text-center text-sm text-gray-500 py-6">No GRNs in this period.</p></Card>
    <Card v-else-if="data" :padding="false">
      <ul class="divide-y divide-gray-100">
        <li v-for="g in data.grns" :key="g.id">
          <button type="button" class="w-full text-left px-4 py-3 hover:bg-gray-50 active:bg-gray-50" @click="router.push(`/purchases/grns/${g.id}`)">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
              <span class="font-semibold text-gray-900">{{ g.grn_number }}</span>
              <Badge v-if="g.status === 'cancelled'" size="sm" variant="danger">Cancelled</Badge>
              <Badge size="sm" :variant="g.payment_mode === 'credit' ? 'warning' : 'default'">{{ g.payment_mode === 'credit' ? 'Credit' : 'Paid' }}</Badge>
              <span class="ml-auto font-semibold" :class="g.status === 'cancelled' ? 'line-through text-gray-400' : ''">₹ {{ money(g.total_amount) }}</span>
            </div>
            <p class="text-sm text-gray-600">
              {{ g.supplier }} · {{ BILL_TYPE_LABELS[g.bill_type] }}<template v-if="g.supplier_bill_no"> {{ g.supplier_bill_no }}</template> · {{ g.bill_date }}
            </p>
            <p class="text-xs text-gray-500">
              {{ g.lines_count }} line(s) · GST ₹ {{ money(g.gst_amount) }}<template v-if="g.received_by"> · {{ g.received_by }}</template>
            </p>
          </button>
        </li>
      </ul>
    </Card>
  </div>
</template>
