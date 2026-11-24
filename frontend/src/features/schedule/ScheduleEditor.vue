<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, getApiProblem } from '@/api/client'
import type {
  ApiResponse,
  ScheduleException,
  StaffMember,
  WorkScheduleRange,
} from '@/api/types'

interface ScheduleData {
  staff_id: number
  work_schedules: WorkScheduleRange[]
  breaks: WorkScheduleRange[]
  exceptions: ScheduleException[]
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

const staff = ref<StaffMember[]>([])
const selectedStaffId = ref<number | null>(null)
const workSchedules = ref<WorkScheduleRange[]>([])
const breaks = ref<WorkScheduleRange[]>([])
const exceptions = ref<ScheduleException[]>([])
const exceptionDate = ref('')
const exceptionIsDayOff = ref(true)
const exceptionStart = ref('09:00')
const exceptionEnd = ref('18:00')
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')

const selectedStaff = computed(
  () => staff.value.find((member) => member.id === selectedStaffId.value) ?? null,
)

function rangesForDay(ranges: WorkScheduleRange[], weekday: number): WorkScheduleRange[] {
  return ranges.filter((range) => range.weekday === weekday)
}

function addRange(target: 'work' | 'break', weekday: number): void {
  const range = { weekday, start_time: target === 'work' ? '09:00' : '13:00', end_time: target === 'work' ? '18:00' : '14:00' }
  if (target === 'work') workSchedules.value.push(range)
  else breaks.value.push(range)
}

function removeRange(target: 'work' | 'break', range: WorkScheduleRange): void {
  const ranges = target === 'work' ? workSchedules : breaks
  const index = ranges.value.indexOf(range)
  if (index >= 0) ranges.value.splice(index, 1)
}

function normalizeTime(time: string): string {
  return time.slice(0, 5)
}

async function loadStaff(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get<ApiResponse<StaffMember[]>>('/manage/staff')
    staff.value = response.data.data
    selectedStaffId.value =
      staff.value.find((member) => member.is_active)?.id ?? staff.value[0]?.id ?? null
    if (selectedStaffId.value) await loadSchedule()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить команду.').message
  } finally {
    loading.value = false
  }
}

async function loadSchedule(): Promise<void> {
  if (!selectedStaffId.value) return

  loading.value = true
  error.value = ''
  notice.value = ''

  try {
    const response = await api.get<ApiResponse<ScheduleData>>(
      `/staff/${selectedStaffId.value}/schedule`,
    )
    workSchedules.value = response.data.data.work_schedules.map((range) => ({
      ...range,
      start_time: normalizeTime(range.start_time),
      end_time: normalizeTime(range.end_time),
    }))
    breaks.value = response.data.data.breaks.map((range) => ({
      ...range,
      start_time: normalizeTime(range.start_time),
      end_time: normalizeTime(range.end_time),
    }))
    exceptions.value = response.data.data.exceptions.map((exception) => ({
      ...exception,
      date: exception.date.slice(0, 10),
      start_time: exception.start_time ? normalizeTime(exception.start_time) : null,
      end_time: exception.end_time ? normalizeTime(exception.end_time) : null,
    }))
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить расписание.').message
  } finally {
    loading.value = false
  }
}

async function saveSchedule(): Promise<void> {
  if (!selectedStaffId.value) return

  saving.value = true
  error.value = ''
  notice.value = ''

  try {
    await api.put(`/staff/${selectedStaffId.value}/schedule`, {
      work_schedules: workSchedules.value.map(({ weekday, start_time, end_time }) => ({
        weekday,
        start_time,
        end_time,
      })),
      breaks: breaks.value.map(({ weekday, start_time, end_time }) => ({
        weekday,
        start_time,
        end_time,
      })),
    })
    notice.value = 'Расписание сохранено.'
    await loadSchedule()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось сохранить расписание.').message
  } finally {
    saving.value = false
  }
}

