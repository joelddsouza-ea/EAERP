<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { TrashIcon } from '@heroicons/vue/20/solid'
import { jobCardIssuesApi } from '@/api/jobSheets.api'
import { useToast } from '@/composables/useToast'
import type { JobCardIssue, JobCardAdditionalJob } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'

interface Props {
  jobCardId: number
  readonly?: boolean
}

const props = withDefaults(defineProps<Props>(), { readonly: false })
const toast = useToast()

const issues = ref<JobCardIssue[]>([])
const jobs = ref<JobCardAdditionalJob[]>([])
const loading = ref(true)

const newIssue = ref({ description: '', priority: 'normal' as JobCardIssue['priority'] })
const newJob = ref({ description: '', parts_used: '', cost: '' as string | number, job_card_issue_id: null as number | null })
const saving = ref(false)

// Resolving / deferring needs a note; collected inline
const resolving = ref<{ issue: JobCardIssue; status: 'resolved' | 'deferred'; notes: string } | null>(null)

const priorityVariant = { critical: 'danger', high: 'warning', normal: 'default' } as const
const statusLabels: Record<JobCardIssue['status'], string> = {
  open: 'Open',
  in_progress: 'In progress',
  resolved: 'Resolved',
  deferred: 'Deferred',
}

const issueOptions = computed(() => issues.value.map((i) => ({ value: i.id, label: i.description })))
const jobsTotal = computed(() => jobs.value.reduce((sum, j) => sum + Number(j.cost || 0), 0))

async function load() {
  loading.value = true
  try {
    const [i, j] = await Promise.all([jobCardIssuesApi.issues(props.jobCardId), jobCardIssuesApi.additionalJobs(props.jobCardId)])
    issues.value = i.data.data
    jobs.value = j.data.data
  } catch (err) {
    toast.apiError(err)
  } finally {
    loading.value = false
  }
}

async function addIssue() {
  if (!newIssue.value.description.trim()) return
  saving.value = true
  try {
    const response = await jobCardIssuesApi.createIssue(props.jobCardId, newIssue.value)
    issues.value.push(response.data.data)
    newIssue.value = { description: '', priority: 'normal' }
  } catch (err) {
    toast.apiError(err)
  } finally {
    saving.value = false
  }
}

async function setIssueStatus(issue: JobCardIssue, status: JobCardIssue['status']) {
  if (status === 'resolved' || status === 'deferred') {
    resolving.value = { issue, status, notes: issue.resolution_notes ?? '' }
    return
  }
  await updateIssue(issue, { status })
}

async function confirmResolve() {
  const r = resolving.value
  if (!r || !r.notes.trim()) return
  if (await updateIssue(r.issue, { status: r.status, resolution_notes: r.notes })) {
    resolving.value = null
  }
}

async function updateIssue(issue: JobCardIssue, data: Partial<JobCardIssue>) {
  try {
    const response = await jobCardIssuesApi.updateIssue(props.jobCardId, issue.id, data)
    Object.assign(issue, response.data.data)
    return true
  } catch (err) {
    toast.apiError(err)
    return false
  }
}

async function removeIssue(issue: JobCardIssue) {
  try {
    await jobCardIssuesApi.deleteIssue(props.jobCardId, issue.id)
    issues.value = issues.value.filter((i) => i.id !== issue.id)
  } catch (err) {
    toast.apiError(err)
  }
}

async function addJob() {
  if (!newJob.value.description.trim()) return
  saving.value = true
  try {
    const response = await jobCardIssuesApi.createAdditionalJob(props.jobCardId, {
      description: newJob.value.description,
      parts_used: newJob.value.parts_used || null,
      cost: newJob.value.cost === '' ? 0 : Number(newJob.value.cost),
      job_card_issue_id: newJob.value.job_card_issue_id,
    })
    jobs.value.push(response.data.data)
    newJob.value = { description: '', parts_used: '', cost: '', job_card_issue_id: null }
  } catch (err) {
    toast.apiError(err)
  } finally {
    saving.value = false
  }
}

async function removeJob(job: JobCardAdditionalJob) {
  try {
    await jobCardIssuesApi.deleteAdditionalJob(props.jobCardId, job.id)
    jobs.value = jobs.value.filter((j) => j.id !== job.id)
  } catch (err) {
    toast.apiError(err)
  }
}

