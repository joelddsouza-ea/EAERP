<script setup lang="ts">
/**
 * Bottom bar for masters screens: "n unsaved changes — Discard / Save changes",
 * with a confirmation listing what will be saved.
 */
import { ref } from 'vue'
import Button from './Button.vue'
import Modal from './Modal.vue'

const props = defineProps<{ count: number; labels: string[]; saving?: boolean }>()
const emit = defineEmits<{ save: []; discard: [] }>()

const confirming = ref(false)

function confirmSave() {
  confirming.value = false
  emit('save')
}

function discard() {
  if (window.confirm(`Discard ${props.count} unsaved change(s)?`)) emit('discard')
}
</script>

<template>
  <div v-if="count" class="sticky bottom-[calc(4rem+env(safe-area-inset-bottom))] lg:bottom-0 z-20 mt-4 -mx-3 sm:mx-0 border-t border-amber-200 bg-amber-50 px-4 py-3 sm:rounded-xl shadow-[0_-4px_12px_rgba(0,0,0,0.05)]">
    <div class="flex flex-wrap items-center gap-3">
      <p class="mr-auto text-sm font-medium text-amber-900">{{ count }} unsaved change{{ count === 1 ? '' : 's' }}</p>
      <Button variant="secondary" size="sm" :disabled="saving" @click="discard">Discard</Button>
      <Button size="sm" :loading="saving" @click="confirming = true">Save changes ({{ count }})</Button>
    </div>

    <Modal :open="confirming" title="Save changes?" @close="confirming = false">
      <p class="text-sm text-gray-600 mb-2">These changes will be saved and apply from now on:</p>
      <ul class="max-h-60 overflow-auto list-disc pl-5 text-sm text-gray-800 space-y-0.5">
        <li v-for="(label, i) in labels" :key="i">{{ label }}</li>
      </ul>
      <template #footer>
        <Button variant="secondary" @click="confirming = false">Cancel</Button>
        <Button @click="confirmSave">Save {{ count }} change{{ count === 1 ? '' : 's' }}</Button>
      </template>
    </Modal>
  </div>
</template>