async function saveException(): Promise<void> {
  if (!selectedStaffId.value || !exceptionDate.value) return

  saving.value = true
  error.value = ''
  notice.value = ''

  try {
    await api.put(
      `/staff/${selectedStaffId.value}/schedule/exceptions/${exceptionDate.value}`,
      exceptionIsDayOff.value
        ? { is_day_off: true }
        : {
            is_day_off: false,
            start_time: exceptionStart.value,
            end_time: exceptionEnd.value,
          },
    )
    exceptionDate.value = ''
    notice.value = 'Исключение сохранено.'
    await loadSchedule()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось сохранить исключение.').message
  } finally {
    saving.value = false
  }
}

async function removeException(exception: ScheduleException): Promise<void> {
  if (!selectedStaffId.value) return

  saving.value = true
  error.value = ''
  notice.value = ''

  try {
    await api.delete(`/staff/${selectedStaffId.value}/schedule/exceptions/${exception.date}`)
    notice.value = 'Исключение удалено.'
    await loadSchedule()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось удалить исключение.').message
  } finally {
    saving.value = false
  }
}

onMounted(loadStaff)
</script>

<template>
  <section class="schedule-page">
    <header class="page-heading">
      <div>
        <h2>Расписание</h2>
        <p>Рабочие интервалы, перерывы и исключения для каждого специалиста.</p>
      </div>
      <label v-if="staff.length" class="staff-select">
        <span>Специалист</span>
        <select v-model.number="selectedStaffId" @change="loadSchedule">
          <option v-for="member in staff" :key="member.id" :value="member.id">
            {{ member.display_name }} · {{ member.location.name }}
          </option>
        </select>
      </label>
    </header>

    <p v-if="error" class="message message--error" role="alert">{{ error }}</p>
    <p v-if="notice" class="message message--success" role="status">{{ notice }}</p>
    <div v-if="loading" class="state-card" role="status">Загружаем расписание…</div>
    <div v-else-if="!staff.length" class="state-card">
      Сначала добавьте специалиста в разделе «Команда».
    </div>
    <template v-else-if="selectedStaff">
      <section class="schedule-card">
        <header class="section-heading">
          <div>
            <h3>{{ selectedStaff.display_name }}</h3>
            <p>{{ selectedStaff.location.name }} · {{ selectedStaff.location.timezone }}</p>
          </div>
          <BaseButton :loading="saving" @click="saveSchedule">Сохранить неделю</BaseButton>
        </header>

        <div class="weekday-list">
          <section v-for="day in weekdays" :key="day.value" class="weekday">
            <h4>{{ day.label }}</h4>
            <div class="range-group">
              <span class="range-group__label">Рабочее время</span>
              <div v-for="range in rangesForDay(workSchedules, day.value)" :key="range.id ?? `${day.value}-${workSchedules.indexOf(range)}`" class="time-range">
                <input v-model="range.start_time" type="time" :aria-label="`${day.label}: начало работы`" />
                <span>—</span>
                <input v-model="range.end_time" type="time" :aria-label="`${day.label}: конец работы`" />
                <button type="button" class="remove-button" :aria-label="`Удалить интервал ${day.label}`" @click="removeRange('work', range)">×</button>
              </div>
              <button type="button" class="text-button" @click="addRange('work', day.value)">+ Добавить интервал</button>
            </div>
            <div class="range-group range-group--break">
              <span class="range-group__label">Перерывы</span>
              <div v-for="range in rangesForDay(breaks, day.value)" :key="range.id ?? `${day.value}-${breaks.indexOf(range)}`" class="time-range">
                <input v-model="range.start_time" type="time" :aria-label="`${day.label}: начало перерыва`" />
                <span>—</span>
                <input v-model="range.end_time" type="time" :aria-label="`${day.label}: конец перерыва`" />
                <button type="button" class="remove-button" :aria-label="`Удалить перерыв ${day.label}`" @click="removeRange('break', range)">×</button>
              </div>
              <button type="button" class="text-button" @click="addRange('break', day.value)">+ Добавить перерыв</button>
            </div>
          </section>
        </div>
      </section>

      <section class="schedule-card">
        <header class="section-heading">
          <div>
            <h3>Исключения на даты</h3>
            <p>Выходной или особые часы на конкретный день.</p>
          </div>
        </header>
        <form class="exception-form" @submit.prevent="saveException">
          <label>
            <span>Дата</span>
            <input v-model="exceptionDate" type="date" required />
          </label>
          <label class="checkbox-field">
            <input v-model="exceptionIsDayOff" type="checkbox" />
            <span>Выходной</span>
          </label>
          <template v-if="!exceptionIsDayOff">
            <label>
              <span>С</span>
              <input v-model="exceptionStart" type="time" required />
            </label>
            <label>
              <span>По</span>
              <input v-model="exceptionEnd" type="time" required />
            </label>
          </template>
          <BaseButton type="submit" :loading="saving">Добавить / изменить</BaseButton>
        </form>

        <div v-if="exceptions.length" class="exception-list">
          <div v-for="exception in exceptions" :key="exception.id" class="exception-row">
            <strong>{{ exception.date }}</strong>
            <span>
              {{ exception.is_day_off ? 'Выходной' : `${exception.start_time} — ${exception.end_time}` }}
            </span>
            <button type="button" class="text-button" :disabled="saving" @click="removeException(exception)">
              Удалить
            </button>
          </div>
        </div>
        <p v-else class="empty-note">Исключений пока нет.</p>
      </section>
    </template>
  </section>
