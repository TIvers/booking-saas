<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, ensureCsrfCookie, getApiProblem } from '@/api/client'
import type { ApiResponse } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

interface GoogleCredentialResponse {
  credential: string
}

declare global {
  interface Window {
    google?: {
      accounts: {
        id: {
          initialize: (options: {
            client_id: string
            callback: (response: GoogleCredentialResponse) => void
          }) => void
          renderButton: (
            element: HTMLElement,
            options: {
              theme: 'outline'
              size: 'large'
              text: 'continue_with'
              width: number
            },
          ) => void
        }
      }
    }
  }
}

const props = defineProps<{
  initialMode?: 'login' | 'register'
}>()

const emit = defineEmits<{
  authenticated: []
}>()

const auth = useAuthStore()
const mode = ref<'login' | 'register'>(
  props.initialMode ?? (window.Telegram?.WebApp?.initData ? 'login' : 'register'),
)
const name = ref('')
const username = ref('')
const email = ref('')
const login = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const remember = ref(true)
const busy = ref(false)
const needsUsernameSetup = ref(false)
const showRecoveryEmailPrompt = ref(false)
const recoveryEmail = ref('')
const message = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const pendingTelegramInitData = ref('')
const pendingTelegramOidc = ref(false)
const googleButton = ref<HTMLDivElement | null>(null)
const googleClientId = ref('')
const googleError = ref('')
const telegramLoginClientId = ref('')
const telegramLoginNonce = ref('')
const telegramError = ref('')
const telegramAvailable = computed(() => Boolean(window.Telegram?.WebApp?.initData))
const telegramBot = computed<'main' | 'tenant'>(() =>
  new URLSearchParams(window.location.search).get('tg_bot') === 'tenant' ? 'tenant' : 'main',
)

function chooseMode(nextMode: 'login' | 'register'): void {
  mode.value = nextMode
  message.value = ''
  fieldErrors.value = {}
}

async function submit(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}

  try {
    if (mode.value === 'register') {
      await auth.register({
        name: name.value,
        username: username.value || undefined,
        email: email.value || undefined,
        password: password.value,
        password_confirmation: passwordConfirmation.value,
      })
    } else {
      await auth.login({
        login: login.value,
        password: password.value,
        remember: remember.value,
      })
    }
    const linkingTelegram = Boolean(pendingTelegramInitData.value || pendingTelegramOidc.value)
    if (pendingTelegramInitData.value) {
      await auth.linkTelegram(pendingTelegramInitData.value, telegramBot.value)
      pendingTelegramInitData.value = ''
    }
    if (pendingTelegramOidc.value) {
      await auth.linkTelegramOidc()
      pendingTelegramOidc.value = false
    }
    if (linkingTelegram && auth.user?.email === null) {
      showRecoveryEmailPrompt.value = true
      return
    }
    if (auth.requiresUsername) {
      needsUsernameSetup.value = true
      message.value = ''
      return
    }

    emit('authenticated')
  } catch (error) {
    const problem = getApiProblem(error, 'Не удалось войти. Проверьте данные и повторите попытку.')
    message.value = problem.message
    fieldErrors.value = problem.errors
  } finally {
    busy.value = false
  }
}

async function signInWithGoogle(response: GoogleCredentialResponse): Promise<void> {
  if (!response.credential || busy.value) return

  busy.value = true
  message.value = ''
  fieldErrors.value = {}

  try {
    await auth.loginWithGoogle(response.credential)
    if (auth.requiresUsername) {
      needsUsernameSetup.value = true
      return
    }

    emit('authenticated')
  } catch (error) {
    const problem = getApiProblem(error, 'Не удалось войти через Google.')
    message.value = problem.message
    fieldErrors.value = problem.errors
  } finally {
    busy.value = false
  }
}

