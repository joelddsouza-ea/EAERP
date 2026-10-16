<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import { useUiStore } from '@/stores/ui.store'
import { useBranchStore } from '@/stores/branch.store'
import Sidebar from './Sidebar.vue'
import Navbar from './Navbar.vue'
import MobileSidebar from './MobileSidebar.vue'
import BottomNav from './BottomNav.vue'
import ToastContainer from '@/components/ui/ToastContainer.vue'

const route = useRoute()
const uiStore = useUiStore()
const branchStore = useBranchStore()

onMounted(() => {
  branchStore.initializeBranch()
})
</script>

<template>
  <div class="min-h-screen bg-gray-100">
    <!-- Desktop sidebar -->
    <div class="hidden lg:block">
      <Sidebar />
    </div>

    <!-- Phones: "More" sheet and bottom tab bar -->
    <MobileSidebar />
    <BottomNav />

    <Navbar />

    <main
      :class="[
        'min-h-screen transition-all duration-300',
        'pt-[calc(3.5rem+env(safe-area-inset-top))] lg:pt-16',
        // Room for the phone tab bar (screens with their own action bar hide it)
        route.meta.hideTabBar ? 'pb-0' : 'pb-[calc(4.5rem+env(safe-area-inset-bottom))] lg:pb-0',
        uiStore.sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-64',
      ]"
    >
      <div class="mx-auto max-w-[1600px] px-3 py-4 sm:px-6 sm:py-6">
        <RouterView />
      </div>
    </main>

    <ToastContainer />
  </div>
</template>
