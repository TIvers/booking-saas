<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { computed, onMounted, ref, watch } from 'vue'
import { api, getApiProblem } from '@/api/client'
import type { ApiResponse, Booking, DemoPayment, Service, Slot, StaffMember } from '@/api/types'
import AuthForm from '@/features/auth/AuthForm.vue'
import ServicePicker from '@/features/booking/ServicePicker.vue'
import SlotPicker from '@/features/booking/SlotPicker.vue'
import StaffPicker from '@/features/booking/StaffPicker.vue'
import { useSlots } from '@/composables/useSlots'
import { dateInTimezone, formatSlotDateTime, formatMoney } from '@/composables/useTimezone'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'

const queryClient = useQueryClient()
const tenantStore = useTenantStore()
const authStore = useAuthStore()
const services = ref<Service[]>([])
const staff = ref<StaffMember[]>([])
const catalogLoading = ref(true)
const catalogError = ref('')
const step = ref(1)
const serviceId = ref<number | null>(null)
const staffId = ref<number | null>(null)
const selectedDate = ref('')
const selectedSlot = ref<Slot | null>(null)
const bookingResult = ref<Booking | null>(null)
const demoPayment = ref<DemoPayment | null>(null)
const bookingBusy = ref(false)
const paymentBusy = ref(false)
const bookingError = ref('')
const paymentError = ref('')
const showAuthForm = ref(false)

const timezone = computed(() => tenantStore.tenant?.timezone ?? 'UTC')
const currency = computed(() => tenantStore.tenant?.currency ?? 'USD')
const demoPaymentsEnabled = computed(() => tenantStore.tenant?.demo_payments_enabled ?? false)
const minimumDate = computed(() => dateInTimezone(timezone.value))
const selectedService = computed(
  () => services.value.find((service) => service.id === serviceId.value) ?? null,
)
const availableStaff = computed(() => {
  if (serviceId.value === null) return []

  return staff.value.filter((member) => member.services.some((service) => service.id === serviceId.value))
})
const selectedStaff = computed(
  () => staff.value.find((member) => member.id === staffId.value) ?? null,
)
const slotsQuery = useSlots(serviceId, staffId, selectedDate)
const slots = computed(() => slotsQuery.data.value ?? [])
const slotError = computed(() =>
  slotsQuery.error.value
    ? getApiProblem(slotsQuery.error.value, 'Не удалось загрузить свободное время.').message
    : '',
)

watch(
  timezone,
  (value) => {
    if (!selectedDate.value) selectedDate.value = dateInTimezone(value)
  },
  { immediate: true },
)

watch([serviceId, staffId, selectedDate], () => {
  selectedSlot.value = null
})

async function loadCatalog(): Promise<void> {
  catalogLoading.value = true
  catalogError.value = ''

  try {
    const [serviceResponse, staffResponse] = await Promise.all([
      api.get<ApiResponse<Service[]>>('/services'),
      api.get<ApiResponse<StaffMember[]>>('/staff'),
    ])
    services.value = serviceResponse.data.data
    staff.value = staffResponse.data.data
  } catch (error) {
    catalogError.value = getApiProblem(error, 'Не удалось загрузить каталог.').message
  } finally {
    catalogLoading.value = false
  }
}

function chooseService(id: number): void {
  serviceId.value = id
  staffId.value = null
  selectedSlot.value = null
  bookingError.value = ''
}

function moveForward(): void {
  if (step.value === 1 && selectedService.value) step.value = 2
  else if (step.value === 2 && selectedStaff.value) step.value = 3
  else if (step.value === 3 && selectedSlot.value) step.value = 4
}

function moveBack(): void {
  if (step.value > 1) step.value -= 1
  bookingError.value = ''
}

