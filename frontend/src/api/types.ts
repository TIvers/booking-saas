export interface ApiResponse<T> {
  data: T
}

export type TenantRole = 'owner' | 'manager' | 'staff' | 'customer'

export interface Service {
  id: number
  name: string
  description: string | null
  duration_min: number
  buffer_min: number
  price_cents: number
  deposit_cents: number
  is_active: boolean
}

export interface StaffMember {
  id: number
  display_name: string
  is_active: boolean
  location: {
    id: number
    name: string
    timezone: string
  }
  services: Pick<Service, 'id' | 'name'>[]
}

export interface Slot {
  starts_at: string
  ends_at: string
}

export interface Booking {
  id: number
  status: 'pending' | 'confirmed' | 'completed' | 'cancelled' | 'no_show'
  starts_at: string
  ends_at: string
  expires_at: string | null
}

export interface DemoPayment {
  id: number
  status: 'pending' | 'succeeded' | 'failed'
  amount_cents: number
  currency: string
}

export interface BookingRecord extends Booking {
  service: Service
  staff: Pick<StaffMember, 'id' | 'display_name' | 'location'>
  customer: {
    id: number
    name: string
    email: string
  }
}

export interface PaginatedMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface DashboardData {
  period: {
    from: string
    to: string
    timezone: string
  }
  summary: {
    total: number
    pending: number
    confirmed: number
    cancelled: number
    completed: number
    no_show: number
    booked_value_cents: number
  }
  popular_services: {
    service_id: number
    name: string
    bookings_count: number
  }[]
  staff_activity: {
    staff_id: number
    display_name: string
    bookings_count: number
  }[]
  upcoming: BookingRecord[]
}

export interface WorkScheduleRange {
  id?: number
  weekday: number
  start_time: string
  end_time: string
}

export interface ScheduleException {
  id: number
  date: string
  is_day_off: boolean
  start_time: string | null
  end_time: string | null
}
