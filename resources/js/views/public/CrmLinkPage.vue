<script setup lang="ts">
/**
 * The customer's CRM link, opened from WhatsApp without logging in:
 *  - book: pick a plan (with the price for their car), a day and a slot, drive-in /
 *    pickup / home — booked in seconds;
 *  - feedback: one-tap rating, then a Google review for happy customers;
 *  - referral: refer a friend (both get a reward);
 *  - creative: the image / video / PDF a message pointed to.
 * "Stop messages" is on every page.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { publicCrmApi } from '@/api/crm.api'

const route = useRoute()
const token = String(route.params.token)

const loading = ref(true)
const error = ref<string | null>(null)
const done = ref<string | null>(null)
const page = ref<any>(null)
const busy = ref(false)

onMounted(async () => {
  try {
    page.value = (await publicCrmApi.show(token)).data.data
    if (page.value.purpose === 'book') {
      form.value.package_id = page.value.package_id ?? page.value.plans[0]?.id ?? null
      const first = page.value.calendar.find((d: any) => d.free > 0)
      if (first) pickDay(first.date)
    }
  } catch (e: any) {
    error.value = e.response?.data?.message || 'This link is not valid any more. Message us on WhatsApp and we will send a new one.'
  } finally {
    loading.value = false
  }
})

const errorOf = (e: any) => {
  const errs = e.response?.data?.errors
  return errs ? (Object.values(errs)[0] as string[])[0] : e.response?.data?.message || 'Something went wrong, please try again.'
}

// ------------------------------------------------------------------- book
const form = ref({ package_id: null as number | null, oil_type_id: null as number | null, booking_date: '', slot_from: null as string | null, service_mode: 'drive_in', address: '', landmark: '' })
const slots = ref<{ from: string; to: string; free: number }[]>([])
const plan = computed(() => page.value?.plans?.find((p: any) => p.id === form.value.package_id))
async function pickDay(date: string) {
  form.value.booking_date = date
  form.value.slot_from = null
  slots.value = (await publicCrmApi.slots(token, date)).data.data
}
async function book() {
  busy.value = true
  error.value = null
  try {
    const res = await publicCrmApi.book(token, { ...form.value, address: form.value.service_mode === 'drive_in' ? null : form.value.address })
    const b = res.data.data
    done.value = `Booked for ${new Date(b.date).toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' })}${b.slot ? ' at ' + b.slot : ''}. We will call you to confirm. Booking no. ${b.number}.`
  } catch (e) {
    error.value = errorOf(e)
  } finally {
    busy.value = false
  }
}

// --------------------------------------------------------------- feedback
const rating = ref(0)
const comments = ref('')
const reviewUrl = ref<string | null>(null)
async function rate() {
  busy.value = true
  try {
    reviewUrl.value = (await publicCrmApi.feedback(token, rating.value, comments.value || undefined)).data.data.review_url
    done.value = rating.value >= 4 ? 'Thank you! It means a lot to us.' : 'Thank you for telling us. Our manager will call you to make it right.'
  } catch (e) {
    error.value = errorOf(e)
  } finally {
    busy.value = false
  }
}
function openReview() {
  publicCrmApi.reviewClicked(token)
  window.open(reviewUrl.value!, '_blank')
}

// --------------------------------------------------------------- referral
const friend = ref({ name: '', phone: '', car_text: '' })
async function refer() {
  busy.value = true
  try {
    await publicCrmApi.refer(token, friend.value)
    done.value = 'Thank you! We will contact your friend. You both get a reward when they service their car with us.'
  } catch (e) {
    error.value = errorOf(e)
  } finally {
    busy.value = false
  }
}

const stopped = ref(false)
async function stop() {
  await publicCrmApi.stop(token)
  stopped.value = true
}
const dayLabel = (d: string) => new Date(d).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' })
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-white border-b border-gray-200">
      <div class="mx-auto max-w-lg px-4 py-3 flex items-center gap-3">
        <img src="/images/logo.png" alt="" class="h-9" />
        <div class="min-w-0">
          <p class="font-semibold text-gray-900 truncate">{{ page?.workshop ?? 'Express Autocare' }}</p>
          <p v-if="page?.branch" class="text-xs text-gray-500 truncate">{{ page.branch.address }}</p>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-lg px-4 py-5">
      <p v-if="loading" class="py-12 text-center text-gray-500">Loading…</p>
      <p v-else-if="error && !page" class="rounded-xl bg-white p-6 text-center text-gray-700 shadow-sm">{{ error }}</p>

      <div v-else-if="done" class="rounded-2xl bg-white p-6 text-center shadow-sm">
        <p class="text-4xl">✅</p>
        <p class="mt-3 text-gray-800">{{ done }}</p>
        <button v-if="reviewUrl" type="button" class="mt-5 w-full rounded-xl bg-primary-600 py-3 font-semibold text-white" @click="openReview">⭐ Leave us a Google review</button>
      </div>

      <template v-else-if="page">
        <h1 class="mb-1 text-xl font-bold text-gray-900">Hi {{ page.name?.split(' ')[0] ?? 'there' }}!</h1>

        <!-- Book -->
        <template v-if="page.purpose === 'book'">
          <p class="mb-4 text-sm text-gray-600">Book a service for your {{ page.car }} in under a minute.</p>
          <div v-if="page.upcoming" class="mb-4 rounded-xl bg-blue-50 p-3 text-sm text-blue-900">You already have booking {{ page.upcoming.number }} on {{ dayLabel(page.upcoming.date) }} {{ page.upcoming.slot }}. Book again only to add another visit.</div>

          <section class="mb-4">
            <h2 class="mb-2 text-sm font-semibold text-gray-700">1. Service</h2>
            <div class="space-y-2">
              <button v-for="p in page.plans" :key="p.id" type="button" class="w-full rounded-xl bg-white p-3 text-left shadow-sm ring-2" :class="form.package_id === p.id ? 'ring-primary-500' : 'ring-transparent'" @click="form.package_id = p.id; form.oil_type_id = null">
                <span class="flex justify-between"><b>{{ p.name }}</b><span class="text-primary-700 font-semibold">from ₹{{ Math.round(p.from_price).toLocaleString('en-IN') }}</span></span>
                <span v-if="form.package_id === p.id && p.features.length" class="mt-1 block text-xs text-gray-600">{{ p.features.join(' · ') }}</span>
              </button>
              <button type="button" class="w-full rounded-xl bg-white p-3 text-left shadow-sm ring-2" :class="form.package_id === null ? 'ring-primary-500' : 'ring-transparent'" @click="form.package_id = null">
                <b>Repair / not sure</b><span class="block text-xs text-gray-500">We'll check the car and quote before any work.</span>
              </button>
            </div>
            <div v-if="plan?.oils?.length" class="mt-2">
              <p class="mb-1 text-xs text-gray-500">Engine oil</p>
              <div class="grid grid-cols-2 gap-2">
                <button v-for="o in plan.oils" :key="o.oil_type_id" type="button" class="rounded-lg bg-white p-2 text-sm ring-1" :class="form.oil_type_id === o.oil_type_id ? 'ring-2 ring-primary-500' : 'ring-gray-200'" @click="form.oil_type_id = o.oil_type_id">
                  {{ o.name }}<span class="block font-semibold">₹{{ Math.round(o.price).toLocaleString('en-IN') }}</span>
                </button>
              </div>
            </div>
          </section>

          <section class="mb-4">
            <h2 class="mb-2 text-sm font-semibold text-gray-700">2. Day & time</h2>
            <div class="flex gap-2 overflow-x-auto pb-1">
              <button v-for="d in page.calendar" :key="d.date" type="button" :disabled="d.free < 1" class="shrink-0 rounded-xl bg-white px-3 py-2 text-center text-sm shadow-sm ring-2 disabled:opacity-40" :class="form.booking_date === d.date ? 'ring-primary-500' : 'ring-transparent'" @click="pickDay(d.date)">
                {{ dayLabel(d.date) }}<span class="block text-[11px] text-gray-500">{{ d.free < 1 ? 'Full' : 'Open' }}</span>
              </button>
            </div>
            <div v-if="slots.length" class="mt-2 grid grid-cols-3 gap-2">
              <button v-for="s in slots" :key="s.from" type="button" :disabled="s.free < 1" class="rounded-lg bg-white py-2 text-sm ring-1 disabled:opacity-40" :class="form.slot_from === s.from ? 'ring-2 ring-primary-500 font-semibold' : 'ring-gray-200'" @click="form.slot_from = s.from">{{ s.from }}</button>
            </div>
          </section>

          <section class="mb-4">
            <h2 class="mb-2 text-sm font-semibold text-gray-700">3. How</h2>
            <div class="space-y-2">
              <label v-for="[k, label] in (Object.entries(page.modes) as [string, string][])" :key="k" class="flex items-center gap-3 rounded-xl bg-white p-3 shadow-sm" :class="k === 'home' && plan && !plan.home_service ? 'opacity-40' : ''">
                <input v-model="form.service_mode" type="radio" :value="k" :disabled="k === 'home' && plan && !plan.home_service" /> {{ label }}
              </label>
            </div>
            <template v-if="form.service_mode !== 'drive_in'">
              <textarea v-model="form.address" rows="2" class="input mt-2" placeholder="Full address" />
              <input v-model="form.landmark" class="input mt-2" placeholder="Landmark" />
            </template>
          </section>

          <p v-if="error" class="mb-3 rounded-lg bg-red-50 p-2 text-sm text-red-700">{{ error }}</p>
          <button type="button" class="w-full rounded-xl bg-primary-600 py-3.5 font-semibold text-white disabled:opacity-50" :disabled="busy || !form.booking_date || (slots.length > 0 && !form.slot_from) || (form.service_mode !== 'drive_in' && !form.address.trim())" @click="book">
            {{ busy ? 'Booking…' : 'Book now' }}
          </button>
        </template>

        <!-- Feedback -->
        <template v-else-if="page.purpose === 'feedback'">
          <p class="mb-4 text-sm text-gray-600">How was the service of your {{ page.car }}<template v-if="page.job_number"> ({{ page.job_number }})</template>?</p>
          <div class="mb-4 flex justify-center gap-2 text-4xl">
            <button v-for="n in 5" :key="n" type="button" :class="n <= rating ? 'text-amber-400' : 'text-gray-300'" @click="rating = n">★</button>
          </div>
          <textarea v-model="comments" rows="3" class="input mb-3" :placeholder="rating && rating <= 3 ? 'What went wrong? We will make it right.' : 'Anything you would like to tell us (optional)'" />
          <p v-if="error" class="mb-3 rounded-lg bg-red-50 p-2 text-sm text-red-700">{{ error }}</p>
          <button type="button" class="w-full rounded-xl bg-primary-600 py-3.5 font-semibold text-white disabled:opacity-50" :disabled="!rating || busy" @click="rate">Send</button>
        </template>

        <!-- Referral -->
        <template v-else-if="page.purpose === 'referral'">
          <p class="mb-4 text-sm text-gray-600">Know someone whose car needs care? Refer them — when they service with us, you get a reward credited to your account.</p>
          <div class="space-y-3">
            <input v-model="friend.name" class="input" placeholder="Friend's name" />
            <input v-model="friend.phone" type="tel" class="input" placeholder="Friend's mobile number" />
            <input v-model="friend.car_text" class="input" placeholder="Their car (optional)" />
          </div>
          <p v-if="error" class="mt-3 rounded-lg bg-red-50 p-2 text-sm text-red-700">{{ error }}</p>
          <button type="button" class="mt-4 w-full rounded-xl bg-primary-600 py-3.5 font-semibold text-white disabled:opacity-50" :disabled="busy || !friend.name || friend.phone.replace(/\D/g, '').length < 10" @click="refer">Refer my friend</button>
        </template>

        <!-- Creative -->
        <template v-else>
          <h2 v-if="page.title" class="mb-3 font-semibold text-gray-800">{{ page.title }}</h2>
          <img v-if="page.kind === 'image' && page.media_url" :src="page.media_url" alt="" class="w-full rounded-xl shadow-sm" />
          <video v-else-if="page.kind === 'video' && page.media_url" :src="page.media_url" controls playsinline class="w-full rounded-xl shadow-sm" />
          <a v-else-if="page.kind === 'pdf' && page.media_url" :href="page.media_url" target="_blank" class="block rounded-xl bg-white p-4 text-center font-semibold text-primary-700 shadow-sm">Open the PDF</a>
          <p v-if="page.caption" class="mt-3 whitespace-pre-line text-sm text-gray-700">{{ page.caption }}</p>
        </template>

        <a v-if="page.branch?.phone" :href="`tel:${page.branch.phone}`" class="mt-6 block text-center text-sm text-gray-600">Questions? Call {{ page.branch.phone }}</a>
        <p class="mt-6 text-center text-xs text-gray-400">
          <span v-if="stopped">You will not get offers from us any more.</span>
          <button v-else type="button" class="underline" @click="stop">Stop offer messages</button>
        </p>
      </template>
    </main>
  </div>
</template>