async function confirmBooking(): Promise<void> {
  if (!selectedService.value || !selectedStaff.value || !selectedSlot.value) return

  if (selectedService.value.deposit_cents > 0 && !demoPaymentsEnabled.value) {
    bookingError.value = 'Демо-оплата недоступна для этой организации.'
    return
  }

  if (!authStore.isAuthenticated) {
    showAuthForm.value = true
    bookingError.value = ''
    return
  }

  bookingBusy.value = true
  bookingError.value = ''
  paymentError.value = ''
  demoPayment.value = null

  try {
    const response = await api.post<ApiResponse<Booking>>('/bookings', {
      service_id: selectedService.value.id,
      staff_id: selectedStaff.value.id,
      starts_at: selectedSlot.value.starts_at,
    })
    bookingResult.value = response.data.data
    if (selectedService.value.deposit_cents > 0) {
      await startDemoPayment()
    }
    await queryClient.invalidateQueries({
      queryKey: ['slots', serviceId.value, staffId.value, selectedDate.value],
    })
  } catch (error) {
    const problem = getApiProblem(error, 'Не удалось создать запись.')

    if (problem.status === 401) {
      authStore.clear()
      showAuthForm.value = true
      bookingError.value = 'Сессия завершилась. Войдите или зарегистрируйтесь ещё раз.'
    } else if (problem.status === 409 && problem.code === 'slot_already_taken') {
      selectedSlot.value = null
      step.value = 3
      bookingError.value = 'Это время только что заняли. Выберите другой свободный слот.'
      await slotsQuery.refetch()
    } else {
      bookingError.value = problem.message
    }
  } finally {
    bookingBusy.value = false
  }
}

async function startDemoPayment(): Promise<void> {
  if (!bookingResult.value) return

  paymentBusy.value = true
  paymentError.value = ''

  try {
    const response = await api.post<ApiResponse<DemoPayment>>(
      `/bookings/${bookingResult.value.id}/demo-payment`,
    )
    demoPayment.value = response.data.data
  } catch (error) {
    paymentError.value = getApiProblem(error, 'Не удалось подготовить демо-оплату.').message
  } finally {
    paymentBusy.value = false
  }
}

async function completeDemoPayment(outcome: 'succeeded' | 'failed'): Promise<void> {
  if (!bookingResult.value) return

  paymentBusy.value = true
  paymentError.value = ''

  try {
    const response = await api.post<
      ApiResponse<{ payment: DemoPayment; booking: Booking }>
    >(`/bookings/${bookingResult.value.id}/demo-payment/result`, { outcome })
    demoPayment.value = response.data.data.payment
    bookingResult.value = response.data.data.booking
    await queryClient.invalidateQueries({
      queryKey: ['slots', serviceId.value, staffId.value, selectedDate.value],
    })
  } catch (error) {
    paymentError.value = getApiProblem(error, 'Не удалось обработать демо-оплату.').message
  } finally {
    paymentBusy.value = false
  }
}

async function onAuthenticated(): Promise<void> {
  showAuthForm.value = false
  await confirmBooking()
}

function startAnotherBooking(): void {
  bookingResult.value = null
  demoPayment.value = null
  bookingError.value = ''
  paymentError.value = ''
  showAuthForm.value = false
  serviceId.value = null
  staffId.value = null
  selectedSlot.value = null
  step.value = 1
}

onMounted(loadCatalog)
</script>

