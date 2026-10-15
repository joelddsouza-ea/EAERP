import type { ServiceType } from '@/api/types'

// Mirrors the service_type values accepted by the backend job card requests.
// "scheduled" is a service-plan job: the plan itself (EA 360, …) is what users see.
export const serviceTypeLabels: Record<ServiceType, string> = {
  scheduled: 'Service Plan',
  general_repair: 'General / Mechanical Repair',
  ac_service: 'AC Service & Repair',
  body_shop: 'Body Shop / Accidental',
  electrical: 'Electrical',
  detailing: 'Detailing',
  home_service: 'Home Service',
}

export function serviceTypeLabel(type: string | null | undefined): string {
  return type ? serviceTypeLabels[type as ServiceType] ?? type : '-'
}

/**
 * What a job is for: the plan name for service-plan jobs (e.g. "EA 360"), otherwise the repair type.
 */
export function jobServiceLabel(job: { service_type?: string | null; package?: { name: string } | null }): string {
  if (job.service_type === 'scheduled' && job.package?.name) return job.package.name
  return serviceTypeLabel(job.service_type)
}
