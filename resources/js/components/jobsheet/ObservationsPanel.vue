<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { ExclamationTriangleIcon, TrashIcon } from '@heroicons/vue/20/solid'
import { jobCardIssuesApi } from '@/api/jobSheets.api'
import { useToast } from '@/composables/useToast'
import type { JobCardObservation } from '@/api/types'
import Button from '@/components/ui/Button.vue'

interface Props {
  jobCardId: number
  readonly?: boolean
  // Bump to reload after an activity's ⚠ flag changes
  refreshKey?: number
}

const props = withDefaults(defineProps<Props>(), { readonly: false, refreshKey: 0 })
const toast = useToast()

const observations = ref<JobCardObservation[]>([])
const draft = ref('')
const saving = ref(false)

async function load() {
  try {
    observations.value = (await jobCardIssuesApi.observations(props.jobCardId)).data.data
  } catch (err) {
    toast.apiError(err)
  }
}

async function add() {
  if (!draft.value.trim()) return
  saving.value = true
  try {
    observations.value.push((await jobCardIssuesApi.createObservation(props.jobCardId, draft.value.trim())).data.data)
    draft.value = ''
  } catch (err) {
    toast.apiError(err)
  } finally {
    saving.value = false
  }
}

async function remove(o: JobCardObservation) {
  try {
    await jobCardIssuesApi.deleteObservation(props.jobCardId, o.id)
    observations.value = observations.value.filter((x) => x.id !== o.id)
  } catch (err) {
    toast.apiError(err)
  }
}

onMounted(load)
watch(() => props.refreshKey, load)
</script>

<template>
  <div class="space-y-3">
    <p class="text-sm text-gray-500">
      Observations & recommendations for the customer. Activities flagged
      <ExclamationTriangleIcon class="inline h-4 w-4 text-amber-500 -mt-0.5" /> appear here automatically.
    </p>

    <p v-if="!observations.length" class="text-sm text-gray-500">None yet.</p>
    <ol class="space-y-2 list-decimal list-inside">
      <li v-for="o in observations" :key="o.id" class="rounded-xl border border-gray-200 bg-white p-3 text-sm text-gray-900">
        <span>{{ o.text }}</span>
        <span v-if="o.source !== 'manual'" class="ml-1 text-xs text-amber-700">(from activity)</span>
        <button
          v-if="!readonly && o.source === 'manual'"
          type="button"
          class="float-right p-1 text-gray-400 hover:text-red-600"
          aria-label="Remove observation"
          @click="remove(o)"
        >
          <TrashIcon class="h-4 w-4" />
        </button>
      </li>
    </ol>

    <form v-if="!readonly" class="flex gap-2" @submit.prevent="add">
      <input v-model="draft" class="input flex-1" placeholder="Add an observation or recommendation" />
      <Button type="submit" size="sm" :loading="saving" :disabled="!draft.trim()">Add</Button>
    </form>
  </div>
</template>
