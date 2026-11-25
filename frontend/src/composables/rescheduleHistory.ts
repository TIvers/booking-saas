import type { BookingChange, BookingRecord } from '@/api/types'
import { formatSlotDateTime } from '@/composables/useTimezone'

export function getRescheduleChanges(booking: BookingRecord): BookingChange[] {
  return (booking.changes ?? [])
    .filter((change) => change.action === 'rescheduled' && change.to_starts_at !== null)
    .sort((left, right) => left.created_at.localeCompare(right.created_at))
}

export function rescheduleHistoryTooltip(booking: BookingRecord): string {
  const timezone = booking.staff.location.timezone

  return getRescheduleChanges(booking)
    .map((change, index) => {
      if (change.to_starts_at === null) return ''

      return [
        `Перенос ${index + 1}: ${formatSlotDateTime(change.from_starts_at, timezone)} → ${formatSlotDateTime(change.to_starts_at, timezone)}`,
        `Выполнен: ${formatSlotDateTime(change.created_at, timezone)}`,
        change.reason ? `Причина: ${change.reason}` : '',
      ].filter(Boolean).join('\n')
    })
    .filter(Boolean)
    .join('\n\n')
}
