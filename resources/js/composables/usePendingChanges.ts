import { reactive, computed, onBeforeUnmount, onMounted } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'

interface PendingChange {
  label: string
  run: () => Promise<unknown>
}

/**
 * Masters screens: edits are held on screen and saved together with a button and a
 * confirmation (no auto-save). Each change has a key so editing the same cell again
 * replaces the earlier change. Leaving the page with unsaved changes asks first.
 */
export function usePendingChanges() {
  const changes = reactive(new Map<string, PendingChange>())
  const count = computed(() => changes.size)
  const labels = computed(() => [...changes.values()].map((c) => c.label))

  function queue(key: string, label: string, run: () => Promise<unknown>) {
    changes.set(key, { label, run })
  }

  function isChanged(key: string): boolean {
    return changes.has(key)
  }

  function clear() {
    changes.clear()
  }

  /**
   * Runs every change in order. Failed ones stay pending so they can be fixed and saved again.
   */
  async function saveAll(): Promise<{ saved: number; errors: unknown[] }> {
    let saved = 0
    const errors: unknown[] = []
    for (const [key, change] of [...changes.entries()]) {
      try {
        await change.run()
        changes.delete(key)
        saved++
      } catch (err) {
        errors.push(err)
      }
    }
    return { saved, errors }
  }

  function confirmLeave(): boolean {
    return changes.size === 0 || window.confirm(`You have ${changes.size} unsaved change(s). Leave without saving?`)
  }

  const beforeUnload = (e: BeforeUnloadEvent) => {
    if (changes.size) {
      e.preventDefault()
      e.returnValue = ''
    }
  }

  onBeforeRouteLeave(() => confirmLeave())
  onMounted(() => window.addEventListener('beforeunload', beforeUnload))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))

  return { count, labels, queue, isChanged, clear, saveAll, confirmLeave }
}
