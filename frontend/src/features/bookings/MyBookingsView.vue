<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, getApiProblem } from '@/api/client'
import type { ApiResponse, BookingRecord, PaginatedMeta, Slot } from '@/api/types'
import { dateInTimezone, formatMoney, formatSlotDateTime, formatSlotTime } from '@/composables/useTimezone'
import { getRescheduleChanges, rescheduleHistoryTooltip } from '@/composables/rescheduleHistory'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'

interface BookingPageResponse extends ApiResponse<BookingRecord[]> {
  meta: PaginatedMeta
}

const tenant = useTenantStore()
const auth = useAuthStore()
const bookings = ref<BookingRecord[]>([])
const meta = ref<PaginatedMeta | null>(null)
const status = ref('')
const page = ref(1)
const loading = ref(false)
const error = ref('')
const actionError = ref('')
const actionNotice = ref('')
const actionLoading = ref(false)
const cancelTarget = ref<BookingRecord | null>(null)
const cancelReason = ref('')
const rescheduleTarget = ref<BookingRecord | null>(null)
const rescheduleReason = ref('')
const rescheduleDate = ref('')
const rescheduleSlots = ref<Slot[]>([])
const selectedSlot = ref<Slot | null>(null)
const searchedRescheduleSlots = ref(false)
const rescheduleLoading = ref(false)
const telegramSettings = ref({
  bot_configured: false,
  telegram_linked: false,
  connected: false,
  remind_24h: true,
  remind_2h: true,
})
const telegramLoading = ref(false)
const telegramError = ref('')
const telegramNotice = ref('')
const telegramWebAppAvailable = computed(() => Boolean(window.Telegram?.WebApp?.initData))

const lastPage = computed(() => meta.value?.last_page ?? 1)

const statusOptions = [
  { value: '', label: 'Все записи' },
  { value: 'pending', label: 'Ожидает подтверждения' },
  { value: 'confirmed', label: 'Подтверждена' },
  { value: 'completed', label: 'Завершена' },
  { value: 'cancelled', label: 'Отменена' },
  { value: 'no_show', label: 'Неявка' },
]

async function load(targetPage = 1): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const params: Record<string, string | number> = {
      page: targetPage,
      per_page: 10,
    }
    if (status.value) params.status = status.value

    const response = await api.get<BookingPageResponse>('/bookings', { params })
    bookings.value = response.data.data
    meta.value = response.data.meta
    page.value = response.data.meta.current_page
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить ваши записи.').message
  } finally {
    loading.value = false
  }
}

function statusLabel(value: BookingRecord['status']): string {
  return statusOptions.find((option) => option.value === value)?.label ?? value
}

function canCancel(booking: BookingRecord): boolean {
  if (booking.status !== 'pending' && booking.status !== 'confirmed') return false
  if (new Date(booking.starts_at).getTime() <= Date.now()) return false

  return booking.status !== 'pending'
    || booking.expires_at === null
    || new Date(booking.expires_at).getTime() > Date.now()
}

function canReschedule(booking: BookingRecord): boolean {
  if (!canCancel(booking) || !tenant.tenant?.allow_customer_rescheduling) return false

  const maximum = tenant.tenant.max_customer_reschedules
  const rescheduleCount = getRescheduleChanges(booking).length
  return maximum === null || rescheduleCount < maximum
}

function openReschedule(booking: BookingRecord): void {
  rescheduleTarget.value = booking
  rescheduleReason.value = ''
  rescheduleDate.value = dateInTimezone(
    booking.staff.location.timezone,
    new Date(booking.starts_at),
  )
  rescheduleSlots.value = []
  selectedSlot.value = null
  searchedRescheduleSlots.value = false
  actionError.value = ''
}

async function cancelBooking(): Promise<void> {
  if (!cancelTarget.value) return

  actionLoading.value = true
  actionError.value = ''
  actionNotice.value = ''

  try {
    await api.post(`/bookings/${cancelTarget.value.id}/cancel`, {
      reason: cancelReason.value.trim() || null,
    })
    cancelTarget.value = null
    cancelReason.value = ''
    actionNotice.value = 'Запись отменена.'
    await load(page.value)
  } catch (requestError) {
    actionError.value = getApiProblem(requestError, 'Не удалось отменить запись.').message
  } finally {
    actionLoading.value = false
  }
}

