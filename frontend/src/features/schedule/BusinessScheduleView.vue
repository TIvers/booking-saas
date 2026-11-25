<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, getApiProblem } from '@/api/client'
import type { ApiResponse } from '@/api/types'
import { dateInTimezone } from '@/composables/useTimezone'

interface LocationSummary {
  id: number
  name: string
  timezone: string
}

interface BusinessScheduleDay {
  weekday: number
  is_closed: boolean
  start_time: string | null
  end_time: string | null
  lunch_start_time: string | null
  lunch_end_time: string | null
}

interface ScheduleClosure {
  date: string
  reason: string
}

interface BusinessScheduleData {
  location: LocationSummary
  work_schedules: BusinessScheduleDay[]
  closures: ScheduleClosure[]
}

const weekdays = [
  { value: 1, label: 'Понедельник' },
  { value: 2, label: 'Вторник' },
  { value: 3, label: 'Среда' },
  { value: 4, label: 'Четверг' },
  { value: 5, label: 'Пятница' },
  { value: 6, label: 'Суббота' },
  { value: 7, label: 'Воскресенье' },
]

const closureReasons = [
  'Праздничный день',
  'Ремонт',
  'Санитарный день',
  'Техническое обслуживание',
  'Инвентаризация',
  'Корпоративное мероприятие',
  'Другое',
]

const locations = ref<LocationSummary[]>([])
const selectedLocationId = ref<number | null>(null)
const workSchedules = ref<BusinessScheduleDay[]>([])
const closures = ref<ScheduleClosure[]>([])
const closureDate = ref('')
const closureReasonType = ref(closureReasons[0])
const customClosureReason = ref('')
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')

const selectedLocation = computed(
  () => locations.value.find((location) => location.id === selectedLocationId.value) ?? null,
)
const minimumClosureDate = computed(() => (
  selectedLocation.value ? dateInTimezone(selectedLocation.value.timezone) : ''
))
const closureReason = computed(() => (
  closureReasonType.value === 'Другое'
    ? customClosureReason.value.trim()
    : closureReasonType.value
))

function normalizeTime(time: string | null): string | null {
  return time ? time.slice(0, 5) : null
}

function timeToMinutes(time: string | null): number | null {
  if (!time) return null

  const [hours, minutes] = time.split(':').map(Number)
  return hours * 60 + minutes
}

function minutesToTime(minutes: number): string {
  const hours = Math.floor(minutes / 60).toString().padStart(2, '0')
  const remainder = (minutes % 60).toString().padStart(2, '0')
  return `${hours}:${remainder}`
}

function hasLunch(day: BusinessScheduleDay): boolean {
  return day.lunch_start_time !== null && day.lunch_end_time !== null
}

function canSetLunch(day: BusinessScheduleDay): boolean {
  const start = timeToMinutes(day.start_time)
  const end = timeToMinutes(day.end_time)
  return start !== null && end !== null && end - start >= 15
}

function setDayOpen(day: BusinessScheduleDay, event: Event): void {
  const isOpen = (event.currentTarget as HTMLInputElement).checked
  day.is_closed = !isOpen

  if (isOpen) {
    day.start_time ??= '10:00'
    day.end_time ??= '18:00'
    return
  }

  day.start_time = null
  day.end_time = null
  day.lunch_start_time = null
  day.lunch_end_time = null
}

function setLunch(day: BusinessScheduleDay, event: Event): void {
  const hasLunchBreak = (event.currentTarget as HTMLInputElement).checked

  if (!hasLunchBreak) {
    day.lunch_start_time = null
    day.lunch_end_time = null
    return
  }

  const start = timeToMinutes(day.start_time)
  const end = timeToMinutes(day.end_time)
  if (start === null || end === null || end - start < 15) return

  const duration = Math.min(60, Math.floor((end - start) / 15) * 15)
  const lunchStart = Math.floor(start + (end - start - duration) / 2)
  day.lunch_start_time = minutesToTime(lunchStart)
  day.lunch_end_time = minutesToTime(lunchStart + duration)
}

