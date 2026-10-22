<script setup lang="ts">
/**
 * CRM › WhatsApp settings (was Settings › WhatsApp). Everything about Interakt in one place:
 *  - Connection: share links or Interakt, API key, webhook URL + signing secret, the CRM
 *    switches, a test message, and which webhooks have arrived (incoming chats only come on
 *    Interakt's higher plans);
 *  - Templates: the approved template for each kind of message, fetched from Interakt with
 *    its approval, and what a quick-reply button tap does (lead actions);
 *  - Contacts & events: contacts and events sent to Interakt for its own campaigns.
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ClipboardDocumentIcon, CheckCircleIcon, ArrowPathIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import apiClient from '@/api/client'
import { crmApi, type InteraktTemplate, type InteraktSyncStatus } from '@/api/crm.api'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Spinner from '@/components/ui/Spinner.vue'

interface State {
  provider: 'share' | 'interakt'
  api_on: boolean
  api_key_masked: string | null
  webhook_url: string
  enabled_at: string | null
  mapped: number
  signing_secret_set: boolean
  crm: { auto_lead: boolean; sync_contacts: boolean; sync_events: boolean; assign_in_interakt: boolean }
  webhooks: { type: string; last_at: string; count_30d: number }[]
  webhook_errors: { type: string; error: string; at: string }[]
}

interface TemplateRow {
  key: string
  label: string
  group: string
  text: string
  suggested_params: string[]
  provider_text: string
  provider_name: string | null
  language: string
  body_params: string[]
  header_type: 'none' | 'image' | 'video' | 'document'
  button_param: string | null
  is_active: boolean
  mapped: boolean
  approval_status: string | null
  category: string | null
  buttons: { type: string; text: string }[]
  button_actions: Record<string, string>
}

const ACTIONS: Record<string, string> = {
  '': 'Nothing (shown in the chat)',
  interested: 'Lead interested — follow up today',
  call_me: 'Call me — follow up now + bell',
  not_now: 'Not now — follow up in 30 days',
}
const CRM_SWITCHES: { key: keyof State['crm']; label: string; hint: string }[] = [
  { key: 'auto_lead', label: 'New chats become leads', hint: 'A number we don\x27t know (or a customer with no job in) writing in becomes a lead, assigned round-robin. Chats starting with a campaign\x27s "WhatsApp start text" go to that campaign.' },
  { key: 'sync_contacts', label: 'Send contacts to Interakt', hint: 'Leads and customers with their car, plan, status, next service … and tags (Lead / Customer / Fleet, source, branch), for Interakt\x27s own campaigns and segments.' },
  { key: 'sync_events', label: 'Send events to Interakt', hint: 'LeadCreated, LeadStatusChanged, LeadLost, BookingConfirmed, JobDelivered, ServiceDue, InsuranceDue — an Interakt ongoing campaign can start from any of them.' },
  { key: 'assign_in_interakt', label: 'Give chats to the same person in Interakt', hint: 'When a chat is assigned here, it is assigned in Interakt\x27s own inbox too (the staff member\x27s e-mail must be their Interakt login).' },
]

// Interakt's placeholders (kept out of the template so Vue doesn't read the braces)
const P1 = '{{1}}'
const P2 = '{{2}}'

const route = useRoute()
const router = useRouter()
const toast = useToast()
type Tab = 'connection' | 'templates' | 'sync'
const tab = ref<Tab>((route.query.tab as Tab) || 'connection')
watch(tab, (t) => router.replace({ query: { tab: t } }))

const state = ref<State | null>(null)
const provider = ref<'share' | 'interakt'>('share')
const apiKey = ref('')
const signingSecret = ref('')
const crmSwitches = ref<State['crm']>({ auto_lead: true, sync_contacts: true, sync_events: true, assign_in_interakt: false })
const busy = ref(false)

const rows = ref<TemplateRow[]>([])
const savedRows = ref<string>('[]')
const open = ref<string | null>(null)
const interaktTemplates = ref<InteraktTemplate[]>([])
const fetching = ref(false)
const fetchNote = ref<string | null>(null)

const FIELDS = ['provider_name', 'language', 'body_params', 'header_type', 'button_param', 'is_active', 'button_actions']
const changedRows = computed(() => {
  const before = JSON.parse(savedRows.value) as TemplateRow[]
  return rows.value.filter((r) => {
    const b = before.find((x) => x.key === r.key)
    return !b || FIELDS.some((f) => JSON.stringify((r as any)[f]) !== JSON.stringify((b as any)[f]))
  })
})
const groups = computed(() => {
  const out: Record<string, TemplateRow[]> = {}
  for (const r of rows.value) (out[r.group] ??= []).push(r)
  return out
})
const crmChanged = computed(() => !!state.value && (Object.keys(crmSwitches.value) as (keyof State['crm'])[]).some((k) => crmSwitches.value[k] !== state.value!.crm[k]))
const chatsArriving = computed(() => !!state.value?.webhooks.some((w) => w.type === 'message_received'))

async function load() {
  try {
    const [s, t, cached] = await Promise.all([apiClient.get('/settings/whatsapp'), apiClient.get('/settings/whatsapp/templates'), crmApi.whatsappInteraktTemplates()])
    setState(s.data.data)
    setRows(t.data.data)
    interaktTemplates.value = cached.data.data.templates
  } catch (err) {
    toast.apiError(err)
  }
}

function setState(s: State) {
  state.value = s
  provider.value = s.provider
  crmSwitches.value = { ...s.crm }
}

function setRows(data: TemplateRow[]) {
  rows.value = data.map((r) => ({ ...r, body_params: [...r.body_params], button_actions: { ...(r.button_actions ?? {}) } }))
  savedRows.value = JSON.stringify(rows.value)
}

async function saveConnection() {
  busy.value = true
  try {
    const res = await apiClient.put('/settings/whatsapp', {
      provider: provider.value,
      api_key: apiKey.value || null,
      signing_secret: signingSecret.value || null,
      crm: crmSwitches.value,
    })
    setState(res.data.data)
    apiKey.value = ''
    signingSecret.value = ''
    toast.success(res.data.message ?? 'Saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function clear(what: 'clear_api_key' | 'clear_signing_secret') {
  busy.value = true
  try {
    const res = await apiClient.put('/settings/whatsapp', { provider: what === 'clear_api_key' ? 'share' : provider.value, [what]: true })
    setState(res.data.data)
    toast.success(what === 'clear_api_key' ? 'API key removed; share links in use' : 'Signing secret removed')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function saveTemplates() {
  busy.value = true
  try {
    const res = await apiClient.put('/settings/whatsapp/templates', { templates: changedRows.value })
    setRows(res.data.data)
    setState((await apiClient.get('/settings/whatsapp')).data.data)
    toast.success('Templates saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function fetchTemplates() {
  fetching.value = true
  fetchNote.value = null
  try {
    const res = await crmApi.whatsappFetchTemplates()
    interaktTemplates.value = res.data.data.templates
    fetchNote.value = res.data.data.ok ? null : (res.data.message ?? null)
    if (res.data.data.ok) {
      toast.success(res.data.message ?? 'Fetched')
      setRows((await apiClient.get('/settings/whatsapp/templates')).data.data)
    }
  } catch (err) {
    toast.apiError(err)
  } finally {
    fetching.value = false
  }
}

/** Picking a template fetched from Interakt fills its name, language and header */
function pick(r: TemplateRow, name: string) {
  const t = interaktTemplates.value.find((x) => x.name === name)
  r.provider_name = name || null
  if (!t) return
  if (t.language) r.language = t.language
  if (['image', 'video', 'document'].includes(t.header)) r.header_type = t.header as TemplateRow['header_type']
  else r.header_type = 'none'
}
const quickReplies = (r: TemplateRow) => {
  const t = interaktTemplates.value.find((x) => x.name === r.provider_name)
  const fromFetch = (t?.buttons ?? r.buttons ?? []).filter((b) => b.type.includes('QUICK'))
  // Buttons typed in by hand earlier stay listed
  const texts = new Set([...fromFetch.map((b) => b.text), ...Object.keys(r.button_actions ?? {})])
  return [...texts]
}
const newButton = ref<Record<string, string>>({})
function addButton(r: TemplateRow) {
  const text = (newButton.value[r.key] ?? '').trim()
  if (!text) return
  r.button_actions = { ...r.button_actions, [text]: r.button_actions[text] ?? '' }
  newButton.value[r.key] = ''
}
const statusVariant = (s: string | null) => (s === 'APPROVED' ? 'success' : s === 'REJECTED' || s === 'NOT_FOUND' || s === 'DISABLED' ? 'danger' : s ? 'warning' : 'default')

