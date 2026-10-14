import { useUiStore } from '@/stores/ui.store'

export function useToast() {
  const uiStore = useUiStore()

  function success(title: string, message?: string) {
    return uiStore.showSuccess(title, message)
  }

  function error(title: string, message?: string) {
    return uiStore.showError(title, message)
  }

  function warning(title: string, message?: string) {
    return uiStore.showWarning(title, message)
  }

  function info(title: string, message?: string) {
    return uiStore.showInfo(title, message)
  }

  function dismiss(id: string) {
    uiStore.removeToast(id)
  }

  function clear() {
    uiStore.clearToasts()
  }

  // Helper for API error handling
  function apiError(err: any, fallbackMessage = 'An error occurred') {
    const message = err.response?.data?.message || err.message || fallbackMessage
    return error('Error', message)
  }

  // Helper for validation errors
  function validationErrors(errors: Record<string, string[]>) {
    const messages = Object.values(errors).flat()
    if (messages.length > 0) {
      return error('Validation Error', messages[0])
    }
  }

  return {
    success,
    error,
    warning,
    info,
    dismiss,
    clear,
    apiError,
    validationErrors,
  }
}