function formatDate(date: string): string {
  return new Intl.DateTimeFormat('ru-RU', {
    timeZone: 'UTC',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date(`${date}T12:00:00Z`))
}

async function loadLocations(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get<ApiResponse<LocationSummary[]>>('/locations')
    locations.value = response.data.data
    selectedLocationId.value = locations.value[0]?.id ?? null
    if (selectedLocationId.value !== null) await loadSchedule()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить филиалы.').message
  } finally {
    loading.value = false
  }
}

async function loadSchedule(): Promise<void> {
  if (selectedLocationId.value === null) return

  loading.value = true
  error.value = ''
  notice.value = ''
  workSchedules.value = []
  closures.value = []

  try {
    const response = await api.get<ApiResponse<BusinessScheduleData>>(
      `/manage/locations/${selectedLocationId.value}/business-schedule`,
    )
    workSchedules.value = response.data.data.work_schedules.map((day) => ({
      ...day,
      start_time: normalizeTime(day.start_time),
      end_time: normalizeTime(day.end_time),
      lunch_start_time: normalizeTime(day.lunch_start_time),
      lunch_end_time: normalizeTime(day.lunch_end_time),
    }))
    closures.value = response.data.data.closures
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить график филиала.').message
  } finally {
    loading.value = false
  }
}

async function saveSchedule(): Promise<void> {
  if (selectedLocationId.value === null) return

  saving.value = true
  error.value = ''
  notice.value = ''

  try {
    const response = await api.put<ApiResponse<BusinessScheduleData>>(
      `/manage/locations/${selectedLocationId.value}/business-schedule`,
      {
        work_schedules: workSchedules.value.map((day) => ({
          weekday: day.weekday,
          is_closed: day.is_closed,
          start_time: day.is_closed ? null : day.start_time,
          end_time: day.is_closed ? null : day.end_time,
          lunch_start_time: day.is_closed ? null : day.lunch_start_time,
          lunch_end_time: day.is_closed ? null : day.lunch_end_time,
        })),
      },
    )
    workSchedules.value = response.data.data.work_schedules.map((day) => ({
      ...day,
      start_time: normalizeTime(day.start_time),
      end_time: normalizeTime(day.end_time),
      lunch_start_time: normalizeTime(day.lunch_start_time),
      lunch_end_time: normalizeTime(day.lunch_end_time),
    }))
    notice.value = 'График филиала сохранён.'
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось сохранить график.').message
  } finally {
    saving.value = false
  }
}

async function addClosure(): Promise<void> {
  if (selectedLocationId.value === null || !closureDate.value || !closureReason.value) return

  saving.value = true
  error.value = ''
  notice.value = ''

  try {
    await api.post(
      `/manage/locations/${selectedLocationId.value}/closures`,
      { date: closureDate.value, reason: closureReason.value },
    )
    closureDate.value = ''
    customClosureReason.value = ''
    notice.value = 'Нерабочий день добавлен.'
    await loadSchedule()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось добавить нерабочий день.').message
  } finally {
    saving.value = false
  }
}

async function removeClosure(closure: ScheduleClosure): Promise<void> {
  if (selectedLocationId.value === null) return

  saving.value = true
  error.value = ''
  notice.value = ''

  try {
    await api.delete(
      `/manage/locations/${selectedLocationId.value}/closures/${closure.date}`,
    )
    notice.value = 'Нерабочий день удалён.'
    await loadSchedule()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось удалить нерабочий день.').message
  } finally {
    saving.value = false
  }
}

onMounted(loadLocations)
</script>

