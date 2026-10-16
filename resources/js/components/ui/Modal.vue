<script setup lang="ts">
import { watch, onMounted, onUnmounted } from 'vue'
import {
  Dialog,
  DialogPanel,
  DialogTitle,
  TransitionRoot,
  TransitionChild,
} from '@headlessui/vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'

interface Props {
  open: boolean
  title?: string
  size?: 'sm' | 'md' | 'lg' | 'xl' | 'full'
  closeable?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  size: 'md',
  closeable: true,
})

const emit = defineEmits<{
  close: []
}>()

const sizeClasses = {
  sm: 'sm:max-w-sm',
  md: 'sm:max-w-lg',
  lg: 'sm:max-w-2xl',
  xl: 'sm:max-w-4xl',
  full: 'sm:max-w-full sm:mx-4',
}

function close() {
  if (props.closeable) {
    emit('close')
  }
}

function handleEscape(event: KeyboardEvent) {
  if (event.key === 'Escape' && props.open && props.closeable) {
    close()
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleEscape)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleEscape)
})

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      document.body.style.overflow = 'hidden'
    } else {
      document.body.style.overflow = ''
    }
  }
)
</script>

<template>
  <TransitionRoot :show="open" as="template">
    <Dialog :open="open" @close="close" class="relative z-50">
      <!-- Backdrop -->
      <TransitionChild
        as="template"
        enter="ease-out duration-300"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="ease-in duration-200"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-gray-900/50 backdrop-blur-[2px]" aria-hidden="true" />
      </TransitionChild>

      <!-- Modal container: a bottom sheet on phones, a centred dialog from sm up -->
      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-end sm:items-center justify-center sm:p-4">
          <TransitionChild
            as="template"
            enter="ease-out duration-300"
            enter-from="opacity-0 translate-y-8 sm:translate-y-0 sm:scale-95"
            enter-to="opacity-100 translate-y-0 sm:scale-100"
            leave="ease-in duration-200"
            leave-from="opacity-100 translate-y-0 sm:scale-100"
            leave-to="opacity-0 translate-y-8 sm:translate-y-0 sm:scale-95"
          >
            <DialogPanel
              :class="[
                'w-full bg-white shadow-xl transform transition-all rounded-t-2xl sm:rounded-2xl max-h-[92vh] flex flex-col',
                sizeClasses[size],
              ]"
            >
              <!-- Grab handle (phones) -->
              <div class="sm:hidden flex justify-center pt-2"><span class="h-1 w-10 rounded-full bg-gray-300" /></div>

              <!-- Header -->
              <div
                v-if="title || closeable"
                class="flex items-center justify-between gap-3 px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-100 shrink-0"
              >
                <DialogTitle v-if="title" class="text-lg font-semibold text-gray-900">
                  {{ title }}
                </DialogTitle>
                <button
                  v-if="closeable"
                  type="button"
                  class="-mr-2 p-2 rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus:outline-none"
                  aria-label="Close"
                  @click="close"
                >
                  <XMarkIcon class="h-6 w-6" />
                </button>
              </div>

              <!-- Body -->
              <div class="px-4 sm:px-6 py-4 overflow-y-auto">
                <slot />
              </div>

              <!-- Footer -->
              <div
                v-if="$slots.footer"
                class="flex flex-wrap items-center justify-end gap-3 px-4 sm:px-6 py-3 sm:py-4 border-t border-gray-100 bg-gray-50/70 sm:rounded-b-2xl shrink-0 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:pb-4"
              >
                <slot name="footer" />
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
