<script setup lang="ts">
import type { Slot } from '@/api/types'
import { formatSlotTime } from '@/composables/useTimezone'

defineProps<{
  date: string
  minimumDate: string
  timezone: string
  slots: Slot[]
  modelValue: Slot | null
  loading: boolean
  error: string
}>()

const emit = defineEmits<{
  'update:date': [value: string]
  'update:modelValue': [value: Slot]
  refresh: []
}>()
</script>

<template>
  <div class="slot-picker">
    <label class="date-field">
      <span>Дата визита</span>
      <input
        type="date"
        :value="date"
        :min="minimumDate"
        @change="emit('update:date', ($event.target as HTMLInputElement).value)"
      />
    </label>

    <div v-if="loading" class="slot-message" role="status">
      <span class="loading-dot" aria-hidden="true"></span>
      Ищем свободное время…
    </div>

    <div v-else-if="error" class="slot-message slot-message--error" role="alert">
      <span>{{ error }}</span>
      <button type="button" class="text-button" @click="emit('refresh')">Повторить</button>
    </div>

    <div v-else-if="slots.length" class="slot-grid" aria-label="Свободное время">
      <button
        v-for="slot in slots"
        :key="slot.starts_at"
        type="button"
        class="slot-option"
        :class="{ 'slot-option--selected': modelValue?.starts_at === slot.starts_at }"
        :aria-pressed="modelValue?.starts_at === slot.starts_at"
        @click="emit('update:modelValue', slot)"
      >
        {{ formatSlotTime(slot.starts_at, timezone) }}
      </button>
    </div>

    <div v-else class="slot-message">
      На эту дату свободного времени нет. Выберите другой день.
    </div>

    <p class="timezone-note">Время указано для филиала · {{ timezone }}</p>
  </div>
</template>

<style scoped>
.slot-picker {
  display: grid;
  gap: 1.15rem;
}

.date-field {
  display: grid;
  gap: 0.45rem;
  max-width: 20rem;
}

.date-field span {
  color: #354a40;
  font-size: 0.9rem;
  font-weight: 650;
}

.date-field input {
  background: #fff;
  border: 1px solid #d5dfd8;
  border-radius: 0.7rem;
  color: #1b2c24;
  font: inherit;
  min-height: 2.85rem;
  padding: 0.6rem 0.75rem;
}

.date-field input:focus {
  border-color: #568073;
  outline: 3px solid #5680732b;
}

.slot-grid {
  display: grid;
  gap: 0.65rem;
  grid-template-columns: repeat(auto-fill, minmax(6.6rem, 1fr));
}

.slot-option {
  background: #fff;
  border: 1px solid #dce5de;
  border-radius: 0.7rem;
  color: #354c40;
  cursor: pointer;
  font: inherit;
  font-weight: 700;
  min-height: 2.9rem;
}

.slot-option:hover,
.slot-option--selected {
  background: #eaf2ec;
  border-color: #477a69;
  color: #204c43;
}

.slot-option--selected {
  box-shadow: 0 0 0 2px #477a6925;
}

.slot-message {
  align-items: center;
  background: #f7f9f6;
  border-radius: 0.8rem;
  color: #617067;
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
  min-height: 3.5rem;
  padding: 0.8rem 1rem;
}

.slot-message--error {
  background: #fff1ef;
  color: #8d322c;
}

.loading-dot {
  animation: pulse 1s ease-in-out infinite;
  background: #568073;
  border-radius: 50%;
  height: 0.55rem;
  width: 0.55rem;
}

.text-button {
  background: transparent;
  border: 0;
  color: #31574e;
  cursor: pointer;
  font: inherit;
  font-weight: 700;
  text-decoration: underline;
}

.timezone-note {
  color: #7b877f;
  font-size: 0.8rem;
  margin: -0.5rem 0 0;
}

@keyframes pulse {
  50% {
    opacity: 0.35;
  }
}
</style>
