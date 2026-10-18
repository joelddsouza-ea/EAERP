<script setup lang="ts">
/**
 * The assistant: "What's wrong with my car?" (describe it, add photos of a warning light or
 * leak) and "Accident help" (damage photos → rough repair range, claim steps, get inspected).
 * Answers come from the workshop's own price list; the workshop confirms on inspection.
 */
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { SparklesIcon, CameraIcon, XMarkIcon, ExclamationTriangleIcon, CheckCircleIcon, PhoneIcon } from '@heroicons/vue/24/outline'
import api, { message, money, type Home } from '../api'
import { toast } from '../ui'

type Mode = 'diagnose' | 'accident'

const router = useRouter()
const mode = ref<Mode>('diagnose')
const status = ref<{ enabled: boolean; left_today: number; paused: boolean } | null>(null)
const cars = ref<Home['vehicles']>([])
const support = ref<string | null>(null)
const vehicleId = ref<number | null>(null)
const question = ref('')
const photos = ref<{ file: File; url: string }[]>([])
const busy = ref(false)
const result = ref<{ id: number; feature: Mode; answer: any; error: string | null } | null>(null)
const leadSent = ref(false)

const URGENCY: Record<string, { label: string; cls: string }> = {
  ok_to_drive: { label: 'OK to drive', cls: 'bg-green-50 text-green-800 ring-green-200' },
  book_soon: { label: 'Book soon', cls: 'bg-amber-50 text-amber-900 ring-amber-200' },
  stop_driving: { label: 'Stop driving', cls: 'bg-red-50 text-red-800 ring-red-200' },
  yes: { label: 'Looks safe to drive', cls: 'bg-green-50 text-green-800 ring-green-200' },
  check_first: { label: 'Check before driving', cls: 'bg-amber-50 text-amber-900 ring-amber-200' },
  no: { label: 'Do not drive', cls: 'bg-red-50 text-red-800 ring-red-200' },
}

const urgency = computed(() => URGENCY[result.value?.answer?.urgency ?? result.value?.answer?.safe_to_drive ?? ''] ?? null)

onMounted(async () => {
  try {
    const home = (await api.get('/home')).data.data as Home
    status.value = home.assistant
    cars.value = home.vehicles
    support.value = home.support_phone
    vehicleId.value = cars.value[0]?.id ?? null
  } catch (err) {
    toast(message(err), 'error')
  }
})

function addPhotos(e: Event) {
  const files = Array.from((e.target as HTMLInputElement).files ?? [])
  for (const f of files.slice(0, 3 - photos.value.length)) photos.value.push({ file: f, url: URL.createObjectURL(f) })
  ;(e.target as HTMLInputElement).value = ''
}

async function ask() {
  busy.value = true
  result.value = null
  leadSent.value = false
  try {
    const form = new FormData()
    form.append('question', question.value)
    if (vehicleId.value) form.append('vehicle_id', String(vehicleId.value))
    photos.value.forEach((p) => form.append('photos[]', p.file))
    result.value = (await api.post(`/assistant/${mode.value}`, form, { timeout: 120000 })).data.data
    if (status.value) status.value.left_today = Math.max(0, status.value.left_today - 1)
  } catch (err) {
    toast(message(err), 'error')
  } finally {
    busy.value = false
  }
}

function book() {
  const a = result.value?.answer
  const notes = a ? `Assistant: ${a.summary}${a.suggested_services?.length ? ' Suggested: ' + a.suggested_services.map((s: any) => s.name).join(', ') : ''}` : ''
  router.push({ path: '/book', query: { vehicle: vehicleId.value ?? undefined, notes: notes.slice(0, 900), ai: result.value?.id, service: mode.value === 'accident' ? 'body_shop' : undefined } })
}

async function inspection() {
  try {
    toast((await api.post(`/assistant/${result.value!.id}/inspection`)).data.message)
    leadSent.value = true
  } catch (err) {
    toast(message(err), 'error')
  }
}

function reset() {
  result.value = null
  question.value = ''
  photos.value = []
}
</script>

