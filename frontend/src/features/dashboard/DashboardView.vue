<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, getApiProblem } from '@/api/client'
import type { ApiResponse, DashboardData } from '@/api/types'
import { formatMoney, formatSlotDateTime } from '@/composables/useTimezone'
import { useTenantStore } from '@/stores/tenant'

const tenant = useTenantStore()
const data = ref<DashboardData | null>(null)
const loading = ref(true)
const error = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get<ApiResponse<DashboardData>>('/manage/dashboard')
    data.value = response.data.data
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить сводку.').message
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="dashboard">
    <div class="dashboard__intro">
      <div>
        <h2>Обзор</h2>
        <p v-if="data">
          {{ data.period.from }} — {{ data.period.to }} · {{ data.period.timezone }}
        </p>
        <p v-else>Записи и активность организации</p>
      </div>
      <RouterLink class="text-link" :to="{ name: 'manage-bookings' }">Все записи</RouterLink>
    </div>

    <div v-if="loading" class="state-card" role="status">Загружаем сводку…</div>
    <div v-else-if="error" class="state-card state-card--error" role="alert">
      <p>{{ error }}</p>
      <button type="button" class="text-button" @click="load">Повторить</button>
    </div>

    <template v-else-if="data">
      <div class="stat-grid">
        <article class="stat-card stat-card--accent">
          <span>Записей за месяц</span>
          <strong>{{ data.summary.total }}</strong>
          <small>{{ data.summary.confirmed }} подтверждено</small>
        </article>
        <article class="stat-card">
          <span>Ожидают подтверждения</span>
          <strong>{{ data.summary.pending }}</strong>
          <small>{{ data.summary.cancelled }} отменено за месяц</small>
        </article>
        <article class="stat-card">
          <span>Завершено</span>
          <strong>{{ data.summary.completed }}</strong>
          <small>{{ data.summary.no_show }} неявок</small>
        </article>
        <article class="stat-card">
          <span>Стоимость подтверждённых</span>
          <strong>{{ formatMoney(data.summary.booked_value_cents, tenant.tenant?.currency ?? 'USD') }}</strong>
          <small>Без учёта фактических оплат</small>
        </article>
      </div>

      <div class="dashboard__columns">
        <section class="panel">
          <header class="panel__heading">
            <div>
              <p class="eyebrow">БЛИЖАЙШИЕ</p>
              <h3>Предстоящие записи</h3>
            </div>
            <RouterLink class="text-link" :to="{ name: 'manage-calendar' }">Календарь</RouterLink>
          </header>

          <div v-if="!data.upcoming.length" class="empty-state">
            Пока нет предстоящих записей.
          </div>
          <ul v-else class="upcoming-list">
            <li v-for="booking in data.upcoming" :key="booking.id">
              <span class="upcoming-list__time">
                {{ formatSlotDateTime(booking.starts_at, booking.staff.location.timezone) }}
              </span>
              <span class="upcoming-list__main">
                <strong>{{ booking.service.name }}</strong>
                <small>{{ booking.customer.name }} · {{ booking.staff.display_name }}</small>
              </span>
              <span class="status-chip" :class="`status-chip--${booking.status}`">
                {{ booking.status === 'pending' ? 'Ожидает' : 'Подтверждена' }}
              </span>
            </li>
          </ul>
        </section>

        <section class="panel">
          <header class="panel__heading">
            <div>
              <p class="eyebrow">ПОПУЛЯРНОСТЬ</p>
              <h3>Услуги и команда</h3>
            </div>
          </header>

          <div v-if="!data.popular_services.length" class="empty-state">
            В этом месяце ещё нет записей.
          </div>
          <div v-else class="ranking-list">
            <div v-for="service in data.popular_services" :key="service.service_id">
              <span>{{ service.name }}</span>
              <strong>{{ service.bookings_count }}</strong>
            </div>
          </div>

          <div v-if="data.staff_activity.length" class="staff-activity">
            <div v-for="member in data.staff_activity" :key="member.staff_id">
              <span>{{ member.display_name }}</span>
              <strong>{{ member.bookings_count }} записей</strong>
            </div>
          </div>
        </section>
      </div>
    </template>
  </section>