async function loadGoogleButton(): Promise<void> {
  try {
    const response = await api.get<ApiResponse<{ enabled: boolean; client_id: string | null }>>(
      '/auth/google/config',
    )
    if (!response.data.data.enabled || !response.data.data.client_id) return

    googleClientId.value = response.data.data.client_id
    await nextTick()
    await new Promise<void>((resolve, reject) => {
      if (window.google?.accounts.id) {
        resolve()
        return
      }

      const script = document.createElement('script')
      script.src = 'https://accounts.google.com/gsi/client'
      script.async = true
      script.defer = true
      script.onload = () => resolve()
      script.onerror = () => reject(new Error('Не удалось загрузить Google Identity Services.'))
      document.head.appendChild(script)
    })

    if (!googleButton.value || !window.google?.accounts.id) {
      throw new Error('Вход через Google сейчас недоступен.')
    }

    window.google.accounts.id.initialize({
      client_id: googleClientId.value,
      callback: (credential) => {
        void signInWithGoogle(credential)
      },
    })
    window.google.accounts.id.renderButton(googleButton.value, {
      theme: 'outline',
      size: 'large',
      text: 'continue_with',
      width: Math.min(googleButton.value.clientWidth, 400),
    })
  } catch (error) {
    googleError.value = getApiProblem(error, 'Не удалось подготовить вход через Google.').message
  }
}

async function loadTelegramLogin(): Promise<void> {
  if (telegramAvailable.value) return

  telegramLoginClientId.value = ''
  telegramLoginNonce.value = ''
  telegramError.value = ''

  try {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<{
      enabled: boolean
      client_id: string | null
      nonce: string | null
    }>>('/auth/telegram/oidc/config')
    if (!response.data.data.enabled || !response.data.data.client_id || !response.data.data.nonce) {
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

    telegramLoginClientId.value = response.data.data.client_id
    telegramLoginNonce.value = response.data.data.nonce
  } catch (error) {
    telegramError.value = getApiProblem(error, 'Не удалось подготовить вход через Telegram.').message
  }
}

async function signInWithTelegramWebApp(): Promise<void> {
  const initData = window.Telegram?.WebApp?.initData
  if (!initData) return

  busy.value = true
  message.value = ''
  fieldErrors.value = {}

  try {
    const signedIn = await auth.loginWithTelegram(initData, telegramBot.value)
    if (signedIn) {
      if (auth.user?.email === null) {
        showRecoveryEmailPrompt.value = true
        return
      }
      emit('authenticated')
      return
    }

    pendingTelegramInitData.value = initData
    mode.value = 'login'
    message.value = 'Telegram ещё не связан с аккаунтом. Войдите в существующий аккаунт или создайте новый.'
  } catch (error) {
    const problem = getApiProblem(error, 'Не удалось войти через Telegram.')
    message.value = problem.message
    fieldErrors.value = problem.errors
  } finally {
    busy.value = false
  }
}

function signInWithTelegram(): void {
  const initData = window.Telegram?.WebApp?.initData

  if (initData) {
    void signInWithTelegramWebApp()
    return
  }

  if (busy.value) return

  if (!telegramLoginClientId.value || !telegramLoginNonce.value || !window.Telegram?.Login) {
    telegramError.value = 'Вход через Telegram сейчас недоступен. Попробуйте обновить страницу.'
    return
  }

  busy.value = true
  message.value = ''
  fieldErrors.value = {}
  telegramError.value = ''

  window.Telegram.Login.auth(
    {
      client_id: telegramLoginClientId.value,
      nonce: telegramLoginNonce.value,
      scope: ['profile'],
      lang: 'ru',
    },
    (result) => {
      void finishTelegramOidcLogin(result)
    },
  )
}

async function finishTelegramOidcLogin(result: TelegramLoginResult): Promise<void> {
  try {
    if (!result.id_token) {
      if (result.error !== 'popup_closed') {
        message.value = 'Не удалось войти через Telegram. Попробуйте ещё раз.'
      }
      return
    }

    const signedIn = await auth.loginWithTelegramOidc(result.id_token)
    if (signedIn) {
      if (auth.user?.email === null) {
        showRecoveryEmailPrompt.value = true
        return
      }
      emit('authenticated')
      return
    }

    pendingTelegramOidc.value = true
    mode.value = 'login'
    message.value = 'Telegram ещё не связан с аккаунтом. Войдите в существующий аккаунт или создайте новый.'
  } catch (error) {
    const problem = getApiProblem(error, 'Не удалось войти через Telegram.')
    message.value = problem.message
    fieldErrors.value = problem.errors
    await loadTelegramLogin()
  } finally {
    busy.value = false
  }
}

async function createTelegramAccount(): Promise<void> {
  if (!pendingTelegramInitData.value && !pendingTelegramOidc.value) return

  busy.value = true
  message.value = ''

  try {
    if (pendingTelegramOidc.value) {
      await auth.registerWithTelegramOidc()
      pendingTelegramOidc.value = false
    } else {
      await auth.registerWithTelegram(pendingTelegramInitData.value, telegramBot.value)
      pendingTelegramInitData.value = ''
    }
    if (auth.user?.email === null) {
      showRecoveryEmailPrompt.value = true
      return
    }
    emit('authenticated')
  } catch (error) {
    const problem = getApiProblem(error, 'Не удалось создать аккаунт Telegram.')
    message.value = problem.message
    fieldErrors.value = problem.errors
  } finally {
    busy.value = false
  }
}

async function saveRecoveryEmail(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}

  try {
    await auth.updateEmail(recoveryEmail.value.trim())
    showRecoveryEmailPrompt.value = false
    emit('authenticated')
  } catch (error) {
    const problem = getApiProblem(error, 'Не удалось сохранить email.')
    message.value = problem.message
    fieldErrors.value = problem.errors
  } finally {
    busy.value = false
  }
}