<template>
  <section class="business-schedule">
    <header class="page-heading">
      <div>
        <h2>График заведения</h2>
        <p>Часы работы филиала ограничивают доступное время для всех его сотрудников.</p>
      </div>
      <label v-if="locations.length" class="location-select">
        <span>Филиал</span>
        <select v-model.number="selectedLocationId" @change="loadSchedule">
          <option v-for="location in locations" :key="location.id" :value="location.id">
            {{ location.name }}
          </option>
        </select>
      </label>
    </header>

    <p v-if="error" class="message message--error" role="alert">{{ error }}</p>
    <p v-if="notice" class="message message--success" role="status">{{ notice }}</p>
    <div v-if="loading" class="state-card" role="status">Загружаем график…</div>
    <div v-else-if="!locations.length" class="state-card">
      Сначала добавьте филиал в разделе «Команда».
    </div>

    <template v-else-if="selectedLocation">
      <section class="schedule-card" aria-labelledby="week-schedule-title">
        <header class="section-heading">
          <div>
            <h3 id="week-schedule-title">Еженедельный график</h3>
            <p>
              Часовой пояс: {{ selectedLocation.timezone }}.
              По умолчанию филиалы работают с понедельника по пятницу, с 10:00 до 18:00.
            </p>
          </div>
          <BaseButton
            :loading="saving"
            :disabled="!workSchedules.length"
            @click="saveSchedule"
          >
            Сохранить график
          </BaseButton>
        </header>

        <div class="schedule-table">
          <div class="schedule-table__head">
            <span>День недели</span>
            <span>Режим работы</span>
            <span>Обед</span>
            <span>Время обеда</span>
            <span>Открытие</span>
            <span>Закрытие</span>
          </div>
          <div v-for="day in workSchedules" :key="day.weekday" class="schedule-row">
            <strong>{{ weekdays.find((item) => item.value === day.weekday)?.label }}</strong>
            <label class="open-toggle">
              <input :checked="!day.is_closed" type="checkbox" @change="setDayOpen(day, $event)" />
              <span>Открыт</span>
            </label>
            <template v-if="!day.is_closed">
              <label class="open-toggle">
                <input
                  :checked="hasLunch(day)"
                  :disabled="!canSetLunch(day)"
                  type="checkbox"
                  @change="setLunch(day, $event)"
                />
                <span>Обед</span>
              </label>
              <div class="lunch-times-cell">
                <div class="lunch-times">
                  <template v-if="hasLunch(day)">
                    <input
                      v-model="day.lunch_start_time"
                      class="time-input"
                      type="time"
                      :aria-label="`Начало обеда, ${weekdays.find((item) => item.value === day.weekday)?.label}`"
                      required
                    />
                    <input
                      v-model="day.lunch_end_time"
                      class="time-input"
                      type="time"
                      :aria-label="`Конец обеда, ${weekdays.find((item) => item.value === day.weekday)?.label}`"
                      required
                    />
                  </template>
                  <template v-else>
                    <span class="lunch-placeholder">--</span>
                    <span class="lunch-placeholder">--</span>
                  </template>
                </div>
              </div>
              <input
                v-model="day.start_time"
                class="time-input"
                type="time"
                :aria-label="`Открытие, ${weekdays.find((item) => item.value === day.weekday)?.label}`"
                required
              />
              <input
                v-model="day.end_time"
                class="time-input"
                type="time"
                :aria-label="`Закрытие, ${weekdays.find((item) => item.value === day.weekday)?.label}`"
                required
              />
            </template>
            <span v-else class="closed-placeholder">Выходной</span>
          </div>
        </div>
      </section>

      <section class="schedule-card" aria-labelledby="closures-title">
        <div>
          <h3 id="closures-title">Отдельные нерабочие дни</h3>
          <p class="section-description">
            Выберите дату и причину — в этот день записи будут недоступны всем сотрудникам филиала.
          </p>
        </div>
        <form class="closure-form" @submit.prevent="addClosure">
          <label class="field">
            <span>Дата</span>
            <input v-model="closureDate" type="date" :min="minimumClosureDate" required />
          </label>
          <label class="field">
            <span>Причина</span>
            <select v-model="closureReasonType">
              <option v-for="reason in closureReasons" :key="reason" :value="reason">
                {{ reason }}
              </option>
            </select>
          </label>
          <label v-if="closureReasonType === 'Другое'" class="field">
            <span>Своя причина</span>
            <input v-model.trim="customClosureReason" maxlength="255" required />
          </label>
          <BaseButton type="submit" :loading="saving" :disabled="!closureReason">
            Добавить день
          </BaseButton>
        </form>

        <ul v-if="closures.length" class="closure-list">
          <li v-for="closure in closures" :key="closure.date">
            <div>
              <strong>{{ formatDate(closure.date) }}</strong>
              <span>{{ closure.reason }}</span>
            </div>
            <BaseButton
              variant="ghost"
              :disabled="saving"
              @click="removeClosure(closure)"
            >
              Удалить
            </BaseButton>
          </li>
        </ul>
        <p v-else class="empty-note">Дополнительных нерабочих дней пока нет.</p>
      </section>
    </template>
  </section>