<template>
  <section class="booking-page">
    <div class="booking-hero">
      <div>
        <p class="eyebrow">ОНЛАЙН-ЗАПИСЬ</p>
        <h1>Время для себя</h1>
        <p class="booking-hero__description">
          Выберите услугу, специалиста и удобное время — запись займёт всего пару минут.
        </p>
      </div>
      <div class="booking-hero__badge" aria-hidden="true">
        <span>01</span>
        <span class="booking-hero__badge-line"></span>
        <span>04</span>
      </div>
    </div>

    <div v-if="catalogLoading" class="booking-card booking-card--message" role="status">
      Загружаем услуги и расписание…
    </div>

    <div v-else-if="catalogError" class="booking-card booking-card--message booking-card--error">
      <p>{{ catalogError }}</p>
      <button type="button" class="link-button" @click="loadCatalog">Повторить загрузку</button>
    </div>

    <section v-else-if="bookingResult && selectedService && selectedService.deposit_cents > 0" class="booking-card booking-success">
      <template v-if="!demoPayment">
        <div class="booking-success__icon" aria-hidden="true">…</div>
        <p class="eyebrow">ДЕМО-ОПЛАТА</p>
        <h2>Предоплата</h2>
        <p class="booking-success__lead">
          {{ selectedService.name }} · {{ selectedStaff?.display_name }}
        </p>
        <p class="booking-success__time">
          {{ formatMoney(selectedService.deposit_cents, currency) }}
        </p>
        <p class="booking-success__note">
          Это демонстрационный режим: реальные деньги не списываются. Запись удерживается до
          {{ bookingResult.expires_at ? formatSlotDateTime(bookingResult.expires_at, timezone) : 'истечения резерва' }}.
        </p>
        <p v-if="paymentError" class="inline-error" role="alert">{{ paymentError }}</p>
        <button
          v-if="!paymentBusy && !bookingBusy"
          type="button"
          class="primary-button"
          @click="startDemoPayment"
        >
          Подготовить демо-оплату
        </button>
        <p v-else role="status">
          {{ bookingBusy ? 'Создаём резерв…' : 'Подготавливаем демо-оплату…' }}
        </p>
      </template>

      <template v-else-if="demoPayment.status === 'pending'">
        <div class="booking-success__icon" aria-hidden="true">₽</div>
        <p class="eyebrow">ДЕМО-РЕЖИМ</p>
        <h2>Проверьте оплату</h2>
        <p class="booking-success__lead">
          {{ selectedService.name }} · {{ selectedStaff?.display_name }}
        </p>
        <p class="booking-success__time">
          {{ formatMoney(demoPayment.amount_cents, demoPayment.currency) }}
        </p>
        <p class="booking-success__note">
          Нажмите один из вариантов для теста. Это не настоящая платёжная операция.
        </p>
        <p v-if="paymentError" class="inline-error" role="alert">{{ paymentError }}</p>
        <div class="wizard-actions wizard-actions--right">
          <button
            type="button"
            class="secondary-button"
            :disabled="paymentBusy"
            @click="completeDemoPayment('failed')"
          >
            Смоделировать отказ
          </button>
          <button
            type="button"
            class="primary-button"
            :disabled="paymentBusy"
            @click="completeDemoPayment('succeeded')"
          >
            {{ paymentBusy ? 'Обрабатываем…' : 'Симулировать оплату' }}
          </button>
        </div>
      </template>

      <template v-else-if="demoPayment.status === 'succeeded'">
        <div class="booking-success__icon" aria-hidden="true">✓</div>
        <p class="eyebrow">ДЕМО-ОПЛАТА УСПЕШНА</p>
        <h2>Вы записаны</h2>
        <p class="booking-success__lead">
          {{ selectedService.name }} · {{ selectedStaff?.display_name }}
        </p>
        <p class="booking-success__time">
          {{ formatSlotDateTime(bookingResult.starts_at, timezone) }}
        </p>
        <p class="booking-success__note">
          Предоплата {{ formatMoney(demoPayment.amount_cents, demoPayment.currency) }} отмечена как тестовая.
          Реальные деньги не списывались. Запись сохранена для аккаунта {{ authStore.user?.email }}.
        </p>
        <button type="button" class="primary-button" @click="startAnotherBooking">
          Записаться ещё
        </button>
      </template>

      <template v-else>
        <div class="booking-success__icon" aria-hidden="true">!</div>
        <p class="eyebrow">ДЕМО-ОПЛАТА</p>
        <h2>Оплата не прошла</h2>
        <p class="booking-success__note">
          Запись отменена, деньги не списывались. Вы можете выбрать другое время и попробовать снова.
        </p>
        <button type="button" class="primary-button" @click="startAnotherBooking">
          Выбрать другое время
        </button>
      </template>
    </section>

    <section v-else-if="bookingResult" class="booking-card booking-success">
      <div class="booking-success__icon" aria-hidden="true">✓</div>
      <p class="eyebrow">ГОТОВО</p>
      <h2>Вы записаны</h2>
      <p class="booking-success__lead">
        {{ selectedService?.name }} · {{ selectedStaff?.display_name }}
      </p>
      <p class="booking-success__time">
        {{ formatSlotDateTime(bookingResult.starts_at, timezone) }}
      </p>
      <p class="booking-success__note">
        Подтверждение записи сохранено для аккаунта {{ authStore.user?.email }}.
      </p>
      <button type="button" class="primary-button" @click="startAnotherBooking">
        Записаться ещё
      </button>
    </section>

    <section v-else class="booking-card">
      <div class="stepper" aria-label="Шаги записи">
        <div
          v-for="(label, index) in ['Услуга', 'Специалист', 'Время', 'Подтверждение']"
          :key="label"
          class="stepper__item"
          :class="{
            'stepper__item--active': step === index + 1,
            'stepper__item--done': step > index + 1,
          }"
        >
          <span class="stepper__number">{{ step > index + 1 ? '✓' : `0${index + 1}` }}</span>
          <span class="stepper__label">{{ label }}</span>
        </div>
      </div>

      <div class="booking-content">
        <template v-if="step === 1">
          <div class="section-heading">
            <div>
              <p class="eyebrow">ШАГ 1 ИЗ 4</p>
              <h2>Выберите услугу</h2>
            </div>
            <span class="section-heading__meta">{{ services.length }} услуг</span>
          </div>
          <ServicePicker
            :model-value="serviceId"
            :services="services"
            :currency="currency"
            :demo-payments-enabled="demoPaymentsEnabled"
            @update:model-value="chooseService"
          />
          <div class="wizard-actions wizard-actions--right">
            <button type="button" class="primary-button" :disabled="!selectedService" @click="moveForward">
              Продолжить
            </button>
          </div>
        </template>

        <template v-else-if="step === 2">
          <div class="section-heading">
            <div>
              <p class="eyebrow">ШАГ 2 ИЗ 4</p>
              <h2>Выберите специалиста</h2>
              <p class="section-heading__subtitle">{{ selectedService?.name }}</p>
            </div>
            <button type="button" class="text-link" @click="step = 1">Изменить услугу</button>
          </div>
          <StaffPicker v-model="staffId" :staff="availableStaff" />
          <div class="wizard-actions">
            <button type="button" class="secondary-button" @click="moveBack">Назад</button>
            <button type="button" class="primary-button" :disabled="!selectedStaff" @click="moveForward">
              Выбрать время
            </button>
          </div>
        </template>

        <template v-else-if="step === 3">
          <div class="section-heading">
            <div>
              <p class="eyebrow">ШАГ 3 ИЗ 4</p>
              <h2>Выберите дату и время</h2>
              <p class="section-heading__subtitle">
                {{ selectedService?.name }} · {{ selectedStaff?.display_name }}
              </p>
            </div>
          </div>
          <SlotPicker
            v-model="selectedSlot"
            :date="selectedDate"
            :minimum-date="minimumDate"
            :timezone="timezone"
            :slots="slots"
            :loading="slotsQuery.isFetching.value"
            :error="slotError"
            @update:date="selectedDate = $event"
            @refresh="slotsQuery.refetch()"
          />
          <p v-if="bookingError" class="inline-error" role="alert">{{ bookingError }}</p>
          <div class="wizard-actions">
            <button type="button" class="secondary-button" @click="moveBack">Назад</button>
            <button type="button" class="primary-button" :disabled="!selectedSlot" @click="moveForward">
              Проверить запись
            </button>
          </div>
        </template>

        <template v-else>
          <div class="section-heading">
            <div>
              <p class="eyebrow">ШАГ 4 ИЗ 4</p>
              <h2>Проверьте детали</h2>
            </div>
          </div>

          <dl class="booking-summary">
            <div>
              <dt>Услуга</dt>
              <dd>{{ selectedService?.name }}</dd>
            </div>
            <div>
              <dt>Специалист</dt>
              <dd>{{ selectedStaff?.display_name }} · {{ selectedStaff?.location.name }}</dd>
            </div>
            <div>
              <dt>Дата и время</dt>
              <dd v-if="selectedSlot">{{ formatSlotDateTime(selectedSlot.starts_at, timezone) }}</dd>
            </div>
            <div>
              <dt>Стоимость</dt>
              <dd>{{ formatMoney(selectedService?.price_cents ?? 0, currency) }}</dd>
            </div>
            <div v-if="(selectedService?.deposit_cents ?? 0) > 0">
              <dt>Демо-предоплата</dt>
              <dd>{{ formatMoney(selectedService?.deposit_cents ?? 0, currency) }}</dd>
            </div>
          </dl>

          <p v-if="bookingError" class="inline-error" role="alert">{{ bookingError }}</p>

          <div v-if="authStore.isAuthenticated" class="customer-note">
            Запись будет создана для <strong>{{ authStore.user?.name }}</strong>
            <span>{{ authStore.user?.email }}</span>
          </div>

          <div v-else-if="showAuthForm" class="auth-panel">
            <h3>Войдите или создайте аккаунт</h3>
            <p>Это нужно, чтобы сохранить запись и отправить её в ваш список.</p>
            <AuthForm @authenticated="onAuthenticated" />
          </div>

          <p v-else class="auth-hint">
            Чтобы подтвердить запись, войдите или создайте аккаунт. Выбранные услуга и время сохранятся.
          </p>

          <div class="wizard-actions">
            <button type="button" class="secondary-button" @click="moveBack">Назад</button>
            <button
              v-if="authStore.isAuthenticated"
              type="button"
              class="primary-button"
              :disabled="bookingBusy || paymentBusy"
              @click="confirmBooking"
            >
              {{
                bookingBusy
                  ? 'Создаём запись…'
                  : (selectedService?.deposit_cents ?? 0) > 0 ? 'Создать резерв и перейти к демо-оплате' : 'Подтвердить запись'
              }}
            </button>
            <button
              v-else-if="!showAuthForm"
              type="button"
              class="primary-button"
              @click="showAuthForm = true"
            >
              Войти или зарегистрироваться
            </button>
          </div>
        </template>
      </div>
    </section>
  </section>
