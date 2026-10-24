<script setup lang="ts">
/**
 * Receive goods (GRN): the spare parts coordinator records one supplier bill or challan —
 * the approved parts it brings for each job (with a photo of each part, which the
 * technician checks against), consumables into stock and third-party services.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CameraIcon, XMarkIcon, PlusIcon, TrashIcon, DocumentIcon } from '@heroicons/vue/20/solid'
import { purchasesApi, GST_MODE_LABELS, BILL_TYPE_LABELS, money, type Receivables, type ReceivablePart, type GstMode, type BillType } from '@/api/purchases.api'
import { prepareMedia } from '@/components/jobsheet/media'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'
import MediaPicker from '@/components/media/MediaPicker.vue'

// Camera first (Android Chrome): photo / video / gallery as separate choices
const billPicker = ref<InstanceType<typeof MediaPicker> | null>(null)

const route = useRoute()
const router = useRouter()
const toast = useToast()

const data = ref<Receivables | null>(null)
const loading = ref(true)
const saving = ref(false)

// ---- bill
const supplierId = ref<number | ''>('')
const newSupplier = ref('')
const billType = ref<BillType>('gst_invoice')
const billNo = ref('')
const billDate = ref(new Date().toISOString().slice(0, 10))
const paymentMode = ref<'cash' | 'credit'>('credit')
const notes = ref('')
const billPhotos = ref<{ file: File; url: string | null }[]>([])

// ---- lines
interface PartLine {
  part: ReceivablePart
  quantity: number | string
  unit_cost: number | string
  gst_mode: GstMode
  gst_rate: number
  brand: string
  part_number: string
  mrp: number | string
  photos: { file: File; url: string }[]
}
interface StockLine {
  stock_item_id: number | ''
  quantity: number | string
  unit_cost: number | string
  gst_mode: GstMode
  gst_rate: number
}
interface ServiceLine {
  job_card_id: number | ''
  estimate_line_key: string
  description: string
  unit_cost: number | string
  gst_mode: GstMode
  gst_rate: number
}
const selected = ref<Record<number, PartLine>>({})
const stockLines = ref<StockLine[]>([])
const serviceLines = ref<ServiceLine[]>([])
const stockItems = ref<{ id: number; name: string; unit: string }[]>([])
const showOtherSuppliers = ref(false)

const noBill = computed(() => billType.value === 'no_bill')
const minPhotos = computed(() => data.value?.min_part_photos ?? 1)

// Parts of the chosen supplier first; the rest on request (bought elsewhere at the last minute)
const supplierParts = computed(() => (data.value?.parts ?? []).filter((p) => supplierId.value && p.supplier_id === supplierId.value))
const otherParts = computed(() => (data.value?.parts ?? []).filter((p) => !supplierId.value || p.supplier_id !== supplierId.value))

function lineAmounts(qty: number, cost: number, mode: GstMode, rate: number) {
  const gross = Math.round(qty * cost * 100) / 100
  if (noBill.value || mode === 'cash') return { taxable: gross, gst: 0, total: gross }
  if (mode === 'excl') {
    const gst = Math.round(gross * rate) / 100
    return { taxable: gross, gst, total: gross + gst }
  }
  const taxable = Math.round((gross / (1 + rate / 100)) * 100) / 100
  return { taxable, gst: Math.round((gross - taxable) * 100) / 100, total: gross }
}

const totals = computed(() => {
  const rows = [
    ...Object.values(selected.value).map((l) => lineAmounts(Number(l.quantity) || 0, Number(l.unit_cost) || 0, l.gst_mode, l.gst_rate)),
    ...stockLines.value.map((l) => lineAmounts(Number(l.quantity) || 0, Number(l.unit_cost) || 0, l.gst_mode, l.gst_rate)),
    ...serviceLines.value.map((l) => lineAmounts(1, Number(l.unit_cost) || 0, l.gst_mode, l.gst_rate)),
  ]
  return {
    taxable: rows.reduce((s, r) => s + r.taxable, 0),
    gst: rows.reduce((s, r) => s + r.gst, 0),
    total: rows.reduce((s, r) => s + r.total, 0),
    count: rows.length,
  }
})

const problems = computed(() => {
  const list: string[] = []
  if (!supplierId.value && !newSupplier.value.trim()) list.push('Choose the supplier')
  if (!noBill.value && !billNo.value.trim()) list.push('Enter the bill / challan number')
  if (!totals.value.count) list.push('Add at least one line')
  for (const l of Object.values(selected.value)) {
    if (l.photos.length < minPhotos.value) list.push(`Photo of ${l.part.part_name}`)
    if (!(Number(l.quantity) > 0)) list.push(`Quantity of ${l.part.part_name}`)
  }
  if (stockLines.value.some((l) => !l.stock_item_id || !(Number(l.quantity) > 0))) list.push('Stock line: item and quantity')
  if (serviceLines.value.some((l) => !l.job_card_id || !l.description.trim())) list.push('Service line: job and description')
  return list
})

function togglePart(p: ReceivablePart) {
  if (selected.value[p.id]) {
    selected.value[p.id].photos.forEach((ph) => URL.revokeObjectURL(ph.url))
    delete selected.value[p.id]
    return
  }
  selected.value[p.id] = {
    part: p,
    quantity: p.quantity,
    unit_cost: p.unit_cost ?? '',
    gst_mode: p.gst_mode ?? 'incl',
    gst_rate: p.gst_rate || 18,
    brand: '',
    part_number: p.part_number ?? '',
    mrp: '',
    photos: [],
  }
}

async function addPhotos(target: { file: File; url: string | null }[], event: Event) {
  const input = event.target as HTMLInputElement
  for (const raw of Array.from(input.files ?? [])) {
    try {
      if (raw.type === 'application/pdf') {
        target.push({ file: raw, url: null })
        continue
      }
      const prepared = await prepareMedia(raw)
      const file = new File([prepared.file], prepared.fileName, { type: prepared.file.type || 'image/jpeg' })
      target.push({ file, url: URL.createObjectURL(file) })
    } catch (err) {
      toast.error('Photo not added', (err as Error).message)
    }
  }
  input.value = ''
}

function removePhoto(target: { url: string | null }[], i: number) {
  const [ph] = target.splice(i, 1)
  if (ph?.url) URL.revokeObjectURL(ph.url)
}

function addStockLine() {
  stockLines.value.push({ stock_item_id: '', quantity: 1, unit_cost: '', gst_mode: 'incl', gst_rate: 18 })
}
function addServiceLine() {
  serviceLines.value.push({ job_card_id: '', estimate_line_key: '', description: '', unit_cost: '', gst_mode: 'excl', gst_rate: 18 })
}
const servicesFor = (jobId: number | '') => (data.value?.third_party_lines ?? []).filter((l) => l.job_card_id === jobId)
function pickService(line: ServiceLine) {
  const match = servicesFor(line.job_card_id).find((l) => l.estimate_line_key === line.estimate_line_key)
  if (match && !line.description) line.description = match.description
}

async function post() {
  if (problems.value.length) return
  saving.value = true
  const photos: Record<number, File[]> = {}
  const parts = Object.values(selected.value)
  parts.forEach((l, i) => (photos[i] = l.photos.map((p) => p.file)))
  try {
    const res = await purchasesApi.post(
      {
        supplier_id: supplierId.value || null,
        supplier_name: supplierId.value ? null : newSupplier.value.trim(),
        supplier_bill_no: noBill.value ? null : billNo.value.trim(),
        bill_date: billDate.value,
        bill_type: billType.value,
        payment_mode: paymentMode.value,
        notes: notes.value.trim() || null,
        lines: [
          ...parts.map((l) => ({
            kind: 'job_part' as const,
            spare_part_request_id: l.part.id,
            quantity: Number(l.quantity),
            unit_cost: Number(l.unit_cost) || 0,
            gst_mode: l.gst_mode,
            gst_rate: l.gst_rate,
            brand: l.brand.trim() || null,
            part_number: l.part_number.trim() || null,
            mrp: l.mrp === '' ? null : Number(l.mrp),
          })),
          ...stockLines.value.map((l) => ({
            kind: 'stock' as const,
            stock_item_id: Number(l.stock_item_id),
            quantity: Number(l.quantity),
            unit_cost: Number(l.unit_cost) || 0,
            gst_mode: l.gst_mode,
            gst_rate: l.gst_rate,
          })),
          ...serviceLines.value.map((l) => ({
            kind: 'third_party' as const,
            job_card_id: Number(l.job_card_id),
            estimate_line_key: l.estimate_line_key || null,
            description: l.description.trim(),
            quantity: 1,
            unit_cost: Number(l.unit_cost) || 0,
            gst_mode: l.gst_mode,
            gst_rate: l.gst_rate,
          })),
        ],
      },
      billPhotos.value.map((p) => p.file),
      photos
    )
    toast.success(res.data.message ?? 'GRN posted', 'The technicians have been told their parts are here.')
    router.replace(`/purchases/grns/${res.data.data.id}`)
  } catch (err) {
    toast.apiError(err)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    const [rec, items] = await Promise.all([purchasesApi.receivables(), purchasesApi.stockItems()])
    data.value = rec.data.data
    stockItems.value = items.data.data.items.filter((i) => i.is_active)

    // Coming from "Receive (GRN)" on a part: that part, its supplier
    const ids = String(route.query.parts ?? '').split(',').map(Number).filter(Boolean)
    const first = data.value.parts.find((p) => ids.includes(p.id))
    if (first?.supplier_id) supplierId.value = first.supplier_id
    data.value.parts.filter((p) => ids.includes(p.id)).forEach(togglePart)
    if (ids.length && data.value.parts.some((p) => ids.includes(p.id) && p.supplier_id !== supplierId.value)) showOtherSuppliers.value = true
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
})

// A cash purchase without a bill carries no GST
watch(billType, (t) => {
  if (t === 'no_bill') paymentMode.value = 'cash'
})
</script>

<template>
  <div class="max-w-3xl mx-auto pb-32">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">Receive goods (GRN)</h1>
      <p class="text-sm text-gray-500">One supplier bill or challan: the parts it brings, consumables for stock, outside services.</p>
    </div>

    <div v-if="loading" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <div v-else-if="data" class="space-y-4">
      <!-- Supplier & bill -->
      <Card>
        <template #header><h3 class="font-semibold">Supplier &amp; bill</h3></template>
        <div class="space-y-3">
          <div>
            <label class="label">Supplier</label>
            <select v-model="supplierId" class="input">
              <option value="">New supplier…</option>
              <option v-for="s in data.suppliers" :key="s.id" :value="s.id">{{ s.name }}{{ s.gstin ? ` · ${s.gstin}` : '' }}</option>
            </select>
            <input v-if="!supplierId" v-model="newSupplier" class="input mt-2" placeholder="New supplier's name" />
          </div>

          <div>
            <label class="label">Bill</label>
            <div class="grid grid-cols-3 gap-1.5">
              <button
                v-for="(label, key) in BILL_TYPE_LABELS"
                :key="key"
                type="button"
                class="rounded-lg border px-2 py-2 text-xs font-semibold leading-tight"
                :class="billType === key ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-gray-300 text-gray-700'"
                @click="billType = key"
              >
                {{ label }}
              </button>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div v-if="!noBill">
              <label class="label">Bill / challan no.</label>
              <input v-model="billNo" class="input" placeholder="e.g. BH-1023" />
            </div>
            <div :class="noBill ? 'col-span-2' : ''">
              <label class="label">Date</label>
              <input v-model="billDate" type="date" class="input" :max="new Date().toISOString().slice(0, 10)" />
            </div>
          </div>

          <div>
            <label class="label">Payment</label>
            <div class="grid grid-cols-2 gap-1.5">
              <button
                v-for="m in [{ v: 'cash', l: 'Paid now (cash / UPI)' }, { v: 'credit', l: 'On credit (pay later)' }]"
                :key="m.v"
                type="button"
                class="rounded-lg border px-2 py-2 text-sm font-semibold"
                :class="paymentMode === m.v ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-gray-300 text-gray-700'"
                :disabled="noBill && m.v === 'credit'"
                @click="paymentMode = m.v as 'cash' | 'credit'"
              >
                {{ m.l }}
              </button>
            </div>
          </div>

          <div>
            <label class="label">Photo of the bill</label>
            <div class="flex flex-wrap gap-2">
              <div v-for="(ph, i) in billPhotos" :key="i" class="relative h-20 w-20 overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-200">
                <img v-if="ph.url" :src="ph.url" class="h-full w-full object-cover" alt="Bill" />
                <DocumentIcon v-else class="m-auto mt-6 h-8 w-8 text-gray-400" />
                <button type="button" class="absolute right-0.5 top-0.5 rounded-full bg-white/90 p-0.5" aria-label="Remove" @click="removePhoto(billPhotos, i)">
                  <XMarkIcon class="h-4 w-4" />
                </button>
              </div>
              <button type="button" class="flex h-20 w-20 flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 text-xs text-gray-500" @click="billPicker?.open()">
                <CameraIcon class="h-6 w-6" />
                Bill
              </button>
              <MediaPicker ref="billPicker" variant="menu" :kinds="['photo']" files @change="addPhotos(billPhotos, $event)" />
            </div>
          </div>
        </div>
      </Card>

      <!-- Parts for jobs -->
      <Card :padding="false">
        <template #header>
          <h3 class="font-semibold">Parts for jobs</h3>
          <p class="text-xs text-gray-500">Approved parts waiting to arrive. Tick the ones on this bill and photograph each part.</p>
        </template>
        <p v-if="!data.parts.length" class="p-4 text-sm text-gray-500">No approved parts are waiting.</p>
        <p v-else-if="supplierId && !supplierParts.length && !showOtherSuppliers" class="p-4 text-sm text-gray-500">
          No parts were priced with this supplier.
        </p>
        <ul class="divide-y divide-gray-100">
          <li
            v-for="p in showOtherSuppliers || !supplierId ? [...supplierParts, ...otherParts] : supplierParts"
            :key="p.id"
            class="p-4"
            :class="selected[p.id] ? 'bg-primary-50/40' : ''"
          >
            <label class="flex items-start gap-3 cursor-pointer">
              <input type="checkbox" class="mt-1 h-5 w-5 rounded" :checked="!!selected[p.id]" @change="togglePart(p)" />
              <span class="min-w-0 flex-1">
                <span class="block font-semibold text-gray-900">{{ p.part_name }}<span v-if="p.part_number" class="font-normal text-gray-500"> · {{ p.part_number }}</span></span>
                <span class="block text-xs text-gray-600">{{ p.job_number }} · {{ p.car }}<template v-if="p.customer"> · {{ p.customer }}</template></span>
                <span class="block text-xs text-gray-500">
                  Qty {{ p.quantity }} · {{ p.supplier ?? 'no quote' }}<template v-if="p.unit_cost != null"> · ₹ {{ money(p.unit_cost, 0) }} ({{ GST_MODE_LABELS[p.gst_mode ?? 'incl'] }})</template>
                </span>
              </span>
            </label>

            <div v-if="selected[p.id]" class="mt-3 ml-8 space-y-2">
              <div class="grid grid-cols-3 gap-2">
                <div><label class="text-xs text-gray-500">Qty</label><input v-model="selected[p.id].quantity" type="number" min="0" step="0.01" class="input" /></div>
                <div><label class="text-xs text-gray-500">Unit cost ₹</label><input v-model="selected[p.id].unit_cost" type="number" min="0" class="input" /></div>
                <div><label class="text-xs text-gray-500">MRP ₹</label><input v-model="selected[p.id].mrp" type="number" min="0" class="input" /></div>
              </div>
              <div v-if="!noBill" class="grid grid-cols-2 gap-2">
                <select v-model="selected[p.id].gst_mode" class="input">
                  <option v-for="(label, key) in GST_MODE_LABELS" :key="key" :value="key">{{ label }}</option>
                </select>
                <select v-model.number="selected[p.id].gst_rate" class="input" :disabled="selected[p.id].gst_mode === 'cash'">
                  <option v-for="r in [5, 12, 18, 28]" :key="r" :value="r">GST {{ r }}%</option>
                </select>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <input v-model="selected[p.id].brand" class="input" placeholder="Brand (e.g. Bosch)" />
                <input v-model="selected[p.id].part_number" class="input" placeholder="Part no." />
              </div>
              <div class="flex flex-wrap gap-2">
                <div v-for="(ph, i) in selected[p.id].photos" :key="i" class="relative h-20 w-20 overflow-hidden rounded-lg ring-1 ring-gray-200">
                  <img :src="ph.url" class="h-full w-full object-cover" alt="Part" />
                  <button type="button" class="absolute right-0.5 top-0.5 rounded-full bg-white/90 p-0.5" aria-label="Remove" @click="removePhoto(selected[p.id].photos, i)">
                    <XMarkIcon class="h-4 w-4" />
                  </button>
                </div>
                <label
                  class="flex h-20 w-20 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed text-xs"
                  :class="selected[p.id].photos.length < minPhotos ? 'border-primary-400 text-primary-700' : 'border-gray-300 text-gray-500'"
                >
                  <CameraIcon class="h-6 w-6" />
                  Part
                  <input type="file" accept="image/*" capture="environment" class="hidden" @change="addPhotos(selected[p.id].photos, $event)" />
                </label>
              </div>
            </div>
          </li>
        </ul>
        <button
          v-if="supplierId && otherParts.length && !showOtherSuppliers"
          type="button"
          class="w-full border-t border-gray-100 px-4 py-3 text-sm font-medium text-primary-700"
          @click="showOtherSuppliers = true"
        >
          Show {{ otherParts.length }} part(s) priced with other suppliers
        </button>
      </Card>

      <!-- Consumables into stock -->
      <Card :padding="false">
        <template #header>
          <h3 class="font-semibold">Consumables into stock</h3>
          <p class="text-xs text-gray-500">Oil, filters, consumables and detailing supplies for the store.</p>
        </template>
        <div class="divide-y divide-gray-100">
          <div v-for="(l, i) in stockLines" :key="i" class="p-4 space-y-2">
            <div class="flex gap-2">
              <select v-model="l.stock_item_id" class="input flex-1">
                <option value="">Stock item…</option>
                <option v-for="it in stockItems" :key="it.id" :value="it.id">{{ it.name }} ({{ it.unit }})</option>
              </select>
              <button type="button" class="p-2 text-gray-400 hover:text-red-600" aria-label="Remove line" @click="stockLines.splice(i, 1)">
                <TrashIcon class="h-5 w-5" />
              </button>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div><label class="text-xs text-gray-500">Qty</label><input v-model="l.quantity" type="number" min="0" step="0.01" class="input" /></div>
              <div><label class="text-xs text-gray-500">Unit cost ₹</label><input v-model="l.unit_cost" type="number" min="0" class="input" /></div>
            </div>
            <div v-if="!noBill" class="grid grid-cols-2 gap-2">
              <select v-model="l.gst_mode" class="input">
                <option v-for="(label, key) in GST_MODE_LABELS" :key="key" :value="key">{{ label }}</option>
              </select>
              <select v-model.number="l.gst_rate" class="input" :disabled="l.gst_mode === 'cash'">
                <option v-for="r in [5, 12, 18, 28]" :key="r" :value="r">GST {{ r }}%</option>
              </select>
            </div>
          </div>
        </div>
        <button type="button" class="w-full border-t border-gray-100 px-4 py-3 text-sm font-medium text-primary-700 inline-flex items-center justify-center gap-1" @click="addStockLine">
          <PlusIcon class="h-4 w-4" /> Add stock item
        </button>
      </Card>

      <!-- Outside services -->
      <Card :padding="false">
        <template #header>
          <h3 class="font-semibold">Outside services</h3>
          <p class="text-xs text-gray-500">Work done for a job by another workshop: alignment, machining, AC gas…</p>
        </template>
        <div class="divide-y divide-gray-100">
          <div v-for="(l, i) in serviceLines" :key="i" class="p-4 space-y-2">
            <div class="flex gap-2">
              <select v-model="l.job_card_id" class="input flex-1">
                <option value="">Job…</option>
                <option v-for="j in data.jobs" :key="j.id" :value="j.id">{{ j.job_number }} · {{ j.car }}</option>
              </select>
              <button type="button" class="p-2 text-gray-400 hover:text-red-600" aria-label="Remove line" @click="serviceLines.splice(i, 1)">
                <TrashIcon class="h-5 w-5" />
              </button>
            </div>
            <select v-if="servicesFor(l.job_card_id).length" v-model="l.estimate_line_key" class="input" @change="pickService(l)">
              <option value="">Not on the estimate</option>
              <option v-for="s in servicesFor(l.job_card_id)" :key="s.estimate_line_key" :value="s.estimate_line_key">{{ s.description }}</option>
            </select>
            <input v-model="l.description" class="input" placeholder="Service (e.g. Wheel alignment)" />
            <div class="grid grid-cols-3 gap-2">
              <input v-model="l.unit_cost" type="number" min="0" class="input" placeholder="Cost ₹" />
              <select v-model="l.gst_mode" class="input" :disabled="noBill">
                <option v-for="(label, key) in GST_MODE_LABELS" :key="key" :value="key">{{ label }}</option>
              </select>
              <select v-model.number="l.gst_rate" class="input" :disabled="noBill || l.gst_mode === 'cash'">
                <option v-for="r in [5, 12, 18, 28]" :key="r" :value="r">{{ r }}%</option>
              </select>
            </div>
          </div>
        </div>
        <button type="button" class="w-full border-t border-gray-100 px-4 py-3 text-sm font-medium text-primary-700 inline-flex items-center justify-center gap-1" @click="addServiceLine">
          <PlusIcon class="h-4 w-4" /> Add outside service
        </button>
      </Card>

      <Card>
        <label class="label">Note</label>
        <textarea v-model="notes" rows="2" class="input" placeholder="Anything to remember about this delivery" />
      </Card>
    </div>

    <!-- Post bar -->
    <div
      v-if="data"
      class="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] lg:bottom-0 z-30 border-t border-gray-200 bg-white/95 backdrop-blur px-4 py-3 shadow-[0_-4px_12px_rgba(0,0,0,0.05)]"
    >
      <div class="max-w-3xl mx-auto lg:pl-64 flex items-center gap-3">
        <div class="flex-1 min-w-0 text-sm">
          <p class="font-semibold text-gray-900">₹ {{ money(totals.total) }} <span class="font-normal text-gray-500">· {{ totals.count }} line(s)</span></p>
          <p v-if="problems.length" class="truncate text-xs text-primary-700">Needed: {{ problems.join(' · ') }}</p>
          <p v-else class="text-xs text-gray-500">Taxable ₹ {{ money(totals.taxable) }} + GST ₹ {{ money(totals.gst) }}</p>
        </div>
        <Button :loading="saving" :disabled="problems.length > 0" @click="post">Post GRN</Button>
      </div>
    </div>
  </div>
</template>