function formatCurrency(amount: number | string): string {
  return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(Number(amount))
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <!-- Customer reported issues -->
    <section>
      <h3 class="text-sm font-semibold text-gray-900 mb-2">Customer reported issues</h3>
      <p v-if="!loading && !issues.length" class="text-sm text-gray-500">None recorded.</p>
      <ul class="space-y-2">
        <li v-for="issue in issues" :key="issue.id" class="rounded-xl border border-gray-200 bg-white p-3">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm text-gray-900">{{ issue.description }}</p>
            <Badge :variant="priorityVariant[issue.priority]" size="sm" class="capitalize shrink-0">{{ issue.priority }}</Badge>
          </div>
          <p v-if="issue.resolution_notes" class="mt-1 text-xs text-gray-600">{{ statusLabels[issue.status] }}: {{ issue.resolution_notes }}</p>
          <div v-if="!readonly" class="mt-2 flex flex-wrap items-center gap-2">
            <button
              v-for="(label, status) in statusLabels"
              :key="status"
              type="button"
              class="min-h-10 px-3 rounded-lg border text-xs font-medium"
              :class="issue.status === status ? 'bg-primary-600 border-primary-600 text-white' : 'bg-white border-gray-300 text-gray-700'"
              @click="setIssueStatus(issue, status)"
            >
              {{ label }}
            </button>
            <button type="button" class="ml-auto p-2 text-gray-400 hover:text-red-600" aria-label="Remove issue" @click="removeIssue(issue)">
              <TrashIcon class="h-4 w-4" />
            </button>
          </div>
          <div v-if="resolving?.issue.id === issue.id" class="mt-2 space-y-2">
            <textarea v-model="resolving.notes" rows="2" class="input" :placeholder="resolving.status === 'resolved' ? 'How was it resolved?' : 'Why is it deferred?'" />
            <div class="flex gap-2">
              <Button size="sm" :disabled="!resolving.notes.trim()" @click="confirmResolve">Save</Button>
              <Button size="sm" variant="secondary" @click="resolving = null">Cancel</Button>
            </div>
          </div>
        </li>
      </ul>

      <form v-if="!readonly" class="mt-3 flex flex-wrap gap-2" @submit.prevent="addIssue">
        <input v-model="newIssue.description" class="input flex-1 min-w-[12rem]" placeholder="Describe the customer's issue" />
        <select v-model="newIssue.priority" class="input w-auto">
          <option value="normal">Normal</option>
          <option value="high">High</option>
          <option value="critical">Critical</option>
        </select>
        <Button type="submit" size="sm" :loading="saving" :disabled="!newIssue.description.trim()">Add issue</Button>
      </form>
    </section>

    <!-- Additional jobs done -->
    <section>
      <div class="flex items-baseline justify-between mb-2">
        <h3 class="text-sm font-semibold text-gray-900">Additional jobs done</h3>
        <span v-if="jobs.length" class="text-sm text-gray-600">Total {{ formatCurrency(jobsTotal) }}</span>
      </div>
      <p v-if="!loading && !jobs.length" class="text-sm text-gray-500">None recorded.</p>
      <ul class="space-y-2">
        <li v-for="job in jobs" :key="job.id" class="rounded-xl border border-gray-200 bg-white p-3 flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="text-sm text-gray-900">{{ job.description }}</p>
            <p v-if="job.parts_used" class="text-xs text-gray-500">Parts: {{ job.parts_used }}</p>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <span class="text-sm font-medium">{{ formatCurrency(job.cost) }}</span>
            <button v-if="!readonly" type="button" class="p-2 text-gray-400 hover:text-red-600" aria-label="Remove job" @click="removeJob(job)">
              <TrashIcon class="h-4 w-4" />
            </button>
          </div>
        </li>
      </ul>

      <form v-if="!readonly" class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2" @submit.prevent="addJob">
        <input v-model="newJob.description" class="input sm:col-span-2" placeholder="Job done" />
        <input v-model="newJob.parts_used" class="input" placeholder="Parts used" />
        <input v-model="newJob.cost" type="number" min="0" step="1" class="input" placeholder="Cost ₹" />
        <select v-if="issueOptions.length" v-model="newJob.job_card_issue_id" class="input sm:col-span-2">
          <option :value="null">Not linked to an issue</option>
          <option v-for="o in issueOptions" :key="o.value" :value="o.value">Fixes: {{ o.label }}</option>
        </select>
        <div class="sm:col-span-2">
          <Button type="submit" size="sm" :loading="saving" :disabled="!newJob.description.trim()">Add job</Button>
        </div>
      </form>
    </section>
  </div>
</template>