<template>
  <div>
    <h1 class="flex items-center gap-2 text-xl font-bold text-gray-900"><SparklesIcon class="h-6 w-6 text-primary-600" /> Ask about your car</h1>

    <p v-if="status && !status.enabled" class="mt-4 rounded-2xl bg-white p-4 text-sm text-gray-600 ring-1 ring-gray-200/80">
      The assistant isn't available right now.
      <a v-if="support" :href="`tel:${support}`" class="font-medium text-primary-700">Call us: {{ support }}</a>
    </p>

    <template v-else-if="status">
      <div class="mt-4 grid grid-cols-2 rounded-xl bg-gray-200 p-1 text-sm font-medium">
        <button type="button" class="rounded-lg py-2" :class="mode === 'diagnose' ? 'bg-white shadow-sm' : 'text-gray-600'" @click="mode = 'diagnose'; reset()">What's wrong?</button>
        <button type="button" class="rounded-lg py-2" :class="mode === 'accident' ? 'bg-white shadow-sm' : 'text-gray-600'" @click="mode = 'accident'; reset()">Accident help</button>
      </div>

      <form v-if="!result" class="mt-4 space-y-3 rounded-2xl bg-white p-4 ring-1 ring-gray-200/80" @submit.prevent="ask">
        <label v-if="cars.length" class="block text-sm text-gray-700">Car
          <select v-model="vehicleId" class="mt-1 w-full rounded-xl border-0 px-3 py-2.5 ring-1 ring-gray-300">
            <option v-for="c in cars" :key="c.id" :value="c.id">{{ c.registration_no }} · {{ c.car }}</option>
          </select>
        </label>
        <label class="block text-sm text-gray-700">{{ mode === 'diagnose' ? 'What are you noticing?' : 'What happened?' }}
          <textarea v-model="question" rows="4" class="mt-1 w-full rounded-xl border-0 px-3 py-2 ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600"
            :placeholder="mode === 'diagnose' ? 'e.g. A squeaking noise when I brake, and the car pulls to the left' : 'e.g. Another car hit my front bumper in the parking'"></textarea>
        </label>
        <div>
          <p class="text-sm text-gray-700">{{ mode === 'diagnose' ? 'Photos (optional): warning light, leak, part' : 'Photos of the damage' }}</p>
          <div class="mt-1 flex gap-2">
            <div v-for="(p, i) in photos" :key="i" class="relative h-20 w-20 overflow-hidden rounded-lg">
              <img :src="p.url" alt="" class="h-full w-full object-cover" />
              <button type="button" class="absolute right-0.5 top-0.5 rounded-full bg-black/60 p-0.5 text-white" aria-label="Remove" @click="photos.splice(i, 1)"><XMarkIcon class="h-4 w-4" /></button>
            </div>
            <label v-if="photos.length < 3" class="flex h-20 w-20 cursor-pointer flex-col items-center justify-center rounded-lg text-xs text-gray-500 ring-1 ring-dashed ring-gray-300">
              <CameraIcon class="h-6 w-6" /> Add
              <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="addPhotos" />
            </label>
          </div>
        </div>
        <button type="submit" :disabled="busy || question.trim().length < 5 || status.paused || status.left_today < 1" class="w-full rounded-xl bg-primary-600 py-3 font-semibold text-white disabled:opacity-50">
          {{ busy ? 'Looking at it… (up to a minute)' : 'Ask' }}
        </button>
        <p class="text-center text-xs text-gray-500">
          <template v-if="status.paused">The assistant is resting for now. Please call us.</template>
          <template v-else>{{ status.left_today }} question(s) left today. The workshop confirms everything on inspection.</template>
        </p>
      </form>

      <!-- Answer -->
      <section v-else class="mt-4 space-y-3">
        <p v-if="result.error && !result.answer" class="rounded-2xl bg-white p-4 text-sm text-gray-700 ring-1 ring-gray-200/80">{{ result.error }} Please call us.</p>
        <template v-if="result.answer">
          <div v-if="urgency" class="flex items-start gap-2 rounded-2xl p-4 ring-1" :class="urgency.cls">
            <ExclamationTriangleIcon v-if="['stop_driving', 'no'].includes(result.answer.urgency ?? result.answer.safe_to_drive)" class="h-6 w-6 shrink-0" />
            <CheckCircleIcon v-else class="h-6 w-6 shrink-0" />
            <div>
              <p class="font-semibold">{{ urgency.label }}</p>
              <p v-if="result.answer.urgency_advice" class="text-sm">{{ result.answer.urgency_advice }}</p>
            </div>
          </div>

          <div class="rounded-2xl bg-white p-4 ring-1 ring-gray-200/80">
            <p class="text-gray-800">{{ result.answer.summary }}</p>

            <template v-if="result.feature === 'diagnose'">
              <h3 v-if="result.answer.likely_causes?.length" class="mt-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Likely causes</h3>
              <ul class="mt-1 space-y-2">
                <li v-for="(c, i) in result.answer.likely_causes" :key="i" class="text-sm"><span class="font-medium text-gray-900">{{ c.cause }}</span> <span class="text-gray-600">— {{ c.why }}</span></li>
              </ul>
              <h3 v-if="result.answer.suggested_services?.length" class="mt-4 text-sm font-semibold uppercase tracking-wide text-gray-500">What would fix it</h3>
              <ul class="mt-1 space-y-2">
                <li v-for="(s, i) in result.answer.suggested_services" :key="i" class="flex items-start justify-between gap-3 text-sm">
                  <span><span class="font-medium text-gray-900">{{ s.name }}</span><span v-if="s.note" class="block text-xs text-gray-500">{{ s.note }}</span></span>
                  <span class="shrink-0 font-semibold text-gray-900">{{ s.price_min ? money(s.price_min) + (s.price_max && s.price_max !== s.price_min ? '–' + money(s.price_max).replace('₹ ', '') : '') : 'After inspection' }}</span>
                </li>
              </ul>
              <h3 v-if="result.answer.questions_to_check?.length" class="mt-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Things to check</h3>
              <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-gray-700">
                <li v-for="(q, i) in result.answer.questions_to_check" :key="i">{{ q }}</li>
              </ul>
            </template>

            <template v-else>
              <h3 v-if="result.answer.damage?.length" class="mt-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Damage seen</h3>
              <ul class="mt-1 list-disc pl-5 text-sm text-gray-700"><li v-for="(d, i) in result.answer.damage" :key="i">{{ d }}</li></ul>
              <p class="mt-3 text-sm"><span class="font-semibold text-gray-900">Rough repair cost: </span>
                <template v-if="result.answer.estimate_min">{{ money(result.answer.estimate_min) }}–{{ money(result.answer.estimate_max).replace('₹ ', '') }}</template>
                <template v-else>after inspection</template>
                <span class="block text-xs text-gray-500">{{ result.answer.estimate_note }}</span>
              </p>
              <h3 class="mt-4 text-sm font-semibold uppercase tracking-wide text-gray-500">Insurance claim</h3>
              <p class="mt-1 text-sm text-gray-700">{{ result.answer.claim_advice }}</p>
              <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-700"><li v-for="(s, i) in result.answer.claim_steps" :key="i">{{ s }}</li></ol>
              <p v-if="result.answer.documents_needed?.length" class="mt-2 text-xs text-gray-500">Keep ready: {{ result.answer.documents_needed.join(', ') }}</p>
            </template>
          </div>

          <button v-if="result.feature === 'accident'" type="button" :disabled="leadSent" class="w-full rounded-xl bg-primary-600 py-3 font-semibold text-white disabled:opacity-60" @click="inspection">
            {{ leadSent ? 'Our body shop team will call you' : 'Get it inspected (we call you)' }}
          </button>
          <button type="button" class="w-full rounded-xl py-3 font-semibold ring-1" :class="result.feature === 'accident' ? 'text-primary-700 ring-primary-600' : 'bg-primary-600 text-white ring-primary-600'" @click="book">Book a visit</button>
          <a v-if="support" :href="`tel:${support}`" class="flex items-center justify-center gap-2 rounded-xl py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300"><PhoneIcon class="h-4 w-4" /> Talk to us</a>
        </template>
        <button type="button" class="w-full py-2 text-sm text-gray-600" @click="reset">Ask something else</button>
        <p class="text-center text-xs text-gray-400">An automatic assistant: a guide, not a diagnosis. Prices are ranges from our price list; the final quote comes after inspection.</p>
      </section>
    </template>
  </div>
</template>
