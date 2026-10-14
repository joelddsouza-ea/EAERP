import apiClient from './client'
import type {
  ApiResponse,
  JobSheet,
  JobSheetItem,
  JobSheetItemStatus,
  JobCardMedia,
  JobCardIssue,
  JobCardAdditionalJob,
  JobCardObservation,
  ChecklistMasterTemplate,
  ChecklistMasterSection,
  ChecklistMasterItem,
} from './types'

const jc = (jobCardId: number) => `/workshop/job-cards/${jobCardId}`

export interface ItemUpdate {
  status?: JobSheetItemStatus
  needs_attention?: boolean
  remarks?: string | null
  value?: unknown
}

export interface MediaUploadMeta {
  poster?: Blob | null
  duration_seconds?: number | null
  captured_at?: string
}

// Technician job sheets and the pre-delivery checklist
export const jobSheetsApi = {
  list(jobCardId: number) {
    return apiClient.get<ApiResponse<JobSheet[]>>(`${jc(jobCardId)}/job-sheets`)
  },

  get(jobCardId: number, sheetId: number) {
    return apiClient.get<ApiResponse<JobSheet>>(`${jc(jobCardId)}/job-sheets/${sheetId}`)
  },

  preDelivery(jobCardId: number) {
    return apiClient.get<ApiResponse<JobSheet>>(`${jc(jobCardId)}/pre-delivery`)
  },

  updateItem(jobCardId: number, sheetId: number, itemId: number, data: ItemUpdate) {
    return apiClient.patch<ApiResponse<{ item: JobSheetItem; progress: JobSheet['progress'] }>>(
      `${jc(jobCardId)}/job-sheets/${sheetId}/items/${itemId}`,
      data
    )
  },

  addItem(jobCardId: number, sheetId: number, data: { item_name: string; section_name?: string }) {
    return apiClient.post<ApiResponse<JobSheetItem>>(`${jc(jobCardId)}/job-sheets/${sheetId}/items`, data)
  },

  uploadMedia(
    jobCardId: number,
    sheetId: number,
    itemId: number,
    file: Blob,
    fileName: string,
    meta: MediaUploadMeta = {},
    onProgress?: (percent: number) => void
  ) {
    const form = new FormData()
    form.append('file', file, fileName)
    if (meta.poster) form.append('poster', meta.poster, 'poster.jpg')
    if (meta.duration_seconds != null) form.append('duration_seconds', String(meta.duration_seconds))
    form.append('captured_at', meta.captured_at ?? new Date().toISOString())

    return apiClient.post<ApiResponse<{ media: JobCardMedia; media_shortfall: string | null }>>(
      `${jc(jobCardId)}/job-sheets/${sheetId}/items/${itemId}/media`,
      form,
      {
        headers: { 'Content-Type': 'multipart/form-data' },
        // Videos over a slow connection take longer than the default 30s
        timeout: 300000,
        onUploadProgress: (e) => {
          if (onProgress && e.total) onProgress(Math.round((e.loaded / e.total) * 100))
        },
      }
    )
  },

  deleteMedia(jobCardId: number, sheetId: number, itemId: number, mediaId: number) {
    return apiClient.delete<ApiResponse<{ item: JobSheetItem; reverted_to_pending: boolean }>>(
      `${jc(jobCardId)}/job-sheets/${sheetId}/items/${itemId}/media/${mediaId}`
    )
  },

  submit(jobCardId: number, sheetId: number, override = false, notes?: string) {
    return apiClient.post<ApiResponse<JobSheet>>(`${jc(jobCardId)}/job-sheets/${sheetId}/submit`, {
      override: override || undefined,
      notes: notes || undefined,
    })
  },

  reopen(jobCardId: number, sheetId: number, notes: string) {
    return apiClient.post<ApiResponse<JobSheet>>(`${jc(jobCardId)}/job-sheets/${sheetId}/reopen`, { notes })
  },

  sync(jobCardId: number) {
    return apiClient.post<ApiResponse<{ added: string[]; removed: string[] }>>(`${jc(jobCardId)}/job-sheets-sync`)
  },
}

