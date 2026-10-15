<script setup lang="ts">
/**
 * Owner's price masters (formerly the Masters and Line Items tabs of the estimate
 * sheet). Edits are held on screen (changed cells highlighted) and saved together
 * with "Save changes" after a confirmation. They apply to estimates prepared from
 * now on; estimates already sent keep their prices.
 */
import { ref, computed, onMounted } from 'vue'
import { pricingApi } from '@/api/estimates.api'
import { usePermissions } from '@/composables/usePermissions'
import { usePendingChanges } from '@/composables/usePendingChanges'
import { useToast } from '@/composables/useToast'
import type { PricingMasters, PlanMaster, FuelGroup, LabourServiceMaster } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'
import UnsavedChangesBar from '@/components/ui/UnsavedChangesBar.vue'
import CarClassesTab from '@/components/masters/CarClassesTab.vue'
import { ArrowUpIcon, ArrowDownIcon, TrashIcon } from '@heroicons/vue/20/solid'

const { can } = usePermissions()
const toast = useToast()
const pending = usePendingChanges()

const data = ref<PricingMasters | null>(null)
const loading = ref(true)
const savingAll = ref(false)
const tab = ref<'plans' | 'prices' | 'items' | 'oil' | 'labour' | 'classes'>('plans')
/** Bumped after saving / discarding so the car classes tab reloads too */
const reloadKey = ref(0)
const planId = ref<number | null>(null)
const newItem = ref('')

const canEdit = computed(() => can('workshop.pricing-masters.update'))

const TABS = [
  { key: 'plans', label: 'Service plans' },
  { key: 'prices', label: 'Package prices' },
  { key: 'items', label: 'Plan items' },
  { key: 'oil', label: 'Engine oil' },
  { key: 'labour', label: 'Labour charges & parts margin' },
  { key: 'classes', label: 'Car classes & models' },
] as const