function continueWithoutEmail(): void {
  showRecoveryEmailPrompt.value = false
  emit('authenticated')
}

async function saveUsername(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}

  try {
    await auth.updateUsername(username.value)
    if (pendingTelegramInitData.value) {
      await auth.linkTelegram(pendingTelegramInitData.value, telegramBot.value)
      pendingTelegramInitData.value = ''
    }
    if (pendingTelegramOidc.value) {
      await auth.linkTelegramOidc()
      pendingTelegramOidc.value = false
    }
    needsUsernameSetup.value = false
    emit('authenticated')
  } catch (error) {
    const problem = getApiProblem(error, 'Не удалось сохранить логин.')
    message.value = problem.message
    fieldErrors.value = problem.errors
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  void loadGoogleButton()
  void loadTelegramLogin()
})
</script>

<template>
  <section class="auth-form">
    <form
      v-if="showRecoveryEmailPrompt"
      class="auth-form__fields recovery-email-form"
      @submit.prevent="saveRecoveryEmail"
    >
      <p class="form-message form-message--info">
        Вы вошли через Telegram. Добавьте email в профиль или продолжите без него.
      </p>
      <label class="field">
        <span>Email (необязательно)</span>
        <input
          v-model.trim="recoveryEmail"
          type="email"
          required
          maxlength="255"
          autocomplete="email"
          autofocus
        />
        <small v-if="fieldErrors.email">{{ fieldErrors.email[0] }}</small>
      </label>
      <p v-if="message" class="form-message" role="alert">{{ message }}</p>
      <BaseButton type="submit" :loading="busy" class="auth-form__submit">
        Сохранить email
      </BaseButton>
      <BaseButton
        type="button"
        variant="secondary"
        :disabled="busy"
        class="auth-form__submit"
        @click="continueWithoutEmail"
      >
        Продолжить без email
      </BaseButton>
    </form>

    <form v-else-if="needsUsernameSetup" class="auth-form__fields" @submit.prevent="saveUsername">
      <p class="form-message form-message--info">
        Создайте логин для дальнейшего входа по логину и паролю.
      </p>
      <label class="field">
        <span>Новый логин</span>
        <input
          v-model.trim="username"
          required
          minlength="3"
          maxlength="32"
          pattern="[a-z0-9][a-z0-9_.-]{2,31}"
          autocapitalize="off"
          autocomplete="username"
        />
        <small v-if="fieldErrors.username">{{ fieldErrors.username[0] }}</small>
      </label>
      <p v-if="message" class="form-message" role="alert">{{ message }}</p>
      <BaseButton type="submit" :loading="busy" class="auth-form__submit">
        Сохранить логин
      </BaseButton>
    </form>

    <template v-else>
    <div class="auth-form__tabs" role="tablist" aria-label="Действие с аккаунтом">
      <button
        type="button"
        role="tab"
        :aria-selected="mode === 'register'"
        :class="{ 'is-active': mode === 'register' }"
        @click="chooseMode('register')"
      >
        Создать аккаунт
      </button>
      <button
        type="button"
        role="tab"
        :aria-selected="mode === 'login'"
        :class="{ 'is-active': mode === 'login' }"
        @click="chooseMode('login')"
      >
        Войти
      </button>
    </div>

    <form class="auth-form__fields" @submit.prevent="submit">
      <label v-if="mode === 'register'" class="field">
        <span>Ваше имя</span>
        <input v-model.trim="name" autocomplete="name" required maxlength="255" />
        <small v-if="fieldErrors.name">{{ fieldErrors.name[0] }}</small>
      </label>

      <label v-if="mode === 'register'" class="field">
        <span>Логин (необязательно, если указан email)</span>
        <input
          v-model.trim="username"
          :required="!email"
          minlength="3"
          maxlength="32"
          pattern="[a-z0-9][a-z0-9_.-]{2,31}"
          autocapitalize="off"
          autocomplete="username"
        />
        <small v-if="fieldErrors.username">{{ fieldErrors.username[0] }}</small>
      </label>

      <label v-if="mode === 'register'" class="field">
        <span>Email (необязательно, если указан логин)</span>
        <input
          v-model.trim="email"
          type="email"
          :required="!username"
          autocomplete="email"
          maxlength="255"
        />
        <small v-if="fieldErrors.email">{{ fieldErrors.email[0] }}</small>
      </label>

      <p v-if="mode === 'register'" class="form-message form-message--info">
        Укажите email или логин — для входа позже подойдёт любой из них.
      </p>

      <label v-else class="field">
        <span>Логин или email</span>
        <input
          v-model.trim="login"
          required
          maxlength="255"
          autocapitalize="off"
          autocomplete="username"
        />
        <small>Введите логин или email, указанный при регистрации.</small>
      </label>

      <label class="field">
        <span>Пароль</span>
        <input
          v-model="password"
          type="password"
          :autocomplete="mode === 'register' ? 'new-password' : 'current-password'"
          :minlength="mode === 'register' ? 12 : undefined"
          required
        />
        <small v-if="fieldErrors.password">{{ fieldErrors.password[0] }}</small>
      </label>

      <label v-if="mode === 'register'" class="field">
        <span>Повторите пароль</span>
        <input
          v-model="passwordConfirmation"
          type="password"
          autocomplete="new-password"
          minlength="12"
          required
        />
      </label>

      <label v-if="mode === 'login'" class="auth-form__remember">
        <input v-model="remember" type="checkbox" />
        <span>Запомнить меня</span>
      </label>

      <p v-if="message" class="form-message" role="alert">{{ message }}</p>

      <BaseButton
        v-if="pendingTelegramInitData || pendingTelegramOidc"
        type="button"
        :loading="busy"
        class="auth-form__submit"
        @click="createTelegramAccount"
      >
        Создать аккаунт через Telegram
      </BaseButton>
      <BaseButton type="submit" :loading="busy" class="auth-form__submit">
        {{ mode === 'register' ? 'Создать аккаунт и записаться' : 'Войти' }}
      </BaseButton>
    </form>

    <div
      v-if="googleClientId || telegramAvailable || telegramLoginClientId || googleError || telegramError"
      class="auth-form__providers"
    >
      <div class="auth-form__separator"><span>или</span></div>
      <div class="auth-form__provider-list">
        <div v-if="googleClientId" ref="googleButton" class="google-button" />
        <button
          v-if="
            !pendingTelegramInitData
              && !pendingTelegramOidc
              && (telegramAvailable || telegramLoginClientId)
          "
          type="button"
          class="auth-form__telegram"
          :disabled="busy"
          @click="signInWithTelegram"
        >
          <svg aria-hidden="true" viewBox="0 0 24 24">
            <path
              d="M21.7 4.4 18.6 19c-.2 1-1 1.2-1.8.7l-4.9-3.6-2.4 2.3c-.3.3-.5.5-1 .5l.4-5 9.1-8.2c.4-.4-.1-.6-.6-.3L6.2 12.6l-4.8-1.5c-1-.3-1-1 .2-1.5L20.3 2.4c.9-.3 1.7.2 1.4 2Z"
              fill="currentColor"
            />
          </svg>
          <span>Продолжить через Telegram</span>
        </button>
      </div>
      <p v-if="googleError" class="form-message" role="alert">{{ googleError }}</p>
      <p v-if="telegramError" class="form-message" role="alert">{{ telegramError }}</p>
    </div>
    </template>
  </section>
