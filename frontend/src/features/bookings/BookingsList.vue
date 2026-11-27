<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, getApiProblem } from '@/api/client'
import type {
  ApiResponse,
  BookingRecord,
  PaginatedMeta,
  Slot,
} from '@/api/types'
import { getBookingTimer } from '@/composables/bookingTimer'
import { getRescheduleChanges, rescheduleHistoryTooltip } from '@/composables/rescheduleHistory'
import { dateInTimezone, formatMoney, formatSlotDateTime, formatSlotTime } from '@/composables/useTimezone'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'

interface BookingPageResponse extends ApiResponse<BookingRecord[]> {
  meta: PaginatedMeta
}

const tenant = useTenantStore()
const auth = useAuthStore()
const bookings = ref<BookingRecord[]>([])
const meta = ref<PaginatedMeta | null>(null)
const loading = ref(false)
const error = ref('')
const status = ref('')
const page = ref(1)
const from = ref('')
const to = ref('')
const cancelTarget = ref<BookingRecord | null>(null)
const cancelReason = ref('')
const completionTarget = ref<BookingRecord | null>(null)
const rescheduleTarget = ref<BookingRecord | null>(null)
const rescheduleReason = ref('')
const rescheduleDate = ref('')
const rescheduleSlots = ref<Slot[]>([])
const searchedRescheduleSlots = ref(false)
const selectedSlot = ref<Slot | null>(null)
const rescheduleLoading = ref(false)
const actionLoading = ref(false)
const actionError = ref('')
const now = ref(Date.now())
let clockInterval: number | undefined

const statusOptions = [
  { value: '', label: 'Все статусы' },
  { value: 'pending', label: 'Ожидает оплаты' },
  { value: 'confirmed', label: 'Подтверждена' },
  { value: 'completed', label: 'Завершена' },
  { value: 'cancelled', label: 'Отменена' },
  { value: 'no_show', label: 'Неявка' },
]

const lastPage = computed(() => meta.value?.last_page ?? 1)

function initializePeriod(): void {
  const today = dateInTimezone(tenant.tenant?.timezone ?? 'UTC')
  const [year, month] = today.split('-').map(Number)
  from.value = `${year}-${String(month).padStart(2, '0')}-01`
  to.value = new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10)
}

async function load(targetPage = 1): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const params: Record<string, string | number> = {
      from: from.value,
      to: to.value,
      page: targetPage,
      per_page: 25,
    }
    if (status.value) params.status = status.value

    const response = await api.get<BookingPageResponse>('/bookings', { params })
    bookings.value = response.data.data
    meta.value = response.data.meta
    page.value = response.data.meta.current_page
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить записи.').message
  } finally {
    loading.value = false
  }
}

function statusLabel(value: BookingRecord['status']): string {
  return statusOptions.find((option) => option.value === value)?.label ?? value
}

function canComplete(booking: BookingRecord): boolean {
  return (auth.canManageTenant || auth.role === 'staff')
    && booking.status === 'confirmed'
    && Date.parse(booking.starts_at) <= now.value
}

async function confirmBooking(booking: BookingRecord): Promise<void> {
  actionLoading.value = true
  actionError.value = ''

  try {
    await api.post(`/bookings/${booking.id}/confirm`)
    await load(page.value)
  } catch (requestError) {
    actionError.value = getApiProblem(requestError, 'Не удалось подтвердить запись.').message
  } finally {
    actionLoading.value = false
  }
}

async function completeBooking(): Promise<void> {
  if (!completionTarget.value) return

  actionLoading.value = true
  actionError.value = ''

  try {
    await api.post(`/bookings/${completionTarget.value.id}/complete`)
    completionTarget.value = null
    await load(page.value)
  } catch (requestError) {
    actionError.value = getApiProblem(requestError, 'Не удалось завершить запись.').message
  } finally {
    actionLoading.value = false
  }
}

async function cancelBooking(): Promise<void> {
  if (!cancelTarget.value) return

  actionLoading.value = true
  actionError.value = ''

  try {
    await api.post(`/bookings/${cancelTarget.value.id}/cancel`, {
      reason: cancelReason.value.trim() || null,
    })
    cancelTarget.value = null
    cancelReason.value = ''
    await load(page.value)
  } catch (requestError) {
    actionError.value = getApiProblem(requestError, 'Не удалось отменить запись.').message
  } finally {
    actionLoading.value = false
  }
}

