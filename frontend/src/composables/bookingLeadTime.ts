export type BookingLeadTimeUnit = 'minutes' | 'hours' | 'days'

export const bookingLeadTimeUnits: { value: BookingLeadTimeUnit; label: string }[] = [
  { value: 'minutes', label: 'Минуты' },
  { value: 'hours', label: 'Часы' },
  { value: 'days', label: 'Сутки (24 часа)' },
]

export function toBookingLeadTimeMinutes(value: number, unit: BookingLeadTimeUnit): number {
  const multiplier = unit === 'days' ? 1440 : unit === 'hours' ? 60 : 1
  return value * multiplier
}

export function bookingLeadTimeParts(minutes: number): {
  value: number
  unit: BookingLeadTimeUnit
} {
  if (minutes > 0 && minutes % 1440 === 0) {
    return { value: minutes / 1440, unit: 'days' }
  }
  if (minutes > 0 && minutes % 60 === 0) {
    return { value: minutes / 60, unit: 'hours' }
  }

  return { value: minutes, unit: 'minutes' }
}

export function formatBookingLeadTime(minutes: number): string {
  const { value, unit } = bookingLeadTimeParts(minutes)
  const suffix = unit === 'days' ? 'дн.' : unit === 'hours' ? 'ч' : 'мин'
  return `${value} ${suffix}`
}
