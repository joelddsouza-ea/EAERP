<script setup lang="ts">
/**
 * Money between the workshop and its staff: customer money an employee collected
 * (and its hand-over), and salary advances.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { hrApi, PAY_METHODS, localDate, type Advance, type CustodyEntry, type CustodySummaryRow } from '@/api/hr.api'
import { inr } from '@/api/accounts.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const toast = useToast()

const tab = ref<'custody' | 'advances'>(route.query.tab === 'advances' || !can('hr.custody.manage') ? 'advances' : 'custody')
watch(tab, (t) => router.replace({ query: { tab: t } }))

const people = ref<{ id: number; name: string }[]>([])
const busy = ref(false)

// --------------------------------------------------------------- custody
const summary = ref<CustodySummaryRow[]>([])
const entries = ref<CustodyEntry[]>([])
const filterUser = ref<number | null>(null)
const totalHeld = computed(() => summary.value.reduce((s, r) => s + Math.max(0, r.balance), 0))

async function loadCustody() {
  try {
    const data = (await hrApi.custody(filterUser.value)).data.data
    summary.value = data.summary
    entries.value = data.entries
  } catch (err) {
    toast.apiError(err)
  }
}
watch(filterUser, loadCustody)

const handOpen = ref(false)
const hand = ref({ user_id: 0, amount: '' as number | string, method: 'cash' as 'cash' | 'bank', entry_date: localDate(), reference_number: '', note: '' })
function openHand(row?: CustodySummaryRow) {
  hand.value = { user_id: row?.user_id ?? summary.value[0]?.user_id ?? 0, amount: row?.balance ?? '', method: 'cash', entry_date: localDate(), reference_number: '', note: '' }
  handOpen.value = true
}
async function saveHand() {
  busy.value = true
  try {
    await hrApi.handOver({ ...hand.value, amount: Number(hand.value.amount), reference_number: hand.value.reference_number || null, note: hand.value.note || null })
    toast.success('Hand-over recorded')
    handOpen.value = false
    loadCustody()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
const KINDS: Record<string, string> = { collected: 'Collected', handed_over: 'Handed over', spent: 'Spent (expense)', salary_recovery: 'From salary' }

// -------------------------------------------------------------- advances
const advances = ref<Advance[]>([])
const advStatus = ref('open')
async function loadAdvances() {
  try {
    advances.value = (await hrApi.advances(advStatus.value)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}
watch(advStatus, loadAdvances)

const advOpen = ref(false)
const adv = ref({ user_id: 0, amount: '' as number | string, given_on: localDate(), method: 'cash', reference_number: '', recover_per_month: '' as number | string, note: '' })
function openAdv() {
  adv.value = { user_id: people.value[0]?.id ?? 0, amount: '', given_on: localDate(), method: 'cash', reference_number: '', recover_per_month: '', note: '' }
  advOpen.value = true
}
async function saveAdv() {
  busy.value = true
  try {
    await hrApi.giveAdvance({
      ...adv.value,
      amount: Number(adv.value.amount),
      recover_per_month: Number(adv.value.recover_per_month || 0),
      reference_number: adv.value.reference_number || null,
      note: adv.value.note || null,
    })
    toast.success('Advance recorded')
    advOpen.value = false
    loadAdvances()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

const editingAdv = ref<Advance | null>(null)
const editRecover = ref<number | string>('')
const cancelReason = ref('')
async function saveRecover() {
  if (!editingAdv.value) return
  busy.value = true
  try {
    await hrApi.updateAdvance(editingAdv.value.id, { recover_per_month: Number(editRecover.value || 0) })
    toast.success('Updated')
    editingAdv.value = null
    loadAdvances()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
async function cancelAdv() {
  if (!editingAdv.value) return
  busy.value = true
  try {
    await hrApi.cancelAdvance(editingAdv.value.id, cancelReason.value)
    toast.success('Advance cancelled')
    editingAdv.value = null
    loadAdvances()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    people.value = (await hrApi.staffDirectory()).data.data
  } catch {
    // names come with the lists too
  }
  if (can('hr.custody.manage')) loadCustody()
  if (can('hr.advances.manage')) loadAdvances()
})
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-3">Staff money</h1>

    <div class="mb-3 flex gap-1 rounded-xl bg-gray-100 p-1 text-sm font-medium">
      <button v-if="can('hr.custody.manage')" type="button" class="flex-1 rounded-lg px-3 py-1.5" :class="tab === 'custody' ? 'bg-white shadow-sm' : 'text-gray-600'" @click="tab = 'custody'">Customer money held</button>
      <button v-if="can('hr.advances.manage')" type="button" class="flex-1 rounded-lg px-3 py-1.5" :class="tab === 'advances' ? 'bg-white shadow-sm' : 'text-gray-600'" @click="tab = 'advances'">Salary advances</button>
    </div>

    <!-- Custody -->
    <template v-if="tab === 'custody'">
      <Card class="mb-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p class="text-xs text-gray-500">Held by staff now</p>
            <p class="text-2xl font-bold" :class="totalHeld > 0 ? 'text-red-700' : 'text-gray-900'">{{ inr(totalHeld) }}</p>
          </div>
          <Button @click="openHand()">Record hand-over</Button>
        </div>
        <p class="mt-2 text-xs text-gray-500">When a customer pays an employee (their UPI or cash in hand), choose "Collected by staff" on the payment. Whatever is not handed over or spent on the workshop comes off their salary.</p>
      </Card>
      <Card :padding="false" class="mb-3">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-xs text-gray-500">
            <tr><th class="px-3 py-2 text-left">Employee</th><th class="px-3 py-2 text-right">Collected</th><th class="px-3 py-2 text-right hidden sm:table-cell">Handed / spent</th><th class="px-3 py-2 text-right">Holds</th><th /></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in summary" :key="r.user_id">
              <td class="px-3 py-2"><button type="button" class="font-medium text-left" @click="filterUser = filterUser === r.user_id ? null : r.user_id">{{ r.name }}</button></td>
              <td class="px-3 py-2 text-right">{{ inr(r.collected) }}</td>
              <td class="px-3 py-2 text-right hidden sm:table-cell">{{ inr(r.handed_over + r.spent + r.recovered) }}</td>
              <td class="px-3 py-2 text-right font-semibold" :class="r.balance > 0 ? 'text-red-700' : ''">{{ inr(r.balance) }}</td>
              <td class="px-3 py-2 text-right"><Button v-if="r.balance > 0" size="sm" variant="secondary" @click="openHand(r)">Hand over</Button></td>
            </tr>
          </tbody>
        </table>
        <p v-if="!summary.length" class="p-4 text-sm text-gray-500">No customer money with staff.</p>
      </Card>
      <Card :padding="false">
        <template #header><h3 class="font-semibold">Movements<span v-if="filterUser"> — {{ summary.find((s) => s.user_id === filterUser)?.name }}</span></h3></template>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="e in entries" :key="e.id" class="px-4 py-2 flex gap-3">
            <span class="w-20 shrink-0 text-gray-500">{{ e.date }}</span>
            <span class="flex-1"><span class="font-medium">{{ e.employee }}</span> · {{ KINDS[e.kind] }}<span v-if="e.note" class="block text-xs text-gray-500">{{ e.note }}</span></span>
            <span class="font-medium" :class="e.amount > 0 ? 'text-red-700' : 'text-green-700'">{{ e.amount > 0 ? '+' : '−' }}{{ inr(Math.abs(e.amount)) }}</span>
          </li>
        </ul>
      </Card>
    </template>

    <!-- Advances -->
    <template v-if="tab === 'advances'">
      <div class="mb-3 flex items-center justify-between gap-2">
        <select v-model="advStatus" class="input w-auto"><option value="open">Outstanding</option><option value="closed">Recovered</option><option value="all">All</option></select>
        <Button @click="openAdv">Give an advance</Button>
      </div>
      <Card :padding="false">
        <p v-if="!advances.length" class="p-4 text-sm text-gray-500">None.</p>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="a in advances" :key="a.id">
            <button type="button" class="w-full px-4 py-3 text-left flex items-center gap-3 hover:bg-gray-50" @click="editingAdv = a; editRecover = a.recover_per_month; cancelReason = ''">
              <span class="flex-1">
                <span class="block font-semibold">{{ a.employee }} <Badge v-if="a.status !== 'open'" size="sm">{{ a.status }}</Badge></span>
                <span class="block text-xs text-gray-500">{{ a.given_on }} · {{ inr(a.amount) }} by {{ PAY_METHODS[a.method] ?? a.method }} · {{ a.recover_per_month ? inr(a.recover_per_month) + '/month' : 'whole balance next payroll' }}</span>
              </span>
              <span class="text-right"><span class="block font-semibold">{{ inr(a.balance) }}</span><span class="block text-xs text-gray-500">left</span></span>
            </button>
          </li>
        </ul>
      </Card>
    </template>

    <Modal :open="handOpen" title="Customer money handed over" @close="handOpen = false">
      <div class="space-y-3">
        <div>
          <label class="label">Employee</label>
          <select v-model.number="hand.user_id" class="input">
            <option v-for="r in summary.filter((x) => x.balance > 0)" :key="r.user_id" :value="r.user_id">{{ r.name }} (holds {{ inr(r.balance) }})</option>
          </select>
        </div>
        <div><label class="label">Amount ₹</label><input v-model="hand.amount" type="number" min="1" class="input" /></div>
        <div>
          <label class="label">Into</label>
          <select v-model="hand.method" class="input"><option value="cash">Cash box</option><option value="bank">Bank (transfer / UPI to the workshop)</option></select>
        </div>
        <div><label class="label">Date</label><input v-model="hand.entry_date" type="date" class="input" :max="localDate()" /></div>
        <div><label class="label">Reference / note</label><input v-model="hand.reference_number" class="input" placeholder="UTR (bank)" /></div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="handOpen = false">Cancel</Button>
        <Button :loading="busy" :disabled="!hand.user_id || !(Number(hand.amount) > 0)" @click="saveHand">Save</Button>
      </template>
    </Modal>

    <Modal :open="advOpen" title="Salary advance" @close="advOpen = false">
      <div class="space-y-3">
        <div>
          <label class="label">Employee</label>
          <select v-model.number="adv.user_id" class="input"><option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option></select>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Amount ₹</label><input v-model="adv.amount" type="number" min="1" class="input" /></div>
          <div><label class="label">Given on</label><input v-model="adv.given_on" type="date" class="input" :max="localDate()" /></div>
        </div>
        <div>
          <label class="label">Paid by</label>
          <select v-model="adv.method" class="input"><option v-for="(label, k) in PAY_METHODS" :key="k" :value="k">{{ label }}</option></select>
        </div>
        <div><label class="label">Recover per month ₹</label><input v-model="adv.recover_per_month" type="number" min="0" class="input" placeholder="Blank = all from the next salary" /></div>
        <div><label class="label">Note</label><input v-model="adv.note" class="input" /></div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="advOpen = false">Cancel</Button>
        <Button :loading="busy" :disabled="!adv.user_id || !(Number(adv.amount) > 0)" @click="saveAdv">Save</Button>
      </template>
    </Modal>

    <Modal :open="!!editingAdv" :title="editingAdv ? `${editingAdv.employee} — advance of ${inr(editingAdv.amount)}` : ''" size="sm" @close="editingAdv = null">
      <div v-if="editingAdv" class="space-y-3 text-sm">
        <p>Recovered {{ inr(editingAdv.recovered) }} · {{ inr(editingAdv.balance) }} left</p>
        <template v-if="editingAdv.status === 'open'">
          <div><label class="label">Recover per month ₹</label><input v-model="editRecover" type="number" min="0" class="input" /></div>
          <Button size="sm" :loading="busy" @click="saveRecover">Save</Button>
          <div v-if="!editingAdv.recovered" class="border-t pt-3">
            <label class="label">Cancel (given by mistake)</label>
            <input v-model="cancelReason" class="input" placeholder="Reason" />
            <Button class="mt-2" size="sm" variant="danger" :loading="busy" :disabled="!cancelReason.trim()" @click="cancelAdv">Cancel advance</Button>
          </div>
        </template>
      </div>
    </Modal>
  </div>
</template>
