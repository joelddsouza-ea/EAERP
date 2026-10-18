<script setup lang="ts">
/**
 * Settings › WhatsApp (owner). Share links (the advisor sends from the workshop phone) stay
 * the default. With Interakt switched on, each kind of message that has an approved
 * template goes out by itself; the others keep the share link. The screen shows the text to
 * submit to Interakt for each kind, with {{1}}, {{2}} … in the right order.
 */
import { ref, computed, onMounted } from 'vue'
import { ClipboardDocumentIcon, CheckCircleIcon } from '@heroicons/vue/24/outline'
import apiClient from '@/api/client'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Spinner from '@/components/ui/Spinner.vue'

interface State {
  provider: 'share' | 'interakt'
  api_on: boolean
  api_key_masked: string | null
  webhook_url: string
  enabled_at: string | null
  mapped: number
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
}

// Interakt's placeholders (kept out of the template so Vue doesn't read the braces)
const P1 = '{{1}}'
const P2 = '{{2}}'

const toast = useToast()
const state = ref<State | null>(null)
const provider = ref<'share' | 'interakt'>('share')
const apiKey = ref('')
const busy = ref(false)

const rows = ref<TemplateRow[]>([])
const savedRows = ref<string>('[]')
const open = ref<string | null>(null)
const changedRows = computed(() => {
  const before = JSON.parse(savedRows.value) as TemplateRow[]
  return rows.value.filter((r) => {
    const b = before.find((x) => x.key === r.key)
    return !b || ['provider_name', 'language', 'body_params', 'header_type', 'button_param', 'is_active'].some((f) => JSON.stringify((r as any)[f]) !== JSON.stringify((b as any)[f]))
  })
})
const groups = computed(() => {
  const out: Record<string, TemplateRow[]> = {}
  for (const r of rows.value) (out[r.group] ??= []).push(r)
  return out
})

async function load() {
  try {
    const [s, t] = await Promise.all([apiClient.get('/settings/whatsapp'), apiClient.get('/settings/whatsapp/templates')])
    state.value = s.data.data
    provider.value = state.value!.provider
    setRows(t.data.data)
  } catch (err) {
    toast.apiError(err)
  }
}

function setRows(data: TemplateRow[]) {
  rows.value = data.map((r) => ({ ...r, body_params: [...r.body_params] }))
  savedRows.value = JSON.stringify(rows.value)
}

async function saveConnection() {
  busy.value = true
  try {
    const res = await apiClient.put('/settings/whatsapp', { provider: provider.value, api_key: apiKey.value || null })
    state.value = res.data.data
    apiKey.value = ''
    toast.success(res.data.message ?? 'Saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

async function clearKey() {
  busy.value = true
  try {
    const res = await apiClient.put('/settings/whatsapp', { provider: 'share', clear_api_key: true })
    state.value = res.data.data
    provider.value = 'share'
    toast.success('API key removed; share links in use')
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
    state.value = (await apiClient.get('/settings/whatsapp')).data.data
    toast.success('Templates saved')
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

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

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-4xl">
    <div class="mb-4">
      <h1 class="text-2xl font-bold text-gray-900">WhatsApp</h1>
      <p class="text-sm text-gray-500">How customer and lead messages are sent.</p>
    </div>

    <div v-if="!state" class="flex justify-center py-10"><Spinner size="lg" /></div>
    <template v-else>
      <Card class="mb-4">
        <div class="space-y-3 text-sm">
          <label class="flex cursor-pointer items-start gap-3 rounded-lg p-2 ring-1" :class="provider === 'share' ? 'ring-[#c4161c] bg-red-50/40' : 'ring-gray-200'">
            <input v-model="provider" type="radio" value="share" class="mt-1" />
            <span>
              <span class="block font-semibold text-gray-900">Share links (current way)</span>
              <span class="text-gray-600">Messages wait in "Messages to send" and on the job card; staff tap to open WhatsApp on the workshop phone and send.</span>
            </span>
          </label>
          <label class="flex cursor-pointer items-start gap-3 rounded-lg p-2 ring-1" :class="provider === 'interakt' ? 'ring-[#c4161c] bg-red-50/40' : 'ring-gray-200'">
            <input v-model="provider" type="radio" value="interakt" class="mt-1" />
            <span>
              <span class="block font-semibold text-gray-900">Interakt (WhatsApp Business API)</span>
              <span class="text-gray-600">
                Messages with an approved template below go out by themselves, with delivery and read ticks. Anything without a template,
                or that Interakt refuses, stays in "Messages to send" for the share link. Messages already waiting are not sent.
              </span>
            </span>
          </label>

          <div v-if="provider === 'interakt' || state.api_key_masked" class="grid gap-3 sm:grid-cols-2">
            <label class="block">
              <span class="text-gray-600">API key (Interakt › Settings › Developer Settings)</span>
              <input v-model="apiKey" type="password" autocomplete="off" class="input mt-1" :placeholder="state.api_key_masked ?? 'Paste the secret key'" />
              <button v-if="state.api_key_masked" type="button" class="mt-1 text-xs text-red-700 underline" @click="clearKey">Remove the key</button>
            </label>
            <div>
              <span class="text-gray-600">Webhook URL (paste in Interakt › Developer Settings › Webhooks)</span>
              <div class="mt-1 flex items-center gap-1">
                <input :value="state.webhook_url" readonly class="input flex-1 text-xs" />
                <button type="button" class="rounded p-2 text-gray-500 hover:bg-gray-100" aria-label="Copy" @click="copy(state.webhook_url)"><ClipboardDocumentIcon class="h-4 w-4" /></button>
              </div>
              <p class="mt-1 text-xs text-gray-500">Gives delivered / read ticks and logs customer replies on their lead. A reply of STOP ends marketing messages.</p>
            </div>
          </div>

          <div class="flex items-center justify-between gap-3 border-t border-gray-100 pt-3">
            <p class="text-xs text-gray-500">
              Now: <strong>{{ state.api_on ? 'Interakt on' : 'Share links' }}</strong>
              <template v-if="state.api_on"> · {{ state.mapped }} message kind(s) with a template</template>
            </p>
            <Button :loading="busy" :disabled="provider === state.provider && !apiKey" @click="saveConnection">Save</Button>
          </div>
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

      <Card :padding="false">
        <div class="border-b border-gray-100 p-4">
          <h2 class="font-semibold text-gray-900">Templates</h2>
          <p class="text-sm text-gray-500">
            For each message you want sent automatically: copy "Text for Interakt", create a template with it in Interakt (Utility for job updates,
            Marketing for creatives), and once it is approved enter its name here. The values fill {{ P1 }}, {{ P2 }} … in this order.
          </p>
        </div>
        <div v-for="(list, group) in groups" :key="group">
          <p class="bg-gray-50 px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ group }}</p>
          <ul class="divide-y divide-gray-100">
            <li v-for="r in list" :key="r.key" class="px-4 py-2.5">
              <button type="button" class="flex w-full items-center gap-2 text-left" @click="open = open === r.key ? null : r.key">
                <CheckCircleIcon class="h-5 w-5 shrink-0" :class="r.mapped ? 'text-green-600' : 'text-gray-300'" />
                <span class="flex-1 text-sm font-medium text-gray-900">{{ r.label }}</span>
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
                    <span class="text-gray-600">Approved template name</span>
                    <input v-model.trim="r.provider_name" class="input mt-1" placeholder="lower_case_name" />
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
    </template>
  </div>
</template>
