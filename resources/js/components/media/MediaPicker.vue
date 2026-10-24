<script setup lang="ts">
/**
 * Camera-first photo / video picker. Android Chrome opens the camera only for a file input
 * with ONE type and capture set — "image/*,video/*" or `multiple` makes it show the gallery.
 * So each kind gets its own input: Take photo, Record video, and (only where allowed) Gallery.
 *
 * variant "buttons": the choices are shown as buttons. variant "menu": nothing is shown;
 * call open() from your own button — one choice opens straight away, several show a small menu.
 * Emits the input's change event, so existing handlers (event.target.files) keep working.
 */
import { ref, computed } from 'vue'
import { CameraIcon, VideoCameraIcon, PhotoIcon, DocumentIcon } from '@heroicons/vue/24/outline'
import Modal from '@/components/ui/Modal.vue'

type Kind = 'photo' | 'video'

const props = withDefaults(
  defineProps<{
    kinds?: Kind[]
    /** Allow picking existing photos/videos (e.g. what a washer sent on WhatsApp) */
    gallery?: boolean
    /** Allow PDFs as well (bills, documents): adds a "File" choice */
    files?: boolean
    variant?: 'buttons' | 'menu'
    disabled?: boolean
    size?: 'sm' | 'md'
  }>(),
  { kinds: () => ['photo'], gallery: false, files: false, variant: 'buttons', disabled: false, size: 'md' }
)
const emit = defineEmits<{ change: [event: Event] }>()

interface Choice {
  key: string
  label: string
  icon: typeof CameraIcon
  accept: string
  capture?: 'environment'
  multiple: boolean
}

const choices = computed<Choice[]>(() => [
  ...(props.kinds.includes('photo') ? [{ key: 'photo', label: 'Take photo', icon: CameraIcon, accept: 'image/*', capture: 'environment' as const, multiple: false }] : []),
  ...(props.kinds.includes('video') ? [{ key: 'video', label: 'Record video', icon: VideoCameraIcon, accept: 'video/*', capture: 'environment' as const, multiple: false }] : []),
  ...(props.gallery
    ? [{ key: 'gallery', label: 'From gallery', icon: PhotoIcon, accept: props.kinds.length === 1 ? (props.kinds[0] === 'video' ? 'video/*' : 'image/*') : 'image/*,video/*', multiple: true }]
    : []),
  ...(props.files ? [{ key: 'file', label: 'PDF / file', icon: DocumentIcon, accept: 'image/*,application/pdf', multiple: false }] : []),
])

const inputs = ref<Record<string, HTMLInputElement | null>>({})
const menuOpen = ref(false)

function onChange(event: Event) {
  menuOpen.value = false
  emit('change', event)
}

/** For variant "menu": one choice opens straight away (keeps the tap's user gesture), several show the menu */
function open() {
  if (props.disabled) return
  if (choices.value.length === 1) {
    inputs.value[choices.value[0].key]?.click()
  } else {
    menuOpen.value = true
  }
}

defineExpose({ open })
</script>

<template>
  <!-- Buttons -->
  <div v-if="variant === 'buttons'" class="flex flex-wrap gap-2">
    <label
      v-for="c in choices"
      :key="c.key"
      class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-gray-300 bg-white font-medium text-gray-700 active:bg-gray-50"
      :class="[size === 'sm' ? 'px-2.5 py-1.5 text-xs' : 'min-h-11 px-3 text-sm', disabled ? 'pointer-events-none opacity-50' : '']"
    >
      <component :is="c.icon" class="h-5 w-5" />
      {{ c.label }}
      <input type="file" class="hidden" :accept="c.accept" :capture="c.capture" :multiple="c.multiple" :disabled="disabled" @change="onChange" />
    </label>
  </div>

  <!-- Menu: hidden inputs, opened by open() -->
  <template v-else>
    <input
      v-for="c in choices"
      :key="c.key"
      :ref="(el) => (inputs[c.key] = el as HTMLInputElement | null)"
      type="file"
      class="hidden"
      :accept="c.accept"
      :capture="c.capture"
      :multiple="c.multiple"
      @change="onChange"
    />
    <Modal :open="menuOpen" title="Add photo or video" @close="menuOpen = false">
      <div class="grid gap-2">
        <!-- Labels: the tap itself opens the camera / gallery -->
        <label
          v-for="c in choices"
          :key="c.key"
          class="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border border-gray-200 px-4 text-base font-medium text-gray-800 active:bg-gray-50"
        >
          <component :is="c.icon" class="h-6 w-6 text-primary-600" />
          {{ c.label }}
          <input type="file" class="hidden" :accept="c.accept" :capture="c.capture" :multiple="c.multiple" @change="onChange" />
        </label>
      </div>
    </Modal>
  </template>
</template>
