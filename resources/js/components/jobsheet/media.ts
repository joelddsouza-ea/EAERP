import type { JobSheetItem, MediaRequirement } from '@/api/types'

// Keep in sync with config/workshop.php job_sheets limits
export const MAX_VIDEO_BYTES = 50 * 1024 * 1024
export const MAX_VIDEO_SECONDS = 60
const MAX_PHOTO_EDGE = 1600

export interface PreparedMedia {
  file: Blob
  fileName: string
  kind: 'photo' | 'video'
  poster?: Blob | null
  durationSeconds?: number | null
}

export function acceptFor(requirement: MediaRequirement): string {
  if (requirement === 'photo') return 'image/*'
  if (requirement === 'video') return 'video/*'
  return 'image/*,video/*'
}

export function mediaHint(item: JobSheetItem): string | null {
  switch (item.media_requirement) {
    case 'photo':
      return item.min_media_count > 1 ? `${item.min_media_count} photos required` : 'Photo required'
    case 'video':
      return item.min_media_count > 1 ? `${item.min_media_count} videos required` : 'Video required'
    case 'photo_or_video':
      return 'Photo or video required'
    default:
      return null
  }
}

/**
 * Shrink photos and validate videos in the browser before upload, so phones on
 * mobile data aren't sending 8 MB camera originals.
 */
export async function prepareMedia(file: File): Promise<PreparedMedia> {
  if (file.type.startsWith('video/')) {
    if (file.size > MAX_VIDEO_BYTES) {
      throw new Error(`Videos must be under ${MAX_VIDEO_BYTES / 1024 / 1024} MB. Record a shorter clip.`)
    }
    const { duration, poster } = await inspectVideo(file)
    if (duration && duration > MAX_VIDEO_SECONDS + 0.5) {
      throw new Error(`Videos must be ${MAX_VIDEO_SECONDS} seconds or shorter (this one is ${Math.round(duration)}s).`)
    }
    return { file, fileName: file.name || 'video.mp4', kind: 'video', poster, durationSeconds: duration ? Math.round(duration * 100) / 100 : null }
  }

  const resized = await downscaleImage(file)
  return {
    file: resized ?? file,
    fileName: resized ? replaceExtension(file.name || 'photo', 'jpg') : file.name || 'photo.jpg',
    kind: 'photo',
  }
}

async function downscaleImage(file: File): Promise<Blob | null> {
  try {
    const bitmap = await createImageBitmap(file)
    const scale = Math.min(1, MAX_PHOTO_EDGE / Math.max(bitmap.width, bitmap.height))
    if (scale === 1 && file.size < 1.5 * 1024 * 1024) {
      bitmap.close()
      return null
    }
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)
    canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    bitmap.close()
    return await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8))
  } catch {
    // Formats the browser can't decode (e.g. HEIC outside Safari) upload as-is
    return null
  }
}

function inspectVideo(file: File): Promise<{ duration: number | null; poster: Blob | null }> {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file)
    const video = document.createElement('video')
    video.preload = 'metadata'
    video.muted = true
    video.playsInline = true
    video.src = url

    const finish = (duration: number | null, poster: Blob | null) => {
      URL.revokeObjectURL(url)
      resolve({ duration, poster })
    }

    const timeout = window.setTimeout(() => finish(null, null), 8000)

    video.onloadedmetadata = () => {
      // Seek a little in to avoid a black first frame
      video.currentTime = Math.min(0.5, (video.duration || 1) / 2)
    }
    video.onseeked = () => {
      window.clearTimeout(timeout)
      const duration = Number.isFinite(video.duration) ? video.duration : null
      try {
        const canvas = document.createElement('canvas')
        const scale = Math.min(1, 640 / Math.max(video.videoWidth || 1, video.videoHeight || 1))
        canvas.width = Math.max(1, Math.round(video.videoWidth * scale))
        canvas.height = Math.max(1, Math.round(video.videoHeight * scale))
        canvas.getContext('2d')!.drawImage(video, 0, 0, canvas.width, canvas.height)
        canvas.toBlob((blob) => finish(duration, blob), 'image/jpeg', 0.75)
      } catch {
        finish(duration, null)
      }
    }
    video.onerror = () => {
      window.clearTimeout(timeout)
      finish(null, null)
    }
  })
}

function replaceExtension(name: string, ext: string): string {
  const dot = name.lastIndexOf('.')
  return (dot > 0 ? name.slice(0, dot) : name) + '.' + ext
}