</template>

<style scoped>
.business-schedule {
  display: grid;
  gap: 1rem;
}

.page-heading,
.section-heading {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.8rem;
  justify-content: space-between;
}

.page-heading h2 {
  color: #23392d;
  font-size: 1.35rem;
  margin: 0;
}

.page-heading p,
.section-heading p,
.section-description,
.empty-note {
  color: #758078;
  font-size: 0.84rem;
  margin: 0.35rem 0 0;
}

.location-select,
.field {
  display: grid;
  gap: 0.35rem;
}

.location-select {
  min-width: min(100%, 16rem);
}

.location-select span,
.field span {
  color: #65756b;
  font-size: 0.75rem;
  font-weight: 700;
}

.location-select select,
.field input,
.field select,
.time-input {
  background: #fff;
  border: 1px solid #d5dfd8;
  border-radius: 0.6rem;
  color: #23392d;
  font: inherit;
  min-height: 2.65rem;
  padding: 0.5rem 0.65rem;
}

.schedule-card,
.state-card {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.95rem;
  display: grid;
  gap: 1rem;
  padding: 1rem;
}

.section-heading h3,
.schedule-card > div > h3 {
  color: #30493b;
  font-size: 1rem;
  margin: 0;
}

.schedule-table {
  display: grid;
  gap: 0.4rem;
}

.schedule-table__head,
.schedule-row {
  align-items: center;
  display: grid;
  gap: 0.65rem;
  grid-template-columns: minmax(8rem, 1.2fr) minmax(7rem, 0.8fr) minmax(5rem, 0.6fr) minmax(11rem, 1.2fr) repeat(2, minmax(6rem, 0.8fr));
}

.schedule-table__head {
  color: #758078;
  font-size: 0.73rem;
  font-weight: 700;
  padding: 0 0.55rem 0.25rem;
}

.schedule-row {
  background: #f8faf7;
  border-radius: 0.65rem;
  min-height: 3.65rem;
  padding: 0.5rem 0.55rem;
}

.schedule-row strong {
  color: #30493b;
  font-size: 0.84rem;
}

.open-toggle {
  align-items: center;
  color: #65756b;
  display: flex;
  font-size: 0.78rem;
  gap: 0.4rem;
}

.open-toggle input {
  accent-color: #31574e;
}

.time-input {
  min-width: 0;
  width: 100%;
}

.closed-placeholder {
  color: #9aa49c;
  grid-column: 3 / -1;
  text-align: center;
}

.lunch-placeholder {
  color: #9aa49c;
  flex: 1 1 5rem;
  text-align: center;
}

.lunch-times-cell,
.lunch-times {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.lunch-times-cell {
  min-width: 0;
}

.lunch-times {
  width: 100%;
}

.lunch-times .time-input {
  flex: 1 1 5rem;
}

.closure-form {
  align-items: end;
  display: flex;
  flex-wrap: wrap;
  gap: 0.7rem;
}

.field {
  flex: 1 1 10rem;
}

.closure-list {
  display: grid;
  gap: 0.45rem;
  list-style: none;
  margin: 0;
  padding: 0;
}

.closure-list li {
  align-items: center;
  border-top: 1px solid #edf1ed;
  display: flex;
  flex-wrap: wrap;
  gap: 0.7rem;
  justify-content: space-between;
  padding: 0.65rem 0;
}

.closure-list li > div {
  display: grid;
  gap: 0.15rem;
}

.closure-list strong {
  color: #30493b;
  font-size: 0.84rem;
}

.closure-list span,
.empty-note {
  color: #758078;
  font-size: 0.78rem;
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
  background: #edf7ee;
  color: #386348;
}

.state-card {
  color: #68776e;
}

@media (max-width: 650px) {
  .schedule-table__head {
    display: none;
  }

  .schedule-row {
    grid-template-columns: 1fr 1fr;
  }

  .schedule-row strong {
    grid-column: 1 / -1;
  }

  .closed-placeholder,
  .lunch-times-cell {
    grid-column: 1 / -1;
  }
}
</style>
