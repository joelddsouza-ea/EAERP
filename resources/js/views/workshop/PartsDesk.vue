<script setup lang="ts">
/**
 * Spare parts coordinator's desk. Price requests: record supplier quotes (GST
 * included / plus GST / cash), pick one per part and send the prices to the
 * advisor. To procure: parts the customer approved, to order from the chosen supplier.
 */
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { formatDistanceToNow } from 'date-fns'
import { TrashIcon } from '@heroicons/vue/20/solid'
import { partsApi } from '@/api/estimates.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import type { SparePartRequest, Supplier, GstMode } from '@/api/types'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'

type Tab = 'requests' | 'procure'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const toast = useToast()

const tab = ref<Tab>(route.query.tab === 'procure' ? 'procure' : 'requests')
const requests = ref<SparePartRequest[]>([])
const suppliers = ref<Supplier[]>([])
const loading = ref(true)
const busy = ref(false)

const canQuote = computed(() => can('workshop.spare-parts.update'))

/** Quote chosen per part (before sending to the advisor) */
const chosen = reactive<Record<number, number>>({})

/** New-quote form per part */
const forms = reactive<Record<number, { supplier: string; unit_cost: number | string; gst_mode: GstMode; availability: string }>>({})

const GST_MODES: { value: GstMode; label: string }[] = [
  { value: 'incl', label: 'Price includes GST' },
  { value: 'excl', label: 'Price + 18% GST' },
  { value: 'cash', label: 'Cash purchase (no GST bill)' },
]

function money(n: number | null | undefined): string {
  return new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(Number(n ?? 0))
}

function ago(d: string | null): string {
  return d ? formatDistanceToNow(new Date(d), { addSuffix: true }) : ''
}

function landed(f: { unit_cost: number | string; gst_mode: GstMode }): number | null {
  const cost = Number(f.unit_cost)
  if (!f.unit_cost || Number.isNaN(cost)) return null
  return f.gst_mode === 'excl' ? Math.round(cost * 1.18 * 100) / 100 : cost
}

/** Requests grouped by job card */
const byJob = computed(() => {
  const groups = new Map<number, { jobCardId: number; jobNumber: string | null; vehicle: string | null; advisor: string | null; parts: SparePartRequest[] }>()
  for (const r of requests.value) {
    if (!groups.has(r.job_card_id)) {
      groups.set(r.job_card_id, { jobCardId: r.job_card_id, jobNumber: r.job_number, vehicle: r.vehicle, advisor: r.advisor, parts: [] })
    }
    groups.get(r.job_card_id)!.parts.push(r)
  }
  return [...groups.values()]
})

function formFor(r: SparePartRequest) {
  return (forms[r.id] ??= { supplier: '', unit_cost: '', gst_mode: 'incl', availability: '' })
}

