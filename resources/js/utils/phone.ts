/**
 * Mobile numbers are 10 digits (the server removes +91 / 0 / spaces the same way).
 */
export function cleanMobile(value: string | null | undefined): string {
  const digits = (value ?? '').replace(/\D/g, '')
  if (digits.length === 12 && digits.startsWith('91')) return digits.slice(2)
  if (digits.length === 11 && digits.startsWith('0')) return digits.slice(1)
  return digits
}

export function isMobile(value: string | null | undefined): boolean {
  return /^[6-9]\d{9}$/.test(cleanMobile(value))
}

export const MOBILE_ERROR = 'Enter a 10-digit mobile number'
