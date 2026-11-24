<script setup lang="ts">
import type { Service } from '@/api/types'
import { formatMoney } from '@/composables/useTimezone'

defineProps<{
  services: Service[]
  modelValue: number | null
  currency: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: number]
}>()
</script>

<template>
  <div v-if="services.length" class="service-grid">
    <article
      v-for="service in services"
      :key="service.id"
      class="service-card"
      :class="{ 'service-card--selected': modelValue === service.id }"
    >
      <div class="service-card__top">
        <div>
          <h3>{{ service.name }}</h3>
          <p v-if="service.description" class="service-card__description">
            {{ service.description }}
          </p>
        </div>
        <span class="service-card__duration">{{ service.duration_min }} мин</span>
      </div>

      <div class="service-card__bottom">
        <div>
          <strong>{{ formatMoney(service.price_cents, currency) }}</strong>
          <span v-if="service.deposit_cents > 0" class="service-card__deposit">
            Предоплата {{ formatMoney(service.deposit_cents, currency) }}
          </span>
        </div>
        <button
          type="button"
          class="service-card__select"
          :disabled="service.deposit_cents > 0"
          @click="emit('update:modelValue', service.id)"
        >
          {{ service.deposit_cents > 0 ? 'Оплата недоступна' : modelValue === service.id ? 'Выбрана' : 'Выбрать' }}
        </button>
      </div>

      <p v-if="service.deposit_cents > 0" class="service-card__notice">
        Для этой услуги нужна предоплата. Онлайн-оплата пока не подключена.
      </p>
    </article>
  </div>

  <div v-else class="empty-state">
    <h3>Услуг пока нет</h3>
    <p>Организация ещё не добавила услуги для онлайн-записи.</p>
  </div>
</template>

<style scoped>
.service-grid {
  display: grid;
  gap: 0.9rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.service-card {
  background: #fff;
  border: 1px solid #e1e9e2;
  border-radius: 1rem;
  min-width: 0;
  padding: 1.15rem;
  transition: border-color 140ms ease, box-shadow 140ms ease;
}

.service-card--selected {
  border-color: #477a69;
  box-shadow: 0 0 0 3px #477a6918;
}

.service-card__top,
.service-card__bottom {
  align-items: flex-start;
  display: flex;
  gap: 0.8rem;
  justify-content: space-between;
}

.service-card h3 {
  color: #23392d;
  font-size: 1.03rem;
  margin: 0;
}

.service-card__description {
  color: #748078;
  font-size: 0.88rem;
  line-height: 1.45;
  margin: 0.4rem 0 0;
}

.service-card__duration {
  background: #f0f4f0;
  border-radius: 99px;
  color: #597067;
  flex: 0 0 auto;
  font-size: 0.78rem;
  padding: 0.3rem 0.55rem;
}

.service-card__bottom {
  align-items: center;
  border-top: 1px solid #edf1ed;
  margin-top: 1rem;
  padding-top: 0.9rem;
}

.service-card__bottom > div {
  display: grid;
  gap: 0.25rem;
}

.service-card__bottom strong {
  color: #203d31;
  font-size: 1.02rem;
}

.service-card__deposit {
  color: #806344;
  font-size: 0.78rem;
}

.service-card__select {
  background: #eaf1ec;
  border: 0;
  border-radius: 0.65rem;
  color: #285447;
  cursor: pointer;
  flex: 0 0 auto;
  font: inherit;
  font-size: 0.85rem;
  font-weight: 700;
  min-height: 2.45rem;
  padding: 0.55rem 0.75rem;
}

.service-card__select:not(:disabled):hover {
  background: #dce9df;
}

.service-card__select:disabled {
  background: #f2f2ef;
  color: #7d827e;
  cursor: not-allowed;
}

.service-card__notice {
  color: #896f4f;
  font-size: 0.8rem;
  line-height: 1.45;
  margin: 0.8rem 0 0;
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
  .service-grid {
    grid-template-columns: 1fr;
  }
}
</style>
