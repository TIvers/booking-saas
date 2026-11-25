<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, getApiProblem } from '@/api/client'
import type { ApiResponse, BookingSettings } from '@/api/types'
import {
  bookingLeadTimeParts,
  bookingLeadTimeUnits,
  toBookingLeadTimeMinutes,
  type BookingLeadTimeUnit,
} from '@/composables/bookingLeadTime'

const settings = reactive({
  allow_customer_rescheduling: true,
  max_customer_reschedules: 'unlimited',
  booking_lead_time_value: '3',
  booking_lead_time_unit: 'hours' as BookingLeadTimeUnit,
})
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')

function applySettings(data: BookingSettings): void {
  settings.allow_customer_rescheduling = data.allow_customer_rescheduling
  settings.max_customer_reschedules = data.max_customer_reschedules === null
    ? 'unlimited'
    : String(data.max_customer_reschedules)

  const leadTime = bookingLeadTimeParts(data.booking_lead_time_minutes)
  settings.booking_lead_time_value = String(leadTime.value)
  settings.booking_lead_time_unit = leadTime.unit
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get<ApiResponse<BookingSettings>>('/manage/booking-settings')
    applySettings(response.data.data)
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить настройки записи.').message
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  const value = Number(settings.booking_lead_time_value)
  const bookingLeadTimeMinutes = toBookingLeadTimeMinutes(value, settings.booking_lead_time_unit)

  if (!Number.isSafeInteger(value) || value < 0
    || !Number.isSafeInteger(bookingLeadTimeMinutes)
    || bookingLeadTimeMinutes > 2147483647
  ) {
    error.value = 'Укажите корректный срок до записи.'
    return
  }

  saving.value = true
  error.value = ''
  notice.value = ''

  try {
    const response = await api.put<ApiResponse<BookingSettings>>('/manage/booking-settings', {
      allow_customer_rescheduling: settings.allow_customer_rescheduling,
      max_customer_reschedules: settings.max_customer_reschedules === 'unlimited'
        ? null
        : Number(settings.max_customer_reschedules),
      booking_lead_time_minutes: bookingLeadTimeMinutes,
    })
    applySettings(response.data.data)
    notice.value = 'Настройки записи сохранены.'
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось сохранить настройки записи.').message
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="booking-settings">
    <header class="page-heading">
      <div>
        <h2>Правила записи</h2>
        <p>Настройте перенос записей клиентами и минимальный срок до визита.</p>
      </div>
    </header>

    <p v-if="error" class="message message--error" role="alert">{{ error }}</p>
    <p v-if="notice" class="message message--success" role="status">{{ notice }}</p>
    <div v-if="loading" class="state-card" role="status">Загружаем настройки…</div>

    <form v-else class="settings-card" @submit.prevent="save">
      <fieldset class="settings-section">
        <legend>Перенос записи клиентом</legend>
        <label class="checkbox-field">
          <input v-model="settings.allow_customer_rescheduling" type="checkbox" />
          <span>Разрешить клиентам переносить записи</span>
        </label>

        <label class="field">
          <span>Максимум переносов одной записи</span>
          <select
            v-model="settings.max_customer_reschedules"
            :disabled="!settings.allow_customer_rescheduling"
          >
            <option value="1">1 раз</option>
            <option value="2">2 раза</option>
            <option value="3">3 раза</option>
            <option value="unlimited">Без лимита</option>
          </select>
        </label>
      </fieldset>

      <fieldset class="settings-section">
        <legend>Минимальный срок до записи</legend>
        <p class="settings-help">
          Клиент сможет выбрать только время, которое наступит не раньше указанного срока.
          Это значение используется по умолчанию для услуг.
        </p>
        <div class="lead-time-fields">
          <label class="field">
            <span>Срок</span>
            <input
              v-model="settings.booking_lead_time_value"
              type="number"
              min="0"
              step="1"
              required
            />
          </label>
          <label class="field">
            <span>Единица времени</span>
            <select v-model="settings.booking_lead_time_unit">
              <option
                v-for="unit in bookingLeadTimeUnits"
                :key="unit.value"
                :value="unit.value"
              >
                {{ unit.label }}
              </option>
            </select>
          </label>
        </div>
      </fieldset>

      <BaseButton type="submit" :loading="saving">Сохранить настройки</BaseButton>
    </form>
  </section>
</template>

<style scoped>
.booking-settings {
  display: grid;
  gap: 1rem;
}

.page-heading h2 {
  color: #23392d;
  font-size: 1.35rem;
  margin: 0;
}

.page-heading p,
.settings-help {
  color: #758078;
  font-size: 0.85rem;
  margin: 0.35rem 0 0;
}

.settings-card,
.state-card {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.95rem;
  display: grid;
  gap: 1.2rem;
  padding: 1.1rem;
}

.settings-section {
  border: 0;
  border-bottom: 1px solid #edf1ed;
  display: grid;
  gap: 0.8rem;
  margin: 0;
  padding: 0 0 1.2rem;
}

.settings-section legend {
  color: #30493b;
  font-size: 1rem;
  font-weight: 750;
  padding: 0 0 0.5rem;
}

.field {
  display: grid;
  gap: 0.35rem;
  max-width: 24rem;
}

.field span,
.checkbox-field span {
  color: #65756b;
  font-size: 0.8rem;
  font-weight: 700;
}

.field input,
.field select {
  background: #fff;
  border: 1px solid #d5dfd8;
  border-radius: 0.6rem;
  color: #23392d;
  font: inherit;
  min-height: 2.7rem;
  padding: 0.55rem 0.65rem;
}

.checkbox-field {
  align-items: center;
  display: flex;
  gap: 0.55rem;
}

.checkbox-field input {
  accent-color: #31574e;
  height: 1.05rem;
  width: 1.05rem;
}

.lead-time-fields {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.state-card {
  color: #68776e;
}

.message {
  border-radius: 0.65rem;
  font-size: 0.83rem;
  margin: 0;
  padding: 0.7rem 0.85rem;
}

.message--error {
  background: #fff1ef;
  color: #8d322c;
}

.message--success {
  background: #edf5ee;
  color: #3c674b;
}
</style>
