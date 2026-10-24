<script setup lang="ts">
/**
 * Purchases module on a job: issue the plan kit from stock in one step (suggested from
 * the plan × car class, plus engine oil by litres), issue or return other items, and —
 * for the owner / back office — the job's parts cost (GRN parts, outside services,
 * consumables at average cost).
 */
import { ref, computed, onMounted } from 'vue'
import { purchasesApi, money, type JobStock } from '@/api/purchases.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'

const props = defineProps<{ jobCardId: number; closed: boolean }>()
const { can } = usePermissions()
const toast = useToast()

const data = ref<JobStock | null>(null)
const busy = ref(false)
const canIssue = computed(() => can('purchases.stock.issue') && !props.closed)

async function load() {
  try {
    data.value = (await purchasesApi.jobStock(props.jobCardId)).data.data
  } catch {
    data.value = null
  }
}

// Kit: editable quantities, issued together
const kitOpen = ref(false)
const kit = ref<{ stock_item_id: number; name: string; unit: string; quantity: number | string; in_stock: number }[]>([])
function openKit() {
  kit.value = (data.value?.suggested_kit ?? []).map((k) => ({ ...k }))
  kitOpen.value = true
}
async function issueKit() {
  busy.value = true
  try {
    const res = await purchasesApi.issue(props.jobCardId, kit.value.filter((k) => Number(k.quantity) > 0).map((k) => ({ stock_item_id: k.stock_item_id, quantity: Number(k.quantity) })), true)
    data.value = res.data.data
    toast.success('Plan kit issued')
    kitOpen.value = false
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

// One item, issue or return
const one = ref<{ mode: 'issue' | 'return'; stock_item_id: number | ''; quantity: number | string } | null>(null)
async function saveOne() {
  if (!one.value?.stock_item_id) return
  busy.value = true
  try {
    const res =
      one.value.mode === 'issue'
        ? await purchasesApi.issue(props.jobCardId, [{ stock_item_id: Number(one.value.stock_item_id), quantity: Number(one.value.quantity) }])
        : await purchasesApi.returnToStock(props.jobCardId, Number(one.value.stock_item_id), Number(one.value.quantity))
    data.value = res.data.data
    toast.success(one.value.mode === 'issue' ? 'Issued' : 'Returned to stock')
    one.value = null
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <Card v-if="data">
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-lg font-semibold">Stock &amp; parts cost</h3>
        <Badge v-if="data.kit_issued_at" size="sm" variant="success">Plan kit issued</Badge>
      </div>
    </template>

    <div class="space-y-4 text-sm">
      <!-- The automatic plan kit at final QC was short: issue these once stock is in -->
      <div v-if="data.kit_shortfall?.length" class="rounded-lg border border-amber-200 bg-amber-50 p-2 text-amber-900">
        <p class="font-medium">Plan kit not fully issued (short in stock)</p>
        <p class="text-xs">{{ data.kit_shortfall.map((s) => `${s.name} ${s.quantity} ${s.unit}`).join(', ') }} — issue it with "Issue item" once received.</p>
      </div>
      <div v-if="canIssue" class="flex flex-wrap gap-2">
        <Button v-if="!data.kit_issued_at && data.suggested_kit.length" size="sm" title="Issued by itself at final QC if not done before" @click="openKit">Issue plan kit now…</Button>
        <Button size="sm" variant="secondary" @click="one = { mode: 'issue', stock_item_id: '', quantity: 1 }">Issue item</Button>
        <Button v-if="data.issued.length" size="sm" variant="secondary" @click="one = { mode: 'return', stock_item_id: '', quantity: 1 }">Return to stock</Button>
      </div>

      <section>
        <h4 class="font-medium text-gray-700 mb-1">Issued from stock</h4>
        <p v-if="!data.issued.length" class="text-gray-500">Nothing yet.</p>
        <ul class="divide-y divide-gray-100">
          <li v-for="i in data.issued" :key="i.stock_item_id" class="py-1.5 flex justify-between gap-2">
            <span>{{ i.name }}</span>
            <span class="text-gray-700">{{ i.quantity }} {{ i.unit }}<template v-if="data.cost"> · ₹ {{ money(i.cost) }}</template></span>
          </li>
        </ul>
      </section>

      <!-- Internal: what the job cost the workshop -->
      <section v-if="data.cost" class="rounded-lg bg-gray-50 p-3">
        <h4 class="font-medium text-gray-700 mb-1">Parts cost (internal)</h4>
        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-2">
          <div><dt class="text-xs text-gray-500">Parts (GRN)</dt><dd class="font-semibold">₹ {{ money(data.cost.parts, 0) }}</dd></div>
          <div><dt class="text-xs text-gray-500">Outside services</dt><dd class="font-semibold">₹ {{ money(data.cost.third_party, 0) }}</dd></div>
          <div><dt class="text-xs text-gray-500">Consumables</dt><dd class="font-semibold">₹ {{ money(data.cost.consumables, 0) }}</dd></div>
          <div><dt class="text-xs text-gray-500">Total</dt><dd class="font-bold">₹ {{ money(data.cost.total, 0) }}</dd></div>
        </dl>
        <ul v-if="data.cost.lines.length" class="mt-2 space-y-0.5 text-xs text-gray-600">
          <li v-for="(l, i) in data.cost.lines" :key="i" :class="l.returned ? 'line-through' : ''">
            {{ l.grn_number }} · {{ l.description }} × {{ l.quantity }} · ₹ {{ money(l.cost) }}
            <template v-if="l.verification === 'wrong_part'"> (wrong part)</template>
          </li>
        </ul>
      </section>
    </div>

    <Modal :open="kitOpen" title="Issue plan kit" @close="kitOpen = false">
      <p class="text-sm text-gray-600 mb-3">Suggested for this plan and car class. Change a quantity if the car needs more or less.</p>
      <ul class="space-y-2">
        <li v-for="k in kit" :key="k.stock_item_id" class="flex items-center gap-3">
          <span class="flex-1 text-sm">
            {{ k.name }}
            <span class="block text-xs" :class="Number(k.quantity) > k.in_stock ? 'text-red-700' : 'text-gray-500'">{{ k.in_stock }} {{ k.unit }} in stock</span>
          </span>
          <input v-model="k.quantity" type="number" min="0" step="0.01" class="input w-24" />
          <span class="w-8 text-xs text-gray-500">{{ k.unit }}</span>
        </li>
      </ul>
      <template #footer>
        <Button variant="secondary" @click="kitOpen = false">Cancel</Button>
        <Button :loading="busy" @click="issueKit">Issue kit</Button>
      </template>
    </Modal>

    <Modal :open="!!one" :title="one?.mode === 'issue' ? 'Issue from stock' : 'Return to stock'" @close="one = null">
      <div v-if="one" class="space-y-3">
        <select v-model="one.stock_item_id" class="input">
          <option value="">Item…</option>
          <template v-if="one.mode === 'issue'">
            <option v-for="i in data.items" :key="i.id" :value="i.id">{{ i.name }} ({{ i.unit }})</option>
          </template>
          <template v-else>
            <option v-for="i in data.issued" :key="i.stock_item_id" :value="i.stock_item_id">{{ i.name }} (issued {{ i.quantity }} {{ i.unit }})</option>
          </template>
        </select>
        <input v-model="one.quantity" type="number" min="0.01" step="0.01" class="input" placeholder="Quantity" />
      </div>
      <template #footer>
        <Button variant="secondary" @click="one = null">Cancel</Button>
        <Button :loading="busy" :disabled="!one?.stock_item_id || !(Number(one?.quantity) > 0)" @click="saveOne">{{ one?.mode === 'issue' ? 'Issue' : 'Return' }}</Button>
      </template>
    </Modal>
  </Card>
</template>
