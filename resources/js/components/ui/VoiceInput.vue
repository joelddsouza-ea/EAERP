<script setup lang="ts">
/**
 * Mic button that dictates into a text field with the browser's speech
 * recognition (Chrome/Android, Safari/iOS). Hidden where it is not supported.
 * Recognises Indian English; translation from other languages comes later.
 */
import { ref, onBeforeUnmount } from 'vue'
import { MicrophoneIcon, StopIcon } from '@heroicons/vue/20/solid'

const props = withDefaults(defineProps<{ lang?: string }>(), { lang: 'en-IN' })

const emit = defineEmits<{
  /** Final recognised text, to append to the field */
  text: [value: string]
}>()

const Recognition: any =
  typeof window !== 'undefined' ? (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition : null

const supported = !!Recognition
const listening = ref(false)
let recognition: any = null

function toggle() {
  if (listening.value) {
    recognition?.stop()
    return
  }

  recognition = new Recognition()
  recognition.lang = props.lang
  recognition.interimResults = false
  recognition.continuous = false

  recognition.onresult = (event: any) => {
    const text = Array.from(event.results as ArrayLike<any>)
      .map((r: any) => r[0]?.transcript ?? '')
      .join(' ')
      .trim()
    if (text) emit('text', text)
  }
  recognition.onend = () => (listening.value = false)
  recognition.onerror = () => (listening.value = false)

  listening.value = true
  recognition.start()
}

onBeforeUnmount(() => recognition?.abort())
</script>

<template>
  <button
    v-if="supported"
    type="button"
    class="inline-flex items-center justify-center h-9 w-9 shrink-0 rounded-lg border transition-colors"
    :class="listening ? 'bg-red-50 border-red-300 text-red-600 animate-pulse' : 'border-gray-300 text-gray-500 hover:bg-gray-50'"
    :title="listening ? 'Stop dictation' : 'Dictate'"
    :aria-label="listening ? 'Stop dictation' : 'Dictate'"
    @click="toggle"
  >
    <StopIcon v-if="listening" class="h-4 w-4" />
    <MicrophoneIcon v-else class="h-4 w-4" />
  </button>
</template>