</template>

<style scoped>
.booking-page {
  margin: 0 auto;
  max-width: 67rem;
}

.booking-hero {
  align-items: flex-end;
  color: #f6f7ee;
  display: flex;
  justify-content: space-between;
  margin: 1.5rem 0 1.35rem;
  min-height: 9.5rem;
  overflow: hidden;
  padding: 1.7rem clamp(1.25rem, 4vw, 2.5rem);
  position: relative;
}

.booking-hero::before {
  background:
    radial-gradient(ellipse at 86% 22%, #a9bf8c60 0, transparent 35%),
    linear-gradient(118deg, #193c32 0%, #2e5949 62%, #54745a 100%);
  border-radius: 1.2rem;
  content: '';
  inset: 0;
  position: absolute;
}

.booking-hero > * {
  position: relative;
}

.booking-hero h1 {
  font-size: clamp(2rem, 5vw, 3rem);
  letter-spacing: -0.05em;
  line-height: 1.05;
  margin: 0.55rem 0 0.6rem;
}

.booking-hero__description {
  color: #d3ded4;
  line-height: 1.5;
  margin: 0;
  max-width: 34rem;
}

.booking-hero .eyebrow {
  color: #c1d1b3;
}

.booking-hero__badge {
  align-items: center;
  color: #d0dec3;
  display: flex;
  font-size: 0.78rem;
  font-weight: 750;
  gap: 0.55rem;
  letter-spacing: 0.1em;
  padding-bottom: 0.25rem;
}

.booking-hero__badge-line {
  background: #d0dec388;
  height: 1px;
  width: 2.4rem;
}

.booking-card {
  background: #fff;
  border: 1px solid #e8ede8;
  border-radius: 1.2rem;
  box-shadow: 0 15px 50px #20372a0b;
  overflow: hidden;
}

.booking-card--message {
  color: #617067;
  line-height: 1.55;
  padding: 1.3rem 1.5rem;
}

.booking-card--error {
  color: #883b35;
}

.booking-card--error p {
  margin: 0 0 0.65rem;
}

.stepper {
  align-items: center;
  background: #fafbf9;
  border-bottom: 1px solid #e9eee9;
  display: flex;
  gap: clamp(0.35rem, 2vw, 1.6rem);
  justify-content: space-between;
  padding: 1rem clamp(1rem, 4vw, 2rem);
}

.stepper__item {
  align-items: center;
  color: #a0aaa2;
  display: flex;
  gap: 0.55rem;
  min-width: 0;
}

.stepper__item--active,
.stepper__item--done {
  color: #315b4c;
}

.stepper__number {
  align-items: center;
  border: 1px solid #d6dfd8;
  border-radius: 50%;
  display: inline-flex;
  flex: 0 0 1.85rem;
  font-size: 0.7rem;
  font-weight: 800;
  height: 1.85rem;
  justify-content: center;
}

.stepper__item--active .stepper__number,
.stepper__item--done .stepper__number {
  background: #e6efe7;
  border-color: #d3e2d5;
}

.stepper__label {
  font-size: 0.83rem;
  font-weight: 650;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.booking-content {
  padding: clamp(1.2rem, 4vw, 2.25rem);
}

.section-heading {
  align-items: center;
  display: flex;
  gap: 1rem;
  justify-content: space-between;
  margin-bottom: 1.35rem;
}

.section-heading h2 {
  color: #20372b;
  font-size: clamp(1.4rem, 3.5vw, 1.85rem);
  letter-spacing: -0.035em;
  margin: 0.35rem 0 0;
}

.section-heading__subtitle {
  color: #758078;
  margin: 0.45rem 0 0;
}

.section-heading__meta {
  color: #819087;
  font-size: 0.82rem;
}

.eyebrow {
  color: #6b8377;
  font-size: 0.69rem;
  font-weight: 800;
  letter-spacing: 0.15em;
  margin: 0;
}

.wizard-actions {
  align-items: center;
  border-top: 1px solid #edf1ed;
  display: flex;
  gap: 0.75rem;
  justify-content: space-between;
  margin-top: 1.6rem;
  padding-top: 1.25rem;
}

.wizard-actions--right {
  justify-content: flex-end;
}

.primary-button,
.secondary-button {
  border: 0;
  border-radius: 0.75rem;
  cursor: pointer;
  font: inherit;
  font-weight: 750;
  min-height: 2.85rem;
  padding: 0.7rem 1.15rem;
  transition: background-color 140ms ease, transform 140ms ease;
}

.primary-button {
  background: #204c43;
  color: #fff;
}

.primary-button:not(:disabled):hover {
  background: #173a33;
}

.primary-button:disabled,
.secondary-button:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.secondary-button {
  background: #edf2ed;
  color: #35584c;
}

.secondary-button:hover {
  background: #e2ebe3;
}

.text-link,
.link-button {
  background: transparent;
  border: 0;
  color: #366e5a;
  cursor: pointer;
  font: inherit;
  font-size: 0.86rem;
  font-weight: 700;
  text-decoration: underline;
}

.booking-summary {
  background: #f8faf7;
  border: 1px solid #eaf0ea;
  border-radius: 0.95rem;
  margin: 0;
  padding: 0.25rem 1rem;
}

.booking-summary > div {
  align-items: center;
  border-bottom: 1px solid #e9eee9;
  display: flex;
  gap: 1rem;
  justify-content: space-between;
  padding: 0.85rem 0;
}

.booking-summary > div:last-child {
  border-bottom: 0;
}

.booking-summary dt {
  color: #758078;
  font-size: 0.9rem;
}

.booking-summary dd {
  color: #263d31;
  font-size: 0.92rem;
  font-weight: 700;
  margin: 0;
  text-align: right;
}

.customer-note,
.auth-hint {
  background: #f5f8f3;
  border-radius: 0.75rem;
  color: #5f6e64;
  line-height: 1.55;
  margin-top: 1rem;
  padding: 0.85rem 1rem;
}

.customer-note span {
  display: block;
  font-size: 0.86rem;
  margin-top: 0.15rem;
}

.auth-hint {
  margin-bottom: 0;
}

.auth-panel {
  background: #f8faf7;
  border: 1px solid #e7eee7;
  border-radius: 1rem;
  margin-top: 1rem;
  padding: clamp(1rem, 3vw, 1.5rem);
}

.auth-panel h3 {
  color: #294237;
  margin: 0;
}

.auth-panel > p {
  color: #738078;
  font-size: 0.9rem;
  line-height: 1.5;
  margin: 0.4rem 0 1rem;
}

.inline-error {
  background: #fff1ef;
  border-radius: 0.7rem;
  color: #8d322c;
  line-height: 1.45;
  margin: 1rem 0 0;
  padding: 0.75rem 0.9rem;
}

.booking-success {
  margin: 2rem auto;
  max-width: 42rem;
  padding: clamp(1.5rem, 6vw, 3rem);
  text-align: center;
}

.booking-success__icon {
  align-items: center;
  background: #e7f2e7;
  border-radius: 50%;
  color: #2b775a;
  display: flex;
  font-size: 1.65rem;
  font-weight: 800;
  height: 3.7rem;
  justify-content: center;
  margin: 0 auto 1.25rem;
  width: 3.7rem;
}

.booking-success h2 {
  color: #20372b;
  font-size: 2rem;
  letter-spacing: -0.04em;
  margin: 0.45rem 0 1rem;
}

.booking-success__lead {
  color: #4a5e51;
  font-weight: 700;
  margin: 0;
}

.booking-success__time {
  color: #234a3e;
  font-size: 1.15rem;
  font-weight: 750;
  margin: 0.55rem 0;
}

.booking-success__note {
  color: #77827a;
  line-height: 1.55;
  margin: 1rem auto 1.4rem;
  max-width: 25rem;
}

@media (max-width: 640px) {
  .booking-hero {
    min-height: 8.5rem;
  }

  .booking-hero__badge {
    display: none;
  }

  .stepper {
    align-items: flex-start;
  }

  .stepper__item {
    flex-direction: column;
    gap: 0.35rem;
    text-align: center;
  }

  .stepper__label {
    font-size: 0.68rem;
  }

  .stepper__number {
    flex-basis: 1.7rem;
    height: 1.7rem;
    width: 1.7rem;
  }

  .section-heading {
    align-items: flex-start;
  }

  .booking-summary > div {
    align-items: flex-start;
    flex-direction: column;
    gap: 0.3rem;
  }

  .booking-summary dd {
    text-align: left;
  }
}
</style>