</template>

<style scoped>
.auth-form__tabs {
  background: #f0f3ef;
  border-radius: 0.8rem;
  display: flex;
  gap: 0.25rem;
  padding: 0.25rem;
}

.auth-form__tabs button {
  background: transparent;
  border: 0;
  border-radius: 0.65rem;
  color: #66746d;
  cursor: pointer;
  flex: 1;
  font: inherit;
  font-size: 0.9rem;
  font-weight: 650;
  min-height: 2.5rem;
}

.auth-form__tabs button.is-active {
  background: #fff;
  box-shadow: 0 1px 5px #142b2214;
  color: #204c43;
}

.auth-form__fields {
  display: grid;
  gap: 1rem;
  margin-top: 1.25rem;
}

.auth-form__remember {
  align-items: center;
  color: #5d6b64;
  display: flex;
  gap: 0.55rem;
  font-size: 0.92rem;
}

.auth-form__remember input {
  accent-color: #204c43;
}

.auth-form__submit {
  width: 100%;
}

.google-button {
  display: flex;
  justify-content: center;
  min-height: 2.75rem;
  width: 100%;
}

.auth-form__providers {
  display: grid;
  gap: 0.8rem;
  margin-top: 1.1rem;
}

.auth-form__provider-list {
  display: grid;
  gap: 0.55rem;
}

