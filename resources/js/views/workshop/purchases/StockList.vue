<script setup lang="ts">
/**
 * Consumables on hand at this branch, at weighted-average cost; low-stock items first
 * to see; movements per item; count adjustments with a reason.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { purchasesApi, money, type StockRow } from '@/api/purchases.api'
import { useBranchStore } from '@/stores/branch.store'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const branchStore = useBranchStore()
const { can } = usePermissions()
const toast = useToast()

const items = ref<StockRow[]>([])
const totalValue = ref(0)
const loading = ref(true)
const lowOnly = ref(false)
const search = ref('')

const shown = computed(() =>
  items.value
    .filter((i) => i.is_active || i.quantity > 0)
    .filter((i) => !lowOnly.value || i.low)
    .filter((i) => !search.value.trim() || i.name.toLowerCase().includes(search.value.trim().toLowerCase()))
)

const TYPES: Record<string, string> = { in: 'In (GRN)', issue: 'Issued to job', return: 'Back from job', adjust: 'Adjusted', grn_cancel: 'GRN cancelled' }

async function load() {
  loading.value = true
  try {
    const data = (await purchasesApi.stock()).data.data
    items.value = data.items
    totalValue.value = data.total_value
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

// Movements
const moves = ref<Awaited<ReturnType<typeof purchasesApi.movements>>['data']['data'] | null>(null)
async function openMoves(item: StockRow) {
  try {
    moves.value = (await purchasesApi.movements(item.id)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}

// Adjust
const adjusting = ref<StockRow | null>(null)
const counted = ref<number | string>('')
const reason = ref('')
const busy = ref(false)
function openAdjust(item: StockRow) {
  adjusting.value = item
  counted.value = item.quantity
  reason.value = ''
}
const change = computed(() => (adjusting.value ? Math.round((Number(counted.value) - adjusting.value.quantity) * 100) / 100 : 0))
async function saveAdjust() {
  if (!adjusting.value || !change.value) return
  busy.value = true
  try {
    await purchasesApi.adjust(adjusting.value.id, change.value, reason.value)
    toast.success('Stock adjusted')
    adjusting.value = null
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

onMounted(load)
watch(() => branchStore.currentBranchId, load)
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">Stock</h1>
      <p class="text-sm text-gray-500">Consumables at {{ branchStore.currentBranch?.name ?? 'this branch' }} · value ₹ {{ money(totalValue, 0) }} at average cost.</p>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-2">
      <input v-model="search" class="input flex-1 min-w-[12rem]" placeholder="Search items" />
      <label class="flex items-center gap-2 text-sm text-gray-700">
        <input v-model="lowOnly" type="checkbox" class="h-4 w-4 rounded" /> Low stock only
      </label>
    </div>

    <div v-if="loading" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <Card v-else-if="!shown.length"><p class="text-center text-sm text-gray-500 py-6">No stock items. Add them in Masters › Stock items &amp; plan kits.</p></Card>
    <Card v-else :padding="false">
      <ul class="divide-y divide-gray-100">
        <li v-for="i in shown" :key="i.id" class="px-4 py-3 flex flex-wrap items-center gap-x-3 gap-y-1">
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">{{ i.name }} <Badge v-if="i.low" size="sm" variant="danger">Low</Badge></p>
            <p class="text-xs text-gray-500 capitalize">{{ i.category }} · avg ₹ {{ money(i.avg_cost) }} / {{ i.unit }}<template v-if="i.reorder_level"> · reorder at {{ i.reorder_level }}</template></p>
          </div>
          <p class="text-right">
            <span class="text-lg font-bold" :class="i.low ? 'text-red-700' : 'text-gray-900'">{{ i.quantity }}</span>
            <span class="text-sm text-gray-500"> {{ i.unit }}</span>
            <span class="block text-xs text-gray-500">₹ {{ money(i.value, 0) }}</span>
          </p>
          <div class="w-full sm:w-auto flex gap-2">
            <Button size="sm" variant="secondary" @click="openMoves(i)">History</Button>
            <Button v-if="can('purchases.stock.adjust')" size="sm" variant="secondary" @click="openAdjust(i)">Count</Button>
          </div>
        </li>
      </ul>
    </Card>

    <Modal :open="!!moves" :title="moves ? `${moves.item.name} — history` : ''" size="lg" @close="moves = null">
      <p v-if="moves && !moves.movements.length" class="text-sm text-gray-500">No movements yet.</p>
      <ul v-else-if="moves" class="divide-y divide-gray-100 text-sm">
        <li v-for="m in moves.movements" :key="m.id" class="py-2 flex flex-wrap gap-x-3">
          <span class="font-medium" :class="m.quantity < 0 ? 'text-red-700' : 'text-green-700'">{{ m.quantity > 0 ? '+' : '' }}{{ m.quantity }} {{ moves.item.unit }}</span>
          <span>{{ TYPES[m.type] ?? m.type }}<template v-if="m.job_number"> · {{ m.job_number }}</template></span>
          <span class="text-gray-500">₹ {{ money(m.unit_cost) }}/{{ moves.item.unit }}</span>
          <span class="ml-auto text-xs text-gray-500">{{ new Date(m.at).toLocaleString('en-IN') }}<template v-if="m.by"> · {{ m.by }}</template></span>
          <span v-if="m.note" class="w-full text-xs text-gray-500">{{ m.note }}</span>
        </li>
      </ul>
    </Modal>

    <Modal :open="!!adjusting" title="Stock count" @close="adjusting = null">
      <div v-if="adjusting" class="space-y-3">
        <p class="text-sm text-gray-600">{{ adjusting.name }}: the system has {{ adjusting.quantity }} {{ adjusting.unit }}.</p>
        <div><label class="label">Counted ({{ adjusting.unit }})</label><input v-model="counted" type="number" step="0.01" min="0" class="input" /></div>
        <p v-if="change" class="text-sm" :class="change < 0 ? 'text-red-700' : 'text-green-700'">{{ change > 0 ? '+' : '' }}{{ change }} {{ adjusting.unit }}</p>
        <div><label class="label">Reason</label><input v-model="reason" class="input" placeholder="e.g. Damaged, spilled, count correction" /></div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="adjusting = null">Cancel</Button>
        <Button :loading="busy" :disabled="!change || reason.trim().length < 3" @click="saveAdjust">Save count</Button>
      </template>
    </Modal>
  </div>
</template>