function openReschedule(booking: BookingRecord): void {
  rescheduleTarget.value = booking
  rescheduleReason.value = ''
  rescheduleDate.value = dateInTimezone(
    booking.staff.location.timezone,
    new Date(booking.starts_at),
  )
  rescheduleSlots.value = []
  searchedRescheduleSlots.value = false
  selectedSlot.value = null
  actionError.value = ''
}

async function loadRescheduleSlots(): Promise<void> {
  if (!rescheduleTarget.value || !rescheduleDate.value) return

  rescheduleLoading.value = true
  actionError.value = ''

  try {
    const response = await api.get<ApiResponse<Slot[]>>('/slots', {
      params: {
        service_id: rescheduleTarget.value.service.id,
        staff_id: rescheduleTarget.value.staff.id,
        date: rescheduleDate.value,
      },
    })
    rescheduleSlots.value = response.data.data
    searchedRescheduleSlots.value = true
    selectedSlot.value = null
  } catch (requestError) {
    actionError.value = getApiProblem(requestError, 'Не удалось загрузить свободное время.').message
  } finally {
    rescheduleLoading.value = false
  }
}

async function saveReschedule(): Promise<void> {
  if (!rescheduleTarget.value || !selectedSlot.value) return

  actionLoading.value = true
  actionError.value = ''

  try {
    await api.post(`/bookings/${rescheduleTarget.value.id}/reschedule`, {
      starts_at: selectedSlot.value.starts_at,
      reason: rescheduleReason.value.trim() || null,
    })
    rescheduleTarget.value = null
    await load(page.value)
  } catch (requestError) {
    actionError.value = getApiProblem(requestError, 'Не удалось перенести запись.').message
  } finally {
    actionLoading.value = false
  }
}

onMounted(() => {
  clockInterval = window.setInterval(() => {
    now.value = Date.now()
  }, 15_000)
  initializePeriod()
  void load()
})

onBeforeUnmount(() => {
  if (clockInterval !== undefined) window.clearInterval(clockInterval)
})
</script>

