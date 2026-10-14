import type { JobCardStatus } from '@/api/types'

export type BadgeVariant = 'default' | 'success' | 'warning' | 'danger' | 'info' | 'purple'

interface StatusMeta {
  label: string
  variant: BadgeVariant
  group: 'pickup' | 'intake' | 'work' | 'qc' | 'delivery' | 'closed'
  color: string
}

// Mirrors App\Modules\Workshop\Enums\JobCardStatus
export const JOB_CARD_STATUS_META: Record<JobCardStatus, StatusMeta> = {
  pickup_scheduled: { label: 'Pickup Scheduled', variant: 'info', group: 'pickup', color: '#93c5fd' },
  pickup_en_route: { label: 'Pickup En Route', variant: 'info', group: 'pickup', color: '#60a5fa' },
  vehicle_collected: { label: 'Vehicle Collected', variant: 'info', group: 'pickup', color: '#3b82f6' },
  intake_pending: { label: 'Intake Pending', variant: 'default', group: 'intake', color: '#6b7280' },
  intake_in_progress: { label: 'Intake In Progress', variant: 'info', group: 'intake', color: '#2563eb' },
  awaiting_approval: { label: 'Awaiting Approval', variant: 'warning', group: 'work', color: '#eab308' },
  work_in_progress: { label: 'Work In Progress', variant: 'warning', group: 'work', color: '#f59e0b' },
  awaiting_parts: { label: 'Awaiting Parts', variant: 'danger', group: 'work', color: '#ef4444' },
  post_work_trial: { label: 'Trial Drive / QA', variant: 'purple', group: 'qc', color: '#8b5cf6' },
  wash_pending: { label: 'Wash Pending', variant: 'purple', group: 'qc', color: '#a78bfa' },
  ready_for_delivery: { label: 'Ready For Delivery', variant: 'success', group: 'delivery', color: '#10b981' },
  invoiced: { label: 'Invoiced', variant: 'success', group: 'delivery', color: '#14b8a6' },
  gate_pass_generated: { label: 'Gate Pass Generated', variant: 'success', group: 'delivery', color: '#0d9488' },
  delivered: { label: 'Delivered', variant: 'success', group: 'closed', color: '#059669' },
  cancelled: { label: 'Cancelled', variant: 'danger', group: 'closed', color: '#dc2626' },
}

// Statuses with working features in Phase 1 (others are disabled in config/workshop.php)
export const ACTIVE_STATUSES: JobCardStatus[] = [
  'intake_pending',
  'intake_in_progress',
  'work_in_progress',
  'awaiting_parts',
  'post_work_trial',
  'wash_pending',
  'ready_for_delivery',
  'delivered',
  'cancelled',
]

export function statusLabel(status: string | null | undefined): string {
  return status ? JOB_CARD_STATUS_META[status as JobCardStatus]?.label ?? status : '-'
}

export function statusVariant(status: string | null | undefined): BadgeVariant {
  return status ? JOB_CARD_STATUS_META[status as JobCardStatus]?.variant ?? 'default' : 'default'
}

export function statusColor(status: string): string {
  return JOB_CARD_STATUS_META[status as JobCardStatus]?.color ?? '#9ca3af'
}

export const statusFilterOptions = ACTIVE_STATUSES.map((value) => ({ value, label: JOB_CARD_STATUS_META[value].label }))
