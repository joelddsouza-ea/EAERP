<script setup lang="ts">
/**
 * Leave requests for the manager to answer (and the record of earlier ones).
 */
import { ref, onMounted, watch } from 'vue'
import { hrApi, type LeaveRequestRow, type LeaveBalance } from '@/api/hr.api'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'

const toast = useToast()
const status = ref<'pending' | 'approved' | 'rejected' | 'all'>('pending')
const rows = ref<LeaveRequestRow[]>([])
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    rows.value = (await hrApi.leaveRequests(status.value)).data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}
watch(status, load)
onMounted(load)

const answering = ref<LeaveRequestRow | null>(null)
const balances = ref<LeaveBalance[]>([])
const note = ref('')
const busy = ref(false)

async function open(r: LeaveRequestRow) {
  answering.value = r
  note.value = ''
  balances.value = []
  try {
    balances.value = (await hrApi.leaveBalances(r.user_id)).data.data
  } catch {
    // balances are a help only
  }
}

async function decide(approve: boolean) {
  if (!answering.value) return
  busy.value = true
  try {
    await hrApi.decideLeave(answering.value.id, approve, note.value || null)
    toast.success(approve ? 'Leave approved' : 'Leave not approved')
    answering.value = null
    load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function cancelApproved() {
  if (!answering.value) return
  busy.value = true
  try {
    await hrApi.cancelLeave(answering.value.id)
    toast.success('Leave cancelled')
    answering.value = null
    load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

const VARIANT: Record<string, 'warning' | 'success' | 'danger' | 'default'> = { pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'default' }
</script>

<template>
  <div class="max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-3">Leave requests</h1>

    <div class="mb-3 flex gap-1 rounded-xl bg-gray-100 p-1 text-sm font-medium">
      <button
        v-for="s in (['pending', 'approved', 'rejected', 'all'] as const)"
        :key="s"
        type="button"
        class="flex-1 rounded-lg px-3 py-1.5 capitalize"
        :class="status === s ? 'bg-white shadow-sm' : 'text-gray-600'"
        @click="status = s"
      >{{ s === 'pending' ? 'To answer' : s }}</button>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><Spinner size="lg" /></div>
    <Card v-else :padding="false">
      <p v-if="!rows.length" class="p-4 text-sm text-gray-500">Nothing here.</p>
      <ul class="divide-y divide-gray-100">
        <li v-for="r in rows" :key="r.id">
          <button type="button" class="w-full px-4 py-3 text-left hover:bg-gray-50" @click="open(r)">
            <div class="flex items-center gap-2">
              <span class="font-semibold text-gray-900">{{ r.employee }}</span>
              <Badge size="sm" :variant="VARIANT[r.status]">{{ r.status }}</Badge>
              <span class="ml-auto text-sm text-gray-600">{{ r.days }} day{{ r.days === 1 ? '' : 's' }}</span>
            </div>
            <p class="text-sm text-gray-600">
              {{ r.leave_type }} · {{ r.from_date }}<template v-if="r.to_date !== r.from_date"> → {{ r.to_date }}</template><template v-if="r.half_day"> (half day)</template>
            </p>
            <p v-if="r.reason" class="text-xs text-gray-500">{{ r.reason }}</p>
            <p v-if="r.unpaid_days" class="text-xs text-amber-700">{{ r.unpaid_days }} day(s) without pay (beyond the balance)</p>
          </button>
        </li>
      </ul>
    </Card>

    <Modal :open="!!answering" :title="answering ? `${answering.employee} — ${answering.leave_type}` : ''" @close="answering = null">
      <div v-if="answering" class="space-y-3 text-sm">
        <p>{{ answering.from_date }}<template v-if="answering.to_date !== answering.from_date"> → {{ answering.to_date }}</template> · {{ answering.days }} day(s)<template v-if="answering.unpaid_days">, {{ answering.unpaid_days }} without pay</template></p>
        <p v-if="answering.reason" class="text-gray-600">“{{ answering.reason }}”</p>
        <div v-if="balances.length" class="grid grid-cols-2 gap-2">
          <div v-for="b in balances" :key="b.leave_type_id" class="rounded-lg bg-gray-50 p-2">
            <p class="text-xs text-gray-500">{{ b.name }}</p>
            <p class="font-medium">{{ b.is_paid ? `${b.available} left` : `${b.taken} taken` }}</p>
          </div>
        </div>
        <template v-if="answering.status === 'pending'">
          <input v-model="note" class="input" placeholder="Note to the employee (optional)" />
        </template>
        <p v-else class="text-gray-500">{{ answering.status }} by {{ answering.decided_by }}<template v-if="answering.decision_note">: {{ answering.decision_note }}</template></p>
      </div>
      <template #footer>
        <template v-if="answering?.status === 'pending'">
          <Button variant="secondary" :loading="busy" @click="decide(false)">Reject</Button>
          <Button :loading="busy" @click="decide(true)">Approve</Button>
        </template>
        <template v-else-if="answering?.status === 'approved'">
          <Button variant="danger" :loading="busy" @click="cancelApproved">Cancel this leave</Button>
          <Button variant="secondary" @click="answering = null">Close</Button>
        </template>
        <Button v-else variant="secondary" @click="answering = null">Close</Button>
      </template>
    </Modal>
  </div>
</template>
