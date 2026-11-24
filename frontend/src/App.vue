<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { getApiProblem } from '@/api/client'
import BaseButton from '@/components/ui/BaseButton.vue'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'

const auth = useAuthStore()
const tenant = useTenantStore()
const router = useRouter()
const ready = ref(false)
const authError = ref('')

async function logout(): Promise<void> {
  authError.value = ''

  try {
    await auth.logout()
    await router.replace('/')
  } catch (error) {
    authError.value = getApiProblem(error, 'Не удалось завершить сеанс.').message
  }
}

onMounted(async () => {
  await tenant.load()
  if (tenant.tenant) await auth.loadCurrentUser()
  ready.value = true
})
</script>

<template>
  <div class="app-shell">
    <header class="site-header">
      <RouterLink class="brand" to="/" aria-label="На главную">
        <span class="brand__mark" aria-hidden="true">b</span>
        <span class="brand__text">
          <strong>{{ tenant.tenant?.name ?? 'Онлайн-запись' }}</strong>
          <small>Забота начинается со времени</small>
        </span>
      </RouterLink>

      <nav class="site-nav" aria-label="Главная навигация">
        <RouterLink to="/">Записаться</RouterLink>
        <RouterLink v-if="auth.canManageTenant || !auth.isAuthenticated" to="/manage">Кабинет</RouterLink>
        <RouterLink v-if="!auth.isAuthenticated" to="/login">Войти</RouterLink>
        <template v-else>
          <span class="site-nav__user">{{ auth.user?.name }}</span>
          <BaseButton variant="ghost" @click="logout">Выйти</BaseButton>
        </template>
      </nav>
    </header>

    <main class="site-main">
      <div v-if="!ready" class="startup-card" role="status">Подключаемся к организации…</div>

      <section v-else-if="tenant.error" class="startup-card startup-card--error" role="alert">
        <p class="eyebrow">НАСТРОЙКА САЙТА</p>
        <h1>Пока не удаётся открыть запись</h1>
        <p>{{ tenant.error }}</p>
        <BaseButton variant="secondary" @click="tenant.load">Повторить</BaseButton>
      </section>

      <RouterView v-else />
      <p v-if="authError" class="site-alert" role="alert">{{ authError }}</p>
    </main>

    <footer class="site-footer">
      <span>{{ tenant.tenant?.name ?? 'Онлайн-запись' }}</span>
      <span>Время отображается в часовом поясе филиала</span>
    </footer>
  </div>
</template>

<style>
:root {
  color: #23352b;
  background: #f5f7f3;
  font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  font-synthesis: none;
  font-weight: 400;
  text-rendering: optimizeLegibility;
}

* {
  box-sizing: border-box;
}

body {
  margin: 0;
  min-width: 320px;
  min-height: 100vh;
}

button,
input {
  font: inherit;
}

button:focus-visible,
a:focus-visible,
input:focus-visible {
  outline: 3px solid #6a9884;
  outline-offset: 3px;
}

.app-shell {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.site-header {
  align-items: center;
  background: #fff;
  border-bottom: 1px solid #e8ede8;
  display: flex;
  gap: 1rem;
  justify-content: space-between;
  padding: 0.8rem max(1.2rem, calc((100vw - 72rem) / 2));
}

.brand {
  align-items: center;
  color: inherit;
  display: flex;
  gap: 0.7rem;
  min-width: 0;
  text-decoration: none;
}

.brand__mark {
  align-items: center;
  background: #204c43;
  border-radius: 0.8rem;
  color: #f2f6ef;
  display: inline-flex;
  flex: 0 0 2.45rem;
  font-family: Georgia, serif;
  font-size: 1.65rem;
  font-style: italic;
  height: 2.45rem;
  justify-content: center;
}

.brand__text {
  display: grid;
  gap: 0.1rem;
  min-width: 0;
}

.brand__text strong {
  color: #20372b;
  font-size: 0.95rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.brand__text small {
  color: #829087;
  font-size: 0.72rem;
}

.site-nav {
  align-items: center;
  display: flex;
  gap: 0.4rem;
}

.site-nav > a {
  border-radius: 0.65rem;
  color: #597067;
  font-size: 0.9rem;
  font-weight: 650;
  padding: 0.65rem 0.8rem;
  text-decoration: none;
}

.site-nav > a:hover,
.site-nav > a.router-link-active {
  background: #f1f5f1;
  color: #204c43;
}

.site-nav__user {
  color: #53665b;
  font-size: 0.88rem;
  max-width: 10rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.site-main {
  flex: 1;
  margin: 0 auto;
  max-width: 76rem;
  padding: 1rem 1.25rem 3rem;
  width: 100%;
}

.startup-card {
  background: #fff;
  border: 1px solid #e5ece5;
  border-radius: 1rem;
  color: #637168;
  line-height: 1.6;
  margin: 8vh auto;
  max-width: 38rem;
  padding: 2rem;
  text-align: center;
}

.startup-card--error {
  border-color: #e8d9d4;
}

.startup-card .eyebrow {
  color: #7a8c80;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.15em;
}

.startup-card h1 {
  color: #243a2e;
  font-size: 1.7rem;
  letter-spacing: -0.035em;
  margin: 0.55rem 0;
}

.startup-card--error > p:not(.eyebrow) {
  margin: 0.55rem 0 1.15rem;
}

.site-alert {
  background: #fff1ef;
  border-radius: 0.7rem;
  color: #8d322c;
  margin: 1rem auto 0;
  max-width: 42rem;
  padding: 0.8rem 1rem;
}

.site-footer {
  align-items: center;
  border-top: 1px solid #e7ece7;
  color: #7b877e;
  display: flex;
  flex-wrap: wrap;
  font-size: 0.78rem;
  gap: 0.5rem 1rem;
  justify-content: space-between;
  padding: 1rem max(1.2rem, calc((100vw - 72rem) / 2));
}

@media (max-width: 540px) {
  .site-header {
    align-items: flex-start;
    padding: 0.75rem 1rem;
  }

  .brand__text small {
    display: none;
  }

  .site-nav {
    gap: 0;
  }

  .site-nav > a {
    font-size: 0.8rem;
    padding: 0.6rem;
  }

  .site-nav__user {
    display: none;
  }

  .site-main {
    padding: 0.7rem 0.8rem 2rem;
  }

  .site-footer {
    align-items: flex-start;
    flex-direction: column;
    padding: 0.9rem 1rem;
  }
}
</style>
