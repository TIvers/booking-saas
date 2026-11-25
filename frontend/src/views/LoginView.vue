<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthForm from '@/features/auth/AuthForm.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const isTelegramWebApp = computed(() => Boolean(window.Telegram?.WebApp?.initData))
const isManagementRedirect = computed(() => {
  const redirect = route.query.redirect
  const tenantSlug = route.params.tenantSlug
  return typeof redirect === 'string'
    && typeof tenantSlug === 'string'
    && (
      redirect === `/t/${tenantSlug}/manage`
      || redirect.startsWith(`/t/${tenantSlug}/manage/`)
    )
})

function afterAuthentication(): void {
  const redirect = route.query.redirect
  const tenantSlug = route.params.tenantSlug
  const destination =
    typeof redirect === 'string'
      && typeof tenantSlug === 'string'
      && redirect.startsWith(`/t/${tenantSlug}/`)
      ? redirect
    : auth.role === 'staff'
      ? { name: 'specialist-calendar', params: { tenantSlug } }
      : auth.canManageTenant
        ? { name: 'manage-dashboard', params: { tenantSlug } }
        : { name: 'booking', params: { tenantSlug } }

  void router.replace(destination)
}
</script>

<template>
  <section class="login-view">
    <div class="login-view__intro">
      <p class="eyebrow">АККАУНТ</p>
      <h1>{{ isManagementRedirect ? 'Вход в кабинет бизнеса' : 'Войдите или зарегистрируйтесь' }}</h1>
      <p>Аккаунт нужен, чтобы сохранить запись и управлять ею.</p>
    </div>
    <AuthForm
      :initial-mode="isManagementRedirect || isTelegramWebApp ? 'login' : 'register'"
      @authenticated="afterAuthentication"
    />
  </section>
</template>

<style scoped>
.login-view {
  background: #fff;
  border: 1px solid #e8ede8;
  border-radius: 1.25rem;
  box-shadow: 0 18px 55px #20372a0d;
  margin: 2rem auto;
  max-width: 34rem;
  padding: clamp(1.25rem, 5vw, 2.5rem);
}

.login-view__intro {
  margin-bottom: 1.6rem;
}

.login-view h1 {
  color: #1b3026;
  font-size: clamp(1.55rem, 4vw, 2rem);
  letter-spacing: -0.035em;
  line-height: 1.15;
  margin: 0.6rem 0;
}

.login-view__intro > p:last-child {
  color: #68776e;
  line-height: 1.6;
  margin: 0;
}

.eyebrow {
  color: #678076;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.15em;
  margin: 0;
}
</style>
