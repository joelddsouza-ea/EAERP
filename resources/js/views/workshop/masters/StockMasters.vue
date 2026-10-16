<script setup lang="ts">
/**
 * Masters › Stock items & plan kits: the consumables kept in stock (engine oil grades
 * have one each), and what each service plan uses per car class. Engine oil comes
 * from the plan's oil litres in Price Masters, so it isn't listed in the kits.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { PlusIcon, TrashIcon } from '@heroicons/vue/20/solid'
import { purchasesApi, type StockItemMaster, type KitLine } from '@/api/purchases.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const { can } = usePermissions()
const toast = useToast()
const canEdit = computed(() => can('purchases.stock-items.manage'))

const tab = ref<'items' | 'kits'>('items')
const items = ref<StockItemMaster[]>([])
const categories = ref<string[]>([])
const units = ref<string[]>([])
const oilTypes = ref<{ id: number; name: string }[]>([])
const loading = ref(true)

async function loadItems() {
  const data = (await purchasesApi.stockItems()).data.data
  items.value = data.items
  categories.value = data.categories
  units.value = data.units
  oilTypes.value = data.oil_types
}

// ---- item editor
const editing = ref<Partial<StockItemMaster> | null>(null)
const busy = ref(false)
function openItem(item?: StockItemMaster) {
  editing.value = item
    ? { ...item, gst_rate: Number(item.gst_rate), reorder_level: Number(item.reorder_level) }
    : { name: '', category: 'consumable', unit: 'nos', gst_rate: 18, reorder_level: 0, hsn_code: '', oil_type_id: null, is_active: true }
}
async function saveItem() {
  if (!editing.value) return
  busy.value = true
  try {
    await purchasesApi.saveStockItem(editing.value)
    toast.success('Stock item saved')
    editing.value = null
    await loadItems()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

// ---- plan kits
const kits = ref<KitLine[]>([])
const packages = ref<{ id: number; name: string }[]>([])
const classes = ref<{ id: number; name: string }[]>([])
const kitItems = ref<{ id: number; name: string; unit: string }[]>([])
const packageId = ref<number | null>(null)
const draft = ref<{ category_id: number | null; stock_item_id: number | ''; quantity: number | string }[]>([])
const dirty = ref(false)

async function loadKits() {
  const data = (await purchasesApi.kits()).data.data
  kits.value = data.kits
  packages.value = data.packages
  classes.value = data.categories
  kitItems.value = data.items
  packageId.value ??= data.packages[0]?.id ?? null
  resetDraft()
}
function resetDraft() {
  draft.value = kits.value
    .filter((k) => k.package_id === packageId.value)
    .map((k) => ({ category_id: k.category_id, stock_item_id: k.stock_item_id, quantity: k.quantity }))
  dirty.value = false
}
watch(packageId, resetDraft)

async function saveKit() {
  if (!packageId.value) return
  busy.value = true
  try {
    const res = await purchasesApi.saveKit(
      packageId.value,
      draft.value.filter((l) => l.stock_item_id && Number(l.quantity) > 0).map((l) => ({ category_id: l.category_id, stock_item_id: Number(l.stock_item_id), quantity: Number(l.quantity) }))
    )
    kits.value = res.data.data.kits
    resetDraft()
    toast.success('Plan kit saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    await Promise.all([loadItems(), loadKits()])
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">Stock items &amp; plan kits</h1>
      <p class="text-sm text-gray-500">Consumables kept in stock, and what each service plan takes from stock for a car.</p>
    </div>

    <div class="flex gap-2 mb-4">
      <button
        v-for="t in ([{ k: 'items', l: 'Stock items' }, { k: 'kits', l: 'Plan kits' }] as const)"
        :key="t.k"
        class="rounded-full px-4 py-2 text-sm font-semibold"
        :class="tab === t.k ? 'bg-primary-600 text-white shadow-sm' : 'bg-white ring-1 ring-gray-300 text-gray-700'"
        @click="tab = t.k"
      >
        {{ t.l }}
      </button>
    </div>

    <div v-if="loading" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <!-- Stock items -->
    <Card v-else-if="tab === 'items'" :padding="false">
      <template #header>
        <div class="flex items-center justify-between gap-2">
          <h3 class="font-semibold">Stock items</h3>
          <Button v-if="canEdit" size="sm" @click="openItem()"><PlusIcon class="h-4 w-4" /> Add item</Button>
        </div>
      </template>
      <ul class="divide-y divide-gray-100">
        <li v-for="i in items" :key="i.id" class="px-4 py-3 flex items-center gap-3" :class="i.is_active ? '' : 'opacity-50'">
          <div class="min-w-0 flex-1">
            <p class="font-medium text-gray-900">{{ i.name }} <Badge v-if="!i.is_active" size="sm">Inactive</Badge></p>
            <p class="text-xs text-gray-500 capitalize">
              {{ i.category }} · {{ i.unit }} · GST {{ Number(i.gst_rate) }}%<template v-if="i.hsn_code"> · HSN {{ i.hsn_code }}</template>
              <template v-if="Number(i.reorder_level)"> · reorder at {{ Number(i.reorder_level) }}</template>
              <template v-if="i.oil_type"> · oil grade {{ i.oil_type.name }}</template>
            </p>
          </div>
          <Button v-if="canEdit" size="sm" variant="secondary" @click="openItem(i)">Edit</Button>
        </li>
      </ul>
    </Card>

    <!-- Plan kits -->
    <Card v-else>
      <div class="space-y-3">
        <div>
          <label class="label">Service plan</label>
          <select v-model="packageId" class="input">
            <option v-for="p in packages" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <p class="text-xs text-gray-500">Engine oil is added by itself from the plan's oil litres and the job's oil grade.</p>

        <div v-for="(l, i) in draft" :key="i" class="grid grid-cols-12 gap-2 items-center">
          <select v-model="l.stock_item_id" class="input col-span-12 sm:col-span-5" :disabled="!canEdit" @change="dirty = true">
            <option value="">Item…</option>
            <option v-for="it in kitItems" :key="it.id" :value="it.id">{{ it.name }} ({{ it.unit }})</option>
          </select>
          <select v-model="l.category_id" class="input col-span-6 sm:col-span-4" :disabled="!canEdit" @change="dirty = true">
            <option :value="null">Every car class</option>
            <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <input v-model="l.quantity" type="number" min="0" step="0.01" class="input col-span-4 sm:col-span-2" :disabled="!canEdit" @input="dirty = true" />
          <button v-if="canEdit" type="button" class="col-span-2 sm:col-span-1 p-2 text-gray-400 hover:text-red-600" aria-label="Remove" @click="draft.splice(i, 1); dirty = true">
            <TrashIcon class="h-5 w-5" />
          </button>
        </div>
        <p v-if="!draft.length" class="text-sm text-gray-500">Nothing in this plan's kit yet.</p>

        <div v-if="canEdit" class="flex flex-wrap gap-2">
          <Button size="sm" variant="secondary" @click="draft.push({ category_id: null, stock_item_id: '', quantity: 1 }); dirty = true">
            <PlusIcon class="h-4 w-4" /> Add line
          </Button>
          <Button size="sm" :disabled="!dirty" :loading="busy" @click="saveKit">Save kit</Button>
          <Button v-if="dirty" size="sm" variant="ghost" @click="resetDraft">Discard</Button>
        </div>
      </div>
    </Card>

    <Modal :open="!!editing" :title="editing?.id ? 'Edit stock item' : 'New stock item'" @close="editing = null">
      <div v-if="editing" class="space-y-3">
        <div><label class="label">Name</label><input v-model="editing.name" class="input" placeholder="e.g. Oil filter (sedan)" /></div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">Category</label>
            <select v-model="editing.category" class="input"><option v-for="c in categories" :key="c" :value="c" class="capitalize">{{ c }}</option></select>
          </div>
          <div>
            <label class="label">Unit</label>
            <select v-model="editing.unit" class="input"><option v-for="u in units" :key="u" :value="u">{{ u }}</option></select>
          </div>
        </div>
        <div v-if="editing.category === 'oil'">
          <label class="label">Engine oil grade</label>
          <select v-model="editing.oil_type_id" class="input">
            <option :value="null">—</option>
            <option v-for="o in oilTypes" :key="o.id" :value="o.id">{{ o.name }}</option>
          </select>
        </div>
        <div class="grid grid-cols-3 gap-3">
          <div><label class="label">HSN</label><input v-model="editing.hsn_code" class="input" /></div>
          <div>
            <label class="label">GST %</label>
            <select v-model.number="editing.gst_rate" class="input"><option v-for="r in [0, 5, 12, 18, 28]" :key="r" :value="r">{{ r }}</option></select>
          </div>
          <div><label class="label">Reorder at</label><input v-model.number="editing.reorder_level" type="number" min="0" class="input" /></div>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="editing.is_active" type="checkbox" class="h-4 w-4 rounded" /> Active</label>
      </div>
      <template #footer>
        <Button variant="secondary" @click="editing = null">Cancel</Button>
        <Button :loading="busy" :disabled="!editing?.name?.trim()" @click="saveItem">Save</Button>
      </template>
    </Modal>
  </div>
</template>
