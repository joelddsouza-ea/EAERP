<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { useUiStore } from '@/stores/ui.store'
import { useBranchStore } from '@/stores/branch.store'
import Sidebar from './Sidebar.vue'
import Navbar from './Navbar.vue'
import MobileSidebar from './MobileSidebar.vue'
import ToastContainer from '@/components/ui/ToastContainer.vue'

const uiStore = useUiStore()
const branchStore = useBranchStore()

onMounted(() => {
  branchStore.initializeBranch()
})
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Desktop Sidebar -->
    <div class="hidden lg:block">
      <Sidebar />
    </div>

    <!-- Mobile Sidebar -->
    <MobileSidebar />

    <!-- Navbar -->
    <Navbar />

    <!-- Main content -->
    <main
      :class="[
        'pt-16 min-h-screen transition-all duration-300',
        uiStore.sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-64',
      ]"
    >
      <div class="p-6">
        <RouterView />
      </div>
    </main>

    <!-- Toast notifications -->
    <ToastContainer />
  </div>
</template>