async function load() {
  loading.value = true
  try {
    data.value = (await pricingApi.get()).data.data
    planId.value ??= data.value.packages[0]?.id ?? null
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

function value(e: Event): string {
  return (e.target as HTMLInputElement).value.trim()
}

function checked(e: Event): boolean {
  return (e.target as HTMLInputElement).checked
}

/** Highlight for a changed, unsaved cell */
function hl(key: string): string {
  return pending.isChanged(key) ? 'border-amber-400 bg-amber-50 ring-1 ring-amber-300' : ''
}

const planName = (id: number) => data.value?.packages.find((p) => p.id === id)?.name ?? 'Plan'
const catName = (id: number) => data.value?.categories.find((c) => c.id === id)?.name ?? 'Car type'
const oilName = (id: number) => data.value?.oil_types.find((o) => o.id === id)?.name ?? 'Oil'

async function saveAll() {
  savingAll.value = true
  const { saved, errors } = await pending.saveAll()
  savingAll.value = false
  if (errors.length) {
    toast.apiError(errors[0])
    toast.error('Some changes were not saved', `${saved} saved, ${errors.length} still pending — check and save again.`)
  } else {
    toast.success('Saved', `${saved} change${saved === 1 ? '' : 's'} saved.`)
  }
  await load()
  reloadKey.value++
}

async function discardAll() {
  pending.clear()
  await load()
  reloadKey.value++
}

/** Adding / removing rows saves at once; other edits must be saved or discarded first */
function noPendingEdits(): boolean {
  if (pending.count.value) {
    toast.error('Unsaved changes', 'Save or discard your other changes first.')
    return false
  }
  return true
}

async function runNow(action: () => Promise<unknown>, ok: string) {
  try {
    await action()
    toast.success(ok)
    await load()
  } catch (err) {
    toast.apiError(err)
  }
}

// ---- package prices: plan × car type × oil grade

function price(packageId: number, categoryId: number, oilTypeId: number): string {
  const row = data.value?.prices.find(
    (p) => p.package_id === packageId && p.category_id === categoryId && p.oil_type_id === oilTypeId
  )
  return row ? String(Number(row.price)) : ''
}

function setPrice(packageId: number, categoryId: number, oilTypeId: number, raw: string) {
  const m = data.value
  if (!m) return
  const amount = raw === '' ? null : Number(raw)
  const index = m.prices.findIndex((p) => p.package_id === packageId && p.category_id === categoryId && p.oil_type_id === oilTypeId)
  if (amount === null) {
    if (index >= 0) m.prices.splice(index, 1)
  } else if (index >= 0) {
    m.prices[index].price = String(amount)
  } else {
    m.prices.push({ package_id: packageId, category_id: categoryId, oil_type_id: oilTypeId, price: String(amount) })
  }
  pending.queue(
    `price:${packageId}:${categoryId}:${oilTypeId}`,
    `${planName(packageId)} · ${catName(categoryId)} · ${oilName(oilTypeId)}: ${amount === null ? 'not offered' : `₹ ${amount}`}`,
    () => pricingApi.setPrice({ package_id: packageId, category_id: categoryId, oil_type_id: oilTypeId, price: amount })
  )
}

// ---- plan items: item × car type

const planItems = computed(() =>
  (data.value?.line_items.filter((i) => i.package_id === planId.value) ?? []).slice().sort((a, b) => a.sort_order - b.sort_order)
)

type LineItem = PricingMasters['line_items'][number]

function setItemDescription(item: LineItem, e: Event) {
  item.description = value(e)
  pending.queue(`item:${item.id}:description`, `${planName(item.package_id)} item renamed: ${item.description}`, () =>
    pricingApi.updateLineItem(item.id, { description: item.description })
  )
}

function setItemActive(item: LineItem, e: Event) {
  item.is_active = checked(e)
  pending.queue(`item:${item.id}:active`, `${item.description}: ${item.is_active ? 'active' : 'inactive'}`, () =>
    pricingApi.updateLineItem(item.id, { is_active: item.is_active })
  )
}

function setItemAmount(item: LineItem, categoryId: number, e: Event) {
  const amount = Number(value(e)) || 0
  item.prices[categoryId] = amount
  pending.queue(`item:${item.id}:price:${categoryId}`, `${item.description} · ${catName(categoryId)}: ₹ ${amount}`, () =>
    pricingApi.updateLineItem(item.id, { prices: { [categoryId]: amount } })
  )
}

function addItem() {
  if (!planId.value || !newItem.value.trim() || !noPendingEdits()) return
  const description = newItem.value.trim()
  newItem.value = ''
  runNow(() => pricingApi.addLineItem({ package_id: planId.value!, description }), 'Plan item added')
}

function deleteItem(id: number, description: string) {
  if (!noPendingEdits()) return
  if (!window.confirm(`Remove "${description}" from this plan? Estimates already prepared keep it.`)) return
  runNow(() => pricingApi.deleteLineItem(id), 'Plan item removed')
}

/**
 * Move a row up or down on screen; the whole list is renumbered and saved with the other changes.
 */
function move<T extends { id: number; sort_order: number }>(
  list: T[],
  index: number,
  dir: -1 | 1,
  keyPrefix: string,
  label: (row: T) => string,
  persist: (id: number, order: number) => Promise<unknown>
) {
  const target = index + dir
  if (target < 0 || target >= list.length) return
  const reordered = [...list]
  ;[reordered[index], reordered[target]] = [reordered[target], reordered[index]]
  reordered.forEach((row, i) => {
    row.sort_order = i + 1
    pending.queue(`${keyPrefix}:order:${row.id}`, `Order: ${label(row)} → ${i + 1}`, () => persist(row.id, i + 1))
  })
  // Re-sort the on-screen arrays
  if (data.value) {
    data.value.packages.sort((a, b) => a.sort_order - b.sort_order)
    data.value.labour_services.sort((a, b) => a.sort_order - b.sort_order)
  }
}

// ---- service plans (the dialog has its own Save, with a confirmation)

const editingPlan = ref<PlanMaster | null>(null)
const showPlanForm = ref(false)
const savingPlan = ref(false)
const planForm = ref({
  name: '',
  description: '',
  features: '',
  activity_points: 0,
  home_service_available: false,
  send_customer_report: false,
  next_service_km: null as number | null,
  next_service_months: null as number | null,
  is_active: true,
})
/** Package prices in the dialog: "categoryId:oilTypeId" → amount ('' = not offered) */
const planPrices = ref<Record<string, string>>({})

function openPlan(plan: PlanMaster | null) {
  if (!noPendingEdits()) return
  editingPlan.value = plan
  planForm.value = {
    name: plan?.name ?? '',
    description: plan?.description ?? '',
    features: (plan?.features ?? []).join('\n'),
    activity_points: plan?.activity_points ?? 0,
    home_service_available: plan?.home_service_available ?? false,
    send_customer_report: plan?.send_customer_report ?? false,
    next_service_km: plan ? plan.next_service_km : 10000,
    next_service_months: plan ? plan.next_service_months : 9,
    is_active: plan?.is_active ?? true,
  }
  planPrices.value = {}
  for (const cat of data.value?.categories ?? []) {
    for (const oil of data.value?.oil_types ?? []) {
      planPrices.value[`${cat.id}:${oil.id}`] = plan ? price(plan.id, cat.id, oil.id) : ''
    }
  }
  showPlanForm.value = true
}

async function savePlan() {
  const f = planForm.value
  if (!f.name.trim()) {
    toast.error('Name needed', 'Give the plan a name.')
    return
  }
  if (!window.confirm(`Save ${editingPlan.value ? 'changes to' : 'the new plan'} "${f.name.trim()}"?`)) return

  const body = {
    ...f,
    name: f.name.trim(),
    description: f.description.trim() || null,
    features: f.features.split('\n').map((x) => x.trim()).filter(Boolean),
    activity_points: Number(f.activity_points) || 0,
  }
  savingPlan.value = true
  try {
    let id = editingPlan.value?.id
    if (id) {
      await pricingApi.updatePackage(id, body)
    } else {
      id = (await pricingApi.createPackage(body)).data.data.id
      planId.value = id
    }
    // Prices that changed in the dialog
    for (const [key, amount] of Object.entries(planPrices.value)) {
      const [categoryId, oilTypeId] = key.split(':').map(Number)
      const before = editingPlan.value ? price(editingPlan.value.id, categoryId, oilTypeId) : ''
      if (amount.trim() === before) continue
      await pricingApi.setPrice({
        package_id: id,
        category_id: categoryId,
        oil_type_id: oilTypeId,
        price: amount.trim() === '' ? null : Number(amount),
      })
    }
    toast.success('Plan saved')
    showPlanForm.value = false
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    savingPlan.value = false
  }
}

function planWarnings(plan: PlanMaster): string[] {
  const out: string[] = []
  if (!plan.prices_count) out.push('no package prices yet')
  if (!plan.sheets_count) out.push('no job sheet linked (Job Sheet Masters)')
  return out
}

// ---- labour charges: service × car type, per fuel

const fuel = ref<FuelGroup>('petrol')
const newService = ref('')

function labourRate(service: LabourServiceMaster, categoryId: number): string {
  const rate = service.rates[fuel.value]?.[categoryId]
  return rate === null || rate === undefined ? '' : String(rate)
}

function setLabourRate(service: LabourServiceMaster, categoryId: number, e: Event) {
  const v = value(e)
  const rate = v === '' ? null : Number(v)
  const group = fuel.value
  service.rates[group] = { ...service.rates[group], [categoryId]: rate }
  pending.queue(
    `labour:${service.id}:${group}:${categoryId}`,
    `${service.name} · ${catName(categoryId)} · ${group}: ${rate === null ? 'not offered' : `₹ ${rate}`}`,
    () => pricingApi.updateLabourService(service.id, { rates: { [group]: { [categoryId]: rate } } })
  )
}

function setLabourField(service: LabourServiceMaster, field: 'name' | 'unit' | 'is_active', e: Event) {
  const next = field === 'is_active' ? checked(e) : value(e)
  ;(service as any)[field] = next
  pending.queue(`labour:${service.id}:${field}`, `${service.name}: ${field.replace('is_', '')} → ${next}`, () =>
    pricingApi.updateLabourService(service.id, { [field]: next })
  )
}

function addService() {
  const name = newService.value.trim()
  if (!name || !noPendingEdits()) return
  newService.value = ''
  runNow(() => pricingApi.addLabourService({ name }), 'Labour service added')
}

function setMargin(e: Event) {
  const v = Number(value(e))
  if (Number.isNaN(v) || v < 0 || !data.value) return
  data.value.parts_default_margin_pct = v
  pending.queue('parts-margin', `Default parts margin: ${v}%`, () => pricingApi.setPartsMargin(v))
}

// ---- oil

function setOil(oil: PricingMasters['oil_types'][number], field: 'product_name' | 'rate_per_litre', e: Event) {
  const raw = value(e)
  if (field === 'product_name') {
    oil.product_name = raw || null
    pending.queue(`oil:${oil.id}:product`, `${oil.name} product: ${raw || '—'}`, () =>
      pricingApi.updateOilType(oil.id, { product_name: raw || null })
    )
  } else {
    const rate = Number(raw) || 0
    oil.rate_per_litre = String(rate)
    pending.queue(`oil:${oil.id}:rate`, `${oil.name} rate per litre: ₹ ${rate}`, () =>
      pricingApi.updateOilType(oil.id, { rate_per_litre: rate })
    )
  }
}

function litres(packageId: number, categoryId: number): string {
  const row = data.value?.litres.find((l) => l.package_id === packageId && l.category_id === categoryId)
  return row ? String(Number(row.litres)) : ''
}

function setLitres(packageId: number, categoryId: number, e: Event) {
  const m = data.value
  if (!m) return
  const amount = Number(value(e)) || 0
  const row = m.litres.find((l) => l.package_id === packageId && l.category_id === categoryId)
  if (row) row.litres = String(amount)
  else m.litres.push({ package_id: packageId, category_id: categoryId, litres: String(amount) })
  pending.queue(`litres:${packageId}:${categoryId}`, `${planName(packageId)} · ${catName(categoryId)}: ${amount} L`, () =>
    pricingApi.setLitres({ package_id: packageId, category_id: categoryId, litres: amount })
  )
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">Price Masters</h1>
      <p class="text-sm text-gray-500">All prices include GST. Edit, then “Save changes” at the bottom. Changes apply to estimates prepared from now on.</p>
    </div>

    <div class="flex flex-wrap gap-2 mb-4">
      <button
        v-for="t in TABS"
        :key="t.key"
        class="rounded-lg px-3 py-1.5 text-sm font-medium"
        :class="tab === t.key ? 'bg-primary-600 text-white' : 'bg-white border border-gray-300 text-gray-700'"
        @click="tab = t.key"
      >
        {{ t.label }}
      </button>
    </div>

    <div v-if="loading && !data" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <template v-else-if="data">
      <!-- Package prices -->
      <div v-if="tab === 'prices'" class="space-y-4">
        <p class="text-sm text-gray-500">Leave a cell empty when the plan is not offered for that car type and oil grade.</p>
        <Card v-for="pkg in data.packages" :key="pkg.id" :padding="false">
          <template #header><h3 class="font-semibold">{{ pkg.name }}</h3></template>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 text-xs text-gray-500">
                <tr>
                  <th class="px-4 py-2 text-left">Car type</th>
                  <th v-for="oil in data.oil_types" :key="oil.id" class="px-2 py-2 text-right">{{ oil.name }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="cat in data.categories" :key="cat.id">
                  <td class="px-4 py-1.5">{{ cat.name }}</td>
                  <td v-for="oil in data.oil_types" :key="oil.id" class="px-2 py-1.5 text-right">
                    <input
                      type="number"
                      min="0"
                      class="w-24 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
                      :value="price(pkg.id, cat.id, oil.id)"
                      :class="hl(`price:${pkg.id}:${cat.id}:${oil.id}`)"
                      :disabled="!canEdit"
                      placeholder="—"
                      @change="(e) => setPrice(pkg.id, cat.id, oil.id, value(e))"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </Card>
      </div>

      <!-- Plan items -->
      <Card v-if="tab === 'items'" :padding="false">
        <template #header>
          <div class="flex flex-wrap items-center gap-3">
            <select v-model="planId" class="rounded-lg border-gray-300 text-sm">
              <option v-for="p in data.packages" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <p class="text-xs text-gray-500">
              Items shown inside the plan on the estimate. The oil and labour lines are automatic: labour = package price −
              oil − these items. 0 = not included for that car type.
            </p>
          </div>
        </template>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500">
              <tr>
                <th class="px-4 py-2 text-left">Item</th>
                <th v-for="cat in data.categories" :key="cat.id" class="px-2 py-2 text-right whitespace-nowrap">{{ cat.name }}</th>
                <th class="px-2 py-2">Active</th>
                <th v-if="canEdit" class="px-2 py-2"><span class="sr-only">Order and delete</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(item, index) in planItems" :key="item.id" :class="item.is_active ? '' : 'opacity-50'">
                <td class="px-4 py-1.5 min-w-[14rem]">
                  <input
                    class="w-full rounded border-gray-300 px-1 py-0.5 text-sm disabled:bg-gray-50"
                    :value="item.description"
                    :disabled="!canEdit"
                    @change="(e) => setItemDescription(item, e)"
                  />
                </td>
                <td v-for="cat in data.categories" :key="cat.id" class="px-2 py-1.5 text-right">
                  <input
                    type="number"
                    min="0"
                    class="w-20 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
                    :value="item.prices[cat.id] ?? 0"
                    :class="hl(`item:${item.id}:price:${cat.id}`)"
                    :disabled="!canEdit"
                    @change="(e) => setItemAmount(item, cat.id, e)"
                  />
                </td>
                <td class="px-2 py-1.5 text-center">
                  <input
                    type="checkbox"
                    class="rounded border-gray-300"
                    :checked="item.is_active"
                    :disabled="!canEdit"
                    @change="(e) => setItemActive(item, e)"
                  />
                </td>
                <td v-if="canEdit" class="px-2 py-1.5 whitespace-nowrap">
                  <button class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="index === 0" aria-label="Move up"
                    @click="move(planItems, index, -1, 'item', (r) => r.description, (id, order) => pricingApi.updateLineItem(id, { sort_order: order }))">
                    <ArrowUpIcon class="h-4 w-4" />
                  </button>
                  <button class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="index === planItems.length - 1" aria-label="Move down"
                    @click="move(planItems, index, 1, 'item', (r) => r.description, (id, order) => pricingApi.updateLineItem(id, { sort_order: order }))">
                    <ArrowDownIcon class="h-4 w-4" />
                  </button>
                  <button class="p-1 text-gray-400 hover:text-red-600" aria-label="Delete item" @click="deleteItem(item.id, item.description)">
                    <TrashIcon class="h-4 w-4" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="canEdit" class="flex gap-2 px-4 py-3 border-t border-gray-100">
          <input v-model="newItem" class="flex-1 rounded-lg border-gray-300 text-sm" placeholder="New item, e.g. Coolant top-up" @keyup.enter="addItem" />
          <Button size="sm" :disabled="!newItem.trim()" @click="addItem">Add item</Button>
        </div>
      </Card>

      <!-- Engine oil -->
      <div v-if="tab === 'oil'" class="space-y-4">
        <Card :padding="false">
          <template #header><h3 class="font-semibold">Oil grades</h3></template>
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500">
              <tr><th class="px-4 py-2 text-left">Grade</th><th class="px-2 py-2 text-left">Product (printed on estimate)</th><th class="px-4 py-2 text-right">Rate / litre</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="oil in data.oil_types" :key="oil.id">
                <td class="px-4 py-1.5">{{ oil.name }}</td>
                <td class="px-2 py-1.5">
                  <input
                    class="w-full rounded border-gray-300 px-1 py-0.5 text-sm disabled:bg-gray-50"
                    :value="oil.product_name ?? ''"
                    :disabled="!canEdit"
                    @change="(e) => setOil(oil, 'product_name', e)"
                  />
                </td>
                <td class="px-4 py-1.5 text-right">
                  <input
                    type="number"
                    min="0"
                    class="w-24 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
                    :value="Number(oil.rate_per_litre)"
                    :disabled="!canEdit"
                    @change="(e) => setOil(oil, 'rate_per_litre', e)"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </Card>

        <Card :padding="false">
          <template #header><h3 class="font-semibold">Engine oil quantity (litres)</h3></template>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 text-xs text-gray-500">
                <tr>
                  <th class="px-4 py-2 text-left">Plan</th>
                  <th v-for="cat in data.categories" :key="cat.id" class="px-2 py-2 text-right whitespace-nowrap">{{ cat.name }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="pkg in data.packages" :key="pkg.id">
                  <td class="px-4 py-1.5">{{ pkg.name }}</td>
                  <td v-for="cat in data.categories" :key="cat.id" class="px-2 py-1.5 text-right">
                    <input
                      type="number"
                      min="0"
                      step="0.5"
                      class="w-16 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
                      :value="litres(pkg.id, cat.id)"
                      :class="hl(`litres:${pkg.id}:${cat.id}`)"
                      :disabled="!canEdit"
                      @change="(e) => setLitres(pkg.id, cat.id, e)"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </Card>
      </div>

      <!-- Service plans -->
      <div v-if="tab === 'plans'" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <p class="text-sm text-gray-500">
            Plans offered to customers. A plan is never deleted (old job cards use it): untick Active to stop offering it.
          </p>
          <Button v-if="canEdit" size="sm" @click="openPlan(null)">Add plan</Button>
        </div>

        <Card :padding="false">
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 text-xs text-gray-500">
                <tr>
                  <th class="px-4 py-2 text-left">Plan</th>
                  <th class="px-2 py-2 text-right">Activity points</th>
                  <th class="px-2 py-2">Report to customer</th>
                  <th class="px-2 py-2 text-right">Next service</th>
                  <th class="px-2 py-2">Active</th>
                  <th class="px-2 py-2"><span class="sr-only">Actions</span></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(pkg, index) in data.packages" :key="pkg.id" :class="pkg.is_active ? '' : 'opacity-50'">
                  <td class="px-4 py-2">
                    <p class="font-medium text-gray-900">{{ pkg.name }}</p>
                    <p v-if="pkg.description" class="text-xs text-gray-500 line-clamp-2">{{ pkg.description }}</p>
                    <p v-for="w in planWarnings(pkg)" :key="w" class="text-xs text-amber-700">⚠ {{ w }}</p>
                  </td>
                  <td class="px-2 py-2 text-right">{{ pkg.activity_points }}</td>
                  <td class="px-2 py-2 text-center">{{ pkg.send_customer_report ? 'Yes' : 'No' }}</td>
                  <td class="px-2 py-2 text-right whitespace-nowrap">
                    {{ pkg.next_service_km ? `${pkg.next_service_km.toLocaleString('en-IN')} km` : '—' }}
                    <span v-if="pkg.next_service_months"> / {{ pkg.next_service_months }} mo</span>
                  </td>
                  <td class="px-2 py-2 text-center">{{ pkg.is_active ? 'Yes' : 'No' }}</td>
                  <td class="px-2 py-2 whitespace-nowrap text-right">
                    <template v-if="canEdit">
                      <button class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="index === 0" aria-label="Move up"
                        @click="move(data.packages, index, -1, 'plan', (r) => r.name, (id, order) => pricingApi.updatePackage(id, { sort_order: order }))">
                        <ArrowUpIcon class="h-4 w-4" />
                      </button>
                      <button class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="index === data.packages.length - 1" aria-label="Move down"
                        @click="move(data.packages, index, 1, 'plan', (r) => r.name, (id, order) => pricingApi.updatePackage(id, { sort_order: order }))">
                        <ArrowDownIcon class="h-4 w-4" />
                      </button>
                      <Button size="sm" variant="ghost" @click="openPlan(pkg)">Edit</Button>
                    </template>
                    <Button v-else size="sm" variant="ghost" @click="openPlan(pkg)">View</Button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </Card>

        <Modal :open="showPlanForm" :title="editingPlan ? `Edit ${editingPlan.name}` : 'Add plan'" size="lg" @close="showPlanForm = false">
          <form class="space-y-4" @submit.prevent="savePlan">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <label class="block text-sm">
                <span class="font-medium text-gray-700">Name</span>
                <input v-model="planForm.name" class="mt-1 w-full rounded-lg border-gray-300 text-sm" :disabled="!canEdit" required maxlength="100" />
              </label>
              <label class="block text-sm">
                <span class="font-medium text-gray-700">Activity points</span>
                <input v-model.number="planForm.activity_points" type="number" min="0" class="mt-1 w-full rounded-lg border-gray-300 text-sm" :disabled="!canEdit" />
              </label>
            </div>
            <label class="block text-sm">
              <span class="font-medium text-gray-700">Description</span>
              <textarea v-model="planForm.description" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm" :disabled="!canEdit" />
            </label>
            <label class="block text-sm">
              <span class="font-medium text-gray-700">Features</span>
              <span class="block text-xs text-gray-500">One per line, as shown to customers.</span>
              <textarea v-model="planForm.features" rows="5" class="mt-1 w-full rounded-lg border-gray-300 text-sm" :disabled="!canEdit" />
            </label>
            <div class="grid grid-cols-2 gap-4">
              <label class="block text-sm">
                <span class="font-medium text-gray-700">Next service after (km)</span>
                <input v-model.number="planForm.next_service_km" type="number" min="0" step="500" class="mt-1 w-full rounded-lg border-gray-300 text-sm" :disabled="!canEdit" />
              </label>
              <label class="block text-sm">
                <span class="font-medium text-gray-700">… or after (months)</span>
                <input v-model.number="planForm.next_service_months" type="number" min="0" max="60" class="mt-1 w-full rounded-lg border-gray-300 text-sm" :disabled="!canEdit" />
              </label>
            </div>
            <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
              <label class="inline-flex items-center gap-2">
                <input v-model="planForm.is_active" type="checkbox" class="rounded border-gray-300" :disabled="!canEdit" /> Active (offered)
              </label>
              <label class="inline-flex items-center gap-2">
                <input v-model="planForm.send_customer_report" type="checkbox" class="rounded border-gray-300" :disabled="!canEdit" /> Service report goes to customer
              </label>
              <label class="inline-flex items-center gap-2">
                <input v-model="planForm.home_service_available" type="checkbox" class="rounded border-gray-300" :disabled="!canEdit" /> Home service available
              </label>
            </div>
            <div>
              <p class="text-sm font-medium text-gray-700">Package prices (₹, incl. GST)</p>
              <p class="text-xs text-gray-500 mb-1">Leave empty where the plan isn't offered for that car type and oil grade.</p>
              <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead class="text-xs text-gray-500">
                    <tr>
                      <th class="py-1 pr-2 text-left">Car type</th>
                      <th v-for="oil in data.oil_types" :key="oil.id" class="px-1 py-1 text-right">{{ oil.name }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="cat in data.categories" :key="cat.id">
                      <td class="py-1 pr-2 whitespace-nowrap">{{ cat.name }}</td>
                      <td v-for="oil in data.oil_types" :key="oil.id" class="px-1 py-1 text-right">
                        <input
                          v-model="planPrices[`${cat.id}:${oil.id}`]"
                          type="number"
                          min="0"
                          class="w-24 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
                          :disabled="!canEdit"
                          placeholder="—"
                        />
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <p v-if="!editingPlan" class="text-xs text-gray-500">
              After adding, set the oil litres (Engine oil) and items (Plan items), and link a job sheet in Job Sheet Masters.
            </p>
            <div class="flex justify-end gap-2">
              <Button type="button" variant="secondary" @click="showPlanForm = false">{{ canEdit ? 'Cancel' : 'Close' }}</Button>
              <Button v-if="canEdit" type="submit" :loading="savingPlan">Save plan</Button>
            </div>
          </form>
        </Modal>
      </div>

      <!-- Labour charges + parts margin -->
      <div v-if="tab === 'labour'" class="space-y-4">
        <Card>
          <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="font-medium text-gray-700">Default margin on parts</span>
            <input
              type="number"
              min="0"
              step="1"
              class="w-20 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
              :value="data.parts_default_margin_pct"
              :disabled="!canEdit"
              @change="setMargin"
            />
            <span class="text-gray-500">% added to the supplier's GST-inclusive cost. The advisor can change it per part.</span>
          </div>
        </Card>

        <Card :padding="false">
          <template #header>
            <div class="flex flex-wrap items-center gap-3">
              <h3 class="font-semibold">Schedule of labour charges</h3>
              <div class="inline-flex rounded-lg border border-gray-300 overflow-hidden text-sm">
                <button
                  v-for="f in (['petrol', 'diesel'] as const)"
                  :key="f"
                  class="px-3 py-1 capitalize"
                  :class="fuel === f ? 'bg-primary-600 text-white' : 'bg-white text-gray-700'"
                  @click="fuel = f"
                >
                  {{ f }}
                </button>
              </div>
              <p class="text-xs text-gray-500">
                GST-inclusive. CNG, hybrid and electric cars use the petrol rates. Leave a cell empty when the service isn't offered.
              </p>
            </div>
          </template>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50 text-xs text-gray-500">
                <tr>
                  <th class="px-4 py-2 text-left">Service</th>
                  <th class="px-2 py-2">Per</th>
                  <th v-for="cat in data.categories" :key="cat.id" class="px-2 py-2 text-right whitespace-nowrap">{{ cat.name }}</th>
                  <th class="px-2 py-2">Active</th>
                  <th v-if="canEdit" class="px-2 py-2"><span class="sr-only">Order</span></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(svc, index) in data.labour_services" :key="svc.id" :class="svc.is_active ? '' : 'opacity-50'">
                  <td class="px-4 py-1.5 min-w-[16rem]">
                    <input
                      class="w-full rounded border-gray-300 px-1 py-0.5 text-sm disabled:bg-gray-50"
                      :value="svc.name"
                      :disabled="!canEdit"
                      @change="(e) => setLabourField(svc, 'name', e)"
                    />
                  </td>
                  <td class="px-2 py-1.5">
                    <select
                      class="rounded border-gray-300 py-0.5 text-sm disabled:bg-gray-50"
                      :value="svc.unit"
                      :disabled="!canEdit"
                      @change="(e) => setLabourField(svc, 'unit', e)"
                    >
                      <option value="job">job</option>
                      <option value="hour">hour</option>
                    </select>
                  </td>
                  <td v-for="cat in data.categories" :key="cat.id" class="px-2 py-1.5 text-right">
                    <input
                      type="number"
                      min="0"
                      class="w-20 rounded border-gray-300 px-1 py-0.5 text-right text-sm disabled:bg-gray-50"
                      :value="labourRate(svc, cat.id)"
                      :class="hl(`labour:${svc.id}:${fuel}:${cat.id}`)"
                      :disabled="!canEdit"
                      placeholder="—"
                      @change="(e) => setLabourRate(svc, cat.id, e)"
                    />
                  </td>
                  <td class="px-2 py-1.5 text-center">
                    <input
                      type="checkbox"
                      class="rounded border-gray-300"
                      :checked="svc.is_active"
                      :disabled="!canEdit"
                      @change="(e) => setLabourField(svc, 'is_active', e)"
                    />
                  </td>
                  <td v-if="canEdit" class="px-2 py-1.5 whitespace-nowrap">
                    <button class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="index === 0" aria-label="Move up"
                      @click="move(data.labour_services, index, -1, 'labour', (r) => r.name, (id, order) => pricingApi.updateLabourService(id, { sort_order: order }))">
                      <ArrowUpIcon class="h-4 w-4" />
                    </button>
                    <button class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="index === data.labour_services.length - 1" aria-label="Move down"
                      @click="move(data.labour_services, index, 1, 'labour', (r) => r.name, (id, order) => pricingApi.updateLabourService(id, { sort_order: order }))">
                      <ArrowDownIcon class="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="canEdit" class="flex gap-2 px-4 py-3 border-t border-gray-100">
            <input v-model="newService" class="flex-1 rounded-lg border-gray-300 text-sm" placeholder="New service, e.g. Wheel Bearing Replacement" @keyup.enter="addService" />
            <Button size="sm" :disabled="!newService.trim()" @click="addService">Add service</Button>
          </div>
        </Card>
      </div>
      <CarClassesTab v-if="tab === 'classes'" :pending="pending" :can-edit="canEdit" :reload-key="reloadKey" />

      <UnsavedChangesBar
        :count="pending.count.value"
        :labels="pending.labels.value"
        :saving="savingAll"
        @save="saveAll"
        @discard="discardAll"
      />
    </template>
  </div>
</template>
