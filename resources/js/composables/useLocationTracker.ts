/**
 * Live location from punch in to punch out. One tracker for the whole app (mounted in the
 * layout, so it runs on every screen):
 *  - in the browser / installed web app it uses the phone's GPS while the app is open;
 *  - inside the Android app it uses the native background-location service, so positions
 *    keep coming with the screen locked (a "sharing your location" notification shows).
 * Positions are queued on the phone (kept across reloads) and sent in batches, so a weak
 * network loses nothing. The server says when to stop (punched out, switched off).
 */
import { ref, readonly } from 'vue'
import { hrApi, type LocationPoint, type TrackingConfig } from '@/api/hr.api'

export type TrackerStatus = 'off' | 'starting' | 'sharing' | 'denied' | 'unavailable'

const QUEUE_KEY = 'eaerp_location_queue'
const MAX_QUEUE = 2000
const FLUSH_EVERY_MS = 30_000

const status = ref<TrackerStatus>('off')
const lastFixAt = ref<Date | null>(null)
const queued = ref(0)
const native = ref(false)
// Punched in but the one-time notice isn't accepted yet: the layout shows it
const pendingConsent = ref<TrackingConfig | null>(null)

let config: TrackingConfig | null = null
let watchId: number | null = null
let timer: ReturnType<typeof setInterval> | null = null
let nativeWatcherId: string | null = null
let lastQueued: LocationPoint | null = null
let lastFlush = 0
let flushing = false
let gap: string | null = null
let battery: { level: number; charging: boolean } | null = null

// ------------------------------------------------------------------ queue (survives reloads)

function readQueue(): LocationPoint[] {
  try {
    return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]')
  } catch {
    return []
  }
}

function writeQueue(points: LocationPoint[]) {
  queued.value = points.length
  try {
    localStorage.setItem(QUEUE_KEY, JSON.stringify(points.slice(-MAX_QUEUE)))
  } catch {
    /* storage full or blocked: points stay in memory only */
  }
}

let memoryQueue: LocationPoint[] = readQueue()
queued.value = memoryQueue.length

function metres(a: { lat: number; lng: number }, b: { lat: number; lng: number }) {
  const r = 6371000
  const dLat = ((b.lat - a.lat) * Math.PI) / 180
  const dLng = ((b.lng - a.lng) * Math.PI) / 180
  const x = Math.sin(dLat / 2) ** 2 + Math.cos((a.lat * Math.PI) / 180) * Math.cos((b.lat * Math.PI) / 180) * Math.sin(dLng / 2) ** 2
  return r * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x))
}

/** Keep a fix when the phone moved far enough or enough time passed since the last one */
function add(point: LocationPoint) {
  lastFixAt.value = new Date(point.recorded_at)
  status.value = 'sharing'
  gap = null
  if (battery) {
    point.battery = Math.round(battery.level * 100)
    point.charging = battery.charging
  }

  const interval = (config?.interval_seconds ?? 60) * 1000
  const distance = config?.distance_filter_m ?? 50
  if (lastQueued) {
    const elapsed = new Date(point.recorded_at).getTime() - new Date(lastQueued.recorded_at).getTime()
    if (elapsed < 5000) return
    if (elapsed < interval && metres(lastQueued, point) < distance) return
  }
  lastQueued = point
  memoryQueue.push(point)
  writeQueue(memoryQueue)

  if (memoryQueue.length >= 20 || Date.now() - lastFlush > FLUSH_EVERY_MS) void flush()
}

export async function flush(): Promise<void> {
  if (flushing || !config) return
  if (!memoryQueue.length && !gap) return
  flushing = true
  lastFlush = Date.now()
  const batch = memoryQueue.slice(0, 500)
  try {
    const res = await hrApi.sendLocations(batch, native.value ? 'android' : 'web', gap)
    memoryQueue = memoryQueue.slice(batch.length)
    writeQueue(memoryQueue)
    if (!res.data.data.tracking) {
      // Punched out or switched off: stop and drop anything left
      memoryQueue = []
      writeQueue(memoryQueue)
      await stop()
    }
  } catch {
    /* offline: try again with the next fix */
  } finally {
    flushing = false
  }
}

function reportGap(reason: 'location_off' | 'permission_denied') {
  gap = reason
  status.value = reason === 'permission_denied' ? 'denied' : 'unavailable'
  void flush()
}

// ------------------------------------------------------------------ browser GPS

function startWeb() {
  if (!('geolocation' in navigator)) {
    status.value = 'unavailable'
    return
  }
  const onFix = (p: GeolocationPosition) =>
    add({
      lat: p.coords.latitude,
      lng: p.coords.longitude,
      accuracy: Math.round(p.coords.accuracy),
      speed: p.coords.speed,
      heading: p.coords.heading,
      recorded_at: new Date(p.timestamp || Date.now()).toISOString(),
    })
  const onError = (e: GeolocationPositionError) => reportGap(e.code === e.PERMISSION_DENIED ? 'permission_denied' : 'location_off')
  const options = { enableHighAccuracy: true, maximumAge: 15000, timeout: 60000 }

  watchId = navigator.geolocation.watchPosition(onFix, onError, options)
  // A phone lying still sends no new fixes: ask for one every interval so the map knows it's alive
  timer = setInterval(() => {
    navigator.geolocation.getCurrentPosition(onFix, onError, { ...options, maximumAge: ((config?.interval_seconds ?? 60) * 1000) / 2 })
    if (Date.now() - lastFlush > FLUSH_EVERY_MS) void flush()
  }, (config?.interval_seconds ?? 60) * 1000)
}

