<script setup lang="ts">
/**
 * One lead, mobile-first: call / WhatsApp / send the plan quote / book, log what happened
 * on the call (the next follow-up is set from it), its timeline, bookings and messages.
 * With Interakt on, the lead's WhatsApp chat is here too (reply, or a template after 24 h).
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { PhoneIcon, ChatBubbleLeftRightIcon, CalendarDaysIcon, DocumentTextIcon } from '@heroicons/vue/24/outline'
import {
  crmApi, STATUS_COLORS, TEMP_COLORS, MODE_LABELS, relTime, fmtDateTime,
  type LeadDetail, type LeadOptions, type QueueMessage, type WaThread,
} from '@/api/crm.api'
import WhatsAppChat from '@/components/crm/WhatsAppChat.vue'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'
import BookingDialog from '@/components/crm/BookingDialog.vue'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const toast = useToast()

const lead = ref<LeadDetail | null>(null)
const options = ref<LeadOptions | null>(null)
const messages = ref<QueueMessage[]>([])
const busy = ref(false)
const id = computed(() => Number(route.params.id))

async function load() {
  try {
    lead.value = (await crmApi.lead(id.value)).data.data
    messages.value = (await crmApi.leadMessages(id.value)).data.data
    loadChat()
  } catch (err) {
    toast.apiError(err)
    router.push('/crm/leads')
  }
}

// ---- WhatsApp chat (Interakt): null until the number has a chat; apiOn false = share links only
const chat = ref<WaThread | null>(null)
const chatApiOn = ref(false)
async function loadChat() {
  if (!can('crm.whatsapp.inbox')) return
  try {
    const data = (await crmApi.whatsappForLead(id.value)).data.data
    chatApiOn.value = !!data?.api_on
    chat.value = data && data.conversation ? (data as WaThread) : null
  } catch {
    chat.value = null
  }
}
async function startChat() {
  try {
    chat.value = (await crmApi.whatsappStartForLead(id.value)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}
async function chatSent() {
  await loadChat()
  lead.value = (await crmApi.lead(id.value)).data.data
}
onMounted(async () => {
  load()
  options.value = (await crmApi.options()).data.data
})

async function act(fn: () => Promise<{ data: { data: any; message?: string } }>, pick?: (d: any) => LeadDetail) {
  busy.value = true
  try {
    const res = await fn()
    lead.value = pick ? pick(res.data.data) : res.data.data
    if (res.data.message) toast.success(res.data.message)
    messages.value = (await crmApi.leadMessages(id.value)).data.data
    return true
  } catch (err) {
    toast.apiError(err)
    return false
  } finally {
    busy.value = false
  }
}

// ------------------------------------------------------------------ call
const callOpen = ref(false)
const call = ref({ outcome: 'connected', note: '', call_back_at: '', lost_reason_id: null as number | null })
function startCall() {
  window.location.href = lead.value!.call_url
  call.value = { outcome: 'connected', note: '', call_back_at: '', lost_reason_id: null }
  setTimeout(() => (callOpen.value = true), 600)
}
async function saveCall() {
  const ok = await act(() => crmApi.call(id.value, { ...call.value, call_back_at: call.value.call_back_at || null, note: call.value.note || null }))
  if (ok) {
    callOpen.value = false
    if (call.value.outcome === 'booked') bookOpen.value = true
  }
}

// ----------------------------------------------------------------- quote
const quoteOpen = ref(false)
const quote = ref({ package_id: null as number | null, category_id: null as number | null })
const lastQuote = ref<QueueMessage | null>(null)
function openQuote() {
  quote.value = { package_id: lead.value?.package_id ?? options.value?.plans[0]?.id ?? null, category_id: lead.value?.category_id ?? null }
  lastQuote.value = null
  quoteOpen.value = true
}
async function sendQuote() {
  busy.value = true
  try {
    const res = await crmApi.quote(id.value, quote.value.package_id!, quote.value.category_id)
    lead.value = res.data.data.lead
    lastQuote.value = res.data.data.message
    messages.value = (await crmApi.leadMessages(id.value)).data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
async function shareAndMark(m: QueueMessage) {
  if (m.share_url) window.open(m.share_url, '_blank')
  try {
    await crmApi.markSent(m.id)
    messages.value = (await crmApi.leadMessages(id.value)).data.data
    lead.value = (await crmApi.lead(id.value)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}

// ------------------------------------------------------- note / lost / misc
const noteOpen = ref(false)
const note = ref({ body: '', next: '' })
async function saveNote() {
  if (await act(() => crmApi.note(id.value, note.value.body, note.value.next || null))) noteOpen.value = false
}
const lostOpen = ref(false)
const lost = ref({ reason: null as number | null, note: '' })
async function saveLost() {
  if (await act(() => crmApi.lost(id.value, lost.value.reason!, lost.value.note || null))) lostOpen.value = false
}
const editOpen = ref(false)
const edit = ref<Record<string, any>>({})
function openEdit() {
  const l = lead.value!
  edit.value = { name: l.name, email: l.email, city: l.city, car_text: l.car_text, registration_no: l.registration_no, category_id: l.category_id, fuel_type: l.fuel_type, interest: l.interest, package_id: l.package_id, requirement: l.requirement, temperature: l.temperature }
  editOpen.value = true
}
async function saveEdit() {
  if (await act(() => crmApi.updateLead(id.value, edit.value))) editOpen.value = false
}
const assignTo = ref<number | null>(null)
async function reassign() {
  if (assignTo.value) await act(() => crmApi.assign(id.value, assignTo.value!))
}
const nextAt = ref('')
async function setFollowUp() {
  if (nextAt.value) await act(() => crmApi.followUp(id.value, nextAt.value))
}

const bookOpen = ref(false)
async function onBooked() {
  bookOpen.value = false
  load()
}

const ICONS: Record<string, string> = {
  created: '✨', enquired_again: '🔁', call: '📞', whatsapp: '💬', note: '📝', status: '➡️', quote: '💰', booking: '📅', assigned: '👤', link_opened: '👀',
}
const isClosed = computed(() => lead.value && ['won', 'lost'].includes(lead.value.status))
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div v-if="!lead" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <template v-else>
      <RouterLink to="/crm/leads" class="text-sm text-primary-700">‹ Leads</RouterLink>

      <!-- Header -->
      <div class="mt-1 mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2 text-2xl font-bold text-gray-900">
            <span class="h-3 w-3 rounded-full" :class="TEMP_COLORS[lead.temperature]" :title="lead.temperature" />
            {{ lead.name }}
          </h1>
          <p class="text-sm text-gray-600">
            +{{ lead.phone }} · {{ lead.car }}
            <span class="ml-1 rounded px-1.5 py-0.5 text-xs font-medium" :class="STATUS_COLORS[lead.status]">{{ lead.status_label }}</span>
            <Badge v-if="lead.kind !== 'new_enquiry'" size="sm" variant="info" class="ml-1">{{ lead.kind_label }}</Badge>
            <Badge v-if="lead.is_customer" size="sm" variant="success" class="ml-1">Customer</Badge>
            <Badge v-if="lead.opted_out" size="sm" variant="danger" class="ml-1">No messages</Badge>
          </p>
          <p class="text-xs text-gray-500">
            {{ lead.source }}<template v-if="lead.campaign"> · {{ lead.campaign }}</template><template v-if="lead.referrer"> · referred by {{ lead.referrer }}</template>
            · with {{ lead.assignee ?? 'nobody' }} · came {{ relTime(lead.created_at) }}
          </p>
        </div>
        <Button size="sm" variant="ghost" @click="openEdit">Edit details</Button>
      </div>

      <!-- Actions -->
      <div class="mb-4 grid grid-cols-4 gap-2">
        <button type="button" class="flex flex-col items-center gap-1 rounded-xl bg-green-600 py-3 text-xs font-semibold text-white" @click="startCall"><PhoneIcon class="h-6 w-6" /> Call</button>
        <a :href="lead.whatsapp_url" target="_blank" rel="noopener" class="flex flex-col items-center gap-1 rounded-xl bg-emerald-500 py-3 text-xs font-semibold text-white"><ChatBubbleLeftRightIcon class="h-6 w-6" /> WhatsApp</a>
        <button type="button" class="flex flex-col items-center gap-1 rounded-xl bg-white py-3 text-xs font-semibold text-gray-800 ring-1 ring-gray-200" :disabled="isClosed!" @click="openQuote"><DocumentTextIcon class="h-6 w-6" /> Send prices</button>
        <button type="button" class="flex flex-col items-center gap-1 rounded-xl bg-primary-600 py-3 text-xs font-semibold text-white" :disabled="lead.status === 'won'" @click="bookOpen = true"><CalendarDaysIcon class="h-6 w-6" /> Book</button>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="md:col-span-2 space-y-4">
          <Card>
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <span class="text-gray-600">Next follow-up:</span>
              <b :class="lead.overdue ? 'text-red-600' : ''">{{ lead.next_follow_up_at ? fmtDateTime(lead.next_follow_up_at) + ' (' + relTime(lead.next_follow_up_at) + ')' : '—' }}</b>
              <input v-model="nextAt" type="datetime-local" class="input w-auto ml-auto" />
              <Button size="sm" variant="secondary" :disabled="!nextAt" @click="setFollowUp">Set</Button>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
              <Button size="sm" variant="secondary" @click="call = { outcome: 'connected', note: '', call_back_at: '', lost_reason_id: null }; callOpen = true">Log a call</Button>
              <Button size="sm" variant="secondary" @click="note = { body: '', next: '' }; noteOpen = true">Add note</Button>
              <Button v-if="!isClosed && lead.status !== 'interested'" size="sm" variant="secondary" @click="act(() => crmApi.interested(id))">Mark interested</Button>
              <Button v-if="!isClosed" size="sm" variant="ghost" @click="lost = { reason: null, note: '' }; lostOpen = true">Close as lost</Button>
              <Button v-if="isClosed && lead.status === 'lost'" size="sm" variant="secondary" @click="act(() => crmApi.reopen(id))">Reopen</Button>
              <Button v-if="!lead.opted_out" size="sm" variant="ghost" @click="act(() => crmApi.optOut(id))">Stop messages</Button>
            </div>
            <p v-if="lead.requirement" class="mt-3 rounded-lg bg-amber-50 p-2 text-sm text-amber-900">“{{ lead.requirement }}”</p>
            <p v-if="lead.status === 'lost'" class="mt-2 text-sm text-gray-600">Lost: {{ lead.lost_reason }}<template v-if="lead.lost_note"> — {{ lead.lost_note }}</template></p>
            <p v-if="lead.status === 'won'" class="mt-2 text-sm font-semibold text-green-700">Won — ₹ {{ lead.won_revenue.toLocaleString('en-IN') }}</p>
          </Card>

          <!-- WhatsApp chat (Interakt) -->
          <Card v-if="chat || chatApiOn" :padding="false">
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="font-semibold">WhatsApp chat</h3>
                <RouterLink v-if="chat" :to="`/crm/whatsapp?c=${chat.conversation.id}`" class="text-sm text-primary-700">Open in inbox ›</RouterLink>
              </div>
            </template>
            <WhatsAppChat v-if="chat" :thread="chat" compact @sent="chatSent" />
            <div v-else class="p-4 text-sm text-gray-600">
              No chat yet. <button type="button" class="font-medium text-primary-700 underline" @click="startChat">Start one</button> (a template first; their reply opens the chat).
            </div>
          </Card>

          <!-- Messages for this lead -->
          <Card v-if="messages.length" :padding="false">
            <template #header><h3 class="font-semibold">Messages (quotes, journeys, bookings)</h3></template>
            <ul class="divide-y divide-gray-100 text-sm">
              <li v-for="m in messages" :key="m.id" class="px-4 py-3">
                <div class="flex items-center gap-2">
                  <Badge size="sm" :variant="m.status === 'sent' ? 'success' : m.status === 'prepared' ? 'warning' : 'default'">{{ m.status === 'prepared' ? 'To send' : m.status }}</Badge>
                  <span class="text-xs text-gray-500">{{ fmtDateTime(m.sent_at ?? m.scheduled_at) }}</span>
                  <Button v-if="m.status === 'prepared' && m.share_url && can('crm.messages.send')" size="sm" class="ml-auto" @click="shareAndMark(m)">Send on WhatsApp</Button>
                </div>
                <p class="mt-1 whitespace-pre-line text-gray-700">{{ m.body }}</p>
              </li>
            </ul>
          </Card>

          <!-- Timeline -->
          <Card :padding="false">
            <template #header><h3 class="font-semibold">Timeline</h3></template>
            <ul class="divide-y divide-gray-100 text-sm">
              <li v-for="a in lead.activities" :key="a.id" class="px-4 py-2 flex gap-3">
                <span class="w-5 text-center">{{ ICONS[a.type] ?? '•' }}</span>
                <span class="flex-1">
                  <span v-if="a.outcome_label" class="font-medium">{{ a.outcome_label }}</span>
                  <span v-if="a.body" :class="a.outcome_label ? 'block text-gray-600' : ''">{{ a.body }}</span>
                </span>
                <span class="text-xs text-gray-400 whitespace-nowrap">{{ fmtDateTime(a.at) }}<span v-if="a.by" class="block">{{ a.by }}</span></span>
              </li>
            </ul>
          </Card>
        </div>

        <div class="space-y-4">
          <Card>
            <template #header><h3 class="font-semibold">Bookings</h3></template>
            <p v-if="!lead.bookings.length" class="text-sm text-gray-500">None yet.</p>
            <ul class="space-y-2 text-sm">
              <li v-for="b in lead.bookings" :key="b.id" class="rounded-lg bg-gray-50 p-2">
                <p class="font-medium">{{ b.number }} · {{ b.date }} {{ b.slot_from }}</p>
                <p class="text-xs text-gray-500">{{ b.package ?? b.service_type }} · {{ MODE_LABELS[b.service_mode] }} · {{ b.status_label }}</p>
                <RouterLink v-if="b.job_card_id" :to="`/job-cards/${b.job_card_id}`" class="text-xs text-primary-700">Job {{ b.job_number }}</RouterLink>
              </li>
            </ul>
          </Card>
          <Card>
            <template #header><h3 class="font-semibold">Details</h3></template>
            <dl class="space-y-1 text-sm">
              <div v-if="lead.interest_label"><dt class="inline text-gray-500">Wants: </dt><dd class="inline">{{ lead.interest_label }}<template v-if="lead.package"> — {{ lead.package }}</template></dd></div>
              <div v-if="lead.category"><dt class="inline text-gray-500">Car type: </dt><dd class="inline">{{ lead.category }}</dd></div>
              <div v-if="lead.email"><dt class="inline text-gray-500">Email: </dt><dd class="inline">{{ lead.email }}</dd></div>
              <div v-if="lead.city"><dt class="inline text-gray-500">Area: </dt><dd class="inline">{{ lead.city }}</dd></div>
              <div><dt class="inline text-gray-500">Score: </dt><dd class="inline">{{ lead.score }}</dd></div>
              <div v-for="(v, k) in lead.tracking ?? {}" :key="k"><dt class="inline text-gray-500">{{ k }}: </dt><dd class="inline">{{ v }}</dd></div>
            </dl>
            <RouterLink v-if="lead.customer_id" :to="`/customers/${lead.customer_id}`" class="mt-2 block text-sm text-primary-700">Open customer</RouterLink>
          </Card>
          <Card v-if="can('crm.leads.manage')">
            <template #header><h3 class="font-semibold">Reassign</h3></template>
            <div class="flex gap-2">
              <select v-model="assignTo" class="input"><option :value="null">Choose…</option><option v-for="e in options?.executives" :key="e.id" :value="e.id">{{ e.name }}</option></select>
              <Button size="sm" :disabled="!assignTo" @click="reassign">Give</Button>
            </div>
          </Card>
        </div>
      </div>
    </template>

    <!-- Call outcome -->
    <Modal :open="callOpen" title="How did the call go?" @close="callOpen = false">
      <div class="space-y-3 text-sm">
        <div class="grid grid-cols-2 gap-2">
          <button v-for="(label, k) in options?.outcomes" :key="k" type="button" class="rounded-lg px-3 py-2 text-left ring-1" :class="call.outcome === k ? 'bg-gray-900 text-white ring-gray-900' : 'ring-gray-200'" @click="call.outcome = k">{{ label }}</button>
        </div>
        <div v-if="call.outcome === 'call_back' || call.outcome === 'connected'"><label class="label">{{ call.outcome === 'call_back' ? 'Call back at *' : 'Next follow-up (optional)' }}</label><input v-model="call.call_back_at" type="datetime-local" class="input" /></div>
        <div v-if="call.outcome === 'not_interested'">
          <label class="label">Why *</label>
          <select v-model="call.lost_reason_id" class="input"><option :value="null">Choose…</option><option v-for="r in options?.lost_reasons" :key="r.id" :value="r.id">{{ r.name }}{{ r.nurture ? ' (keep in touch)' : '' }}</option></select>
        </div>
        <div><label class="label">Note</label><textarea v-model="call.note" rows="2" class="input" placeholder="What they said" /></div>
        <p v-if="call.outcome === 'no_answer' || call.outcome === 'busy'" class="text-xs text-gray-500">We'll remind you to try again (2 h, then tomorrow, then in 3 days). The WhatsApp journey keeps going.</p>
      </div>
      <template #footer>
        <Button variant="secondary" @click="callOpen = false">Cancel</Button>
        <Button :loading="busy" :disabled="(call.outcome === 'call_back' && !call.call_back_at) || (call.outcome === 'not_interested' && !call.lost_reason_id)" @click="saveCall">Save</Button>
      </template>
    </Modal>

    <!-- Quote -->
    <Modal :open="quoteOpen" title="Send plan prices" @close="quoteOpen = false">
      <div v-if="!lastQuote" class="space-y-3 text-sm">
        <div><label class="label">Plan</label><select v-model="quote.package_id" class="input"><option v-for="p in options?.plans" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
        <div>
          <label class="label">Car type (for the exact price)</label>
          <select v-model="quote.category_id" class="input"><option :value="null">Not sure — send the price range</option><option v-for="c in options?.categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        </div>
        <p class="text-xs text-gray-500">The message has the prices from Price Masters, the plan's brochure (if set in Settings › CRM › Creatives) and a link to book a slot.</p>
      </div>
      <div v-else class="space-y-3 text-sm">
        <p class="whitespace-pre-line rounded-lg bg-gray-50 p-3">{{ lastQuote.body }}</p>
      </div>
      <template #footer>
        <Button variant="secondary" @click="quoteOpen = false">Close</Button>
        <Button v-if="!lastQuote" :loading="busy" :disabled="!quote.package_id" @click="sendQuote">Prepare message</Button>
        <Button v-else-if="lastQuote.share_url" @click="shareAndMark(lastQuote); quoteOpen = false">Send on WhatsApp</Button>
      </template>
    </Modal>

    <Modal :open="noteOpen" title="Note" size="sm" @close="noteOpen = false">
      <div class="space-y-3 text-sm">
        <textarea v-model="note.body" rows="3" class="input" />
        <div><label class="label">Next follow-up (optional)</label><input v-model="note.next" type="datetime-local" class="input" /></div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="noteOpen = false">Cancel</Button>
        <Button :loading="busy" :disabled="!note.body.trim()" @click="saveNote">Save</Button>
      </template>
    </Modal>

    <Modal :open="lostOpen" title="Close as lost" size="sm" @close="lostOpen = false">
      <div class="space-y-3 text-sm">
        <select v-model="lost.reason" class="input"><option :value="null">Why?</option><option v-for="r in options?.lost_reasons" :key="r.id" :value="r.id">{{ r.name }}{{ r.nurture ? ' (keep in touch)' : '' }}</option></select>
        <input v-model="lost.note" class="input" placeholder="Note" />
      </div>
      <template #footer>
        <Button variant="secondary" @click="lostOpen = false">Cancel</Button>
        <Button variant="danger" :loading="busy" :disabled="!lost.reason" @click="saveLost">Close lead</Button>
      </template>
    </Modal>

    <Modal :open="editOpen" title="Lead details" @close="editOpen = false">
      <div class="grid grid-cols-2 gap-3 text-sm">
        <div><label class="label">Name</label><input v-model="edit.name" class="input" /></div>
        <div><label class="label">Email</label><input v-model="edit.email" class="input" /></div>
        <div><label class="label">Car</label><input v-model="edit.car_text" class="input" /></div>
        <div><label class="label">Car number</label><input v-model="edit.registration_no" class="input uppercase" /></div>
        <div>
          <label class="label">Car type</label>
          <select v-model="edit.category_id" class="input"><option :value="null">—</option><option v-for="c in options?.categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        </div>
        <div><label class="label">Area</label><input v-model="edit.city" class="input" /></div>
        <div>
          <label class="label">Wants</label>
          <select v-model="edit.interest" class="input"><option v-for="(label, k) in options?.interests" :key="k" :value="k">{{ label }}</option></select>
        </div>
        <div>
          <label class="label">Plan</label>
          <select v-model="edit.package_id" class="input"><option :value="null">—</option><option v-for="p in options?.plans" :key="p.id" :value="p.id">{{ p.name }}</option></select>
        </div>
        <div class="col-span-2"><label class="label">Requirement</label><textarea v-model="edit.requirement" rows="2" class="input" /></div>
        <div class="col-span-2">
          <label class="label">How keen</label>
          <select v-model="edit.temperature" class="input"><option value="hot">Hot</option><option value="warm">Warm</option><option value="cold">Cold</option></select>
        </div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="editOpen = false">Cancel</Button>
        <Button :loading="busy" @click="saveEdit">Save</Button>
      </template>
    </Modal>

    <BookingDialog
      :open="bookOpen"
      :options="options"
      :lead-id="lead?.id"
      :package-id="lead?.package_id"
      :interest="lead?.interest"
      @close="bookOpen = false"
      @booked="onBooked"
    />
  </div>
</template>
