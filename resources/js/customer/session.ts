/**
 * Who is signed in to the customer app (kept on the phone; try/catch because storage can be
 * blocked in private windows).
 */
import { reactive } from 'vue'

const TOKEN_KEY = 'ea_customer_token'
const CUSTOMER_KEY = 'ea_customer'
const VIEWER_KEY = 'ea_viewer'

/** A fleet's driver sees only their cabs; the fleet owner also gets the fleet screens */
export interface Viewer {
  role: 'customer' | 'fleet_owner' | 'driver'
  driver: { id: number; name: string; phone: string } | null
  fleet_name: string | null
}

function read<T>(key: string, fallback: T): T {
  try {
    const raw = localStorage.getItem(key)
    return raw ? (JSON.parse(raw) as T) : fallback
  } catch {
    return fallback
  }
}

function write(key: string, value: unknown) {
  try {
    if (value === null) localStorage.removeItem(key)
    else localStorage.setItem(key, JSON.stringify(value))
  } catch {
    /* storage blocked: the session lasts until the tab closes */
  }
}

export const session = reactive<{ token: string | null; customer: { id: number; name: string; phone: string } | null; viewer: Viewer | null }>({
  token: read<string | null>(TOKEN_KEY, null),
  customer: read(CUSTOMER_KEY, null),
  viewer: read<Viewer | null>(VIEWER_KEY, null),
})

export function signIn(token: string, customer: { id: number; name: string; phone: string }, viewer: Viewer | null = null) {
  session.token = token
  session.customer = customer
  write(TOKEN_KEY, token)
  write(CUSTOMER_KEY, customer)
  setViewer(viewer)
}

/** Kept fresh from Home (an owner may mark the customer as a fleet later) */
export function setViewer(viewer: Viewer | null) {
  session.viewer = viewer
  write(VIEWER_KEY, viewer)
}

export const isDriver = () => session.viewer?.role === 'driver'
export const isFleetOwner = () => session.viewer?.role === 'fleet_owner'

export function signOutLocally() {
  session.token = null
  session.customer = null
  session.viewer = null
  write(TOKEN_KEY, null)
  write(CUSTOMER_KEY, null)
  write(VIEWER_KEY, null)
}
