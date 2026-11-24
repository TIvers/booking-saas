<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, getApiProblem } from '@/api/client'
import type { ApiResponse, ScheduleException, WorkScheduleRange } from '@/api/types'

interface MySchedule {
  staff_id: number
  staff: {
    display_name: string
    location: {
      name: string
      timezone: string
    }
  }
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

const schedule = ref<MySchedule | null>(null)
const loading = ref(true)
const error = ref('')

function rangesForDay(ranges: WorkScheduleRange[], weekday: number): WorkScheduleRange[] {
  return ranges.filter((range) => range.weekday === weekday)
}

function formatTime(time: string): string {
  return time.slice(0, 5)
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get<ApiResponse<MySchedule>>('/staff/me/schedule')
    schedule.value = response.data.data
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить расписание.').message
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="my-schedule">
    <div v-if="loading" class="state-card" role="status">Загружаем расписание…</div>
    <div v-else-if="error" class="state-card state-card--error" role="alert">
      <p>{{ error }}</p>
      <button type="button" class="text-button" @click="load">Повторить</button>
    </div>
    <template v-else-if="schedule">
      <header class="schedule-heading">
        <div>
          <h2>{{ schedule.staff.display_name }}</h2>
          <p>{{ schedule.staff.location.name }} · {{ schedule.staff.location.timezone }}</p>
        </div>
        <span class="read-only-label">Только просмотр</span>
      </header>

      <section class="schedule-card" aria-label="Еженедельное расписание">
        <article v-for="day in weekdays" :key="day.value" class="weekday">
          <h3>{{ day.label }}</h3>
          <div v-if="rangesForDay(schedule.work_schedules, day.value).length" class="range-list">
            <div
              v-for="range in rangesForDay(schedule.work_schedules, day.value)"
              :key="`${range.start_time}-${range.end_time}`"
              class="range"
            >
              <span>{{ formatTime(range.start_time) }}–{{ formatTime(range.end_time) }}</span>
              <small
                v-for="breakRange in rangesForDay(schedule.breaks, day.value).filter(
                  (item) => item.start_time >= range.start_time && item.end_time <= range.end_time,
                )"
                :key="`break-${breakRange.start_time}`"
              >
                Перерыв {{ formatTime(breakRange.start_time) }}–{{ formatTime(breakRange.end_time) }}
              </small>
            </div>
          </div>
          <p v-else class="day-off">Выходной</p>
        </article>
      </section>

      <section class="exceptions">
        <h3>Особые даты</h3>
        <p v-if="!schedule.exceptions.length" class="day-off">Исключений нет.</p>
        <ul v-else>
          <li v-for="exception in schedule.exceptions" :key="exception.id">
            <strong>{{ exception.date.slice(0, 10) }}</strong>
            <span v-if="exception.is_day_off">Выходной</span>
            <span v-else>
              {{ exception.start_time ? formatTime(exception.start_time) : '' }}–
              {{ exception.end_time ? formatTime(exception.end_time) : '' }}
            </span>
          </li>
        </ul>
      </section>
    </template>
  </section>
</template>

<style scoped>
.my-schedule {
  display: grid;
  gap: 1rem;
}

.schedule-heading,
.weekday {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.85rem;
  padding: 1rem;
}

.schedule-heading {
  align-items: center;
  display: flex;
  gap: 0.75rem;
  justify-content: space-between;
}

.schedule-heading h2 {
  color: #23392d;
  font-size: 1.15rem;
  margin: 0;
}

.schedule-heading p {
  color: #758078;
  font-size: 0.82rem;
  margin: 0.3rem 0 0;
}

.read-only-label {
  background: #edf4ee;
  border-radius: 99px;
  color: #477153;
  font-size: 0.72rem;
  font-weight: 700;
  padding: 0.4rem 0.65rem;
  white-space: nowrap;
}

.schedule-card {
  display: grid;
  gap: 0.6rem;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
}

.weekday h3,
.exceptions h3 {
  color: #30493b;
  font-size: 0.9rem;
  margin: 0 0 0.6rem;
}

.range-list {
  display: grid;
  gap: 0.5rem;
}

.range {
  background: #f5f8f4;
  border-radius: 0.6rem;
  color: #31574e;
  display: grid;
  gap: 0.25rem;
  font-size: 0.85rem;
  font-weight: 700;
  padding: 0.65rem;
}

.range small {
  color: #7d7562;
  font-size: 0.72rem;
  font-weight: 500;
}

.day-off,
.state-card {
  color: #7b877f;
  font-size: 0.83rem;
  margin: 0;
}

.exceptions {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.85rem;
  padding: 1rem;
}

.exceptions ul {
  display: grid;
  gap: 0.5rem;
  list-style: none;
  margin: 0;
  padding: 0;
}

.exceptions li {
  color: #5e6f64;
  display: flex;
  flex-wrap: wrap;
  font-size: 0.82rem;
  gap: 0.5rem;
  justify-content: space-between;
}

.exceptions strong {
  color: #31574e;
}

.state-card {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.85rem;
  padding: 1rem;
}

.state-card p {
  margin: 0 0 0.6rem;
}

.state-card--error {
  color: #8d322c;
}

.text-button {
  background: transparent;
  border: 0;
  color: #31574e;
  cursor: pointer;
  font: inherit;
  font-weight: 700;
}
</style>