async function loadRescheduleSlots(): Promise<void> {
  if (!rescheduleTarget.value || !rescheduleDate.value) return

  rescheduleLoading.value = true
  actionError.value = ''
  rescheduleSlots.value = []
  selectedSlot.value = null
  searchedRescheduleSlots.value = false

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
  actionNotice.value = ''

  try {
    await api.post(`/bookings/${rescheduleTarget.value.id}/reschedule`, {
      starts_at: selectedSlot.value.starts_at,
      reason: rescheduleReason.value.trim() || null,
    })
    rescheduleTarget.value = null
    actionNotice.value = 'Запись перенесена.'
    await load(page.value)
  } catch (requestError) {
    actionError.value = getApiProblem(requestError, 'Не удалось перенести запись.').message
  } finally {
    actionLoading.value = false
  }
}

async function loadTelegramSettings(): Promise<void> {
  try {
    const response = await api.get<ApiResponse<typeof telegramSettings.value>>('/telegram/subscription')
    telegramSettings.value = response.data.data
  } catch (requestError) {
    telegramError.value = getApiProblem(requestError, 'Не удалось загрузить настройки Telegram.').message
  }
}

function refreshTelegramSettings(): void {
  void loadTelegramSettings()
}

async function connectTelegramNotifications(): Promise<void> {
  telegramLoading.value = true
  telegramError.value = ''
  telegramNotice.value = ''

  try {
    const webApp = window.Telegram?.WebApp
    const bot = new URLSearchParams(window.location.search).get('tg_bot') === 'tenant'
      ? 'tenant'
      : 'main'

    if (!telegramSettings.value.telegram_linked && webApp?.initData) {
      await api.post('/auth/telegram/link', { init_data: webApp.initData, bot })
      telegramSettings.value.telegram_linked = true
    }

    if (!telegramSettings.value.telegram_linked) {
      telegramError.value = 'Сначала войдите через Telegram или откройте кабинет в Telegram WebApp, чтобы связать аккаунты.'
      return
    }

    const response = await api.post<ApiResponse<{ url: string }>>('/telegram/subscription')
    if (webApp) webApp.openTelegramLink(response.data.data.url)
    else window.open(response.data.data.url, '_blank', 'noopener,noreferrer')
    telegramNotice.value = 'Откройте Telegram-бота и нажмите Start, чтобы подтвердить подключение.'
  } catch (requestError) {
    telegramError.value = getApiProblem(requestError, 'Не удалось подготовить подключение уведомлений.').message
  } finally {
    telegramLoading.value = false
  }
}

async function saveTelegramPreferences(): Promise<void> {
  telegramLoading.value = true
  telegramError.value = ''
  telegramNotice.value = ''

  try {
    const response = await api.put<ApiResponse<{
      connected: boolean
      remind_24h: boolean
      remind_2h: boolean
    }>>('/telegram/subscription', {
      remind_24h: telegramSettings.value.remind_24h,
      remind_2h: telegramSettings.value.remind_2h,
    })
    Object.assign(telegramSettings.value, response.data.data)
    telegramNotice.value = 'Настройки уведомлений сохранены.'
  } catch (requestError) {
    telegramError.value = getApiProblem(requestError, 'Не удалось сохранить настройки.').message
  } finally {
    telegramLoading.value = false
  }
}

async function disconnectTelegramNotifications(): Promise<void> {
  telegramLoading.value = true
  telegramError.value = ''
  telegramNotice.value = ''

  try {
    await api.delete('/telegram/subscription')
    telegramSettings.value.connected = false
    telegramNotice.value = 'Уведомления отключены.'
  } catch (requestError) {
    telegramError.value = getApiProblem(requestError, 'Не удалось отключить уведомления.').message
  } finally {
    telegramLoading.value = false
  }
}

onMounted(() => {
  void load()
  void loadTelegramSettings()
  window.addEventListener('focus', refreshTelegramSettings)
})