</template>

<style scoped>
.schedule-page {
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
.section-heading p {
  color: #758078;
  font-size: 0.84rem;
  margin: 0.3rem 0 0;
}

.staff-select,
.exception-form label:not(.checkbox-field) {
  display: grid;
  gap: 0.35rem;
}

.staff-select span,
.exception-form label > span {
  color: #65756b;
  font-size: 0.73rem;
  font-weight: 700;
}

.staff-select select,
.exception-form input:not([type='checkbox']) {
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
  border-radius: 1rem;
  padding: 1.1rem;
}

.section-heading {
  margin-bottom: 0.9rem;
}

.section-heading h3 {
  color: #2b4436;
  font-size: 1.05rem;
  margin: 0;
}

.weekday-list {
  display: grid;
  gap: 0.5rem;
}

.weekday {
  border-top: 1px solid #edf1ed;
  display: grid;
  gap: 0.75rem;
  grid-template-columns: minmax(7rem, 0.7fr) repeat(2, minmax(12rem, 1fr));
  padding: 0.75rem 0;
}

.weekday h4 {
  color: #3b5447;
  font-size: 0.84rem;
  margin: 0.25rem 0 0;
}

.range-group {
  display: grid;
  gap: 0.45rem;
}

.range-group__label {
  color: #7a877f;
  font-size: 0.7rem;
  font-weight: 700;
}

.time-range {
  align-items: center;
  display: flex;
  gap: 0.35rem;
}

.time-range input {
  background: #fff;
  border: 1px solid #dce5de;
  border-radius: 0.55rem;
  color: #344b3e;
  font: inherit;
  min-height: 2.25rem;
  min-width: 0;
  padding: 0.35rem;
  width: 6rem;
}

.remove-button {
  background: transparent;
  border: 0;
  color: #a45a50;
  cursor: pointer;
  font-size: 1.1rem;
}

.text-button {
  background: transparent;
  border: 0;
  color: #31574e;
  cursor: pointer;
  font: inherit;
  font-size: 0.76rem;
  font-weight: 700;
  justify-self: start;
}

.exception-form {
  align-items: end;
  display: flex;
  flex-wrap: wrap;
  gap: 0.7rem;
}

.checkbox-field {
  align-items: center;
  color: #4b5f52;
  display: flex;
  gap: 0.4rem;
  min-height: 2.65rem;
}

.checkbox-field input {
  accent-color: #31574e;
}

.exception-list {
  border-top: 1px solid #edf1ed;
  margin-top: 1rem;
}

.exception-row {
  align-items: center;
  border-bottom: 1px solid #edf1ed;
  color: #53665b;
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem 1rem;
  justify-content: space-between;
  padding: 0.65rem 0;
}

.exception-row strong {
  color: #344b3e;
}

.empty-note {
  color: #869189;
  font-size: 0.82rem;
  margin: 0.9rem 0 0;
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

@media (max-width: 800px) {
  .weekday {
    grid-template-columns: 1fr;
  }
}
</style>
