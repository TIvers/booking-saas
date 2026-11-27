<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, getApiProblem } from '@/api/client'
import type { ApiResponse, BookingRecord, PaginatedMeta } from '@/api/types'
import { getBookingTimer } from '@/composables/bookingTimer'
import { dateInTimezone, formatSlotTime } from '@/composables/useTimezone'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'

interface BookingPageResponse extends ApiResponse<BookingRecord[]> {
  meta: PaginatedMeta
}

interface CalendarDay {
  date: string
  label: string
  isToday: boolean
  bookings: BookingRecord[]
}

const tenant = useTenantStore()
const auth = useAuthStore()
const bookings = ref<BookingRecord[]>([])
const meta = ref<PaginatedMeta | null>(null)
const loading = ref(false)
const error = ref('')
const now = ref(Date.now())
const weekStart = ref(mondayOf(dateInTimezone(tenant.tenant?.timezone ?? 'UTC')))
let clockInterval: number | undefined

const weekDates = computed(() =>
  Array.from({ length: 7 }, (_, index) => addDays(weekStart.value, index)),
)

const days = computed<CalendarDay[]>(() => {
  const timezone = tenant.tenant?.timezone ?? 'UTC'
  const today = dateInTimezone(timezone)

  return weekDates.value.map((date) => ({
    date,
    label: new Intl.DateTimeFormat('ru-RU', {
      timeZone: 'UTC',
      weekday: 'short',
      day: 'numeric',
      month: 'short',
    }).format(new Date(`${date}T12:00:00Z`)),
    isToday: date === today,
    bookings: bookings.value
      .filter(
        (booking) =>
          dateInTimezone(timezone, new Date(booking.starts_at)) === date,
      )
      .sort((left, right) => left.starts_at.localeCompare(right.starts_at)),
  }))
})

const weekEnd = computed(() => weekDates.value.at(-1) ?? weekStart.value)
const bookingsLocation = computed(() => ({
  name: auth.role === 'staff' ? 'specialist-bookings' : 'manage-bookings',
  params: { tenantSlug: tenant.slug ?? '' },
}))

function addDays(date: string, count: number): string {
  const value = new Date(`${date}T12:00:00Z`)
  value.setUTCDate(value.getUTCDate() + count)
  return value.toISOString().slice(0, 10)
}

function mondayOf(date: string): string {
  const value = new Date(`${date}T12:00:00Z`)
  const weekday = value.getUTCDay() || 7
  value.setUTCDate(value.getUTCDate() - weekday + 1)
  return value.toISOString().slice(0, 10)
}

async function load(): Promise<void> {
  if (!weekStart.value) return

  loading.value = true
  error.value = ''

  try {
    const response = await api.get<BookingPageResponse>('/bookings', {
      params: {
        from: weekStart.value,
        to: weekEnd.value,
        per_page: 100,
      },
    })
    bookings.value = response.data.data
    meta.value = response.data.meta
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить календарь.').message
  } finally {
    loading.value = false
  }
}

function changeWeek(daysToMove: number): void {
  weekStart.value = addDays(weekStart.value, daysToMove)
  void load()
}

function statusLabel(status: BookingRecord['status']): string {
  const labels: Record<BookingRecord['status'], string> = {
    pending: 'Ожидает оплаты',
    confirmed: 'Подтверждена',
    completed: 'Завершена',
    cancelled: 'Отменена',
    no_show: 'Неявка',
  }

  return labels[status]
}

onMounted(() => {
  void load()
  clockInterval = window.setInterval(() => {
    now.value = Date.now()
  }, 60_000)
})

onUnmounted(() => {
  if (clockInterval !== undefined) window.clearInterval(clockInterval)
})
</script>

<template>
  <section class="calendar-page">
    <header class="calendar-heading">
      <div>
        <h2>Календарь записей</h2>
        <p>{{ weekStart }} — {{ weekEnd }} · {{ tenant.tenant?.timezone ?? 'UTC' }}</p>
      </div>
      <div class="calendar-heading__actions">
        <button type="button" class="calendar-button" :disabled="loading" @click="changeWeek(-7)">
          Предыдущая
        </button>
        <button
          type="button"
          class="calendar-button"
          :disabled="loading"
          @click="weekStart = mondayOf(dateInTimezone(tenant.tenant?.timezone ?? 'UTC')); load()"
        >
          Сегодня
        </button>
        <button type="button" class="calendar-button" :disabled="loading" @click="changeWeek(7)">
          Следующая
        </button>
      </div>
    </header>

    <div v-if="error" class="calendar-state calendar-state--error" role="alert">
      <p>{{ error }}</p>
      <button type="button" class="text-button" @click="load">Повторить</button>
    </div>
    <div v-else-if="loading && !bookings.length" class="calendar-state" role="status">
      Загружаем неделю…
    </div>

    <p v-if="meta && meta.total > bookings.length" class="calendar-warning">
      За неделю больше 100 записей. Откройте
      <RouterLink :to="bookingsLocation">
        список
      </RouterLink>
      для просмотра всех.
    </p>

    <div class="calendar-scroll">
      <div class="calendar-grid">
        <section
          v-for="day in days"
          :key="day.date"
          class="calendar-day"
          :class="{ 'calendar-day--today': day.isToday }"
        >
          <header class="calendar-day__heading">
            <strong>{{ day.label }}</strong>
            <small>{{ day.date }}</small>
          </header>
          <div v-if="loading" class="calendar-day__placeholder">Обновляем…</div>
          <div v-else-if="!day.bookings.length" class="calendar-day__empty">Нет записей</div>
          <article
            v-for="booking in day.bookings"
            :key="booking.id"
            class="calendar-booking"
            :class="[
              `calendar-booking--${booking.status}`,
              { 'calendar-booking--overdue': getBookingTimer(booking, now)?.state === 'overdue' },
            ]"
          >
            <strong>{{ formatSlotTime(booking.starts_at, booking.staff.location.timezone) }}</strong>
            <span>{{ booking.service.name }}</span>
            <small>{{ booking.customer.name }}</small>
            <small>{{ booking.staff.display_name }}</small>
            <span
              v-if="getBookingTimer(booking, now)?.state === 'overdue'"
              class="calendar-booking__overdue"
              :title="getBookingTimer(booking, now)?.label"
              aria-label="Просроченная запись"
            >
              ⚠ Просрочено
            </span>
            <span class="calendar-booking__status">{{ statusLabel(booking.status) }}</span>
          </article>
        </section>
      </div>
    </div>
  </section>
