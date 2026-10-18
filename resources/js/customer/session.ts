/**
 * Who is signed in to the customer app (kept on the phone; try/catch because storage can be
 * blocked in private windows).
 */
import { reactive } from 'vue'

const TOKEN_KEY = 'ea_customer_token'
const CUSTOMER_KEY = 'ea_customer'

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

export const session = reactive<{ token: string | null; customer: { id: number; name: string; phone: string } | null }>({
  token: read<string | null>(TOKEN_KEY, null),
  customer: read(CUSTOMER_KEY, null),
})

export function signIn(token: string, customer: { id: number; name: string; phone: string }) {
  session.token = token
  session.customer = customer
  write(TOKEN_KEY, token)
  write(CUSTOMER_KEY, customer)
}

export function signOutLocally() {
  session.token = null
  session.customer = null
  write(TOKEN_KEY, null)
  write(CUSTOMER_KEY, null)
}
