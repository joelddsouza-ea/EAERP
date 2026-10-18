<script setup lang="ts">
/**
 * The driver / home technician on the way: their last position and the customer's place,
 * refreshed by the parent. OpenStreetMap via Leaflet (no key needed).
 */
import { ref, watch, onMounted, onBeforeUnmount, shallowRef } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { PhoneIcon, TruckIcon } from '@heroicons/vue/24/solid'
import type { Driver } from '../api'

const props = defineProps<{ driver: Driver }>()
const el = ref<HTMLDivElement | null>(null)
const map = shallowRef<L.Map | null>(null)
const layer = L.layerGroup()

function draw() {
  if (!map.value) return
  layer.clearLayers()
  const pts: L.LatLngExpression[] = []
  const d = props.driver
  if (d.lat !== null && d.lng !== null) {
    L.marker([d.lat, d.lng], {
      icon: L.divIcon({ className: '', html: '<div class="flex h-9 w-9 items-center justify-center rounded-full border-2 border-white bg-[#c4161c] text-white shadow-lg">🚗</div>', iconSize: [36, 36], iconAnchor: [18, 18] }),
    }).addTo(layer)
    pts.push([d.lat, d.lng])
  }
  if (d.destination) {
    L.circleMarker([d.destination.lat, d.destination.lng], { radius: 8, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1 }).bindTooltip('You').addTo(layer)
    pts.push([d.destination.lat, d.destination.lng])
  }
  if (pts.length === 1) map.value.setView(pts[0], 15)
  else if (pts.length > 1) map.value.fitBounds(L.latLngBounds(pts), { padding: [30, 30], maxZoom: 16 })
}

onMounted(() => {
  if (!el.value) return
  map.value = L.map(el.value, { zoomControl: false, attributionControl: true }).setView([19.076, 72.8777], 12)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map.value)
  layer.addTo(map.value)
  draw()
})
onBeforeUnmount(() => map.value?.remove())
watch(() => props.driver, draw, { deep: true })
</script>

<template>
  <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200/80">
    <div class="flex items-center gap-3 p-4">
      <TruckIcon class="h-8 w-8 text-primary-600" />
      <div class="flex-1">
        <p class="font-semibold text-gray-900">{{ driver.name }} is on the way</p>
        <p class="text-sm text-gray-600">
          {{ driver.label }}
          <template v-if="driver.eta_minutes"> · about {{ driver.eta_minutes }} min</template>
          <template v-if="driver.distance_km !== null"> ({{ driver.distance_km }} km)</template>
        </p>
      </div>
      <a v-if="driver.phone" :href="`tel:${driver.phone}`" class="rounded-full bg-green-600 p-2.5 text-white" aria-label="Call"><PhoneIcon class="h-5 w-5" /></a>
    </div>
    <div v-show="driver.lat !== null" ref="el" class="z-0 h-56 w-full"></div>
    <p v-if="driver.lat === null" class="px-4 pb-4 text-sm text-gray-500">Live position will show as soon as their phone shares it.</p>
  </section>
</template>
