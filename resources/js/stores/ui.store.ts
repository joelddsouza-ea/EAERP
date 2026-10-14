import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

const SIDEBAR_KEY = 'eaerp_sidebar_collapsed'
const THEME_KEY = 'eaerp_theme'

export type Theme = 'light' | 'dark' | 'system'

export interface Toast {
  id: string
  type: 'success' | 'error' | 'warning' | 'info'
  title: string
  message?: string
  duration?: number
}

export const useUiStore = defineStore('ui', () => {
  // State
  const sidebarCollapsed = ref<boolean>(
    JSON.parse(localStorage.getItem(SIDEBAR_KEY) || 'false')
  )
  const sidebarMobileOpen = ref(false)
  const theme = ref<Theme>((localStorage.getItem(THEME_KEY) as Theme) || 'light')
  const toasts = ref<Toast[]>([])
  const loading = ref(false)
  const loadingMessage = ref('')

  // Getters
  const isDarkMode = computed(() => {
    if (theme.value === 'system') {
      return window.matchMedia('(prefers-color-scheme: dark)').matches
    }
    return theme.value === 'dark'
  })

  // Actions
  function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value
    localStorage.setItem(SIDEBAR_KEY, JSON.stringify(sidebarCollapsed.value))
  }

  function setSidebarCollapsed(collapsed: boolean) {
    sidebarCollapsed.value = collapsed
    localStorage.setItem(SIDEBAR_KEY, JSON.stringify(collapsed))
  }

  function toggleMobileSidebar() {
    sidebarMobileOpen.value = !sidebarMobileOpen.value
  }

  function closeMobileSidebar() {
    sidebarMobileOpen.value = false
  }

  function setTheme(newTheme: Theme) {
    theme.value = newTheme
    localStorage.setItem(THEME_KEY, newTheme)
    applyTheme()
  }

  function applyTheme() {
    if (isDarkMode.value) {
      document.documentElement.classList.add('dark')
    } else {
      document.documentElement.classList.remove('dark')
    }
  }

  function showToast(toast: Omit<Toast, 'id'>) {
    const id = Date.now().toString() + Math.random().toString(36).substr(2, 9)
    const newToast: Toast = {
      id,
      duration: 5000,
      ...toast,
    }

    toasts.value.push(newToast)

    if (newToast.duration && newToast.duration > 0) {
      setTimeout(() => {
        removeToast(id)
      }, newToast.duration)
    }

    return id
  }

  function removeToast(id: string) {
    const index = toasts.value.findIndex((t) => t.id === id)
    if (index > -1) {
      toasts.value.splice(index, 1)
    }
  }

  function clearToasts() {
    toasts.value = []
  }

  function showSuccess(title: string, message?: string) {
    return showToast({ type: 'success', title, message })
  }

  function showError(title: string, message?: string) {
    return showToast({ type: 'error', title, message, duration: 8000 })
  }

  function showWarning(title: string, message?: string) {
    return showToast({ type: 'warning', title, message })
  }

  function showInfo(title: string, message?: string) {
    return showToast({ type: 'info', title, message })
  }

  function startLoading(message = 'Loading...') {
    loading.value = true
    loadingMessage.value = message
  }

  function stopLoading() {
    loading.value = false
    loadingMessage.value = ''
  }

  // Initialize theme
  applyTheme()

  return {
    // State
    sidebarCollapsed,
    sidebarMobileOpen,
    theme,
    toasts,
    loading,
    loadingMessage,

    // Getters
    isDarkMode,

    // Actions
    toggleSidebar,
    setSidebarCollapsed,
    toggleMobileSidebar,
    closeMobileSidebar,
    setTheme,
    applyTheme,
    showToast,
    removeToast,
    clearToasts,
    showSuccess,
    showError,
    showWarning,
    showInfo,
    startLoading,
    stopLoading,
  }
})
