<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { getApiProblem } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

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
        username: username.value,
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
    if (pendingTelegramInitData.value) {
      await auth.linkTelegram(pendingTelegramInitData.value, telegramBot.value)
      pendingTelegramInitData.value = ''
      if (auth.user?.email === null) {
        showRecoveryEmailPrompt.value = true
        return
      }
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

async function signInWithTelegram(): Promise<void> {
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

async function createTelegramAccount(): Promise<void> {
  if (!pendingTelegramInitData.value) return

  busy.value = true
  message.value = ''

  try {
    await auth.registerWithTelegram(pendingTelegramInitData.value, telegramBot.value)
    pendingTelegramInitData.value = ''
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
        Для входа по логину создайте его для существующего аккаунта. До этого вход выполнен по email.
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
      <BaseButton
        v-if="telegramAvailable && !pendingTelegramInitData"
        type="button"
        variant="secondary"
        :loading="busy"
        class="auth-form__submit"
        @click="signInWithTelegram"
      >
        Продолжить через Telegram
      </BaseButton>
      <label v-if="mode === 'register'" class="field">
        <span>Ваше имя</span>
        <input v-model.trim="name" autocomplete="name" required maxlength="255" />
        <small v-if="fieldErrors.name">{{ fieldErrors.name[0] }}</small>
      </label>

      <label v-if="mode === 'register'" class="field">
        <span>Логин</span>
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

      <label v-if="mode === 'register'" class="field">
        <span>Email для будущего подтверждения (необязательно)</span>
        <input
          v-model.trim="email"
          type="email"
          autocomplete="email"
          maxlength="255"
        />
        <small v-if="fieldErrors.email">{{ fieldErrors.email[0] }}</small>
      </label>

      <label v-else class="field">
        <span>Логин</span>
        <input
          v-model.trim="login"
          required
          maxlength="255"
          autocapitalize="off"
          autocomplete="username"
        />
        <small>Для старых аккаунтов временно подойдёт email.</small>
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
        v-if="pendingTelegramInitData"
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