onBeforeUnmount(() => {
  window.removeEventListener('focus', refreshTelegramSettings)
})
</script>

<template>
  <section class="my-bookings">
    <header class="page-heading">
      <div>
        <p class="eyebrow">ВАШИ ЗАПИСИ</p>
        <h1>Мои записи</h1>
        <p>Просматривайте записи, меняйте время или отменяйте планы.</p>
      </div>
      <span v-if="meta" class="result-count">{{ meta.total }} всего</span>
    </header>

    <section class="telegram-settings" aria-labelledby="telegram-settings-title">
      <div>
        <h2 id="telegram-settings-title">Уведомления в Telegram</h2>
        <p>Напомним о записи за 24 часа и за 2 часа, а также сообщим об отмене или переносе.</p>
      </div>
      <p v-if="telegramError" class="message message--error" role="alert">{{ telegramError }}</p>
      <p v-if="telegramNotice" class="message message--success" role="status">{{ telegramNotice }}</p>
      <p
        v-if="telegramSettings.bot_configured && !telegramSettings.telegram_linked && !telegramWebAppAvailable"
        class="empty-note"
      >
        Чтобы связать аккаунты, сначала войдите через Telegram или откройте эту страницу в Telegram WebApp.
      </p>
      <p v-if="!telegramSettings.bot_configured" class="empty-note">
        Организация ещё не подключила Telegram-бота.
      </p>
      <template v-else-if="telegramSettings.connected">
        <label class="telegram-toggle">
          <input v-model="telegramSettings.remind_24h" type="checkbox" @change="saveTelegramPreferences" />
          <span>Напоминать за 24 часа</span>
        </label>
        <label class="telegram-toggle">
          <input v-model="telegramSettings.remind_2h" type="checkbox" @change="saveTelegramPreferences" />
          <span>Напоминать за 2 часа</span>
        </label>
        <BaseButton variant="ghost" :disabled="telegramLoading" @click="disconnectTelegramNotifications">
          Отключить уведомления
        </BaseButton>
      </template>
      <BaseButton
        v-else-if="auth.role === 'customer'"
        variant="secondary"
        :loading="telegramLoading"
        :disabled="!telegramSettings.telegram_linked && !telegramWebAppAvailable"
        @click="connectTelegramNotifications"
      >
        Подключить Telegram
      </BaseButton>
    </section>

    <form class="filters" @submit.prevent="load(1)">
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

    <p v-if="actionNotice" class="message message--success" role="status">{{ actionNotice }}</p>
    <p v-if="actionError && !cancelTarget && !rescheduleTarget" class="message message--error" role="alert">
      {{ actionError }}
    </p>

    <div v-if="loading" class="state-card" role="status">Загружаем записи…</div>
    <div v-else-if="error" class="state-card state-card--error" role="alert">
      <p>{{ error }}</p>
      <BaseButton variant="secondary" @click="load(page)">Повторить</BaseButton>
    </div>
    <div v-else-if="!bookings.length" class="state-card empty-state">
      <h2>Записей пока нет</h2>
      <p>Выберите услугу и время, чтобы создать первую запись.</p>
      <RouterLink :to="{ name: 'booking', params: { tenantSlug: tenant.slug ?? '' } }">
        Перейти к онлайн-записи
      </RouterLink>
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
        </div>
        <div class="booking-card__details">
          <strong>{{ booking.service.name }}</strong>
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
        <div v-if="canReschedule(booking) || canCancel(booking)" class="booking-card__actions">
          <BaseButton
            v-if="canReschedule(booking)"
            variant="secondary"
            :disabled="actionLoading"
            @click="openReschedule(booking)"
          >
            Перенести
          </BaseButton>
          <BaseButton
            v-if="canCancel(booking)"
            variant="ghost"
            :disabled="actionLoading"
            @click="cancelTarget = booking; cancelReason = ''; actionError = ''"
          >
            Отменить
          </BaseButton>
        </div>
      </article>
    </div>

    <nav v-if="meta && meta.last_page > 1" class="pagination" aria-label="Страницы ваших записей">
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
        <h2 id="cancel-title">Отменить запись?</h2>
        <p>
          {{ cancelTarget.service.name }} ·
          {{ formatSlotDateTime(cancelTarget.starts_at, cancelTarget.staff.location.timezone) }}
        </p>
        <label class="field">
          <span>Причина отмены (необязательно)</span>
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

    <div v-if="rescheduleTarget" class="modal-backdrop" @click.self="rescheduleTarget = null">
      <section class="modal-card reschedule-card" role="dialog" aria-modal="true" aria-labelledby="reschedule-title">
        <h2 id="reschedule-title">Перенести запись</h2>
        <p>{{ rescheduleTarget.service.name }} · {{ rescheduleTarget.staff.display_name }}</p>
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
        <BaseButton
          variant="secondary"
          :loading="rescheduleLoading"
          :disabled="!rescheduleDate"
          @click="loadRescheduleSlots"
        >
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
.my-bookings {
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

.page-heading h1 {
  color: #23392d;
  font-size: clamp(1.5rem, 4vw, 2rem);
  margin: 0;
}

.page-heading p:not(.eyebrow) {
  color: #758078;
  font-size: 0.88rem;
  margin: 0.35rem 0 0;
}

.eyebrow {
  color: #678076;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.15em;
  margin: 0 0 0.45rem;
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

.telegram-settings {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.9rem;
  display: grid;
  gap: 0.7rem;
  padding: 1rem;
}

.telegram-settings h2 {
  color: #31574e;
  font-size: 1rem;
  margin: 0;
}

.telegram-settings > div > p {
  color: #6f7d73;
  font-size: 0.82rem;
  margin: 0.35rem 0 0;
}

.telegram-toggle {
  align-items: center;
  color: #526459;
  display: flex;
  gap: 0.55rem;
  font-size: 0.88rem;
}

.telegram-toggle input {
  accent-color: #31574e;
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
  align-items: flex-start;
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
}

.booking-card__price {
  color: #40594b;
  flex: 0 1 9rem;
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

.empty-state {
  text-align: center;
}

.empty-state h2 {
  color: #30493b;
  font-size: 1.15rem;
  margin: 0 0 0.4rem;
}

.empty-state a {
  color: #285447;
  font-weight: 700;
}

.message {
  border-radius: 0.65rem;
  font-size: 0.84rem;
  margin: 0;
  padding: 0.7rem 0.85rem;
}

.message--error {
  background: #fff1ef;
}

.message--success {
  background: #eaf5e9;
  color: #315f3c;
}

.modal-backdrop {
  align-items: center;
  background: #14251e88;
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
  box-shadow: 0 20px 70px #10231a40;
  color: #65756b;
  display: grid;
  gap: 0.9rem;
  max-height: min(90vh, 48rem);
  max-width: 34rem;
  overflow: auto;
  padding: 1.35rem;
  width: 100%;
}

.modal-card h2 {
  color: #23392d;
  font-size: 1.25rem;
  margin: 0;
}

.modal-card p {
  margin: 0;
}

.field input {
  width: 100%;
}

.slot-grid {
  display: grid;
  gap: 0.5rem;
  grid-template-columns: repeat(auto-fill, minmax(5rem, 1fr));
  max-height: 16rem;
  overflow: auto;
}

.slot-grid button {
  background: #f4f7f3;
  border: 1px solid #dce5dc;
  border-radius: 0.65rem;
  color: #31574e;
  cursor: pointer;
  font: inherit;
  font-weight: 700;
  padding: 0.65rem;
}

.slot-grid button.is-selected {
  background: #31574e;
  border-color: #31574e;
  color: #fff;
}

.empty-note {
  color: #7a867d;
  font-size: 0.82rem;
  margin: 0;
}

@media (max-width: 640px) {
  .page-heading,
  .booking-card {
    align-items: flex-start;
    flex-direction: column;
  }

  .booking-card__actions {
    justify-content: flex-start;
    width: 100%;
  }

  .modal-actions {
    align-items: stretch;
    flex-direction: column-reverse;
  }
}
</style>
