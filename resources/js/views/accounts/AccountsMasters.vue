<script setup lang="ts">
/**
 * Masters › Accounts: chart of accounts (system heads keep their type), bank accounts,
 * the date the books start (earlier bills are covered by opening balances), month locks,
 * and "bring the books up to date" for history.
 */
import { ref, computed, onMounted } from 'vue'
import { accountsApi, type AccountsMasters, type Account, type BankAccountRow } from '@/api/accounts.api'
import { lastMonth } from '@/api/hr.api'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const { can } = usePermissions()
const toast = useToast()
const canEdit = computed(() => can('accounts.settings.manage'))

const data = ref<AccountsMasters | null>(null)
const busy = ref(false)
const q = ref('')
const TYPE_LABELS: Record<string, string> = { asset: 'Assets', liability: 'Liabilities', equity: 'Capital', income: 'Income', expense: 'Expenses' }

async function load() {
  try {
    data.value = (await accountsApi.masters()).data.data
    startDate.value = data.value.books_start_date ?? ''
  } catch (err) {
    toast.apiError(err)
  }
}
onMounted(load)

const byType = computed(() => {
  const t = q.value.trim().toLowerCase()
  const list = (data.value?.accounts ?? []).filter((a) => !t || a.name.toLowerCase().includes(t) || a.code.includes(t))
  return Object.keys(TYPE_LABELS).map((type) => ({ type, items: list.filter((a) => a.type === type) })).filter((g) => g.items.length)
})