</template>

<style scoped>
.calendar-page {
  display: grid;
  gap: 1rem;
}

.calendar-heading,
.calendar-heading__actions {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
  justify-content: space-between;
}

.calendar-heading h2 {
  color: #23392d;
  font-size: 1.35rem;
  margin: 0;
}

.calendar-heading p {
  color: #758078;
  font-size: 0.85rem;
  margin: 0.35rem 0 0;
}

.calendar-button {
  background: #fff;
  border: 1px solid #dce5de;
  border-radius: 0.65rem;
  color: #31574e;
  cursor: pointer;
  font: inherit;
  font-size: 0.8rem;
  font-weight: 700;
  min-height: 2.45rem;
  padding: 0.45rem 0.7rem;
}

.calendar-button:disabled {
  cursor: wait;
  opacity: 0.6;
}

.calendar-scroll {
  overflow-x: auto;
  padding-bottom: 0.4rem;
}

.calendar-grid {
  display: grid;
  gap: 0.55rem;
  grid-template-columns: repeat(7, minmax(9.5rem, 1fr));
  min-width: 72rem;
}

.calendar-day {
  background: #f9fbf8;
  border: 1px solid #e3ebe3;
  border-radius: 0.8rem;
  min-height: 19rem;
  padding: 0.6rem;
}

.calendar-day--today {
  background: #eff5ef;
  border-color: #b8d0bd;
}

.calendar-day__heading {
  border-bottom: 1px solid #e2eae2;
  display: grid;
  gap: 0.15rem;
  margin-bottom: 0.55rem;
  padding: 0.15rem 0.1rem 0.55rem;
}

.calendar-day__heading strong {
  color: #345347;
  font-size: 0.83rem;
  text-transform: capitalize;
}

.calendar-day__heading small {
  color: #879189;
  font-size: 0.7rem;
}

.calendar-day__empty,
.calendar-day__placeholder {
  color: #9aa49c;
  font-size: 0.75rem;
  padding: 0.75rem 0.2rem;
}

.calendar-booking {
  background: #fff;
  border: 1px solid #e1e9e2;
  border-left: 3px solid #598470;
  border-radius: 0.55rem;
  display: grid;
  gap: 0.22rem;
  margin-bottom: 0.45rem;
  padding: 0.5rem;
}

.calendar-booking--pending {
  border-left-color: #bc934d;
}

.calendar-booking--cancelled,
.calendar-booking--no_show {
  opacity: 0.65;
}

.calendar-booking--overdue {
  border-color: #efb6aa;
  border-left-color: #b64c3c;
}

.calendar-booking__overdue {
  color: #a63f34;
  font-size: 0.68rem;
  font-weight: 800;
}

.calendar-booking > strong {
  color: #31574e;
  font-size: 0.78rem;
}

.calendar-booking > span:not(.calendar-booking__status) {
  color: #43594c;
  font-size: 0.75rem;
  font-weight: 700;
}

.calendar-booking small,
.calendar-booking__status {
  color: #808c83;
  font-size: 0.67rem;
  overflow-wrap: anywhere;
}

.calendar-state {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.8rem;
  color: #68776e;
  padding: 1rem;
}

.calendar-state p {
  margin: 0 0 0.4rem;
}

.calendar-state--error {
  background: #fff6f4;
  color: #8d322c;
}

.calendar-warning {
  background: #fff8e9;
  border-radius: 0.65rem;
  color: #7e6538;
  font-size: 0.82rem;
  margin: 0;
  padding: 0.7rem 0.85rem;
}

.calendar-warning a,
.text-button {
  color: #31574e;
  font-weight: 700;
}

.text-button {
  background: transparent;
  border: 0;
  cursor: pointer;
  font: inherit;
  text-decoration: underline;
}

@media (max-width: 640px) {
  .calendar-heading {
    align-items: stretch;
    flex-direction: column;
  }

  .calendar-heading__actions {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .calendar-button {
    min-width: 0;
    padding: 0.45rem 0.35rem;
  }

  .calendar-scroll {
    overflow: visible;
  }

  .calendar-grid {
    grid-template-columns: minmax(0, 1fr);
    min-width: 0;
  }

  .calendar-day {
    min-height: 0;
  }
}
</style>