<template>
  <section class="bookings-page">
    <header class="page-heading">
      <div>
        <h2>Записи</h2>
        <p>Фильтруйте, подтверждайте и переносите записи клиентов.</p>
      </div>
      <span v-if="meta" class="result-count">{{ meta.total }} всего</span>
    </header>

    <form class="filters" @submit.prevent="load(1)">
      <label>
        <span>С</span>
        <input v-model="from" type="date" required />
      </label>
      <label>
        <span>По</span>
        <input v-model="to" type="date" required />
      </label>
      <label>
        <span>Статус</span>
        <select v-model="status">
          <option v-for="option in statusOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>
      <BaseButton type="submit" :loading="loading">Показать</BaseButton>
    </form>

    <p
      v-if="actionError && !cancelTarget && !completionTarget && !rescheduleTarget"
      class="message message--error"
      role="alert"
    >
      {{ actionError }}
    </p>
    <div v-if="loading" class="state-card" role="status">Загружаем записи…</div>
    <div v-else-if="error" class="state-card state-card--error" role="alert">
      <p>{{ error }}</p>
      <BaseButton variant="secondary" @click="load(page)">Повторить</BaseButton>
    </div>
    <div v-else-if="!bookings.length" class="state-card">
      За выбранный период записей нет.
    </div>

    <div v-else class="booking-list">
      <article v-for="booking in bookings" :key="booking.id" class="booking-card">
        <div class="booking-card__date">
          <strong>{{ formatSlotDateTime(booking.starts_at, booking.staff.location.timezone) }}</strong>
          <span class="status-chip" :class="`status-chip--${booking.status}`">
            {{ statusLabel(booking.status) }}
          </span>
          <span
            v-if="getRescheduleChanges(booking).length"
            class="status-chip status-chip--rescheduled"
            :title="rescheduleHistoryTooltip(booking)"
            tabindex="0"
          >
            Перенесена<span v-if="getRescheduleChanges(booking).length > 1"> ×{{ getRescheduleChanges(booking).length }}</span>
          </span>
          <span
            v-if="getBookingTimer(booking, now)?.state === 'overdue'"
            class="status-chip status-chip--overdue"
          >
            {{ getBookingTimer(booking, now)?.label }}
          </span>
        </div>
        <div class="booking-card__details">
          <strong>{{ booking.service.name }}</strong>
          <span>
            {{ booking.customer.name }}<template v-if="booking.customer.email"> · {{ booking.customer.email }}</template>
          </span>
          <span>{{ booking.staff.display_name }} · {{ booking.staff.location.name }}</span>
        </div>
        <div
          v-if="booking.changes?.some((change) => change.action === 'cancelled' && change.reason)"
          class="booking-comments"
        >
          <p
            v-for="change in booking.changes"
            v-show="change.action === 'cancelled' && change.reason"
            :key="change.id"
          >
            <strong>Причина отмены:</strong>
            {{ change.reason }}
          </p>
        </div>
        <div class="booking-card__price">
          {{ formatMoney(booking.service.price_cents, tenant.tenant?.currency ?? 'USD') }}
        </div>
        <div class="booking-card__actions">
          <BaseButton
            v-if="canComplete(booking)"
            variant="secondary"
            :disabled="actionLoading"
            @click="completionTarget = booking; actionError = ''"
          >
            Завершить
          </BaseButton>
          <BaseButton
            v-if="auth.canManageTenant && booking.status === 'pending'"
            :loading="actionLoading"
            @click="confirmBooking(booking)"
          >
            Подтвердить
          </BaseButton>
          <BaseButton
            v-if="booking.status === 'confirmed' || (auth.role === 'staff' && booking.status === 'pending')"
            variant="secondary"
            :disabled="actionLoading"
            @click="openReschedule(booking)"
          >
            Перенести
          </BaseButton>
          <BaseButton
            v-if="booking.status === 'pending' || booking.status === 'confirmed'"
            variant="ghost"
            :disabled="actionLoading"
            @click="cancelTarget = booking; cancelReason = ''; actionError = ''"
          >
            Отменить
          </BaseButton>
        </div>
      </article>
    </div>

    <nav v-if="meta && meta.last_page > 1" class="pagination" aria-label="Страницы записей">
      <BaseButton variant="secondary" :disabled="page <= 1 || loading" @click="load(page - 1)">
        Назад
      </BaseButton>
      <span>Страница {{ page }} из {{ lastPage }}</span>
      <BaseButton variant="secondary" :disabled="page >= lastPage || loading" @click="load(page + 1)">
        Далее
      </BaseButton>
    </nav>

    <div v-if="cancelTarget" class="modal-backdrop" @click.self="cancelTarget = null">
      <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="cancel-title">
        <h3 id="cancel-title">Отменить запись?</h3>
        <p>
          {{ cancelTarget.customer.name }} ·
          {{ formatSlotDateTime(cancelTarget.starts_at, cancelTarget.staff.location.timezone) }}
        </p>
        <label class="field">
          <span>Причина (необязательно)</span>
          <textarea v-model.trim="cancelReason" maxlength="1000" rows="3" />
        </label>
        <p v-if="actionError" class="message message--error" role="alert">{{ actionError }}</p>
        <div class="modal-actions">
          <BaseButton variant="secondary" :disabled="actionLoading" @click="cancelTarget = null">
            Не отменять
          </BaseButton>
          <BaseButton :loading="actionLoading" @click="cancelBooking">Подтвердить отмену</BaseButton>
        </div>
      </section>
    </div>

    <div v-if="completionTarget" class="modal-backdrop" @click.self="completionTarget = null">
      <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="complete-title">
        <h3 id="complete-title">Завершить запись?</h3>
        <p>
          {{ completionTarget.service.name }} · {{ completionTarget.customer.name }}
        </p>
        <p v-if="actionError" class="message message--error" role="alert">{{ actionError }}</p>
        <div class="modal-actions">
          <BaseButton variant="secondary" :disabled="actionLoading" @click="completionTarget = null">
            Не сейчас
          </BaseButton>
          <BaseButton :loading="actionLoading" @click="completeBooking">Подтвердить завершение</BaseButton>
        </div>
      </section>
    </div>

    <div v-if="rescheduleTarget" class="modal-backdrop" @click.self="rescheduleTarget = null">
      <section class="modal-card reschedule-card" role="dialog" aria-modal="true" aria-labelledby="reschedule-title">
        <h3 id="reschedule-title">Перенести запись</h3>
        <p>{{ rescheduleTarget.service.name }} · {{ rescheduleTarget.customer.name }}</p>
        <label class="field">
          <span>Новая дата</span>
          <input
            v-model="rescheduleDate"
            type="date"
            :min="dateInTimezone(rescheduleTarget.staff.location.timezone)"
            @change="searchedRescheduleSlots = false; rescheduleSlots = []; selectedSlot = null"
          />
        </label>
        <label class="field">
          <span>Причина переноса (необязательно)</span>
          <textarea v-model.trim="rescheduleReason" maxlength="1000" rows="3" />
        </label>
        <BaseButton variant="secondary" :loading="rescheduleLoading" @click="loadRescheduleSlots">
          Найти время
        </BaseButton>
        <div v-if="rescheduleLoading" class="empty-note" role="status">Ищем свободное время…</div>
        <div v-else-if="rescheduleSlots.length" class="slot-grid">
          <button
            v-for="slot in rescheduleSlots"
            :key="slot.starts_at"
            type="button"
            :aria-pressed="selectedSlot?.starts_at === slot.starts_at"
            :class="{ 'is-selected': selectedSlot?.starts_at === slot.starts_at }"
            @click="selectedSlot = slot"
          >
            {{ formatSlotTime(slot.starts_at, rescheduleTarget.staff.location.timezone) }}
          </button>
        </div>
        <p v-if="actionError" class="message message--error" role="alert">{{ actionError }}</p>
        <p v-else-if="!rescheduleLoading && !rescheduleSlots.length" class="empty-note">
          {{ searchedRescheduleSlots ? 'На эту дату свободного времени нет.' : 'Выберите дату и нажмите «Найти время».' }}
        </p>
        <div class="modal-actions">
          <BaseButton variant="secondary" :disabled="actionLoading" @click="rescheduleTarget = null">
            Закрыть
          </BaseButton>
          <BaseButton :disabled="!selectedSlot" :loading="actionLoading" @click="saveReschedule">
            Перенести
          </BaseButton>
        </div>
      </section>
    </div>
  </section>
