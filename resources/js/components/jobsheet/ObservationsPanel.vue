<script setup lang="ts">
/**
 * Observations & recommendations for the customer's report: work the customer did not
 * approve on the estimate (added automatically), deferred Additional Issues lines, and
 * observations added here. Each gets a remark from the master list (e.g. "Fix within _ days"),
 * which the technician or the advisor can set. Flagged ⚠ activities are work to do, so they
 * go to Additional Issues instead.
 */
import { ref, onMounted, watch } from 'vue'
import { TrashIcon } from '@heroicons/vue/20/solid'
import { jobCardIssuesApi } from '@/api/jobSheets.api'
import { useToast } from '@/composables/useToast'
import type { JobCardObservation, ObservationRemark } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import VoiceInput from '@/components/ui/VoiceInput.vue'

interface Props {
  jobCardId: number
  /** Adding / removing observations: the assigned technician */
  readonly?: boolean
  /** Remarks and what the customer sees: technician or advisor */
  remarksReadonly?: boolean
  // Bump to reload after an activity's ⚠ flag changes
  refreshKey?: number
}

const props = withDefaults(defineProps<Props>(), { readonly: false, remarksReadonly: false, refreshKey: 0 })
const toast = useToast()

const observations = ref<JobCardObservation[]>([])
const remarks = ref<ObservationRemark[]>([])
const draft = ref('')
const draftRemark = ref<number | null>(null)
const saving = ref(false)

const SOURCE_LABEL: Record<JobCardObservation['source'], string | null> = {
  manual: null,
  activity: 'from activity',
  pre_delivery: 'from pre-delivery',
  estimate_declined: 'customer not approved',
  issue_deferred: 'deferred issue',
}

async function load() {
  try {
    const [o, r] = await Promise.all([
      jobCardIssuesApi.observations(props.jobCardId),
      remarks.value.length ? null : jobCardIssuesApi.remarks(),
    ])
    observations.value = o.data.data
    if (r) remarks.value = r.data.data
  } catch (err) {
    toast.apiError(err)
  }
}

function remarkFor(id: number | null) {
  return remarks.value.find((r) => r.id === id) ?? null
}

async function add() {
  if (!draft.value.trim()) return
  saving.value = true
  try {
    observations.value.push(
      (await jobCardIssuesApi.createObservation(props.jobCardId, draft.value.trim(), { remark_id: draftRemark.value })).data.data
    )
    draft.value = ''
    draftRemark.value = null
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    saving.value = false
  }
}

async function update(o: JobCardObservation, data: Partial<JobCardObservation>) {
  try {
    const updated = (await jobCardIssuesApi.updateObservation(props.jobCardId, o.id, data)).data.data
    Object.assign(o, updated)
  } catch (err) {
    toast.apiError(err)
  }
}

function setRemark(o: JobCardObservation, value: string) {
  const id = value ? Number(value) : null
  const remark = remarkFor(id)
  update(o, { remark_id: id, remark_days: remark?.needs_days ? o.remark_days ?? 7 : null })
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
      Goes on the customer's report. Work the customer did not approve and deferred issues appear here automatically;
      add other observations below. Pick a remark for each.
    </p>

    <p v-if="!observations.length" class="text-sm text-gray-500">None yet.</p>

    <ol class="space-y-2">
      <li v-for="(o, index) in observations" :key="o.id" class="rounded-xl border border-gray-200 bg-white p-3 text-sm">
        <div class="flex items-start justify-between gap-2">
          <p class="text-gray-900">
            {{ index + 1 }}. {{ o.text }}
            <span v-if="SOURCE_LABEL[o.source]" class="ml-1 text-xs text-amber-700">({{ SOURCE_LABEL[o.source] }})</span>
          </p>
          <button
            v-if="!readonly && o.source === 'manual'"
            type="button"
            class="p-1 text-gray-400 hover:text-red-600 shrink-0"
            aria-label="Remove observation"
            @click="remove(o)"
          >
            <TrashIcon class="h-4 w-4" />
          </button>
        </div>

        <!-- Remark -->
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <span class="text-xs font-medium text-gray-600">Remarks</span>
          <template v-if="!remarksReadonly">
            <select class="input w-auto py-1 text-sm" :value="o.remark_id ?? ''" @change="(e) => setRemark(o, (e.target as HTMLSelectElement).value)">
              <option value="">— choose —</option>
              <option v-for="r in remarks" :key="r.id" :value="r.id">{{ r.label.replace('_', '…') }}</option>
            </select>
            <label v-if="remarkFor(o.remark_id)?.needs_days" class="inline-flex items-center gap-1 text-xs text-gray-600">
              <input
                type="number"
                min="1"
                class="input w-20 py-1 text-sm"
                :value="o.remark_days ?? ''"
                @change="(e) => update(o, { remark_days: Number((e.target as HTMLInputElement).value) || null })"
              />
              days
            </label>
            <label class="ml-auto inline-flex items-center gap-1 text-xs text-gray-600">
              <input
                type="checkbox"
                class="rounded border-gray-300"
                :checked="o.customer_visible"
                @change="(e) => update(o, { customer_visible: (e.target as HTMLInputElement).checked })"
              />
              On customer report
            </label>
          </template>
          <span v-else class="text-sm text-gray-800">{{ o.remark_text ?? '—' }}</span>
        </div>
      </li>
    </ol>

    <form v-if="!readonly" class="space-y-2" @submit.prevent="add">
      <div class="flex gap-2">
        <input v-model="draft" class="input flex-1" placeholder="Add an observation or recommendation" />
        <VoiceInput @text="(t: string) => (draft = draft ? `${draft} ${t}` : t)" />
      </div>
      <div class="flex gap-2">
        <select v-model="draftRemark" class="input flex-1">
          <option :value="null">Remark (optional)</option>
          <option v-for="r in remarks" :key="r.id" :value="r.id">{{ r.label.replace('_', '…') }}</option>
        </select>
        <Button type="submit" size="sm" :loading="saving" :disabled="!draft.trim()">Add</Button>
      </div>
    </form>
  </div>
</template>
