import type { BookingRecord } from '@/api/types'

export interface BookingTimer {
  label: string
  state: 'soon' | 'running' | 'overdue'
}

function formatOverdue(minutes: number): string {
  if (minutes <= 120) return `${minutes} мин`

  const hours = Math.floor(minutes / 60)
  const days = Math.floor(hours / 24)
  const remainingHours = hours % 24

  if (days > 0) return remainingHours > 0 ? `${days} д ${remainingHours} ч` : `${days} д`

  return `${hours} ч ${minutes % 60} мин`
}

export function getBookingTimer(booking: BookingRecord, now = Date.now()): BookingTimer | null {
  const startsAt = Date.parse(booking.starts_at)
  if (!Number.isFinite(startsAt)) return null

  const timeToStart = startsAt - now
  if (timeToStart > 60 * 60 * 1000) return null
  if (timeToStart > 0) {
    return { label: `Через ${Math.ceil(timeToStart / 60_000)} мин`, state: 'soon' }
  }

  if (booking.status !== 'confirmed') return null

  const expectedEnd = startsAt + booking.service.duration_min * 60_000
  const timeToEnd = expectedEnd - now
  if (timeToEnd >= 0) {
    return { label: `До конца ${Math.ceil(timeToEnd / 60_000)} мин`, state: 'running' }
  }

  const overdueMinutes = Math.ceil(-timeToEnd / 60_000)
  return { label: `Просрочено ${formatOverdue(overdueMinutes)}`, state: 'overdue' }
}