// Customer-reported issues, additional jobs and observations
export const jobCardIssuesApi = {
  issues(jobCardId: number) {
    return apiClient.get<ApiResponse<JobCardIssue[]>>(`${jc(jobCardId)}/issues`)
  },
  createIssue(jobCardId: number, data: Partial<JobCardIssue>) {
    return apiClient.post<ApiResponse<JobCardIssue>>(`${jc(jobCardId)}/issues`, data)
  },
  updateIssue(jobCardId: number, issueId: number, data: Partial<JobCardIssue>) {
    return apiClient.patch<ApiResponse<JobCardIssue>>(`${jc(jobCardId)}/issues/${issueId}`, data)
  },
  deleteIssue(jobCardId: number, issueId: number) {
    return apiClient.delete(`${jc(jobCardId)}/issues/${issueId}`)
  },

  additionalJobs(jobCardId: number) {
    return apiClient.get<ApiResponse<JobCardAdditionalJob[]>>(`${jc(jobCardId)}/additional-jobs`)
  },
  createAdditionalJob(jobCardId: number, data: Partial<JobCardAdditionalJob>) {
    return apiClient.post<ApiResponse<JobCardAdditionalJob>>(`${jc(jobCardId)}/additional-jobs`, data)
  },
  updateAdditionalJob(jobCardId: number, jobId: number, data: Partial<JobCardAdditionalJob>) {
    return apiClient.patch<ApiResponse<JobCardAdditionalJob>>(`${jc(jobCardId)}/additional-jobs/${jobId}`, data)
  },
  deleteAdditionalJob(jobCardId: number, jobId: number) {
    return apiClient.delete(`${jc(jobCardId)}/additional-jobs/${jobId}`)
  },

  observations(jobCardId: number) {
    return apiClient.get<ApiResponse<JobCardObservation[]>>(`${jc(jobCardId)}/observations`)
  },
  createObservation(jobCardId: number, text: string) {
    return apiClient.post<ApiResponse<JobCardObservation>>(`${jc(jobCardId)}/observations`, { text })
  },
  updateObservation(jobCardId: number, observationId: number, data: Partial<JobCardObservation>) {
    return apiClient.patch<ApiResponse<JobCardObservation>>(`${jc(jobCardId)}/observations/${observationId}`, data)
  },
  deleteObservation(jobCardId: number, observationId: number) {
    return apiClient.delete(`${jc(jobCardId)}/observations/${observationId}`)
  },
}

// Job-sheet masters (owner/manager)
export const jobSheetMastersApi = {
  templates(includeInactive = false) {
    return apiClient.get<ApiResponse<ChecklistMasterTemplate[]>>('/workshop/job-sheet-masters/templates', {
      params: { include_inactive: includeInactive ? 1 : undefined },
    })
  },
  template(id: number) {
    return apiClient.get<ApiResponse<ChecklistMasterTemplate>>(`/workshop/job-sheet-masters/templates/${id}`)
  },
  updateTemplate(id: number, data: Partial<ChecklistMasterTemplate> & { package_ids?: number[] }) {
    return apiClient.put<ApiResponse<ChecklistMasterTemplate>>(`/workshop/job-sheet-masters/templates/${id}`, data)
  },
  createSection(templateId: number, name: string) {
    return apiClient.post<ApiResponse<ChecklistMasterSection>>(`/workshop/job-sheet-masters/templates/${templateId}/sections`, { name })
  },
  createItem(sectionId: number, data: Partial<ChecklistMasterItem>) {
    return apiClient.post<ApiResponse<ChecklistMasterItem>>(`/workshop/job-sheet-masters/sections/${sectionId}/items`, data)
  },
  updateItem(sectionId: number, itemId: number, data: Partial<ChecklistMasterItem>) {
    return apiClient.put<ApiResponse<ChecklistMasterItem>>(`/workshop/job-sheet-masters/sections/${sectionId}/items/${itemId}`, data)
  },
  reorderItems(sectionId: number, ids: number[]) {
    return apiClient.post<ApiResponse<ChecklistMasterItem[]>>(`/workshop/job-sheet-masters/sections/${sectionId}/items/reorder`, { ids })
  },
}
