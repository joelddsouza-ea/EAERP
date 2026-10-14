import type { ServiceType } from '@/api/types'

// Mirrors the service_type values accepted by the backend job card requests
export const serviceTypeLabels: Record<ServiceType, string> = {
  scheduled: 'Scheduled Service',
  general_repair: 'General / Mechanical Repair',
  ac_service: 'AC Service & Repair',
  body_shop: 'Body Shop / Accidental',
  electrical: 'Electrical',
  detailing: 'Detailing',
  home_service: 'Home Service',
}

export const serviceTypeOptions = Object.entries(serviceTypeLabels).map(([value, label]) => ({
  value,
  label,
}))

export function serviceTypeLabel(type: string | null | undefined): string {
  return type ? serviceTypeLabels[type as ServiceType] ?? type : '-'
}
