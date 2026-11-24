<script setup lang="ts">
import type { StaffMember } from '@/api/types'

defineProps<{
  staff: StaffMember[]
  modelValue: number | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: number]
}>()
</script>

<template>
  <div v-if="staff.length" class="staff-grid">
    <button
      v-for="member in staff"
      :key="member.id"
      type="button"
      class="staff-card"
      :class="{ 'staff-card--selected': modelValue === member.id }"
      :aria-pressed="modelValue === member.id"
      @click="emit('update:modelValue', member.id)"
    >
      <span class="staff-card__avatar" aria-hidden="true">{{ member.display_name.charAt(0) }}</span>
      <span class="staff-card__content">
        <strong>{{ member.display_name }}</strong>
        <small>{{ member.location.name }}</small>
      </span>
      <span class="staff-card__check" aria-hidden="true">
        {{ modelValue === member.id ? '✓' : '' }}
      </span>
    </button>
  </div>

  <div v-else class="empty-state">
    <h3>Нет доступных сотрудников</h3>
    <p>Для выбранной услуги пока не назначен сотрудник.</p>
  </div>
</template>

<style scoped>
.staff-grid {
  display: grid;
  gap: 0.8rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.staff-card {
  align-items: center;
  background: #fff;
  border: 1px solid #e1e9e2;
  border-radius: 0.95rem;
  color: inherit;
  cursor: pointer;
  display: flex;
  gap: 0.8rem;
  min-height: 5rem;
  padding: 0.85rem;
  text-align: left;
}

.staff-card:hover,
.staff-card--selected {
  border-color: #477a69;
}

.staff-card--selected {
  box-shadow: 0 0 0 3px #477a6918;
}

.staff-card__avatar {
  align-items: center;
  background: #e9f0e9;
  border-radius: 50%;
  color: #365e4e;
  display: inline-flex;
  flex: 0 0 2.65rem;
  font-size: 1rem;
  font-weight: 750;
  height: 2.65rem;
  justify-content: center;
  width: 2.65rem;
}

.staff-card__content {
  display: grid;
  gap: 0.25rem;
  min-width: 0;
}

.staff-card__content strong {
  color: #25392e;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.staff-card__content small {
  color: #728078;
}

.staff-card__check {
  color: #32735e;
  font-weight: 800;
  margin-left: auto;
}

.empty-state {
  background: #f8faf7;
  border: 1px dashed #cbd8ce;
  border-radius: 1rem;
  color: #64746a;
  padding: 2rem;
  text-align: center;
}

.empty-state h3 {
  color: #30493b;
  margin: 0 0 0.4rem;
}

.empty-state p {
  margin: 0;
}

@media (max-width: 640px) {
  .staff-grid {
    grid-template-columns: 1fr;
  }
}
</style>
