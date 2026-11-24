export interface ApiResponse<T> {
  data: T
}

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
