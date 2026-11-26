<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, ensureCsrfCookie, getApiProblem } from '@/api/client'
import type { ApiResponse } from '@/api/types'
import AuthForm from '@/features/auth/AuthForm.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const isTelegramLinkFlow = computed(() => route.query.link === 'telegram')
const tenantSlug = computed(() =>
  typeof route.query.tenant === 'string' ? route.query.tenant : '',
)
const canLinkTelegram = computed(() => auth.role === 'customer' || auth.role === 'staff')
const telegramLinkSettings = ref<{ clientId: string; nonce: string } | null>(null)
const telegramLinkLoading = ref(false)
const telegramLinkBusy = ref(false)
const telegramLinkError = ref('')
const telegramLinkMessage = ref('')
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

async function loadTelegramLink(): Promise<void> {
  telegramLinkLoading.value = true

  try {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<{
      enabled: boolean
      client_id: string | null
      nonce: string | null
    }>>('/auth/telegram/oidc/config')

    if (!response.data.data.enabled || !response.data.data.client_id || !response.data.data.nonce) {
      telegramLinkError.value = 'Привязка Telegram пока не настроена.'
      return
    }

    if (!window.Telegram?.Login) {
      await new Promise<void>((resolve, reject) => {
        const script = document.createElement('script')
        script.src = 'https://oauth.telegram.org/js/telegram-login.js'
        script.async = true
        script.onload = () => resolve()
        script.onerror = () => reject(new Error('Не удалось загрузить Telegram Login.'))
        document.head.appendChild(script)
      })
    }

    if (!window.Telegram?.Login) {
      throw new Error('Вход через Telegram сейчас недоступен.')
    }

    telegramLinkSettings.value = {
      clientId: response.data.data.client_id,
      nonce: response.data.data.nonce,
    }
    telegramLinkError.value = ''
  } catch (error) {
    telegramLinkError.value = getApiProblem(error, 'Не удалось подготовить привязку Telegram.').message
  } finally {
    telegramLinkLoading.value = false
  }
}

function startTelegramLink(): void {
  const settings = telegramLinkSettings.value
  if (!settings || !window.Telegram?.Login || telegramLinkBusy.value) return

  telegramLinkBusy.value = true
  telegramLinkMessage.value = ''
  telegramLinkError.value = ''

  window.Telegram.Login.auth(
    {
      client_id: settings.clientId,
      nonce: settings.nonce,
      scope: ['profile'],
      lang: 'ru',
    },
    (result) => {
      void finishTelegramLink(result)
    },
  )
}

async function finishTelegramLink(result: TelegramLoginResult): Promise<void> {
  try {
    if (!result.id_token) {
      if (result.error !== 'popup_closed') {
        telegramLinkError.value = 'Не удалось получить подтверждение Telegram. Попробуйте ещё раз.'
      }
      return
    }

    const outcome = await auth.linkTelegramOidcWithToken(result.id_token)
    telegramLinkMessage.value = outcome.merged
      ? 'Аккаунты объединены. Telegram и остальные способы входа ведут в один профиль.'
      : outcome.alreadyLinked
        ? 'Telegram уже привязан. Отдельный аккаунт не найден.'
        : 'Telegram привязан к аккаунту.'
    telegramLinkSettings.value = null
    afterAuthentication()
  } catch (error) {
    telegramLinkError.value = getApiProblem(error, 'Не удалось привязать Telegram.').message
    telegramLinkSettings.value = null
    await loadTelegramLink()
  } finally {
    telegramLinkBusy.value = false
  }
}

onMounted(() => {
  if (isTelegramLinkFlow.value && auth.isAuthenticated && canLinkTelegram.value) {
    void loadTelegramLink()
  }
})
</script>

<template>
  <section class="login-view">
    <div class="login-view__intro">
      <p class="eyebrow">АККАУНТ</p>
      <h1>
        {{
          isTelegramLinkFlow
            ? 'Привязка Telegram'
            : isManagementRedirect
              ? 'Вход в кабинет бизнеса'
              : 'Войдите или зарегистрируйтесь'
        }}
      </h1>
      <p v-if="isTelegramLinkFlow">
        Подтвердите Telegram, чтобы привязать его к текущему аккаунту.
      </p>
      <p v-else>Аккаунт нужен, чтобы сохранить запись и управлять ею.</p>
    </div>
    <div v-if="isTelegramLinkFlow" class="telegram-link">
      <p v-if="telegramLinkLoading" class="telegram-link__info">
        Подготавливаем безопасное подключение Telegram…
      </p>
      <p v-if="telegramLinkError" class="telegram-link__error" role="alert">
        {{ telegramLinkError }}
      </p>
      <p v-if="telegramLinkMessage" class="telegram-link__success" role="status">
        {{ telegramLinkMessage }}
      </p>
      <p v-if="!auth.isAuthenticated" class="telegram-link__error" role="alert">
        Для привязки сначала войдите в аккаунт.
      </p>
      <p v-else-if="!canLinkTelegram" class="telegram-link__error" role="alert">
        Привязка Telegram доступна для аккаунтов клиентов и специалистов.
      </p>
      <BaseButton
        v-if="telegramLinkSettings && auth.isAuthenticated && canLinkTelegram"
        :loading="telegramLinkBusy"
        @click="startTelegramLink"
      >
        Подтвердить в Telegram
      </BaseButton>
    </div>
    <AuthForm
      v-else
      initial-mode="login"
      @authenticated="afterAuthentication"
    />
  </section>
</template>

<style scoped>
.telegram-link {
  display: grid;
  gap: 0.8rem;
}

.telegram-link__info,
.telegram-link__error,
.telegram-link__success {
  font-size: 0.85rem;
  line-height: 1.5;
  margin: 0;
}

.telegram-link__info {
  color: #68776e;
}

.telegram-link__error {
  color: #8d322c;
}

.telegram-link__success {
  color: #386348;
}

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
