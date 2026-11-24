<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { useTenantStore } from '@/stores/tenant'

const tenantStore = useTenantStore()
const demoPaymentsEnabled = computed(() => tenantStore.tenant?.demo_payments_enabled ?? false)
</script>

<template>
  <section class="management">
    <header class="management__heading">
      <div>
        <p class="eyebrow">УПРАВЛЕНИЕ ОРГАНИЗАЦИЕЙ</p>
        <h1>Кабинет бизнеса</h1>
      </div>
    </header>

    <nav class="management-nav" aria-label="Разделы кабинета">
      <RouterLink :to="{ name: 'manage-dashboard' }">Обзор</RouterLink>
      <RouterLink :to="{ name: 'manage-calendar' }">Календарь</RouterLink>
      <RouterLink :to="{ name: 'manage-bookings' }">Записи</RouterLink>
      <RouterLink :to="{ name: 'manage-schedule' }">Расписание</RouterLink>
      <RouterLink :to="{ name: 'manage-services' }">Услуги</RouterLink>
      <RouterLink :to="{ name: 'manage-staff' }">Команда</RouterLink>
    </nav>

    <p v-if="demoPaymentsEnabled" class="demo-payment-notice" role="note">
      Демо-оплата включена: отметка оплаты не означает реальное поступление денег.
    </p>

    <RouterView />
  </section>
</template>

<style scoped>
.management {
  display: grid;
  gap: 1.2rem;
  margin: 0 auto;
  max-width: 76rem;
}

.management__heading {
  align-items: flex-end;
  display: flex;
  justify-content: space-between;
}

.eyebrow {
  color: #678076;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.15em;
  margin: 0 0 0.45rem;
}

h1 {
  color: #1b3026;
  font-size: clamp(1.7rem, 4vw, 2.25rem);
  letter-spacing: -0.04em;
  margin: 0;
}

.management-nav {
  border-bottom: 1px solid #dfe7df;
  display: flex;
  gap: 0.3rem;
  overflow-x: auto;
  padding-bottom: 0.55rem;
}

.management-nav a {
  border-radius: 0.65rem;
  color: #62736a;
  flex: 0 0 auto;
  font-size: 0.88rem;
  font-weight: 650;
  padding: 0.65rem 0.85rem;
  text-decoration: none;
}

.management-nav a:hover,
.management-nav a.router-link-active {
  background: #e8eee9;
  color: #204c43;
}

.demo-payment-notice {
  background: #fff6df;
  border: 1px solid #efd28a;
  border-radius: 0.75rem;
  color: #70551b;
  margin: 0;
  padding: 0.8rem 1rem;
}
</style>