</template>

<style scoped>
.dashboard {
  display: grid;
  gap: 1.15rem;
}

.dashboard__intro,
.panel__heading {
  align-items: center;
  display: flex;
  gap: 1rem;
  justify-content: space-between;
}

.dashboard__intro h2,
.panel__heading h3 {
  color: #23392d;
  margin: 0;
}

.dashboard__intro h2 {
  font-size: 1.35rem;
}

.dashboard__intro p {
  color: #758078;
  font-size: 0.88rem;
  margin: 0.35rem 0 0;
}

.stat-grid {
  display: grid;
  gap: 0.85rem;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.stat-card,
.panel,
.state-card {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 1rem;
  box-shadow: 0 10px 32px #20372a08;
}

.stat-card {
  display: grid;
  gap: 0.55rem;
  min-width: 0;
  padding: 1.15rem;
}

.stat-card > span {
  color: #6f7d73;
  font-size: 0.82rem;
}

.stat-card strong {
  color: #20372b;
  font-size: clamp(1.35rem, 3vw, 1.9rem);
  overflow-wrap: anywhere;
}

.stat-card small {
  color: #879189;
  font-size: 0.75rem;
}

.stat-card--accent {
  background: #edf4ee;
  border-color: #dce9df;
}

.dashboard__columns {
  display: grid;
  gap: 1rem;
  grid-template-columns: minmax(0, 1.3fr) minmax(16rem, 0.7fr);
}

.panel {
  min-width: 0;
  padding: 1.2rem;
}

.panel__heading {
  margin-bottom: 1rem;
}

.eyebrow {
  color: #7a8c80;
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.14em;
  margin: 0 0 0.25rem;
}

.panel__heading h3 {
  font-size: 1.08rem;
}

.upcoming-list,
.ranking-list,
.staff-activity {
  display: grid;
  gap: 0.65rem;
  list-style: none;
  margin: 0;
  padding: 0;
}

.upcoming-list li {
  align-items: center;
  border-top: 1px solid #edf1ed;
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  padding-top: 0.75rem;
}

.upcoming-list__time {
  color: #477a69;
  flex: 0 0 10rem;
  font-size: 0.78rem;
  font-weight: 700;
}

.upcoming-list__main {
  display: grid;
  flex: 1 1 10rem;
  gap: 0.15rem;
}

.upcoming-list__main strong {
  color: #31483b;
  font-size: 0.88rem;
}

.upcoming-list__main small {
  color: #849087;
  font-size: 0.75rem;
}

.status-chip {
  background: #eef4ed;
  border-radius: 99px;
  color: #477153;
  font-size: 0.7rem;
  font-weight: 700;
  padding: 0.32rem 0.55rem;
}

.status-chip--pending {
  background: #fff3dc;
  color: #8b6527;
}

.ranking-list > div,
.staff-activity > div {
  align-items: center;
  border-bottom: 1px solid #edf1ed;
  color: #4a5e51;
  display: flex;
  font-size: 0.85rem;
  justify-content: space-between;
  padding: 0.65rem 0;
}

.ranking-list strong,
.staff-activity strong {
  color: #31574e;
}

.staff-activity {
  border-top: 1px solid #e7ede7;
  margin-top: 1rem;
  padding-top: 0.45rem;
}

.text-link,
.text-button {
  background: transparent;
  border: 0;
  color: #31574e;
  cursor: pointer;
  font: inherit;
  font-size: 0.82rem;
  font-weight: 700;
  text-decoration: none;
}

.text-link:hover,
.text-button:hover {
  text-decoration: underline;
}

.empty-state {
  color: #7b877f;
  font-size: 0.88rem;
  padding: 0.8rem 0;
}

.state-card {
  color: #68776e;
  padding: 1.2rem;
}

.state-card p {
  margin: 0 0 0.4rem;
}

.state-card--error {
  background: #fff6f4;
  border-color: #f0d7d2;
  color: #8d322c;
}

@media (max-width: 850px) {
  .stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .dashboard__columns {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 480px) {
  .stat-grid {
    grid-template-columns: 1fr 1fr;
  }

  .stat-card {
    padding: 0.85rem;
  }
}
</style>
