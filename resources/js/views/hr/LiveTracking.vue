<script setup lang="ts">
/**
 * Owner / managers: where everyone who punched in today is, on a map (refreshes every
 * 30 s), and any employee's route for a day: distance, stops and gaps with no signal.
 * Map: OpenStreetMap through Leaflet (no key, no cost).
 */
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick, shallowRef } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { ArrowPathIcon, BoltIcon, TruckIcon, DevicePhoneMobileIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import {
  hrApi, localDate, LIVE_STATE_LABELS, LIVE_STATE_COLORS, GAP_LABELS,
  type LiveMap, type LivePerson, type Trail,
} from '@/api/hr.api'
import { useBranchStore } from '@/stores/branch.store'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Spinner from '@/components/ui/Spinner.vue'

const toast = useToast()
const branchStore = useBranchStore()

const data = ref<LiveMap | null>(null)
const loading = ref(true)
const filter = ref<'all' | 'field' | 'problems'>('all')

const mapEl = ref<HTMLDivElement | null>(null)
const map = shallowRef<L.Map | null>(null)
const liveLayer = L.layerGroup()
const branchLayer = L.layerGroup()
const trailLayer = L.layerGroup()
let fitted = false

const people = computed(() => {
  const all = data.value?.people ?? []
  if (filter.value === 'field') return all.filter((p) => p.state !== 'punched_out' && !p.at_workshop && p.lat !== null)
  if (filter.value === 'problems') return all.filter((p) => ['no_signal', 'waiting', 'not_tracked'].includes(p.state) || p.gap_reason)
  return all
})

const counts = computed(() => {
  const all = data.value?.people ?? []
  const on = all.filter((p) => p.state !== 'punched_out')
  return {
    in: on.length,
    field: on.filter((p) => !p.at_workshop && p.lat !== null).length,
    problems: on.filter((p) => ['no_signal', 'waiting'].includes(p.state) || p.gap_reason).length,
  }
})

function ago(p: LivePerson) {
  if (p.minutes_ago === null) return 'no position yet'
  if (p.minutes_ago < 1) return 'just now'
  if (p.minutes_ago < 60) return `${p.minutes_ago} min ago`
  return `${Math.floor(p.minutes_ago / 60)} h ${p.minutes_ago % 60} min ago`
}

function initials(name: string) {
  return name.split(/\s+/).map((s) => s[0]).slice(0, 2).join('').toUpperCase()
}

function marker(p: LivePerson) {
  const color = LIVE_STATE_COLORS[p.state]
  return L.marker([p.lat!, p.lng!], {
    icon: L.divIcon({
      className: '',
      html: `<div style="background:${color}" class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-white text-[11px] font-bold text-white shadow-md">${initials(p.name)}</div>`,
      iconSize: [32, 32],
      iconAnchor: [16, 16],
    }),
    title: p.name,
  })
    .bindTooltip(`${p.name} · ${LIVE_STATE_LABELS[p.state]} · ${ago(p)}`)
    .on('click', () => openTrail(p))
}

function drawLive() {
  if (!map.value || !data.value) return
  branchLayer.clearLayers()
  for (const b of data.value.branches) {
    L.circle([b.lat, b.lng], { radius: b.radius_m, color: '#c4161c', weight: 1, fillOpacity: 0.08 }).addTo(branchLayer)
    L.marker([b.lat, b.lng], {
      icon: L.divIcon({ className: '', html: `<div class="whitespace-nowrap rounded bg-white/90 px-1.5 py-0.5 text-[11px] font-semibold text-[#c4161c] shadow">${b.name}</div>`, iconAnchor: [0, 0] }),
      interactive: false,
    }).addTo(branchLayer)
  }

  liveLayer.clearLayers()
  const points: L.LatLngExpression[] = data.value.branches.map((b) => [b.lat, b.lng])
  for (const p of people.value) {
    if (p.lat === null || p.lng === null) continue
    marker(p).addTo(liveLayer)
    points.push([p.lat, p.lng])
  }
  if (!fitted && points.length && !trail.value) {
    map.value.fitBounds(L.latLngBounds(points), { padding: [40, 40], maxZoom: 15 })
    fitted = true
  }
}

async function load(quiet = false) {
  if (!quiet) loading.value = true
  try {
    data.value = (await hrApi.liveMap(branchStore.currentBranchId)).data.data
    drawLive()
  } catch (err) {
    if (!quiet) toast.apiError(err)
  } finally {
    loading.value = false
  }
}

function focus(p: LivePerson) {
  if (p.lat !== null && p.lng !== null) map.value?.setView([p.lat, p.lng], 16)
}

// ------------------------------------------------------------------ a person's day
const trail = ref<Trail | null>(null)
const trailPerson = ref<{ id: number; name: string } | null>(null)
const trailDate = ref(localDate())
const trailLoading = ref(false)
const scrub = ref(100)

async function openTrail(p: { user_id: number; name: string }) {
  trailPerson.value = { id: p.user_id, name: p.name }
  await loadTrail()
}

async function loadTrail() {
  if (!trailPerson.value) return
  trailLoading.value = true
  try {
    trail.value = (await hrApi.trail(trailPerson.value.id, trailDate.value)).data.data
    scrub.value = 100
    drawTrail(true)
    // No route that day: at least show where they are now
    if (!trail.value?.points.length) {
      const p = data.value?.people.find((x) => x.user_id === trailPerson.value?.id)
      if (p) focus(p)
    }
  } catch (err) {
    toast.apiError(err)
  } finally {
    trailLoading.value = false
  }
}
watch(trailDate, loadTrail)

const scrubPoint = computed(() => {
  const pts = trail.value?.points ?? []
  if (!pts.length) return null
  return pts[Math.min(pts.length - 1, Math.round(((pts.length - 1) * scrub.value) / 100))]
})

function drawTrail(fit = false) {
  trailLayer.clearLayers()
  if (!map.value || !trail.value) return
  const pts = trail.value.points
  const line: L.LatLngExpression[] = pts.map((p) => [p.lat, p.lng])
  if (line.length) {
    L.polyline(line, { color: '#c4161c', weight: 4, opacity: 0.75 }).addTo(trailLayer)
    L.circleMarker(line[0], { radius: 6, color: '#16a34a', fillOpacity: 1 }).bindTooltip(`Start ${pts[0].t.slice(11, 16)}`).addTo(trailLayer)
  }
  for (const s of trail.value.stops) {
    L.circleMarker([s.lat, s.lng], { radius: 8, color: '#d97706', fillColor: '#fbbf24', fillOpacity: 0.9 })
      .bindTooltip(`Stopped ${s.from}–${s.to} (${s.minutes} min)`)
      .addTo(trailLayer)
  }
  for (const p of trail.value.punches) {
    if (p.lat === null || p.lng === null) continue
    L.circleMarker([p.lat, p.lng], { radius: 7, color: '#111827', fillColor: p.type === 'in' ? '#22c55e' : '#ef4444', fillOpacity: 1 })
      .bindTooltip(`Punch ${p.type} ${p.time}${p.branch ? ' · ' + p.branch : ''}`)
      .addTo(trailLayer)
  }
  if (scrubPoint.value) {
    L.circleMarker([scrubPoint.value.lat, scrubPoint.value.lng], { radius: 9, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1 }).addTo(trailLayer)
  }
  if (fit && line.length) map.value.fitBounds(L.latLngBounds(line), { padding: [40, 40], maxZoom: 16 })
}
watch(scrub, () => drawTrail())

function closeTrail() {
  trail.value = null
  trailPerson.value = null
  trailLayer.clearLayers()
}

const time = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

// ------------------------------------------------------------------ lifecycle
let timer: ReturnType<typeof setInterval> | null = null
onMounted(async () => {
  await nextTick()
  if (mapEl.value) {
    map.value = L.map(mapEl.value, { zoomControl: true }).setView([19.076, 72.8777], 11)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map.value)
    branchLayer.addTo(map.value)
    trailLayer.addTo(map.value)
    liveLayer.addTo(map.value)
  }
  await load()
  timer = setInterval(() => load(true), 30_000)
})
onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
  map.value?.remove()
})
watch(() => branchStore.currentBranchId, () => {
  fitted = false
  void load()
})
watch(filter, drawLive)
</script>

