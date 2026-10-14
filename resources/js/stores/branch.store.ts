import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useAuthStore } from './auth.store'
import type { Branch } from '@/api/types'

const BRANCH_KEY = 'eaerp_current_branch'

export const useBranchStore = defineStore('branch', () => {
  // State
  const currentBranchId = ref<number | null>(
    JSON.parse(localStorage.getItem(BRANCH_KEY) || 'null')
  )

  // Getters
  const authStore = useAuthStore()

  const currentBranch = computed((): Branch | null => {
    if (!currentBranchId.value) return null
    return authStore.branches.find((b) => b.id === currentBranchId.value) || null
  })

  const availableBranches = computed((): Branch[] => {
    return authStore.branches.filter((b) => b.is_active !== false)
  })

  const hasMultipleBranches = computed((): boolean => {
    return availableBranches.value.length > 1
  })

  const canViewAllBranches = computed((): boolean => {
    return authStore.hasAnyRole(['owner', 'manager', 'body-shop-manager'])
  })

  // Actions
  function setCurrentBranch(branchId: number | null) {
    if (branchId === null) {
      currentBranchId.value = null
      localStorage.removeItem(BRANCH_KEY)
      return
    }

    // Verify user has access to this branch
    if (!authStore.hasBranchAccess(branchId)) {
      console.warn('User does not have access to branch:', branchId)
      return
    }

    currentBranchId.value = branchId
    localStorage.setItem(BRANCH_KEY, JSON.stringify(branchId))
  }

  function initializeBranch() {
    // If user only has one branch, auto-select it
    if (availableBranches.value.length === 1 && !currentBranchId.value) {
      setCurrentBranch(availableBranches.value[0].id)
      return
    }

    // Verify current branch is still valid
    if (currentBranchId.value && !authStore.hasBranchAccess(currentBranchId.value)) {
      // Reset to first available branch or null
      if (availableBranches.value.length > 0) {
        setCurrentBranch(availableBranches.value[0].id)
      } else {
        setCurrentBranch(null)
      }
    }
  }

  function clearBranch() {
    currentBranchId.value = null
    localStorage.removeItem(BRANCH_KEY)
  }

  return {
    // State
    currentBranchId,

    // Getters
    currentBranch,
    availableBranches,
    hasMultipleBranches,
    canViewAllBranches,

    // Actions
    setCurrentBranch,
    initializeBranch,
    clearBranch,
  }
})