function paramsText(r: TemplateRow) {
  return r.body_params.join(', ')
}
function setParams(r: TemplateRow, value: string) {
  r.body_params = value.split(',').map((p) => p.trim().replace(/^:/, '')).filter(Boolean)
}

async function copy(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    toast.success('Copied')
  } catch {
    toast.error('Could not copy', 'Select the text and copy it by hand.')
  }
}

// Test message
const test = ref({ phone: '', template: '', values: '' })
const testing = ref(false)
async function sendTest() {
  testing.value = true
  try {
    const values = test.value.values.split('|').map((v) => v.trim()).filter((v, i, a) => v !== '' || i < a.length - 1)
    const res = await apiClient.post('/settings/whatsapp/test', { phone: test.value.phone, template: test.value.template.trim(), values: test.value.values.trim() ? values : [] })
    toast.success(res.data.message ?? 'Sent')
  } catch (err) {
    toast.apiError(err)
  } finally {
    testing.value = false
  }
}

// Contacts & events
const sync = ref<InteraktSyncStatus | null>(null)
async function loadSync() {
  try {
    sync.value = (await crmApi.whatsappSyncStatus()).data.data
  } catch (err) {
    toast.apiError(err)
  }
}
async function syncAll() {
  busy.value = true
  try {
    const res = await crmApi.whatsappSyncAll()
    toast.success(res.data.message ?? 'Queued')
    await loadSync()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
watch(tab, (t) => t === 'sync' && loadSync(), { immediate: true })

const when = (d: string | null) => (d ? new Date(d).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '—')

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-4xl">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">WhatsApp &amp; Interakt</h1>
      <p class="text-sm text-gray-500">How messages go out, chats come in, and what Interakt knows about your leads and customers.</p>
    </div>

    <div class="mb-4 flex gap-1 overflow-x-auto rounded-xl bg-gray-100 p-1 text-sm font-medium">
      <button v-for="t in ([['connection', 'Connection'], ['templates', 'Templates & buttons'], ['sync', 'Contacts & events']] as const)" :key="t[0]" type="button"
        class="whitespace-nowrap rounded-lg px-3 py-1.5" :class="tab === t[0] ? 'bg-white shadow-sm' : 'text-gray-600'" @click="tab = t[0]">{{ t[1] }}</button>
    </div>

    <div v-if="!state" class="flex justify-center py-10"><Spinner size="lg" /></div>

    <!-- ============================== Connection -->
    <template v-else-if="tab === 'connection'">
      <Card class="mb-4">
        <div class="space-y-3 text-sm">
          <label class="flex cursor-pointer items-start gap-3 rounded-lg p-2 ring-1" :class="provider === 'share' ? 'ring-[#c4161c] bg-red-50/40' : 'ring-gray-200'">
            <input v-model="provider" type="radio" value="share" class="mt-1" />
            <span>
              <span class="block font-semibold text-gray-900">Share links</span>
              <span class="text-gray-600">Messages wait in "Messages to send" and on the job card; staff tap to open WhatsApp on the workshop phone and send. No inbox, no incoming chats.</span>
            </span>
          </label>
          <label class="flex cursor-pointer items-start gap-3 rounded-lg p-2 ring-1" :class="provider === 'interakt' ? 'ring-[#c4161c] bg-red-50/40' : 'ring-gray-200'">
            <input v-model="provider" type="radio" value="interakt" class="mt-1" />
            <span>
              <span class="block font-semibold text-gray-900">Interakt (WhatsApp Business API)</span>
              <span class="text-gray-600">
                Messages with an approved template go out by themselves with ticks; customers' chats come into CRM › WhatsApp Inbox and can be
                answered from there; quick-reply taps act on the lead; contacts and events go to Interakt. Anything without a template stays in
                "Messages to send" for the share link.
              </span>
            </span>
          </label>

          <div v-if="provider === 'interakt' || state.api_key_masked" class="grid gap-3 sm:grid-cols-2">
            <label class="block">
              <span class="text-gray-600">API key (Interakt › Settings › Developer Settings)</span>
              <input v-model="apiKey" type="password" autocomplete="off" class="input mt-1" :placeholder="state.api_key_masked ?? 'Paste the secret key'" />
              <button v-if="state.api_key_masked" type="button" class="mt-1 text-xs text-red-700 underline" @click="clear('clear_api_key')">Remove the key</button>
            </label>
            <div>
              <span class="text-gray-600">Webhook URL (Interakt › Developer Settings › Webhooks)</span>
              <div class="mt-1 flex items-center gap-1">
                <input :value="state.webhook_url" readonly class="input flex-1 text-xs" />
                <button type="button" class="rounded p-2 text-gray-500 hover:bg-gray-100" aria-label="Copy" @click="copy(state.webhook_url)"><ClipboardDocumentIcon class="h-4 w-4" /></button>
              </div>
            </div>
            <label class="block">
              <span class="text-gray-600">Webhook secret key (the same one you enter in Interakt)</span>
              <input v-model="signingSecret" type="password" autocomplete="off" class="input mt-1" :placeholder="state.signing_secret_set ? '•••••••• set' : 'Optional, recommended'" />
              <span class="text-xs text-gray-500">Calls without a matching Interakt-Signature are refused.</span>
              <button v-if="state.signing_secret_set" type="button" class="ml-2 text-xs text-red-700 underline" @click="clear('clear_signing_secret')">Remove</button>
            </label>
            <p class="text-xs text-gray-500 sm:pt-6">
              Interakt waits 3 seconds for an answer and switches the webhook off after 5 failures in 10 minutes (switch it back on in Interakt).
              The ERP answers at once and works on the message afterwards.
            </p>
          </div>

          <div v-if="provider === 'interakt' || state.api_on" class="space-y-2 border-t border-gray-100 pt-3">
            <label v-for="s in CRM_SWITCHES" :key="s.key" class="flex items-start gap-3">
              <input v-model="crmSwitches[s.key]" type="checkbox" class="mt-1 h-4 w-4 rounded" />
              <span><span class="font-medium text-gray-900">{{ s.label }}</span><span class="block text-xs text-gray-500">{{ s.hint }}</span></span>
            </label>
          </div>

          <div class="flex items-center justify-between gap-3 border-t border-gray-100 pt-3">
            <p class="text-xs text-gray-500">
              Now: <strong>{{ state.api_on ? 'Interakt on' : 'Share links' }}</strong>
              <template v-if="state.api_on"> · {{ state.mapped }} message kind(s) with a template</template>
            </p>
            <Button :loading="busy" :disabled="provider === state.provider && !apiKey && !signingSecret && !crmChanged" @click="saveConnection">Save</Button>
          </div>
        </div>
      </Card>

      <Card v-if="state.api_on" class="mb-4">
        <h2 class="mb-1 font-semibold text-gray-900">What Interakt has sent us (last 30 days)</h2>
        <p v-if="!state.webhooks.length" class="text-sm text-amber-700">Nothing yet. Check the webhook URL in Interakt › Developer Settings, and that the webhook is switched on.</p>
        <ul v-else class="divide-y divide-gray-100 text-sm">
          <li v-for="w in state.webhooks" :key="w.type" class="flex justify-between py-1.5"><span class="font-mono text-xs">{{ w.type }}</span><span class="text-gray-500">{{ w.count_30d }} · last {{ when(w.last_at) }}</span></li>
        </ul>
        <p v-if="!chatsArriving" class="mt-2 flex gap-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">
          <ExclamationTriangleIcon class="h-4 w-4 shrink-0" />
          No customer chats have arrived. Interakt sends incoming messages only on its higher plans (Advanced; check whether yours includes them).
          Until then the inbox shows what we send, ticks and button taps; chats are answered in Interakt.
        </p>
        <div v-if="state.webhook_errors.length" class="mt-3">
          <p class="text-xs font-semibold text-red-700">Recent errors</p>
          <p v-for="(e, i) in state.webhook_errors" :key="i" class="text-xs text-red-700">{{ when(e.at) }} · {{ e.type }}: {{ e.error }}</p>
        </div>
      </Card>

      <Card v-if="state.api_key_masked" class="mb-4">
        <h2 class="mb-2 font-semibold text-gray-900">Send a test message</h2>
        <div class="grid gap-3 text-sm sm:grid-cols-3">
          <label class="block"><span class="text-gray-600">Mobile</span><input v-model="test.phone" class="input mt-1" inputmode="tel" placeholder="98xxxxxxxx" /></label>
          <label class="block"><span class="text-gray-600">Template name</span><input v-model="test.template" class="input mt-1" placeholder="e.g. booking_confirmed_v1" /></label>
          <label class="block"><span class="text-gray-600">Values, separated by |</span><input v-model="test.values" class="input mt-1" placeholder="Asha | MH04AB1234" /></label>
        </div>
        <div class="mt-3 flex justify-end"><Button variant="secondary" :loading="testing" :disabled="!test.phone || !test.template" @click="sendTest">Send test</Button></div>
      </Card>
    </template>

    <!-- ============================== Templates -->
    <Card v-else-if="tab === 'templates'" :padding="false">
      <div class="border-b border-gray-100 p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <h2 class="font-semibold text-gray-900">Templates</h2>
          <Button v-if="state.api_key_masked" size="sm" variant="secondary" :loading="fetching" @click="fetchTemplates"><ArrowPathIcon class="mr-1 h-4 w-4" /> Fetch from Interakt</Button>
        </div>
        <p class="mt-1 text-sm text-gray-500">
          For each message you want sent automatically: copy "Text for Interakt", create the template in Interakt (Utility for job updates,
          Marketing for offers and creatives, Authentication for the sign-in code). Once approved, pick it here (or type its name). The values fill
          {{ P1 }}, {{ P2 }} … in this order.
        </p>
        <p v-if="fetchNote" class="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">{{ fetchNote }}</p>
      </div>
      <div v-for="(list, group) in groups" :key="group">
        <p class="bg-gray-50 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ group }}</p>
        <ul class="divide-y divide-gray-100">
          <li v-for="r in list" :key="r.key" class="px-4 py-2.5">
            <button type="button" class="flex w-full items-center gap-2 text-left" @click="open = open === r.key ? null : r.key">
              <CheckCircleIcon class="h-5 w-5 shrink-0" :class="r.mapped ? 'text-green-600' : 'text-gray-300'" />
              <span class="flex-1 text-sm font-medium text-gray-900">{{ r.label }}</span>
              <Badge v-if="r.approval_status" size="sm" :variant="statusVariant(r.approval_status)">{{ r.approval_status.replace('_', ' ').toLowerCase() }}</Badge>
              <span class="text-xs text-gray-500">{{ r.provider_name || 'share link' }}</span>
            </button>
            <div v-if="open === r.key" class="mt-3 space-y-3 text-sm">
              <div>
                <div class="flex items-center justify-between">
                  <span class="text-gray-600">Text for Interakt</span>
                  <button type="button" class="inline-flex items-center gap-1 text-xs text-gray-600 hover:text-gray-900" @click="copy(r.provider_text)"><ClipboardDocumentIcon class="h-4 w-4" /> Copy</button>
                </div>
                <pre class="mt-1 whitespace-pre-wrap rounded-lg bg-gray-50 p-2 text-xs text-gray-800">{{ r.provider_text || '(no text set for this message)' }}</pre>
              </div>
              <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                  <span class="text-gray-600">Approved template</span>
                  <select v-if="interaktTemplates.length" :value="r.provider_name ?? ''" class="input mt-1" @change="pick(r, ($event.target as HTMLSelectElement).value)">
                    <option value="">— share link (no template) —</option>
                    <option v-for="t in interaktTemplates" :key="t.name + t.language" :value="t.name">{{ t.name }} · {{ t.language || 'en' }} · {{ (t.status || '?').toLowerCase() }}</option>
                  </select>
                  <input v-else v-model.trim="r.provider_name" class="input mt-1" placeholder="lower_case_name" />
                </label>
                <label class="block">
                  <span class="text-gray-600">Language code</span>
                  <input v-model.trim="r.language" class="input mt-1" placeholder="en" />
                </label>
                <label class="block sm:col-span-2">
                  <span class="text-gray-600">Values in order ({{ P1 }}, {{ P2 }} …)</span>
                  <input :value="paramsText(r)" class="input mt-1" @change="setParams(r, ($event.target as HTMLInputElement).value)" />
                  <span class="text-xs text-gray-500">Available: {{ r.suggested_params.join(', ') || 'none' }}</span>
                </label>
                <label class="block">
                  <span class="text-gray-600">Header</span>
                  <select v-model="r.header_type" class="input mt-1">
                    <option value="none">None (text only)</option>
                    <option value="image">Image</option>
                    <option value="video">Video</option>
                    <option value="document">Document (PDF)</option>
                  </select>
                </label>
                <label class="block">
                  <span class="text-gray-600">Link button value (optional)</span>
                  <input v-model.trim="r.button_param" class="input mt-1" placeholder="e.g. link" />
                  <span class="text-xs text-gray-500">The button URL in Interakt should be this app's address followed by {{ P1 }}.</span>
                </label>
              </div>

              <div class="rounded-lg bg-gray-50 p-3">
                <p class="font-medium text-gray-800">Quick-reply buttons: what a tap does</p>
                <p class="text-xs text-gray-500">Type each button exactly as in the template (fetched templates list them). Taps always show in the chat and on the lead.</p>
                <div v-for="text in quickReplies(r)" :key="text" class="mt-2 grid grid-cols-2 items-center gap-2">
                  <span class="truncate text-gray-800">"{{ text }}"</span>
                  <select :value="r.button_actions[text] ?? ''" class="input" @change="r.button_actions = { ...r.button_actions, [text]: ($event.target as HTMLSelectElement).value }">
                    <option v-for="(label, k) in ACTIONS" :key="k" :value="k">{{ label }}</option>
                  </select>
                </div>
                <div class="mt-2 flex gap-2">
                  <input v-model="newButton[r.key]" class="input flex-1" maxlength="25" placeholder="Button text, e.g. Interested" @keyup.enter="addButton(r)" />
                  <Button size="sm" variant="secondary" type="button" @click="addButton(r)">Add</Button>
                </div>
              </div>
              <label class="flex items-center gap-2"><input v-model="r.is_active" type="checkbox" class="h-4 w-4 rounded" /> Use this template</label>
            </div>
          </li>
        </ul>
      </div>
      <div class="flex items-center justify-end gap-2 border-t border-gray-100 p-3">
        <span v-if="changedRows.length" class="text-xs text-gray-500">{{ changedRows.length }} changed</span>
        <Button variant="secondary" :disabled="!changedRows.length" @click="setRows(JSON.parse(savedRows))">Discard</Button>
        <Button :loading="busy" :disabled="!changedRows.length" @click="saveTemplates">Save templates ({{ changedRows.length }})</Button>
      </div>
    </Card>

    <!-- ============================== Contacts & events -->
    <template v-else>
      <Card class="mb-4">
        <h2 class="font-semibold text-gray-900">Contacts &amp; events in Interakt</h2>
        <p class="mt-1 text-sm text-gray-600">
          Leads and customers go to Interakt as contacts, with traits (name, car, plan, branch, lead status and temperature, last visit, next
          service due, fleet, marketing opt-out) and tags (Lead / Customer / Fleet / Corporate, lead source, branch). Interakt keeps the latest
          value of each trait; tags can only be added, never removed. Events let an Interakt "ongoing campaign" start by itself.
        </p>
        <p class="mt-2 text-sm"><strong>Contacts:</strong> {{ sync?.contacts_on ? 'on' : 'off' }} · <strong>Events:</strong> {{ sync?.events_on ? 'on' : 'off' }}
          <button type="button" class="ml-2 text-xs text-primary-700 underline" @click="tab = 'connection'">change</button></p>
        <div v-if="sync" class="mt-3 grid grid-cols-3 gap-2 text-sm">
          <div class="rounded-lg bg-gray-50 p-2"><p class="text-xs text-gray-500">Waiting</p><p class="font-semibold">{{ sync.waiting }}</p></div>
          <div class="rounded-lg bg-gray-50 p-2"><p class="text-xs text-gray-500">Sent today</p><p class="font-semibold">{{ sync.sent_today }}</p></div>
          <div class="rounded-lg p-2" :class="sync.gave_up ? 'bg-red-50' : 'bg-gray-50'"><p class="text-xs text-gray-500">Gave up</p><p class="font-semibold">{{ sync.gave_up }}</p></div>
        </div>
        <p class="mt-2 text-xs text-gray-500">They go every 15 minutes, about 250 at a time (Interakt allows 300 a minute on Growth). Waiting ones are tried again later after an error.</p>
        <div class="mt-3 flex justify-end"><Button :loading="busy" :disabled="!sync?.contacts_on" @click="syncAll">Send all contacts now</Button></div>
      </Card>
      <Card v-if="sync" class="mb-4">
        <h3 class="font-semibold text-gray-900">Events sent</h3>
        <p class="mt-1 flex flex-wrap gap-1"><code v-for="e in sync.events" :key="e" class="rounded bg-gray-100 px-1.5 py-0.5 text-xs">{{ e }}</code></p>
        <p class="mt-2 text-xs text-gray-500">In Interakt: Campaigns › Ongoing › trigger "Event" › choose one, then pick the template; event values (e.g. registrationNo, dueDate) can fill its variables.</p>
        <div v-if="sync.errors.length" class="mt-3">
          <p class="text-xs font-semibold text-red-700">Recent errors</p>
          <p v-for="(e, i) in sync.errors" :key="i" class="text-xs text-red-700">{{ when(e.at) }} · {{ e.subject }} · {{ e.what }} (try {{ e.attempts }}): {{ e.error }}</p>
        </div>
      </Card>
    </template>
  </div>
</template>