</template>

<style scoped>
.bookings-page {
  display: grid;
  gap: 1rem;
}

.page-heading,
.booking-card,
.booking-card__date,
.booking-card__actions,
.pagination,
.modal-actions {
  align-items: center;
  display: flex;
  gap: 0.8rem;
  justify-content: space-between;
}

.page-heading h2 {
  color: #23392d;
  font-size: 1.35rem;
  margin: 0;
}

.page-heading p {
  color: #758078;
  font-size: 0.88rem;
  margin: 0.35rem 0 0;
}

.result-count {
  color: #6f7d73;
  font-size: 0.82rem;
  white-space: nowrap;
}

.filters {
  align-items: end;
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.9rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  padding: 0.9rem;
}

.filters label,
.field {
  display: grid;
  gap: 0.35rem;
}

.filters label span,
.field span {
  color: #65756b;
  font-size: 0.75rem;
  font-weight: 700;
}

.filters input,
.filters select,
.field input,
.field textarea {
  background: #fff;
  border: 1px solid #d5dfd8;
  border-radius: 0.6rem;
  color: #23392d;
  font: inherit;
  min-height: 2.7rem;
  padding: 0.55rem 0.65rem;
}

.field textarea {
  resize: vertical;
}

.booking-comments {
  background: #f5f8f4;
  border-radius: 0.65rem;
  color: #647368;
  flex: 1 1 100%;
  font-size: 0.78rem;
  padding: 0.65rem 0.8rem;
}

.booking-comments p {
  margin: 0.15rem 0;
}

.booking-comments strong {
  color: #31574e;
}