// ---------------------------------------------------------------- account
const account = ref<Partial<Account> | null>(null)
async function saveAccount() {
  busy.value = true
  try {
    await accountsApi.saveAccount(account.value!)
    toast.success('Account saved')
    account.value = null
    load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

// ------------------------------------------------------------------- bank
const bank = ref<Partial<BankAccountRow> | null>(null)
async function saveBank() {
  busy.value = true
  try {
    await accountsApi.saveBank(bank.value!)
    toast.success('Bank account saved')
    bank.value = null
    load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

// ------------------------------------------------------- start & locks
const startDate = ref('')
async function saveStart() {
  busy.value = true
  try {
    data.value = (await accountsApi.saveSettings({ books_start_date: startDate.value || null })).data.data
    toast.success('Saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
const resyncOpen = ref(false)
async function resync() {
  resyncOpen.value = false
  busy.value = true
  try {
    await accountsApi.resync()
    toast.success('Books brought up to date')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

const lockMonth = ref(lastMonth())
async function lock(period: string, locked: boolean) {
  busy.value = true
  try {
    data.value = (await accountsApi.lockPeriod(period, locked)).data.data
    toast.success(locked ? 'Month locked' : 'Month unlocked')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">Accounts masters</h1>
      <p class="text-sm text-gray-500">Chart of accounts, bank accounts, when the books start, and month locks.</p>
    </div>

    <div v-if="!data" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <template v-else>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <Card>
          <template #header><h3 class="font-semibold">Books start</h3></template>
          <p class="text-sm text-gray-600 mb-2">Bills, receipts and GRNs before this date are not posted; enter the balances on that day as an opening-balance voucher.</p>
          <div class="flex gap-2">
            <input v-model="startDate" type="date" class="input" :disabled="!canEdit" />
            <Button v-if="canEdit" :loading="busy" @click="saveStart">Save</Button>
          </div>
          <Button v-if="canEdit" class="mt-3" variant="secondary" size="sm" :loading="busy" @click="resyncOpen = true">Bring the books up to date</Button>
        </Card>

        <Card>
          <template #header><h3 class="font-semibold">Month locks</h3></template>
          <p class="text-sm text-gray-600 mb-2">A locked month can't change: a late correction is booked on today's date instead.</p>
          <div v-if="can('accounts.period.lock')" class="flex gap-2 mb-2">
            <input v-model="lockMonth" type="month" class="input" />
            <Button :loading="busy" @click="lock(lockMonth, true)">Lock</Button>
          </div>
          <ul class="divide-y divide-gray-100 text-sm">
            <li v-for="p in data.periods" :key="p.period" class="py-1.5 flex items-center justify-between">
              <span>{{ p.period }} <span class="text-xs text-gray-500">by {{ p.locked_by }}</span></span>
              <button v-if="can('accounts.period.lock')" type="button" class="text-xs text-primary-700" @click="lock(p.period, false)">Unlock</button>
            </li>
          </ul>
          <p v-if="!data.periods.length" class="text-sm text-gray-500">No month locked.</p>
        </Card>
      </div>

      <Card :padding="false" class="mb-4">
        <template #header>
          <div class="flex items-center justify-between"><h3 class="font-semibold">Bank accounts</h3><Button v-if="canEdit" size="sm" @click="bank = { name: '', is_default: false, is_active: true }">Add bank</Button></div>
        </template>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="b in data.banks" :key="b.id">
            <button type="button" class="w-full px-4 py-2 text-left flex items-center gap-2 hover:bg-gray-50" :disabled="!canEdit" @click="bank = { ...b }">
              <span class="flex-1 font-medium">{{ b.name }} <span class="font-normal text-gray-500">{{ b.account_number }}</span></span>
              <Badge v-if="b.is_default" size="sm" variant="success">Default</Badge>
              <Badge v-if="!b.is_active" size="sm">Inactive</Badge>
            </button>
          </li>
        </ul>
        <p class="px-4 py-2 text-xs text-gray-500">Card, UPI, bank transfers and cheques received land in the default bank.</p>
      </Card>

      <Card :padding="false">
        <template #header>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold">Chart of accounts</h3>
            <div class="flex gap-2">
              <input v-model="q" class="input w-40" placeholder="Search" />
              <Button v-if="canEdit" size="sm" @click="account = { code: '', name: '', type: 'expense', group: 'Overheads', is_expense_head: true, is_active: true }">Add head</Button>
            </div>
          </div>
        </template>
        <div v-for="g in byType" :key="g.type">
          <p class="bg-gray-50 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ TYPE_LABELS[g.type] }}</p>
          <ul class="divide-y divide-gray-100 text-sm">
            <li v-for="a in g.items" :key="a.id">
              <button type="button" class="w-full px-4 py-2 text-left flex items-center gap-2 hover:bg-gray-50" :class="a.is_active ? '' : 'opacity-50'" :disabled="!canEdit" @click="account = { ...a }">
                <span class="w-12 text-gray-400">{{ a.code }}</span>
                <span class="flex-1">{{ a.name }} <span class="text-xs text-gray-400">{{ a.group }}</span></span>
                <Badge v-if="a.is_system" size="sm">System</Badge>
                <Badge v-if="a.is_expense_head" size="sm" variant="info">On expense form</Badge>
              </button>
            </li>
          </ul>
        </div>
      </Card>
    </template>

    <Modal :open="!!account" :title="account?.id ? 'Edit account' : 'New account head'" size="sm" @close="account = null">
      <div v-if="account && data" class="space-y-3 text-sm">
        <div class="grid grid-cols-3 gap-3">
          <div><label class="label">Code</label><input v-model="account.code" class="input" /></div>
          <div class="col-span-2"><label class="label">Name</label><input v-model="account.name" class="input" /></div>
        </div>
        <div>
          <label class="label">Type</label>
          <select v-model="account.type" class="input" :disabled="account.is_system">
            <option v-for="(label, t) in TYPE_LABELS" :key="t" :value="t">{{ label }}</option>
          </select>
        </div>
        <div>
          <label class="label">Group</label>
          <input v-model="account.group" class="input" list="acc-groups" />
          <datalist id="acc-groups"><option v-for="g in data.groups" :key="g" :value="g" /></datalist>
        </div>
        <label class="flex items-center gap-2"><input v-model="account.is_expense_head" type="checkbox" class="h-4 w-4 rounded" /> Show on the expense form</label>
        <label v-if="!account.is_system" class="flex items-center gap-2"><input v-model="account.is_active" type="checkbox" class="h-4 w-4 rounded" /> Active</label>
      </div>
      <template #footer>
        <Button variant="secondary" @click="account = null">Cancel</Button>
        <Button :loading="busy" :disabled="!account?.code || !account?.name" @click="saveAccount">Save</Button>
      </template>
    </Modal>

    <Modal :open="!!bank" :title="bank?.id ? 'Edit bank account' : 'New bank account'" size="sm" @close="bank = null">
      <div v-if="bank" class="space-y-3 text-sm">
        <div><label class="label">Name</label><input v-model="bank.name" class="input" placeholder="HDFC current" /></div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">Account no.</label><input v-model="bank.account_number" class="input" /></div>
          <div><label class="label">IFSC</label><input v-model="bank.ifsc" class="input uppercase" /></div>
        </div>
        <label class="flex items-center gap-2"><input v-model="bank.is_default" type="checkbox" class="h-4 w-4 rounded" /> Default for card / UPI / transfers</label>
        <label class="flex items-center gap-2"><input v-model="bank.is_active" type="checkbox" class="h-4 w-4 rounded" /> Active</label>
      </div>
      <template #footer>
        <Button variant="secondary" @click="bank = null">Cancel</Button>
        <Button :loading="busy" :disabled="!bank?.name" @click="saveBank">Save</Button>
      </template>
    </Modal>

    <Modal :open="resyncOpen" title="Bring the books up to date?" size="sm" @close="resyncOpen = false">
      <p class="text-sm text-gray-600">Every bill, receipt, GRN, expense and payslip from the books start date is checked and posted if it is missing. Safe to run again.</p>
      <template #footer>
        <Button variant="secondary" @click="resyncOpen = false">Cancel</Button>
        <Button @click="resync">Run</Button>
      </template>
    </Modal>
  </div>
</template>
