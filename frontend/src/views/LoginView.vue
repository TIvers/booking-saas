<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthForm from '@/features/auth/AuthForm.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const tenantSlug = computed(() =>
  typeof route.query.tenant === 'string' ? route.query.tenant : '',
)
const isManagementRedirect = computed(() => {
  const redirect = route.query.redirect
  return typeof redirect === 'string'
    && tenantSlug.value !== ''
    && (
      redirect === `/t/${tenantSlug.value}/manage`
      || redirect.startsWith(`/t/${tenantSlug.value}/manage/`)
    )
})

function afterAuthentication(): void {
  const redirect = route.query.redirect
  const slug = tenantSlug.value
  const safeRedirect = typeof redirect === 'string' ? tenantRedirect(redirect, slug) : null
  const destination = safeRedirect
    ? safeRedirect
    : auth.role === 'staff'
      ? { name: 'specialist-calendar', params: { tenantSlug: slug } }
      : auth.canManageTenant
        ? { name: 'manage-dashboard', params: { tenantSlug: slug } }
        : { name: 'booking', params: { tenantSlug: slug } }

  void router.replace(destination)
}

function tenantRedirect(path: string, slug: string): string | null {
  if (!slug || !path.startsWith('/') || path.startsWith('//') || path.includes('\\')) return null

  const resolved = router.resolve(path)
  const belongsToTenant = resolved.params.tenantSlug === slug
    && (resolved.path === `/t/${slug}` || resolved.path.startsWith(`/t/${slug}/`))

  return belongsToTenant ? resolved.fullPath : null
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
      initial-mode="login"
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