.booking-list {
  display: grid;
  gap: 0.7rem;
}

.booking-card,
.state-card {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.9rem;
  padding: 1rem;
}

.booking-card {
  align-items: center;
  flex-wrap: wrap;
}

.booking-card__date {
  align-items: flex-start;
  flex: 1 1 12rem;
  flex-direction: column;
  justify-content: flex-start;
}

.booking-card__date > strong {
  color: #31574e;
  font-size: 0.86rem;
}

.booking-card__details {
  display: grid;
  flex: 2 1 14rem;
  gap: 0.2rem;
}

.booking-card__details strong {
  color: #253b2f;
}

.booking-card__details span {
  color: #78847c;
  font-size: 0.78rem;
  overflow-wrap: anywhere;
}

.booking-card__price {
  color: #40594b;
  flex: 0 1 10rem;
  font-size: 0.85rem;
  font-weight: 750;
}

.booking-card__actions {
  flex-wrap: wrap;
  justify-content: flex-end;
}

.status-chip {
  background: #eef4ed;
  border-radius: 99px;
  color: #477153;
  font-size: 0.68rem;
  font-weight: 700;
  padding: 0.3rem 0.55rem;
}

.status-chip--pending {
  background: #fff3dc;
  color: #8b6527;
}

.status-chip--cancelled,
.status-chip--no_show {
  background: #f9e9e6;
  color: #963f35;
}

.status-chip--rescheduled {
  background: #eaf3ff;
  color: #315e9b;
  cursor: help;
}

.status-chip--overdue {
  background: #fff0ed;
  color: #a63f34;
}

.status-chip--completed {
  background: #edf0f3;
  color: #576574;
}

.pagination {
  justify-content: center;
}

.pagination span {
  color: #68776e;
  font-size: 0.82rem;
}

.state-card {
  color: #68776e;
}

.state-card--error,
.message--error {
  color: #8d322c;
}

.state-card p {
  margin: 0 0 0.6rem;
}

.message {
  background: #fff1ef;
  border-radius: 0.65rem;
  font-size: 0.84rem;
  margin: 0;
  padding: 0.7rem 0.85rem;
}

.modal-backdrop {
  align-items: center;
  background: #17241d88;
  display: flex;
  inset: 0;
  justify-content: center;
  padding: 1rem;
  position: fixed;
  z-index: 20;
}

.modal-card {
  background: #fff;
  border-radius: 1rem;
  box-shadow: 0 24px 80px #10231c33;
  display: grid;
  gap: 0.9rem;
  max-width: 31rem;
  padding: 1.3rem;
  width: 100%;
}

.modal-card h3 {
  color: #23392d;
  margin: 0;
}

.modal-card > p {
  color: #6f7d73;
  font-size: 0.85rem;
  margin: 0;
}

.modal-actions {
  justify-content: flex-end;
  margin-top: 0.25rem;
}

.slot-grid {
  display: grid;
  gap: 0.5rem;
  grid-template-columns: repeat(auto-fill, minmax(5rem, 1fr));
  max-height: 12rem;
  overflow-y: auto;
}

.slot-grid button {
  background: #fff;
  border: 1px solid #dce5de;
  border-radius: 0.6rem;
  color: #354c40;
  cursor: pointer;
  font: inherit;
  font-weight: 700;
  min-height: 2.5rem;
}

.slot-grid button.is-selected {
  background: #eaf2ec;
  border-color: #477a69;
}

.empty-note {
  color: #7b877f;
  font-size: 0.82rem;
  margin: 0;
}

@media (max-width: 650px) {
  .booking-card {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }

  .booking-card__details {
    grid-column: 1 / -1;
    grid-row: 2;
  }

  .booking-card__actions {
    grid-column: 1 / -1;
    justify-content: flex-start;
  }

  .modal-actions {
    align-items: stretch;
    flex-direction: column-reverse;
  }

  .modal-backdrop {
    align-items: flex-end;
    padding: 0.5rem;
    padding-bottom: max(0.5rem, env(safe-area-inset-bottom));
  }

  .modal-card {
    max-height: calc(100dvh - 1rem - env(safe-area-inset-top));
    overflow-y: auto;
  }
}
</style>