// ------------------------------------------------------------------ Android app (Capacitor)

interface NativeLocation {
  latitude: number
  longitude: number
  accuracy: number
  speed: number | null
  bearing: number | null
  time: number | null
}

interface BackgroundGeolocationPlugin {
  addWatcher(
    options: { backgroundMessage?: string; backgroundTitle?: string; requestPermissions?: boolean; stale?: boolean; distanceFilter?: number },
    callback: (location?: NativeLocation, error?: { code?: string; message?: string }) => void,
  ): Promise<string>
  removeWatcher(options: { id: string }): Promise<void>
  openSettings(): Promise<void>
}

type CapacitorGlobal = {
  isNativePlatform?: () => boolean
  registerPlugin?: <T>(name: string) => T
  Plugins?: Record<string, unknown>
}

function capacitor(): CapacitorGlobal | null {
  const cap = (window as unknown as { Capacitor?: CapacitorGlobal }).Capacitor
  return cap?.isNativePlatform?.() ? cap : null
}

function nativePlugin(): BackgroundGeolocationPlugin | null {
  const cap = capacitor()
  if (!cap) return null
  return (cap.Plugins?.BackgroundGeolocation as BackgroundGeolocationPlugin | undefined)
    ?? cap.registerPlugin?.<BackgroundGeolocationPlugin>('BackgroundGeolocation')
    ?? null
}

async function startNative(plugin: BackgroundGeolocationPlugin) {
  native.value = true
  nativeWatcherId = await plugin.addWatcher(
    {
      backgroundTitle: 'EA Workshop',
      backgroundMessage: 'Sharing your location until you punch out.',
      requestPermissions: true,
      stale: false,
      distanceFilter: config?.distance_filter_m ?? 50,
    },
    (location, error) => {
      if (error) {
        reportGap(error.code === 'NOT_AUTHORIZED' ? 'permission_denied' : 'location_off')
        return
      }
      if (!location) return
      add({
        lat: location.latitude,
        lng: location.longitude,
        accuracy: Math.round(location.accuracy),
        speed: location.speed,
        heading: location.bearing,
        recorded_at: new Date(location.time ?? Date.now()).toISOString(),
      })
    },
  )
  // Timers slow down in the background; the plugin's callbacks still flush (see add)
  timer = setInterval(() => void flush(), FLUSH_EVERY_MS)
}

// ------------------------------------------------------------------ public

async function readBattery() {
  try {
    const nav = navigator as Navigator & { getBattery?: () => Promise<{ level: number; charging: boolean; addEventListener: (e: string, f: () => void) => void }> }
    const b = await nav.getBattery?.()
    if (!b) return
    const update = () => (battery = { level: b.level, charging: b.charging })
    update()
    b.addEventListener('levelchange', update)
    b.addEventListener('chargingchange', update)
  } catch {
    /* not available on this browser */
  }
}

export async function start(cfg: TrackingConfig): Promise<void> {
  config = cfg
  if (status.value === 'sharing' || status.value === 'starting') return
  status.value = 'starting'
  void readBattery()

  const plugin = nativePlugin()
  try {
    if (plugin) await startNative(plugin)
    else startWeb()
  } catch {
    // The native plugin isn't there (older app build): fall back to the browser
    native.value = false
    startWeb()
  }
  void flush()
}

export async function stop(): Promise<void> {
  if (watchId !== null) navigator.geolocation.clearWatch(watchId)
  watchId = null
  if (timer) clearInterval(timer)
  timer = null
  if (nativeWatcherId) {
    try {
      await nativePlugin()?.removeWatcher({ id: nativeWatcherId })
    } catch {
      /* already gone */
    }
  }
  nativeWatcherId = null
  lastQueued = null
  status.value = 'off'
}

/** Start or stop to match the server (after loading the app, a punch, or a periodic check) */
export async function sync(cfg: TrackingConfig | null | undefined): Promise<void> {
  if (!cfg) return
  config = cfg
  pendingConsent.value = cfg.required && !cfg.consent ? cfg : null
  if (cfg.required && cfg.consent) await start(cfg)
  else if (!cfg.required && status.value !== 'off') {
    await flush()
    await stop()
  }
}

/** Signed out: stop and forget queued positions (they belong to the person who left) */
export async function reset(): Promise<void> {
  await stop()
  config = null
  pendingConsent.value = null
  memoryQueue = []
  writeQueue(memoryQueue)
}

/** The employee accepted the notice: remember it and start */
export async function acceptConsent(): Promise<void> {
  const res = await hrApi.locationConsent()
  await sync(res.data.data)
}

export async function openLocationSettings(): Promise<void> {
  try {
    await nativePlugin()?.openSettings()
  } catch {
    /* web: the person opens the phone settings themselves */
  }
}

export function useLocationTracker() {
  return {
    status: readonly(status),
    lastFixAt: readonly(lastFixAt),
    queued: readonly(queued),
    native: readonly(native),
    pendingConsent: readonly(pendingConsent),
    acceptConsent,
    start,
    stop,
    sync,
    flush,
    openLocationSettings,
  }
}