<template>
  <div>
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Live staff map</h1>
        <p class="text-sm text-gray-500">Everyone punched in today. Positions refresh every 30 s; tap a person for their route.</p>
      </div>
      <button type="button" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900" @click="load()">
        <ArrowPathIcon class="h-4 w-4" :class="loading ? 'animate-spin' : ''" /> Refresh
      </button>
    </div>

    <p v-if="data && !data.tracking_enabled" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
      Live location is switched off in Settings › HR & payroll › Pay, OT & Statutory.
    </p>

    <div class="mb-3 grid grid-cols-3 gap-2 text-center text-sm">
      <button type="button" class="rounded-xl p-2 ring-1" :class="filter === 'all' ? 'bg-white ring-gray-400' : 'bg-white ring-gray-200'" @click="filter = 'all'">
        <p class="text-xs text-gray-500">Punched in</p><p class="text-lg font-semibold">{{ counts.in }}</p>
      </button>
      <button type="button" class="rounded-xl p-2 ring-1" :class="filter === 'field' ? 'bg-green-50 ring-green-400' : 'bg-green-50 ring-transparent'" @click="filter = 'field'">
        <p class="text-xs text-gray-500">In the field</p><p class="text-lg font-semibold">{{ counts.field }}</p>
      </button>
      <button type="button" class="rounded-xl p-2 ring-1" :class="filter === 'problems' ? 'bg-red-50 ring-red-400' : 'bg-red-50 ring-transparent'" @click="filter = 'problems'">
        <p class="text-xs text-gray-500">No signal</p><p class="text-lg font-semibold">{{ counts.problems }}</p>
      </button>
    </div>

    <div class="grid gap-3 lg:grid-cols-[1fr_340px]">
      <div class="relative">
        <div ref="mapEl" class="z-0 h-[55vh] w-full overflow-hidden rounded-xl ring-1 ring-gray-200 lg:h-[calc(100vh-15rem)]"></div>

        <!-- A person's day -->
        <div v-if="trailPerson" class="absolute inset-x-2 bottom-2 z-[500] rounded-xl bg-white p-3 shadow-lg ring-1 ring-gray-200 sm:left-auto sm:w-80">
          <div class="mb-2 flex items-center gap-2">
            <p class="flex-1 font-semibold text-gray-900">{{ trailPerson.name }}</p>
            <input v-model="trailDate" type="date" class="input w-auto py-1 text-sm" :max="localDate()" />
            <button type="button" class="text-gray-400 hover:text-gray-700" aria-label="Close" @click="closeTrail"><XMarkIcon class="h-5 w-5" /></button>
          </div>
          <div v-if="trailLoading" class="flex justify-center py-4"><Spinner /></div>
          <template v-else-if="trail">
            <p v-if="!trail.points.length" class="text-sm text-gray-500">No positions on this day.</p>
            <template v-else>
              <p class="text-sm text-gray-700">
                <strong>{{ trail.km }} km</strong> · {{ trail.stops.length }} stop(s) · {{ time(trail.points[0].t) }}–{{ time(trail.points[trail.points.length - 1].t) }}
              </p>
              <input v-model.number="scrub" type="range" min="0" max="100" class="mt-2 w-full accent-[#c4161c]" aria-label="Time on the route" />
              <p v-if="scrubPoint" class="text-xs text-gray-500">
                {{ time(scrubPoint.t) }}<template v-if="scrubPoint.spd !== null"> · {{ Math.round(scrubPoint.spd * 3.6) }} km/h</template><template v-if="scrubPoint.bat !== null"> · battery {{ scrubPoint.bat }}%</template>
              </p>
              <ul class="mt-2 max-h-32 space-y-0.5 overflow-y-auto text-xs text-gray-600">
                <li v-for="p in trail.punches" :key="p.type + p.time">Punch {{ p.type }} {{ p.time }}<template v-if="p.branch"> · {{ p.branch }}</template></li>
                <li v-for="s in trail.stops" :key="'s' + s.from">Stopped {{ s.from }}–{{ s.to }} ({{ s.minutes }} min)</li>
                <li v-for="g in trail.gaps" :key="'g' + g.from" class="text-red-600">No signal {{ g.from }}–{{ g.to }} ({{ g.minutes }} min)</li>
              </ul>
            </template>
          </template>
        </div>
      </div>

      <Card :padding="false" class="lg:max-h-[calc(100vh-15rem)] lg:overflow-y-auto">
        <div v-if="loading && !data" class="flex justify-center py-10"><Spinner size="lg" /></div>
        <p v-else-if="!people.length" class="p-4 text-sm text-gray-500">Nobody here yet. Staff appear once they punch in.</p>
        <ul class="divide-y divide-gray-100">
          <li v-for="p in people" :key="p.user_id">
            <button type="button" class="flex w-full items-start gap-3 px-3 py-2.5 text-left hover:bg-gray-50" @click="openTrail(p)">
              <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white" :style="{ background: LIVE_STATE_COLORS[p.state] }">{{ initials(p.name) }}</span>
              <span class="min-w-0 flex-1">
                <span class="flex items-center gap-1.5">
                  <span class="truncate font-semibold text-gray-900">{{ p.name }}</span>
                  <span class="shrink-0 rounded px-1.5 text-[10px] font-semibold uppercase" :style="{ color: LIVE_STATE_COLORS[p.state], background: LIVE_STATE_COLORS[p.state] + '1a' }">{{ LIVE_STATE_LABELS[p.state] }}</span>
                </span>
                <span class="block truncate text-xs text-gray-500">{{ p.designation || p.role }}<template v-if="p.branch"> · {{ p.branch }}</template></span>
                <span class="block text-xs text-gray-600">
                  <template v-if="p.state === 'punched_out'">In {{ p.punched_in_at }} · out {{ p.punched_out_at }}</template>
                  <template v-else>
                    {{ p.at_workshop ? 'At ' + p.at_workshop : p.lat !== null ? 'In the field' : '' }}<template v-if="p.lat !== null || p.minutes_ago !== null"> · {{ ago(p) }}</template>
                  </template>
                </span>
                <span v-if="p.trip" class="mt-0.5 flex items-center gap-1 text-xs text-gray-700">
                  <TruckIcon class="h-3.5 w-3.5" /> {{ p.trip.label }}<template v-if="p.trip.job_number"> · {{ p.trip.job_number }}</template>
                </span>
                <span v-if="p.gap_reason" class="block text-xs text-red-600">{{ GAP_LABELS[p.gap_reason] ?? p.gap_reason }}</span>
                <span class="mt-0.5 flex items-center gap-2 text-[11px] text-gray-400">
                  <span v-if="p.battery_pct !== null" class="inline-flex items-center gap-0.5" :class="p.battery_pct <= 15 ? 'text-red-500' : ''"><BoltIcon class="h-3 w-3" />{{ p.battery_pct }}%</span>
                  <span v-if="p.source" class="inline-flex items-center gap-0.5"><DevicePhoneMobileIcon class="h-3 w-3" />{{ p.source === 'android' ? 'Android app' : 'Web app (open screen only)' }}</span>
                </span>
              </span>
            </button>
          </li>
        </ul>
      </Card>
    </div>
  </div>
</template>
