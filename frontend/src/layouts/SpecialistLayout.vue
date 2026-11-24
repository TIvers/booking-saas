<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'
import { useTenantStore } from '@/stores/tenant'

const tenant = useTenantStore()
const routeParams = { tenantSlug: tenant.slug ?? '' }
</script>

<template>
  <section class="specialist-portal">
    <header class="portal-heading">
      <p class="eyebrow">КАБИНЕТ СПЕЦИАЛИСТА</p>
      <h1>Моя работа</h1>
    </header>

    <nav class="portal-nav" aria-label="Разделы специалиста">
      <RouterLink :to="{ name: 'specialist-calendar', params: routeParams }">Календарь</RouterLink>
      <RouterLink :to="{ name: 'specialist-bookings', params: routeParams }">Мои записи</RouterLink>
      <RouterLink :to="{ name: 'specialist-schedule', params: routeParams }">Моё расписание</RouterLink>
    </nav>

    <RouterView />
  </section>
</template>

<style scoped>
.specialist-portal {
  display: grid;
  gap: 1.1rem;
  margin: 0 auto;
  max-width: 76rem;
}

.portal-heading h1 {
  color: #1b3026;
  font-size: clamp(1.7rem, 4vw, 2.25rem);
  letter-spacing: -0.04em;
  margin: 0;
}

.eyebrow {
  color: #678076;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.15em;
  margin: 0 0 0.4rem;
}

.portal-nav {
  border-bottom: 1px solid #dfe7df;
  display: flex;
  gap: 0.3rem;
  overflow-x: auto;
  padding-bottom: 0.55rem;
}

.portal-nav a {
  border-radius: 0.65rem;
  color: #62736a;
  flex: 0 0 auto;
  font-size: 0.88rem;
  font-weight: 650;
  padding: 0.65rem 0.85rem;
  text-decoration: none;
}

.portal-nav a:hover,
.portal-nav a.router-link-active {
  background: #e8eee9;
  color: #204c43;
}
</style>
