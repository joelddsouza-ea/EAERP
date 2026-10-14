import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number
  to: number
}

export function usePagination(defaultPerPage = 15) {
  const route = useRoute()
  const router = useRouter()

  const currentPage = ref(parseInt(route.query.page as string) || 1)
  const perPage = ref(parseInt(route.query.per_page as string) || defaultPerPage)
  const totalItems = ref(0)
  const lastPage = ref(1)

  const hasNextPage = computed(() => currentPage.value < lastPage.value)
  const hasPrevPage = computed(() => currentPage.value > 1)

  const from = computed(() => {
    if (totalItems.value === 0) return 0
    return (currentPage.value - 1) * perPage.value + 1
  })

  const to = computed(() => {
    const end = currentPage.value * perPage.value
    return Math.min(end, totalItems.value)
  })

  const pageNumbers = computed(() => {
    const pages: (number | string)[] = []
    const maxVisible = 7

    if (lastPage.value <= maxVisible) {
      for (let i = 1; i <= lastPage.value; i++) {
        pages.push(i)
      }
    } else {
      pages.push(1)

      if (currentPage.value > 3) {
        pages.push('...')
      }

      const start = Math.max(2, currentPage.value - 1)
      const end = Math.min(lastPage.value - 1, currentPage.value + 1)

      for (let i = start; i <= end; i++) {
        pages.push(i)
      }

      if (currentPage.value < lastPage.value - 2) {
        pages.push('...')
      }

      pages.push(lastPage.value)
    }

    return pages
  })

  function setMeta(meta: PaginationMeta) {
    currentPage.value = meta.current_page
    lastPage.value = meta.last_page
    perPage.value = meta.per_page
    totalItems.value = meta.total
  }

  function goToPage(page: number) {
    if (page < 1 || page > lastPage.value) return
    currentPage.value = page
    updateQuery()
  }

  function nextPage() {
    if (hasNextPage.value) {
      goToPage(currentPage.value + 1)
    }
  }

  function prevPage() {
    if (hasPrevPage.value) {
      goToPage(currentPage.value - 1)
    }
  }

  function setPerPage(value: number) {
    perPage.value = value
    currentPage.value = 1
    updateQuery()
  }

  function reset() {
    currentPage.value = 1
    updateQuery()
  }

  function updateQuery() {
    const query = { ...route.query }
    if (currentPage.value > 1) {
      query.page = currentPage.value.toString()
    } else {
      delete query.page
    }
    if (perPage.value !== defaultPerPage) {
      query.per_page = perPage.value.toString()
    } else {
      delete query.per_page
    }
    router.replace({ query })
  }

  // Watch for route changes
  watch(
    () => route.query.page,
    (newPage) => {
      currentPage.value = parseInt(newPage as string) || 1
    }
  )

  return {
    currentPage,
    perPage,
    totalItems,
    lastPage,
    hasNextPage,
    hasPrevPage,
    from,
    to,
    pageNumbers,

    setMeta,
    goToPage,
    nextPage,
    prevPage,
    setPerPage,
    reset,
  }
}
