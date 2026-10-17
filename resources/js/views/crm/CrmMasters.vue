<script setup lang="ts">
/**
 * Settings › CRM: where leads come from (each source's webhook link for Google Ads /
 * Meta via Pabbly / Zapier / the website), campaigns and ad spend, creatives, journeys
 * (which creative goes when), booking slots, lost reasons, and SLA / sending / referral
 * settings (Save bar).
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { crmApi, type CrmMasters, type Creative, type JourneyRow } from '@/api/crm.api'
import { WEEKDAYS, thisMonth } from '@/api/hr.api'
import { inr } from '@/api/accounts.api'
import { useAuthStore } from '@/stores/auth.store'
import { usePermissions } from '@/composables/usePermissions'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Spinner from '@/components/ui/Spinner.vue'
import UnsavedChangesBar from '@/components/ui/UnsavedChangesBar.vue'

type Tab = 'journeys' | 'creatives' | 'sources' | 'campaigns' | 'slots' | 'reasons' | 'settings'
const TABS: [Tab, string][] = [['journeys', 'Journeys'], ['creatives', 'Creatives'], ['sources', 'Lead sources'], ['campaigns', 'Campaigns & spend'], ['slots', 'Booking slots'], ['reasons', 'Lost reasons'], ['settings', 'Settings']]

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { can } = usePermissions()
const toast = useToast()
const canEdit = computed(() => can('crm.settings.manage'))

const tab = ref<Tab>((route.query.tab as Tab) || 'journeys')
watch(tab, (t) => router.replace({ query: { tab: t } }))
const data = ref<CrmMasters | null>(null)
const busy = ref(false)

async function load() {
  try {
    data.value = (await crmApi.masters()).data.data
    settings.value = JSON.parse(JSON.stringify(data.value.settings))
    loadSlots()
  } catch (err) {
    toast.apiError(err)
  }
}
onMounted(load)

async function run(fn: () => Promise<unknown>, done: string) {
  busy.value = true
  try {
    await fn()
    toast.success(done)
    await load()
    return true
  } catch (err) {
    toast.apiError(err)
    return false
  } finally {
    busy.value = false
  }
}

// --------------------------------------------------------------- journeys
const journey = ref<Partial<JourneyRow> | null>(null)
const isDue = computed(() => !!journey.value?.trigger && !!data.value?.due_triggers.includes(journey.value.trigger))
function newJourney() {
  journey.value = { name: '', trigger: 'new_lead', is_active: true, description: '', steps: [{ day_offset: 0, send_at: '10:30', action: 'message', creative_id: null, task_note: null }] }
}
function addStep() {
  const steps = journey.value!.steps!
  const last = steps[steps.length - 1]
  steps.push({ day_offset: (last?.day_offset ?? 0) + 7, send_at: '10:30', action: 'message', creative_id: last?.creative_id ?? null, task_note: null })
}
async function saveJourney() {
  if (await run(() => crmApi.saveJourney(journey.value!), 'Journey saved')) journey.value = null
}
const creativeTitle = (id: number | null) => data.value?.creatives.find((c) => c.id === id)?.title ?? '—'
const dayLabel = (d: number, due: boolean) => (due ? (d === 0 ? 'On the day' : d < 0 ? `${-d} days before` : `${d} days after`) : d === 0 ? 'At once' : `Day ${d}`)

// -------------------------------------------------------------- creatives
const creative = ref<(Partial<Creative> & { remove_file?: boolean }) | null>(null)
const creativeFile = ref<File | null>(null)
async function saveCreative() {
  const c = creative.value!
  const fd = new FormData()
  for (const k of ['title', 'caption', 'wa_template', 'wa_language'] as const) if (c[k]) fd.append(k, String(c[k]))
  if (c.package_id) fd.append('package_id', String(c.package_id))
  fd.append('is_active', c.is_active === false ? '0' : '1')
  if (c.remove_file) fd.append('remove_file', '1')
  if (creativeFile.value) fd.append('file', creativeFile.value)
  if (await run(() => crmApi.saveCreative(fd, c.id), 'Creative saved')) {
    creative.value = null
    creativeFile.value = null
  }
}
const PLACEHOLDERS = [':name', ':car', ':plan', ':price', ':book_link', ':feedback_link', ':referral_link', ':due_date', ':offer', ':workshop']

// ---------------------------------------------------------------- sources
const source = ref<{ id?: number; name: string; is_paid: boolean; is_active: boolean } | null>(null)
async function saveSource() {
  if (await run(() => crmApi.saveSource(source.value!), 'Source saved')) source.value = null
}
function copy(text: string | null) {
  if (!text) return
  navigator.clipboard?.writeText(text)
  toast.success('Link copied')
}

// -------------------------------------------------------------- campaigns
const campaign = ref<Record<string, any> | null>(null)
async function saveCampaign() {
  if (await run(() => crmApi.saveCampaign(campaign.value!), 'Campaign saved')) campaign.value = null
}
const spend = ref<{ id: number; name: string; period: string; amount: number | string } | null>(null)
async function saveSpend() {
  if (await run(() => crmApi.saveSpend(spend.value!.id, spend.value!.period, Number(spend.value!.amount)), 'Spend saved')) spend.value = null
}

// ------------------------------------------------------------------ slots
const slotBranch = ref<number | null>(null)
const slots = ref<CrmMasters['slots']>([])
function loadSlots() {
  slots.value = (data.value?.slots ?? []).filter((s) => s.branch_id === slotBranch.value).map((s) => ({ ...s }))
}
watch(slotBranch, loadSlots)
function addSlot(weekday: number) {
  const day = slots.value.filter((s) => s.weekday === weekday)
  const last = day[day.length - 1]
  slots.value.push({ branch_id: slotBranch.value, weekday, start_time: last?.end_time ?? '09:30', end_time: '11:00', capacity: last?.capacity ?? 3, is_active: true })
}
function copyMonday() {
  const mon = slots.value.filter((s) => s.weekday === 1)
  slots.value = [...mon, ...[2, 3, 4, 5, 6].flatMap((d) => mon.map((s) => ({ ...s, id: undefined, weekday: d })))]
}
async function saveSlots() {
  await run(() => crmApi.saveSlots(slotBranch.value, slots.value), 'Booking slots saved')
}

// ---------------------------------------------------------------- reasons
const reason = ref<Record<string, any> | null>(null)
async function saveReason() {
  if (await run(() => crmApi.saveLostReason(reason.value!), 'Saved')) reason.value = null
}

// --------------------------------------------------------------- settings
const SETTINGS: { key: string; label: string; type?: 'time' | 'text' | 'area'; hint?: string }[] = [
  { key: 'crm.sla_minutes', label: 'Call a new lead within (minutes)', hint: 'The executive, then the manager, is alerted when missed.' },
  { key: 'crm.work_start', label: 'Working hours from', type: 'time' },
  { key: 'crm.work_end', label: 'Working hours to', type: 'time' },
  { key: 'crm.quiet_start', label: 'Send messages from', type: 'time' },
  { key: 'crm.quiet_end', label: 'Send messages until', type: 'time' },
  { key: 'crm.frequency_cap_days', label: 'At most one nurture message per person every (days)' },
  { key: 'crm.no_show_after_hours', label: 'No-show after the slot (hours)' },
  { key: 'crm.win_back_months', label: 'Win-back after no visit for (months)' },
  { key: 'crm.referral_reward', label: 'Referral reward ₹ (customer credit)' },
  { key: 'crm.booking_days_ahead', label: 'Self-booking: days ahead offered' },
  { key: 'crm.link_valid_days', label: 'Customer links valid for (days)' },
  { key: 'crm.google_review_url', label: 'Google review link', type: 'text', hint: 'Asked of customers who rate 4–5 ★.' },
  { key: 'crm.offer_text', label: 'Current offer (:offer)', type: 'text' },
  { key: 'crm.messages.plan_quote', label: 'Plan quote message', type: 'area', hint: ':plan :prices :inclusions :book_link' },
  { key: 'crm.messages.booking_confirmed', label: 'Booking confirmed message', type: 'area', hint: ':number :date :slot :mode_line :book_link' },
  { key: 'crm.messages.booking_reminder', label: 'Day-before reminder', type: 'area' },
]
const settings = ref<Record<string, any>>({})
const changed = computed(() => Object.keys(settings.value).filter((k) => String(settings.value[k] ?? '') !== String(data.value?.settings[k] ?? '')))
async function saveSettings() {
  busy.value = true
  try {
    data.value = (await crmApi.saveSettings(Object.fromEntries(changed.value.map((k) => [k, settings.value[k]])))).data.data
    settings.value = JSON.parse(JSON.stringify(data.value.settings))
    toast.success('Settings saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">CRM settings</h1>
      <p class="text-sm text-gray-500">Which creative goes when, where leads come from, booking slots and the rules.</p>
    </div>
    <div class="mb-4 flex gap-1 overflow-x-auto scrollbar-none rounded-xl bg-gray-100 p-1 text-sm font-medium">
      <button v-for="t in TABS" :key="t[0]" type="button" class="whitespace-nowrap rounded-lg px-3 py-1.5" :class="tab === t[0] ? 'bg-white shadow-sm' : 'text-gray-600'" @click="tab = t[0]">{{ t[1] }}</button>
    </div>

    <div v-if="!data" class="flex justify-center py-12"><Spinner size="lg" /></div>
    <template v-else>
      <!-- Journeys -->
      <template v-if="tab === 'journeys'">
        <div class="mb-3 flex justify-between items-center">
          <p class="text-sm text-gray-600">Each journey starts on its trigger and stops when the person books, is closed, or opts out.</p>
          <Button v-if="canEdit" size="sm" @click="newJourney">New journey</Button>
        </div>
        <div class="space-y-3">
          <Card v-for="j in data.journeys" :key="j.id">
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="font-semibold">{{ j.name }}</h3>
              <Badge size="sm" variant="info">{{ data.triggers[j.trigger] }}</Badge>
              <Badge v-if="!j.is_active" size="sm">Off</Badge>
              <Button v-if="canEdit" size="sm" variant="ghost" class="ml-auto" @click="journey = JSON.parse(JSON.stringify(j))">Edit</Button>
            </div>
            <p v-if="j.description" class="text-xs text-gray-500">{{ j.description }}</p>
            <ol class="mt-2 flex flex-wrap gap-2 text-xs">
              <li v-for="(s, i) in j.steps" :key="i" class="rounded-lg bg-gray-50 px-2 py-1">
                <b>{{ dayLabel(s.day_offset, data.due_triggers.includes(j.trigger)) }}</b>
                · {{ s.action === 'message' ? s.creative : s.action === 'task' ? '📞 ' + (s.task_note ?? 'Follow-up') : '🎯 ' + (s.task_note ?? 'Lead') }}
              </li>
            </ol>
          </Card>
        </div>
      </template>

      <!-- Creatives -->
      <template v-if="tab === 'creatives'">
        <div class="mb-3 flex justify-between items-center">
          <p class="text-sm text-gray-600">Images, videos, PDFs or text. A plan's brochure goes with that plan's quote.</p>
          <Button v-if="canEdit" size="sm" @click="creative = { title: '', caption: '', is_active: true, package_id: null }; creativeFile = null">New creative</Button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <Card v-for="c in data.creatives" :key="c.id" :padding="false" class="overflow-hidden">
            <img v-if="c.kind === 'image' && c.file_url" :src="c.file_url" alt="" class="h-36 w-full object-cover" />
            <div v-else class="flex h-20 items-center justify-center bg-gray-50 text-2xl">{{ c.kind === 'video' ? '🎬' : c.kind === 'pdf' ? '📄' : '💬' }}</div>
            <div class="p-3 text-sm">
              <p class="font-semibold">{{ c.title }} <Badge v-if="!c.is_active" size="sm">Off</Badge></p>
              <p v-if="c.package" class="text-xs text-primary-700">{{ c.package }} brochure</p>
              <p class="mt-1 line-clamp-3 whitespace-pre-line text-xs text-gray-600">{{ c.caption }}</p>
              <Button v-if="canEdit" size="sm" variant="ghost" class="mt-1" @click="creative = { ...c }; creativeFile = null">Edit</Button>
            </div>
          </Card>
        </div>
      </template>

      <!-- Sources -->
      <template v-if="tab === 'sources'">
        <Card class="mb-3 text-sm text-gray-600">
          <p><b>Google Ads lead forms:</b> paste the source's link as the webhook URL (any key).</p>
          <p class="mt-1"><b>Meta lead ads:</b> connect the Facebook page to the link through Pabbly / Zapier / Make ("Facebook Lead Ads → Webhook (POST)").</p>
          <p class="mt-1"><b>Website form:</b> post name, phone, car, message (and ?ref= referral code) to the Website link.</p>
        </Card>
        <Card :padding="false">
          <template #header><div class="flex items-center justify-between"><h3 class="font-semibold">Lead sources</h3><Button v-if="canEdit" size="sm" @click="source = { name: '', is_paid: false, is_active: true }">Add</Button></div></template>
          <ul class="divide-y divide-gray-100 text-sm">
            <li v-for="s in data.sources" :key="s.id" class="px-4 py-3">
              <div class="flex items-center gap-2">
                <span class="font-semibold">{{ s.name }}</span>
                <Badge v-if="s.is_paid" size="sm" variant="warning">Paid</Badge>
                <Badge v-if="!s.is_active" size="sm">Off</Badge>
                <Button v-if="canEdit" size="sm" variant="ghost" class="ml-auto" @click="source = { ...s }">Edit</Button>
              </div>
              <div v-if="s.webhook_url" class="mt-1 flex items-center gap-2">
                <code class="flex-1 truncate rounded bg-gray-50 px-2 py-1 text-xs">{{ s.webhook_url }}</code>
                <Button size="sm" variant="secondary" @click="copy(s.webhook_url)">Copy</Button>
                <Button v-if="canEdit" size="sm" variant="ghost" @click="run(() => crmApi.newWebhookKey(s.id), 'New link made')">New link</Button>
              </div>
            </li>
          </ul>
        </Card>
      </template>

      <!-- Campaigns -->
      <template v-if="tab === 'campaigns'">
        <div class="mb-3 flex justify-between items-center">
          <p class="text-sm text-gray-600">Campaigns are created by themselves from incoming leads; enter each month's spend for cost per lead and ROI.</p>
          <Button v-if="canEdit" size="sm" @click="campaign = { name: '', lead_source_id: data.sources[0]?.id, external_ref: '', is_active: true }">Add campaign</Button>
        </div>
        <Card :padding="false">
          <ul class="divide-y divide-gray-100 text-sm">
            <li v-for="c in data.campaigns" :key="c.id" class="px-4 py-3 flex flex-wrap items-center gap-2">
              <span class="flex-1 min-w-0"><span class="font-semibold">{{ c.name }}</span> <span class="text-xs text-gray-500">· {{ c.source }}</span>
                <span class="block text-xs text-gray-500"><template v-for="s in c.spends.slice(0, 3)" :key="s.period">{{ s.period }}: {{ inr(s.amount) }} · </template></span>
              </span>
              <Button v-if="can('crm.campaigns.spend')" size="sm" variant="secondary" @click="spend = { id: c.id, name: c.name, period: thisMonth(), amount: '' }">Enter spend</Button>
              <Button v-if="canEdit" size="sm" variant="ghost" @click="campaign = { ...c }">Edit</Button>
            </li>
          </ul>
          <p v-if="!data.campaigns.length" class="p-4 text-sm text-gray-500">None yet.</p>
        </Card>
      </template>

      <!-- Slots -->
      <template v-if="tab === 'slots'">
        <div class="mb-3 flex flex-wrap items-center gap-2">
          <select v-model="slotBranch" class="input w-auto">
            <option :value="null">All branches (default)</option>
            <option v-for="b in auth.branches" :key="b.id" :value="b.id">{{ b.name }} (own slots)</option>
          </select>
          <Button v-if="canEdit" size="sm" variant="ghost" @click="copyMonday">Copy Monday to Tue–Sat</Button>
          <Button v-if="canEdit" size="sm" class="ml-auto" :loading="busy" @click="saveSlots">Save slots</Button>
        </div>
        <p class="mb-3 text-xs text-gray-500">A branch with its own slots uses them; others use the default. No slots on the branch's weekly off or holidays.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <Card v-for="(d, wi) in WEEKDAYS" :key="wi">
            <template #header><div class="flex items-center justify-between"><h3 class="font-semibold">{{ d }}</h3><Button v-if="canEdit" size="sm" variant="ghost" @click="addSlot(wi)">+ Slot</Button></div></template>
            <p v-if="!slots.some((s) => s.weekday === wi)" class="text-sm text-gray-400">Closed</p>
            <div v-for="(s, i) in slots" v-show="s.weekday === wi" :key="i" class="mb-2 flex items-center gap-2 text-sm">
              <input v-model="s.start_time" type="time" class="input w-28" :disabled="!canEdit" />
              <span>–</span>
              <input v-model="s.end_time" type="time" class="input w-28" :disabled="!canEdit" />
              <input v-model.number="s.capacity" type="number" min="0" class="input w-16" :disabled="!canEdit" title="Cars" />
              <span class="text-xs text-gray-500">cars</span>
              <button v-if="canEdit" type="button" class="text-xs text-gray-400 hover:text-red-700" @click="slots.splice(i, 1)">✕</button>
            </div>
          </Card>
        </div>
      </template>

      <!-- Lost reasons -->
      <Card v-if="tab === 'reasons'" :padding="false">
        <template #header><div class="flex items-center justify-between"><h3 class="font-semibold">Lost reasons</h3><Button v-if="canEdit" size="sm" @click="reason = { name: '', nurture: false, is_active: true }">Add</Button></div></template>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="r in data.lost_reasons" :key="r.id" class="px-4 py-2 flex items-center gap-2">
            <span class="flex-1">{{ r.name }}</span>
            <Badge v-if="r.nurture" size="sm" variant="info">Keeps nurturing</Badge>
            <Badge v-if="!r.is_active" size="sm">Off</Badge>
            <Button v-if="canEdit" size="sm" variant="ghost" @click="reason = { ...r }">Edit</Button>
          </li>
        </ul>
      </Card>

      <!-- Settings -->
      <template v-if="tab === 'settings'">
        <Card>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <label v-for="s in SETTINGS" :key="s.key" class="block" :class="s.type === 'area' || s.type === 'text' ? 'sm:col-span-2' : ''">
              <span class="text-gray-600">{{ s.label }}</span>
              <textarea v-if="s.type === 'area'" v-model="settings[s.key]" rows="3" class="input mt-1" :disabled="!canEdit" />
              <input v-else-if="s.type === 'text'" v-model="settings[s.key]" class="input mt-1" :disabled="!canEdit" />
              <input v-else-if="s.type === 'time'" v-model="settings[s.key]" type="time" class="input mt-1" :disabled="!canEdit" />
              <input v-else v-model.number="settings[s.key]" type="number" min="0" class="input mt-1" :disabled="!canEdit" />
              <span v-if="s.hint" class="mt-0.5 block text-xs text-gray-500">{{ s.hint }}</span>
            </label>
          </div>
        </Card>
        <UnsavedChangesBar v-if="canEdit" :count="changed.length" :labels="changed.map((k) => SETTINGS.find((s) => s.key === k)?.label ?? k)" :saving="busy" @save="saveSettings" @discard="settings = JSON.parse(JSON.stringify(data!.settings))" />
      </template>
    </template>

    <!-- Journey editor -->
    <Modal :open="!!journey" :title="journey?.id ? 'Edit journey' : 'New journey'" size="xl" @close="journey = null">
      <div v-if="journey && data" class="space-y-3 text-sm">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div><label class="label">Name</label><input v-model="journey.name" class="input" /></div>
          <div>
            <label class="label">Starts when</label>
            <select v-model="journey.trigger" class="input"><option v-for="(label, k) in data.triggers" :key="k" :value="k">{{ label }}</option></select>
          </div>
        </div>
        <div><label class="label">Description</label><input v-model="journey.description" class="input" /></div>
        <label class="flex items-center gap-2"><input v-model="journey.is_active" type="checkbox" class="h-4 w-4 rounded" /> Active</label>

        <p class="font-semibold text-gray-700">Steps <span class="font-normal text-gray-500">({{ isDue ? 'days before (−) / after the due date' : 'days after the start' }})</span></p>
        <div v-for="(s, i) in journey.steps" :key="i" class="grid grid-cols-12 gap-2 items-center rounded-lg bg-gray-50 p-2">
          <input v-model.number="s.day_offset" type="number" class="input col-span-2" :min="isDue ? -365 : 0" title="Day" />
          <input v-model="s.send_at" type="time" class="input col-span-2" />
          <select v-model="s.action" class="input col-span-3"><option v-for="(label, k) in data.actions" :key="k" :value="k">{{ label }}</option></select>
          <select v-if="s.action === 'message'" v-model="s.creative_id" class="input col-span-4"><option :value="null">Creative…</option><option v-for="c in data.creatives" :key="c.id" :value="c.id">{{ c.title }}</option></select>
          <input v-else v-model="s.task_note" class="input col-span-4" placeholder="What to do" />
          <button type="button" class="col-span-1 text-gray-400 hover:text-red-700" @click="journey.steps!.splice(i, 1)">✕</button>
        </div>
        <button type="button" class="text-xs font-medium text-primary-700" @click="addStep">+ Add step</button>
        <p class="text-xs text-gray-500">{{ journey.steps?.map((s) => dayLabel(s.day_offset, isDue) + ': ' + (s.action === 'message' ? creativeTitle(s.creative_id) : s.task_note)).join(' → ') }}</p>
      </div>
      <template #footer>
        <Button variant="secondary" @click="journey = null">Cancel</Button>
        <Button :loading="busy" :disabled="!journey?.name || !journey?.steps?.length" @click="saveJourney">Save journey</Button>
      </template>
    </Modal>

    <!-- Creative editor -->
    <Modal :open="!!creative" :title="creative?.id ? 'Edit creative' : 'New creative'" size="lg" @close="creative = null">
      <div v-if="creative" class="space-y-3 text-sm">
        <div><label class="label">Title</label><input v-model="creative.title" class="input" /></div>
        <div>
          <label class="label">Message</label>
          <textarea v-model="creative.caption" rows="5" class="input" />
          <p class="mt-1 text-xs text-gray-500">Placeholders: {{ PLACEHOLDERS.join(' ') }}. With a file, a link to view it is added to the message.</p>
        </div>
        <div>
          <label class="label">Image / video / PDF</label>
          <input type="file" accept="image/*,video/mp4,application/pdf" @change="creativeFile = ($event.target as HTMLInputElement).files?.[0] ?? null" />
          <label v-if="creative.has_file" class="mt-1 flex items-center gap-2 text-xs"><input v-model="creative.remove_file" type="checkbox" class="h-4 w-4 rounded" /> Remove the current file</label>
        </div>
        <div>
          <label class="label">Brochure for plan (sent with its quote)</label>
          <select v-model="creative.package_id" class="input"><option :value="null">—</option><option v-for="p in data?.plans" :key="p.id" :value="p.id">{{ p.name }}</option></select>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="label">WhatsApp template (for the API later)</label><input v-model="creative.wa_template" class="input" placeholder="service_reminder_v1" /></div>
          <div><label class="label">Template language</label><input v-model="creative.wa_language" class="input" placeholder="en" /></div>
        </div>
        <label class="flex items-center gap-2"><input v-model="creative.is_active" type="checkbox" class="h-4 w-4 rounded" /> Active</label>
      </div>
      <template #footer>
        <Button variant="secondary" @click="creative = null">Cancel</Button>
        <Button :loading="busy" :disabled="!creative?.title || !creative?.caption" @click="saveCreative">Save</Button>
      </template>
    </Modal>

    <Modal :open="!!source" :title="source?.id ? 'Edit source' : 'New source'" size="sm" @close="source = null">
      <div v-if="source" class="space-y-3 text-sm">
        <input v-model="source.name" class="input" placeholder="Justdial, Instagram DM…" />
        <label class="flex items-center gap-2"><input v-model="source.is_paid" type="checkbox" class="h-4 w-4 rounded" /> Paid (track ad spend)</label>
        <label class="flex items-center gap-2"><input v-model="source.is_active" type="checkbox" class="h-4 w-4 rounded" /> Active</label>
      </div>
      <template #footer><Button variant="secondary" @click="source = null">Cancel</Button><Button :loading="busy" :disabled="!source?.name" @click="saveSource">Save</Button></template>
    </Modal>

    <Modal :open="!!campaign" :title="campaign?.id ? 'Edit campaign' : 'New campaign'" size="sm" @close="campaign = null">
      <div v-if="campaign && data" class="space-y-3 text-sm">
        <select v-model="campaign.lead_source_id" class="input"><option v-for="s in data.sources" :key="s.id" :value="s.id">{{ s.name }}</option></select>
        <input v-model="campaign.name" class="input" placeholder="Name" />
        <input v-model="campaign.external_ref" class="input" placeholder="Campaign name / id as the platform sends it" />
        <label class="flex items-center gap-2"><input v-model="campaign.is_active" type="checkbox" class="h-4 w-4 rounded" /> Active</label>
      </div>
      <template #footer><Button variant="secondary" @click="campaign = null">Cancel</Button><Button :loading="busy" :disabled="!campaign?.name" @click="saveCampaign">Save</Button></template>
    </Modal>

    <Modal :open="!!spend" :title="spend ? `Spend — ${spend.name}` : ''" size="sm" @close="spend = null">
      <div v-if="spend" class="grid grid-cols-2 gap-3 text-sm">
        <div><label class="label">Month</label><input v-model="spend.period" type="month" class="input" /></div>
        <div><label class="label">Amount ₹</label><input v-model="spend.amount" type="number" min="0" class="input" /></div>
      </div>
      <template #footer><Button variant="secondary" @click="spend = null">Cancel</Button><Button :loading="busy" :disabled="spend?.amount === ''" @click="saveSpend">Save</Button></template>
    </Modal>

    <Modal :open="!!reason" :title="reason?.id ? 'Edit reason' : 'New reason'" size="sm" @close="reason = null">
      <div v-if="reason" class="space-y-3 text-sm">
        <input v-model="reason.name" class="input" />
        <label class="flex items-center gap-2"><input v-model="reason.nurture" type="checkbox" class="h-4 w-4 rounded" /> Keep sending occasional messages ("not now")</label>
        <label class="flex items-center gap-2"><input v-model="reason.is_active" type="checkbox" class="h-4 w-4 rounded" /> Active</label>
      </div>
      <template #footer><Button variant="secondary" @click="reason = null">Cancel</Button><Button :loading="busy" :disabled="!reason?.name" @click="saveReason">Save</Button></template>
    </Modal>
  </div>
</template>
