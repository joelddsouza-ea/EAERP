/**
 * Small shared state for the customer app: a toast line and the unread inbox count.
 */
import { reactive } from 'vue'

export const ui = reactive<{ toast: { text: string; kind: 'ok' | 'error' } | null; unread: number }>({
  toast: null,
  unread: 0,
})

let timer: ReturnType<typeof setTimeout> | undefined

export function toast(text: string, kind: 'ok' | 'error' = 'ok') {
  ui.toast = { text, kind }
  clearTimeout(timer)
  timer = setTimeout(() => (ui.toast = null), kind === 'error' ? 6000 : 3500)
}

/** Ask once for phone notifications and send the subscription to the server */
export async function enablePush(publicKey: string | null, save: (sub: PushSubscriptionJSON) => Promise<unknown>): Promise<boolean> {
  try {
    if (!publicKey || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return false
    if (Notification.permission === 'denied') return false
    if (Notification.permission === 'default' && (await Notification.requestPermission()) !== 'granted') return false
    const reg = await navigator.serviceWorker.ready
    const existing = await reg.pushManager.getSubscription()
    const sub = existing ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(publicKey) }))
    await save(sub.toJSON())
    return true
  } catch {
    return false
  }
}

function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
  const out = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i)
  return out
}
