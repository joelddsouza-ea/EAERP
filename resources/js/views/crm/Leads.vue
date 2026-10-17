<script setup lang="ts">
/**
 * Leads: "Today" (new leads to call first, then follow-ups due — hot before cold), a
 * board by status, and the full list with filters. Quick add for calls and walk-ins;
 * import for ad exports.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { PhoneIcon, PlusIcon, ArrowUpTrayIcon } from '@heroicons/vue/24/outline'
import { crmApi, STATUS_LABELS, STATUS_COLORS, TEMP_COLORS, relTime, type LeadRow, type LeadOptions, type LeadStatus } from '@/api/crm.api'
import { useBranchStore } from '@/stores/branch.store'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const branchStore = useBranchStore()
const { can } = usePermissions()
const toast = useToast()
const canManage = computed(() => can('crm.leads.manage'))

type View = 'today' | 'board' | 'list'
const view = ref<View>((route.query.view as View) || 'today')
const everyone = ref(false)
const filters = ref({ q: '', status: 'open', source_id: '', temperature: '', kind: '', assigned_to: '' })
const rows = ref<LeadRow[]>([])
const meta = ref<{ current_page: number; last_page: number; total: number } | null>(null)
const page = ref(1)
const loading = ref(false)
const options = ref<LeadOptions | null>(null)

async function load() {
  router.replace({ query: { view: view.value } })
  loading.value = true
  try {
    const params: Record<string, any> = { view: view.value, branch_id: branchStore.currentBranchId || undefined }
    if (view.value === 'today') params.everyone = everyone.value ? 1 : undefined
    else Object.assign(params, Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== '')), { page: page.value })
    const res = (await crmApi.leads(params)).data
    rows.value = view.value === 'list' ? res.data : res.data.rows
    meta.value = view.value === 'list' ? res.meta : null
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}
watch([view, everyone, () => branchStore.currentBranchId], () => {
  page.value = 1
  load()
})
let timer: number | undefined
watch(filters, () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => {
    page.value = 1
    load()
  }, 350)
}, { deep: true })
watch(page, load)

onMounted(async () => {
  load()
  try {
    options.value = (await crmApi.options()).data.data
  } catch (err) {
    toast.apiError(err)
  }
})

const notCalled = computed(() => rows.value.filter((r) => r.status === 'new' && !r.first_contacted_at))
const followUps = computed(() => rows.value.filter((r) => !(r.status === 'new' && !r.first_contacted_at)))
const BOARD: LeadStatus[] = ['new', 'contacted', 'interested', 'booked', 'unreachable', 'won', 'lost']
const columns = computed(() => BOARD.map((s) => ({ status: s, rows: rows.value.filter((r) => r.status === s) })))

// ------------------------------------------------------------- quick add
const addOpen = ref(false)
const busy = ref(false)
const blank = () => ({ name: '', phone: '', car_text: '', registration_no: '', interest: 'scheduled', package_id: null as number | null, requirement: '', source: 'call', temperature: 'warm' })
const form = ref(blank())
async function add() {
  busy.value = true
  try {
    const res = await crmApi.addLead({ ...form.value, branch_id: branchStore.currentBranchId })
    toast.success(res.data.message ?? 'Lead added')
    addOpen.value = false
    router.push(`/crm/leads/${res.data.data.id}`)
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

// ---------------------------------------------------------------- import
const importOpen = ref(false)
const csv = ref('')
const importSource = ref('meta')
const parsed = computed(() => {
  const lines = csv.value.split(/\r?\n/).map((l) => l.trim()).filter(Boolean)
  if (lines.length < 2) return [] as Record<string, string>[]
  const split = (l: string) => l.split(/,|\t/).map((c) => c.replace(/^"|"$/g, '').trim())
  const head = split(lines[0]).map((h) => h.toLowerCase())
  const col = (names: string[]) => head.findIndex((h) => names.some((n) => h.includes(n)))
  const idx = {
    name: col(['name']),
    phone: col(['phone', 'mobile', 'number']),
    email: col(['email']),
    car_text: col(['car', 'vehicle', 'model']),
    requirement: col(['requirement', 'message', 'service', 'note']),
    campaign: col(['campaign']),
  }
  return lines.slice(1).map((l) => {
    const c = split(l)
    return Object.fromEntries(Object.entries(idx).filter(([, i]) => i >= 0).map(([k, i]) => [k, c[i] ?? ''])) as Record<string, string>
  }).filter((r) => r.phone)
})
async function runImport() {
  busy.value = true
  try {
    const res = await crmApi.importLeads(parsed.value, importSource.value, branchStore.currentBranchId)
    toast.success(res.data.message ?? 'Imported')
    importOpen.value = false
    csv.value = ''
    load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
async function onFile(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) csv.value = await file.text()
}

const due = (r: LeadRow) => (r.status === 'new' && !r.first_contacted_at ? r.first_contact_due_at : r.next_follow_up_at)
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Leads</h1>
        <p class="text-sm text-gray-500">Call new leads first — speed wins the booking.</p>
      </div>
      <div class="flex gap-2">
        <Button v-if="canManage" variant="ghost" size="sm" @click="importOpen = true"><ArrowUpTrayIcon class="h-4 w-4 mr-1" /> Import</Button>
        <Button @click="form = blank(); addOpen = true"><PlusIcon class="h-4 w-4 mr-1" /> Add lead</Button>
      </div>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-2">
      <div class="flex rounded-xl bg-gray-100 p-1 text-sm font-medium">
        <button v-for="v in (['today', 'board', 'list'] as const)" :key="v" type="button" class="rounded-lg px-3 py-1.5 capitalize" :class="view === v ? 'bg-white shadow-sm' : 'text-gray-600'" @click="view = v">
          {{ v === 'today' ? 'Today' : v === 'board' ? 'Board' : 'All leads' }}
        </button>
      </div>
      <label v-if="view === 'today' && canManage" class="flex items-center gap-2 text-sm text-gray-700"><input v-model="everyone" type="checkbox" class="h-4 w-4 rounded" /> Everyone's</label>
      <template v-if="view !== 'today'">
        <input v-model="filters.q" class="input w-48" placeholder="Name, phone, car" />
        <select v-if="view === 'list'" v-model="filters.status" class="input w-auto">
          <option value="open">Open</option>
          <option value="">All</option>
          <option v-for="(label, s) in STATUS_LABELS" :key="s" :value="s">{{ label }}</option>
        </select>
        <select v-model="filters.source_id" class="input w-auto">
          <option value="">All sources</option>
          <option v-for="s in options?.sources" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
        <select v-model="filters.temperature" class="input w-auto">
          <option value="">Any</option><option value="hot">Hot</option><option value="warm">Warm</option><option value="cold">Cold</option>
        </select>
        <select v-model="filters.kind" class="input w-auto">
          <option value="">All kinds</option>
          <option v-for="(label, k) in options?.kinds" :key="k" :value="k">{{ label }}</option>
        </select>
        <select v-if="canManage" v-model="filters.assigned_to" class="input w-auto">
          <option value="">Everyone</option>
          <option v-for="e in options?.executives" :key="e.id" :value="e.id">{{ e.name }}</option>
        </select>
      </template>
    </div>

    <div v-if="loading && !rows.length" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <!-- Today -->
    <template v-else-if="view === 'today'">
      <p v-if="!rows.length" class="rounded-xl bg-green-50 p-4 text-sm text-green-800">All caught up — no calls due today. 🎉</p>
      <section v-if="notCalled.length" class="mb-4">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-red-700">Call now — new leads ({{ notCalled.length }})</h2>
        <Card :padding="false">
          <ul class="divide-y divide-gray-100">
            <li v-for="r in notCalled" :key="r.id" class="flex items-center gap-3 px-4 py-3">
              <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="TEMP_COLORS[r.temperature]" />
              <RouterLink :to="`/crm/leads/${r.id}`" class="min-w-0 flex-1">
                <span class="block font-semibold text-gray-900 truncate">{{ r.name }}</span>
                <span class="block text-xs text-gray-500 truncate">{{ r.source }}<template v-if="r.campaign"> · {{ r.campaign }}</template> · {{ r.car }}<template v-if="r.assignee && everyone"> · {{ r.assignee }}</template></span>
              </RouterLink>
              <span class="text-xs" :class="r.overdue ? 'text-red-600 font-semibold' : 'text-gray-500'">{{ relTime(r.created_at) }}</span>
              <a :href="`tel:+${r.phone}`" class="rounded-full bg-green-600 p-2 text-white" aria-label="Call"><PhoneIcon class="h-4 w-4" /></a>
            </li>
          </ul>
        </Card>
      </section>
      <section v-if="followUps.length">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Follow-ups due ({{ followUps.length }})</h2>
        <Card :padding="false">
          <ul class="divide-y divide-gray-100">
            <li v-for="r in followUps" :key="r.id" class="flex items-center gap-3 px-4 py-3">
              <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="TEMP_COLORS[r.temperature]" />
              <RouterLink :to="`/crm/leads/${r.id}`" class="min-w-0 flex-1">
                <span class="block font-semibold text-gray-900 truncate">{{ r.name }} <span class="rounded px-1.5 py-0.5 text-[10px] font-medium" :class="STATUS_COLORS[r.status]">{{ r.status_label }}</span></span>
                <span class="block text-xs text-gray-500 truncate">{{ r.kind !== 'new_enquiry' ? r.kind_label + ' · ' : '' }}{{ r.car }}<template v-if="r.requirement"> · {{ r.requirement }}</template></span>
              </RouterLink>
              <span class="text-xs whitespace-nowrap" :class="r.overdue ? 'text-red-600 font-semibold' : 'text-gray-500'">{{ relTime(due(r)) }}</span>
              <a :href="`tel:+${r.phone}`" class="rounded-full bg-green-600 p-2 text-white" aria-label="Call"><PhoneIcon class="h-4 w-4" /></a>
            </li>
          </ul>
        </Card>
      </section>
    </template>

    <!-- Board -->
    <div v-else-if="view === 'board'" class="flex gap-3 overflow-x-auto pb-4">
      <div v-for="c in columns" :key="c.status" class="w-64 shrink-0">
        <p class="mb-2 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-gray-500">
          <span>{{ STATUS_LABELS[c.status] }}</span><span>{{ c.rows.length }}</span>
        </p>
        <div class="space-y-2">
          <RouterLink v-for="r in c.rows" :key="r.id" :to="`/crm/leads/${r.id}`" class="block rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-200 hover:ring-primary-300">
            <p class="flex items-center gap-2 font-medium text-gray-900"><span class="h-2 w-2 rounded-full" :class="TEMP_COLORS[r.temperature]" /> <span class="truncate">{{ r.name }}</span></p>
            <p class="mt-0.5 truncate text-xs text-gray-500">{{ r.car }}</p>
            <p class="mt-1 text-[11px] text-gray-400">{{ r.source }}<template v-if="r.assignee"> · {{ r.assignee }}</template></p>
            <p v-if="r.status === 'won' && r.won_revenue" class="mt-1 text-xs font-semibold text-green-700">₹ {{ r.won_revenue.toLocaleString('en-IN') }}</p>
          </RouterLink>
        </div>
      </div>
    </div>

    <!-- List -->
    <template v-else>
      <Card :padding="false">
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500">
              <tr><th class="px-3 py-2 text-left">Lead</th><th class="px-3 py-2 text-left hidden md:table-cell">Source</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left hidden md:table-cell">With</th><th class="px-3 py-2 text-left hidden sm:table-cell">Next</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="r in rows" :key="r.id" class="cursor-pointer hover:bg-gray-50" @click="router.push(`/crm/leads/${r.id}`)">
                <td class="px-3 py-2">
                  <span class="flex items-center gap-2 font-medium"><span class="h-2 w-2 rounded-full" :class="TEMP_COLORS[r.temperature]" />{{ r.name }}</span>
                  <span class="block text-xs text-gray-500">{{ r.phone }} · {{ r.car }}</span>
                </td>
                <td class="px-3 py-2 hidden md:table-cell text-gray-600">{{ r.source }}<span v-if="r.campaign" class="block text-xs text-gray-400">{{ r.campaign }}</span></td>
                <td class="px-3 py-2"><span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="STATUS_COLORS[r.status]">{{ r.status_label }}</span></td>
                <td class="px-3 py-2 hidden md:table-cell text-gray-600">{{ r.assignee ?? '—' }}</td>
                <td class="px-3 py-2 hidden sm:table-cell text-xs" :class="r.overdue ? 'text-red-600' : 'text-gray-500'">{{ relTime(due(r)) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="!rows.length" class="p-4 text-sm text-gray-500">No leads match.</p>
      </Card>
      <div v-if="meta && meta.last_page > 1" class="mt-3 flex items-center justify-center gap-3 text-sm">
        <Button size="sm" variant="secondary" :disabled="page <= 1" @click="page--">Previous</Button>
        <span>Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} leads</span>
        <Button size="sm" variant="secondary" :disabled="page >= meta.last_page" @click="page++">Next</Button>
      </div>
    </template>

    <!-- Quick add -->
    <Modal :open="addOpen" title="Add a lead" @close="addOpen = false">
      <div class="space-y-3 text-sm">
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Mobile *</label><input v-model="form.phone" type="tel" inputmode="tel" class="input" placeholder="98xxxxxxxx" /></div>
          <div><label class="label">Name</label><input v-model="form.name" class="input" /></div>
          <div><label class="label">Car</label><input v-model="form.car_text" class="input" placeholder="Swift diesel" /></div>
          <div><label class="label">Car number</label><input v-model="form.registration_no" class="input uppercase" /></div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">Came from</label>
            <select v-model="form.source" class="input"><option v-for="s in options?.sources" :key="s.slug" :value="s.slug">{{ s.name }}</option></select>
          </div>
          <div>
            <label class="label">Wants</label>
            <select v-model="form.interest" class="input"><option v-for="(label, k) in options?.interests" :key="k" :value="k">{{ label }}</option></select>
          </div>
        </div>
        <div v-if="form.interest === 'scheduled'">
          <label class="label">Plan</label>
          <select v-model="form.package_id" class="input"><option :value="null">Not decided</option><option v-for="p in options?.plans" :key="p.id" :value="p.id">{{ p.name }}</option></select>
        </div>
        <div><label class="label">What they said</label><textarea v-model="form.requirement" rows="2" class="input" /></div>
        <div>
          <label class="label">How keen</label>
          <div class="flex gap-2">
            <button v-for="t in (['hot', 'warm', 'cold'] as const)" :key="t" type="button" class="flex-1 rounded-lg px-3 py-2 ring-1 capitalize" :class="form.temperature === t ? 'bg-gray-900 text-white ring-gray-900' : 'ring-gray-200'" @click="form.temperature = t">{{ t }}</button>
          </div>
        </div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="addOpen = false">Cancel</Button>
        <Button :loading="busy" :disabled="form.phone.replace(/\D/g, '').length < 10" @click="add">Add lead</Button>
      </template>
    </Modal>

    <!-- Import -->
    <Modal :open="importOpen" title="Import leads (CSV / Excel export)" size="lg" @close="importOpen = false">
      <div class="space-y-3 text-sm">
        <p class="text-gray-600">Save the sheet as CSV (or copy the cells) with a header row: Name, Phone, Car, Requirement, Campaign. A number that already has an open lead is updated, not duplicated.</p>
        <input type="file" accept=".csv,text/csv,text/plain" @change="onFile" />
        <textarea v-model="csv" rows="6" class="input font-mono text-xs" placeholder="Name,Phone,Car&#10;Ravi,9820011111,Baleno" />
        <div>
          <label class="label">Source</label>
          <select v-model="importSource" class="input w-auto"><option v-for="s in options?.sources" :key="s.slug" :value="s.slug">{{ s.name }}</option></select>
        </div>
        <p class="font-medium">{{ parsed.length }} rows with a phone number</p>
      </div>
      <template #footer>
        <Button variant="secondary" @click="importOpen = false">Cancel</Button>
        <Button :loading="busy" :disabled="!parsed.length" @click="runImport">Import {{ parsed.length }}</Button>
      </template>
    </Modal>
  </div>
</template>