async function load() {
  loading.value = true
  try {
    requests.value = (await partsApi.desk(tab.value)).data.data.requests
    for (const r of requests.value) {
      if (r.selected_quote_id) chosen[r.id] ??= r.selected_quote_id
      else if (r.quotes.length === 1) chosen[r.id] ??= r.quotes[0].id
    }
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

async function loadSuppliers() {
  try {
    suppliers.value = (await partsApi.suppliers()).data.data.filter((s) => s.is_active)
  } catch {
    suppliers.value = []
  }
}

async function addQuote(r: SparePartRequest) {
  const f = formFor(r)
  const name = f.supplier.trim()
  if (!name || f.unit_cost === '') {
    toast.error('Missing details', 'Enter the supplier and the price.')
    return
  }
  const existing = suppliers.value.find((s) => s.name.toLowerCase() === name.toLowerCase())
  busy.value = true
  try {
    const quote = (
      await partsApi.addQuote(r.id, {
        supplier_id: existing?.id ?? null,
        supplier_name: existing ? null : name,
        unit_cost: Number(f.unit_cost),
        gst_mode: f.gst_mode,
        availability: f.availability.trim() || null,
      })
    ).data.data
    chosen[r.id] = quote.id
    forms[r.id] = { supplier: '', unit_cost: '', gst_mode: f.gst_mode, availability: '' }
    if (!existing) await loadSuppliers()
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function removeQuote(r: SparePartRequest, quoteId: number) {
  try {
    await partsApi.removeQuote(r.id, quoteId)
    if (chosen[r.id] === quoteId) delete chosen[r.id]
    await load()
  } catch (err) {
    toast.apiError(err)
  }
}

function readyToSend(parts: SparePartRequest[]) {
  return parts.filter((p) => chosen[p.id] && (p.status === 'requested' || p.selected_quote_id !== chosen[p.id]))
}

async function sendPrices(parts: SparePartRequest[]) {
  const selections = Object.fromEntries(readyToSend(parts).map((p) => [p.id, chosen[p.id]]))
  if (!Object.keys(selections).length) return
  busy.value = true
  try {
    await partsApi.submit(selections)
    toast.success('Prices sent', 'The advisor has been notified.')
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function markOrdered(r: SparePartRequest) {
  try {
    await partsApi.markOrdered(r.id)
    toast.success('Marked as ordered')
    await load()
  } catch (err) {
    toast.apiError(err)
  }
}

function selectedQuote(r: SparePartRequest) {
  return r.quotes.find((q) => q.id === r.selected_quote_id) ?? null
}

function setTab(t: Tab) {
  tab.value = t
  router.replace({ query: { tab: t } })
}

watch(tab, load)
watch(
  () => route.query.tab,
  (t) => {
    if (t === 'procure' || t === 'requests') tab.value = t
  }
)

onMounted(() => {
  load()
  loadSuppliers()
})
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">Parts Desk</h1>
      <p class="text-sm text-gray-500">Get prices for the parts advisors need, and order the parts customers approve.</p>
    </div>

    <div class="flex gap-2 mb-4">
      <button
        v-for="t in ([{ key: 'requests', label: 'Price requests' }, { key: 'procure', label: 'To procure' }] as const)"
        :key="t.key"
        class="rounded-lg px-3 py-1.5 text-sm font-medium"
        :class="tab === t.key ? 'bg-primary-600 text-white' : 'bg-white border border-gray-300 text-gray-700'"
        @click="setTab(t.key)"
      >
        {{ t.label }}
      </button>
    </div>

    <datalist id="supplier-names">
      <option v-for="s in suppliers" :key="s.id" :value="s.name" />
    </datalist>

    <div v-if="loading && !requests.length" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <Card v-else-if="!requests.length">
      <p class="text-center text-sm text-gray-500 py-6">
        {{ tab === 'requests' ? 'No price requests waiting.' : 'Nothing to procure right now.' }}
      </p>
    </Card>

    <!-- Price requests -->
    <div v-else-if="tab === 'requests'" class="space-y-4">
      <Card v-for="group in byJob" :key="group.jobCardId" :padding="false">
        <template #header>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <RouterLink :to="`/job-cards/${group.jobCardId}`" class="font-semibold text-primary-700 hover:underline">
                {{ group.jobNumber }}
              </RouterLink>
              <span class="text-sm text-gray-500"> · {{ group.vehicle }}</span>
              <span v-if="group.advisor" class="text-sm text-gray-500"> · Advisor {{ group.advisor }}</span>
            </div>
            <Button v-if="canQuote" size="sm" :loading="busy" :disabled="!readyToSend(group.parts).length" @click="sendPrices(group.parts)">
              Send prices to advisor
            </Button>
          </div>
        </template>

        <ul class="divide-y divide-gray-100">
          <li v-for="r in group.parts" :key="r.id" class="px-4 py-3">
            <div class="flex flex-wrap items-center gap-2">
              <p class="font-medium text-gray-900">{{ r.part_name }}</p>
              <span v-if="r.part_number" class="text-xs text-gray-500">{{ r.part_number }}</span>
              <span class="text-xs text-gray-500">× {{ r.quantity }} {{ r.unit }}</span>
              <Badge size="sm" :variant="r.status === 'quoted' ? 'success' : 'warning'">
                {{ r.status === 'quoted' ? 'Price sent' : 'Needs price' }}
              </Badge>
              <span class="text-xs text-gray-400 ml-auto">{{ r.requested_by }} · {{ ago(r.requested_at) }}</span>
            </div>
            <p v-if="r.note" class="text-xs text-gray-600 mt-1">Note: {{ r.note }}</p>

            <!-- Quotes -->
            <ul v-if="r.quotes.length" class="mt-2 space-y-1">
              <li v-for="q in r.quotes" :key="q.id" class="flex flex-wrap items-center gap-2 text-sm">
                <label class="inline-flex items-center gap-2">
                  <input v-model="chosen[r.id]" type="radio" :name="`quote-${r.id}`" :value="q.id" :disabled="!canQuote" />
                  <span class="font-medium">{{ q.supplier }}</span>
                </label>
                <span class="text-gray-600">
                  ₹ {{ money(q.unit_cost) }}
                  <template v-if="q.gst_mode === 'excl'">+ GST = ₹ {{ money(q.landed_unit_cost) }}</template>
                  <template v-else-if="q.gst_mode === 'incl'">incl. GST</template>
                  <template v-else>cash, no bill</template>
                </span>
                <span v-if="q.availability" class="text-xs text-gray-500">· {{ q.availability }}</span>
                <button v-if="canQuote" class="text-gray-400 hover:text-red-600" aria-label="Remove quote" @click="removeQuote(r, q.id)">
                  <TrashIcon class="h-4 w-4" />
                </button>
              </li>
            </ul>

            <!-- New quote -->
            <div v-if="canQuote" class="mt-2 grid grid-cols-12 gap-2 items-center">
              <input
                v-model="formFor(r).supplier"
                list="supplier-names"
                class="col-span-12 sm:col-span-4 rounded-lg border-gray-300 text-sm"
                placeholder="Supplier"
              />
              <input
                v-model="formFor(r).unit_cost"
                type="number"
                min="0"
                class="col-span-4 sm:col-span-2 rounded-lg border-gray-300 text-sm"
                placeholder="Price / unit"
              />
              <select v-model="formFor(r).gst_mode" class="col-span-8 sm:col-span-3 rounded-lg border-gray-300 text-sm">
                <option v-for="m in GST_MODES" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
              <input
                v-model="formFor(r).availability"
                class="col-span-8 sm:col-span-2 rounded-lg border-gray-300 text-sm"
                placeholder="Availability"
              />
              <Button size="sm" variant="secondary" class="col-span-4 sm:col-span-1" :disabled="busy" @click="addQuote(r)">Add</Button>
              <p v-if="landed(formFor(r)) !== null && formFor(r).gst_mode === 'excl'" class="col-span-12 text-xs text-gray-500">
                Cost with GST: ₹ {{ money(landed(formFor(r))) }}
              </p>
            </div>
          </li>
        </ul>
      </Card>
    </div>

    <!-- To procure -->
    <Card v-else :padding="false">
      <ul class="divide-y divide-gray-100">
        <li v-for="r in requests" :key="r.id" class="px-4 py-3 flex flex-wrap items-center gap-x-3 gap-y-1">
          <RouterLink :to="`/job-cards/${r.job_card_id}`" class="text-sm font-semibold text-primary-700 hover:underline">
            {{ r.job_number }}
          </RouterLink>
          <span class="text-xs text-gray-500">{{ r.vehicle }}</span>
          <p class="w-full sm:w-auto font-medium text-gray-900">
            {{ r.part_name }} <span v-if="r.part_number" class="text-xs text-gray-500">{{ r.part_number }}</span>
            <span class="text-xs text-gray-500"> × {{ r.quantity }} {{ r.unit }}</span>
          </p>
          <span v-if="selectedQuote(r)" class="text-sm text-gray-600">
            {{ selectedQuote(r)!.supplier }} · ₹ {{ money(selectedQuote(r)!.landed_unit_cost) }}
            <span v-if="selectedQuote(r)!.gst_mode === 'cash'">(cash)</span>
          </span>
          <span class="ml-auto flex items-center gap-2">
            <Badge v-if="r.status === 'ordered'" size="sm" variant="success">Ordered {{ ago(r.ordered_at) }}</Badge>
            <Button v-else-if="canQuote" size="sm" @click="markOrdered(r)">Mark ordered</Button>
          </span>
        </li>
      </ul>
      <p class="px-4 py-2 text-xs text-gray-400 border-t border-gray-100">Receiving parts (GRN) comes in the next phase.</p>
    </Card>
  </div>
</template>