.auth-form__separator {
  align-items: center;
  color: #78847e;
  display: flex;
  font-size: 0.85rem;
  gap: 0.75rem;
}

.auth-form__separator::before,
.auth-form__separator::after {
  background: #d7dfd9;
  content: '';
  flex: 1;
  height: 1px;
}

.auth-form__telegram {
  align-items: center;
  background: #f5f7f8;
  border: 1px solid #d1dce3;
  border-radius: 0.5rem;
  color: #17212b;
  cursor: pointer;
  display: flex;
  font: inherit;
  font-size: 0.92rem;
  font-weight: 600;
  gap: 0.5rem;
  justify-content: center;
  min-height: 2.75rem;
  padding: 0.55rem 0.8rem;
  width: 100%;
}

.auth-form__telegram:hover:not(:disabled) {
  background: #edf2f4;
}

.auth-form__telegram:disabled {
  cursor: not-allowed;
  opacity: 0.65;
}

.auth-form__telegram svg {
  color: #168de2;
  height: 1.2rem;
  width: 1.2rem;
}

.field {
  display: grid;
  gap: 0.45rem;
}

.field span {
  color: #354a40;
  font-size: 0.9rem;
  font-weight: 650;
}

.field input {
  background: #fff;
  border: 1px solid #d5dfd8;
  border-radius: 0.7rem;
  color: #1b2c24;
  font: inherit;
  min-height: 2.85rem;
  padding: 0.65rem 0.8rem;
  width: 100%;
}

.field input:focus {
  border-color: #568073;
  outline: 3px solid #5680732b;
}

.field small {
  color: #9c3d36;
}

.form-message {
  background: #fff1ef;
  border-radius: 0.65rem;
  color: #8d322c;
  margin: 0;
  padding: 0.75rem 0.85rem;
}

.form-message--info {
  background: #edf5ee;
  color: #355b42;
}
</style>
