<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, ensureCsrfCookie, getApiProblem } from '@/api/client'
import type { ApiResponse } from '@/api/types'
import EmailVerificationPrompt from '@/features/auth/EmailVerificationPrompt.vue'
import { preferredEmailVerificationChannel } from '@/features/auth/emailVerification'
import { useAuthStore } from '@/stores/auth'
import type { EmailVerificationChallenge } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const name = ref(auth.user?.name ?? '')
const phone = ref(formatRussianPhone(auth.user?.phone ?? ''))
const email = ref(auth.user?.email ?? '')
const username = ref(auth.user?.username ?? '')
const password = ref('')
const passwordConfirmation = ref('')
const profileBusy = ref(false)
const emailBusy = ref(false)
const usernameBusy = ref(false)
const passwordBusy = ref(false)
const telegramBusy = ref(false)
const profileMessage = ref('')
const profileError = ref('')
const emailMessage = ref('')
const emailError = ref('')
const credentialsMessage = ref('')
const credentialsError = ref('')
const emailChallenge = ref<EmailVerificationChallenge | null>(null)
const googleButton = ref<HTMLDivElement | null>(null)
const googleClientId = ref('')
const googleBusy = ref(false)
const googleConfigLoaded = ref(false)
const googleError = ref('')
const googleMessage = ref('')
const telegramMessage = ref('')
const telegramError = ref('')
const telegramInitData = computed(() => window.Telegram?.WebApp?.initData ?? '')
const canLinkProviders = computed(() => auth.user?.account_type === 'customer')
const canLinkTelegram = computed(
  () => canLinkProviders.value && auth.role === 'customer',
)
const telegramBot = computed<'main' | 'tenant'>(() =>
  new URLSearchParams(window.location.search).get('tg_bot') === 'tenant' ? 'tenant' : 'main',
)

function formatRussianPhone(value: string): string {
  const digits = value.replace(/\D/g, '')
  if (!digits) return ''

  const normalized = digits.startsWith('8')
    ? `7${digits.slice(1)}`
    : digits.startsWith('7')
      ? digits
      : `7${digits}`
  const national = normalized.slice(1, 11)
  let formatted = '+7'

  if (national.length > 0) formatted += ` (${national.slice(0, 3)}`
  if (national.length >= 3) formatted += ')'
  if (national.length > 3) formatted += ` ${national.slice(3, 6)}`
  if (national.length > 6) formatted += `-${national.slice(6, 8)}`
  if (national.length > 8) formatted += `-${national.slice(8, 10)}`

  return formatted
}

function onPhoneInput(event: Event): void {
  const input = event.target as HTMLInputElement
  const rawValue = input.value
  const cursor = input.selectionStart ?? rawValue.length
  const digitsBeforeCursor = (rawValue.slice(0, cursor).match(/\d/g) ?? []).length
  const digits = rawValue.replace(/\D/g, '')
  const trimmedValue = rawValue.trimStart()
  const hasCountryPrefix = trimmedValue.startsWith('+7')
    || (!trimmedValue.startsWith('+') && (digits.startsWith('7') || digits.startsWith('8')))
  const nationalDigitsBeforeCursor = Math.max(
    0,
    digitsBeforeCursor - (hasCountryPrefix ? 1 : 0),
  )

  phone.value = formatRussianPhone(rawValue)
  const targetDigitCount = 1 + nationalDigitsBeforeCursor
  let nextCursor = phone.value.length
  let digitCount = 0

  for (let index = 0; index < phone.value.length; index += 1) {
    if (/\d/.test(phone.value[index])) digitCount += 1
    if (digitCount >= targetDigitCount) {
      nextCursor = index + 1
      break
    }
  }

  void nextTick(() => input.setSelectionRange(nextCursor, nextCursor))
}

watch(
  () => auth.user,
  () => {
    name.value = auth.user?.name ?? ''
    phone.value = formatRussianPhone(auth.user?.phone ?? '')
    email.value = auth.user?.email ?? ''
    username.value = auth.user?.username ?? ''
  },
  { immediate: true },
)

async function saveProfile(): Promise<void> {
  profileBusy.value = true
  profileMessage.value = ''
  profileError.value = ''

  try {
    await auth.updateProfile(name.value.trim(), phone.value.trim() || null)
    profileMessage.value = 'Данные профиля сохранены.'
  } catch (error) {
    profileError.value = getApiProblem(error, 'Не удалось сохранить данные профиля.').message
  } finally {
    profileBusy.value = false
  }
}

async function saveEmail(): Promise<void> {
  emailBusy.value = true
  emailMessage.value = ''
  emailError.value = ''

  try {
    const challenge = await auth.updateEmail(email.value.trim(), preferredEmailVerificationChannel())
    emailChallenge.value = challenge
    emailMessage.value = challenge
      ? 'Отправили письмо. Подтвердите новый адрес, чтобы завершить изменение.'
      : 'Этот email уже подтверждён.'
  } catch (error) {
    emailError.value = getApiProblem(error, 'Не удалось обновить email.').message
  } finally {
    emailBusy.value = false
  }
}

async function saveUsername(): Promise<void> {
  usernameBusy.value = true
  credentialsMessage.value = ''
  credentialsError.value = ''

  try {
    await auth.updateUsername(username.value.trim())
    credentialsMessage.value = 'Глобальный логин сохранён.'
  } catch (error) {
    credentialsError.value = getApiProblem(error, 'Не удалось сохранить логин.').message
  } finally {
    usernameBusy.value = false
  }
}

async function savePassword(): Promise<void> {
  passwordBusy.value = true
  credentialsMessage.value = ''
  credentialsError.value = ''

  try {
    await auth.setPassword(password.value, passwordConfirmation.value)
    password.value = ''
    passwordConfirmation.value = ''
    credentialsMessage.value = 'Парольный вход включён.'
  } catch (error) {
    credentialsError.value = getApiProblem(error, 'Не удалось сохранить пароль.').message
  } finally {
    passwordBusy.value = false
  }
}

function onEmailVerified(): void {
  emailChallenge.value = null
  email.value = auth.user?.email ?? email.value
  emailMessage.value = 'Email подтверждён.'
}

function updateEmailChallenge(challenge: EmailVerificationChallenge): void {
  emailChallenge.value = challenge
}

async function connectTelegram(): Promise<void> {
  if (!telegramInitData.value) return

  telegramBusy.value = true
  telegramMessage.value = ''
  telegramError.value = ''

  try {
    const outcome = await auth.linkTelegram(telegramInitData.value, telegramBot.value)
    telegramMessage.value = outcome.merged
      ? 'Аккаунты объединены. Telegram и остальные способы входа ведут в один профиль.'
      : outcome.alreadyLinked
        ? 'Telegram уже привязан. Отдельный аккаунт не найден.'
        : 'Telegram привязан к аккаунту.'
  } catch (error) {
    telegramError.value = getApiProblem(error, 'Не удалось привязать Telegram.').message
  } finally {
    telegramBusy.value = false
  }
}

function startTelegramOidcLink(): void {
  const tenantSlug = route.params.tenantSlug
  if (typeof tenantSlug !== 'string') return

  void router.push({
    name: 'login',
    query: {
      tenant: tenantSlug,
      link: 'telegram',
      redirect: route.fullPath,
    },
  })
}

async function connectGoogle(credential: string): Promise<void> {
  if (googleBusy.value) return

  googleBusy.value = true
  googleMessage.value = ''
  googleError.value = ''

  try {
    await ensureCsrfCookie()
    const outcome = await auth.linkGoogle(credential)
    googleMessage.value = outcome.merged
      ? 'Аккаунты объединены. Google и остальные способы входа ведут в один профиль.'
      : outcome.alreadyLinked
        ? 'Google уже привязан. Отдельный аккаунт не найден.'
        : 'Google привязан к аккаунту.'
  } catch (error) {
    googleError.value = getApiProblem(error, 'Не удалось привязать Google.').message
  } finally {
    googleBusy.value = false
  }
}

async function loadGoogleButton(): Promise<void> {
  try {
    const response = await api.get<ApiResponse<{ enabled: boolean; client_id: string | null }>>(
      '/auth/google/config',
    )
    googleConfigLoaded.value = true

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
      throw new Error('Привязка Google сейчас недоступна.')
    }

    window.google.accounts.id.initialize({
      client_id: googleClientId.value,
      callback: (response) => {
        void connectGoogle(response.credential)
      },
    })
    window.google.accounts.id.renderButton(googleButton.value, {
      theme: 'outline',
      size: 'large',
      text: 'continue_with',
      width: Math.min(googleButton.value.clientWidth || 320, 400),
    })
  } catch (error) {
    googleConfigLoaded.value = true
    googleError.value = getApiProblem(error, 'Не удалось подготовить привязку Google.').message
  }
}

onMounted(() => {
  if (canLinkProviders.value && !auth.user?.google_linked) void loadGoogleButton()
})
</script>

<template>
  <section class="account-page">
    <header class="page-heading">
      <div>
        <p class="eyebrow">ВАШ ПРОФИЛЬ</p>
        <h1>Профиль</h1>
        <p>Управляйте личными данными и подключёнными аккаунтами.</p>
      </div>
    </header>

    <section class="account-card" aria-labelledby="profile-title">
      <h2 id="profile-title">Личные данные</h2>
      <form class="account-form" @submit.prevent="saveProfile">
        <label class="field">
          <span>ФИО</span>
          <input v-model.trim="name" required maxlength="255" autocomplete="name" />
        </label>
        <label class="field">
          <span>Номер телефона</span>
          <input
            :value="phone"
            type="tel"
            maxlength="32"
            autocomplete="tel"
            placeholder="+7 (900) 000-00-00"
            @input="onPhoneInput"
          />
          <small>
            Формат российского номера: +7 (900) 000-00-00. Подтверждение по SMS пока не используется.
          </small>
        </label>
        <p v-if="profileError" class="message message--error" role="alert">{{ profileError }}</p>
        <p v-if="profileMessage" class="message message--success" role="status">{{ profileMessage }}</p>
        <BaseButton type="submit" :loading="profileBusy">Сохранить данные</BaseButton>
      </form>
    </section>

    <section class="account-card" aria-labelledby="email-title">
      <div>
        <h2 id="email-title">Контактный email</h2>
        <p class="help-text">
          Автоматическое восстановление доступа по email пока не настроено.
        </p>
      </div>
      <form class="account-form" @submit.prevent="saveEmail">
        <label class="field">
          <span>Email</span>
          <input
            v-model.trim="email"
            type="email"
            required
            maxlength="255"
            autocomplete="email"
          />
        </label>
        <p v-if="emailError" class="message message--error" role="alert">{{ emailError }}</p>
        <p v-if="emailMessage" class="message message--success" role="status">{{ emailMessage }}</p>
        <BaseButton type="submit" :loading="emailBusy">Сохранить email</BaseButton>
      </form>
      <p class="help-text">
        Статус: {{ auth.user?.email_verified ? 'email подтверждён' : 'email не подтверждён' }}.
        Для аккаунта Google подтверждённый Google email считается подтверждённым.
      </p>
      <EmailVerificationPrompt
        v-if="emailChallenge"
        :challenge="emailChallenge"
        @verified="onEmailVerified"
        @challenge-updated="updateEmailChallenge"
      />
    </section>

    <section class="account-card" aria-labelledby="credentials-title">
      <h2 id="credentials-title">Парольный вход</h2>
      <p class="help-text">
        Логин уникален для всей платформы. Для входа можно использовать его или подтверждённый email.
      </p>
      <form class="account-form" @submit.prevent="saveUsername">
        <label class="field">
          <span>Глобальный логин</span>
          <input
            v-model.trim="username"
            required
            minlength="3"
            maxlength="32"
            pattern="[a-z0-9][a-z0-9_.-]{2,31}"
            autocapitalize="off"
            autocomplete="username"
          />
        </label>
        <BaseButton type="submit" :loading="usernameBusy">Сохранить логин</BaseButton>
      </form>
      <form class="account-form" @submit.prevent="savePassword">
        <label class="field">
          <span>{{ auth.user?.has_password ? 'Новый пароль' : 'Пароль' }}</span>
          <input
            v-model="password"
            type="password"
            required
            minlength="12"
            autocomplete="new-password"
          />
        </label>
        <label class="field">
          <span>Повторите пароль</span>
          <input
            v-model="passwordConfirmation"
            type="password"
            required
            minlength="12"
            autocomplete="new-password"
          />
        </label>
        <BaseButton
          type="submit"
          :loading="passwordBusy"
          :disabled="!auth.user?.email_verified"
        >
          {{ auth.user?.has_password ? 'Изменить пароль' : 'Установить пароль' }}
        </BaseButton>
      </form>
      <p v-if="!auth.user?.email_verified" class="help-text">
        Сначала укажите и подтвердите email.
      </p>
      <p v-if="credentialsError" class="message message--error" role="alert">
        {{ credentialsError }}
      </p>
      <p v-if="credentialsMessage" class="message message--success" role="status">
        {{ credentialsMessage }}
      </p>
    </section>

    <section
      v-if="canLinkProviders"
      class="account-card"
      aria-labelledby="connections-title"
    >
      <div class="section-heading">
        <div>
          <h2 id="connections-title">Привязанные аккаунты</h2>
          <p>Подключённые сервисы помогают входить в аккаунт и получать уведомления.</p>
        </div>
      </div>
      <p class="help-text">
        Привязанные сервисы можно использовать для входа в аккаунт.
      </p>
      <div class="connection-row">
        <div>
          <strong>Google</strong>
          <span>{{ auth.user?.google_linked ? 'Привязан' : 'Не привязан' }}</span>
        </div>
        <div
          v-if="!auth.user?.google_linked"
          ref="googleButton"
          class="google-button"
        />
      </div>
      <p v-if="!auth.user?.google_linked && googleConfigLoaded && !googleClientId && !googleError" class="help-text">
        Вход через Google пока не настроен.
      </p>
      <p v-if="googleError" class="message message--error" role="alert">{{ googleError }}</p>
      <p v-if="googleMessage" class="message message--success" role="status">{{ googleMessage }}</p>
      <div class="connection-row">
        <div>
          <strong>Telegram</strong>
          <span>{{ auth.user?.telegram_linked ? 'Привязан' : 'Не привязан' }}</span>
        </div>
        <BaseButton
          v-if="auth.user?.telegram_linked && canLinkTelegram"
          variant="secondary"
          @click="startTelegramOidcLink"
        >
          Проверить и объединить Telegram
        </BaseButton>
        <BaseButton
          v-else-if="canLinkTelegram && telegramInitData"
          variant="secondary"
          :loading="telegramBusy"
          @click="connectTelegram"
        >
          Привязать Telegram
        </BaseButton>
        <BaseButton
          v-else-if="canLinkTelegram && !telegramInitData"
          variant="secondary"
          @click="startTelegramOidcLink"
        >
          Привязать Telegram
        </BaseButton>
      </div>
      <p
        v-if="
          canLinkTelegram && (auth.user?.telegram_linked || !telegramInitData)
        "
        class="help-text"
      >
        Подтвердите Telegram на общей странице. Если найден второй профиль, он будет объединён с текущим.
      </p>
      <p v-if="!auth.user?.telegram_linked && !canLinkTelegram" class="help-text">
        Привязка Telegram доступна только клиентским аккаунтам.
      </p>
      <p v-if="telegramError" class="message message--error" role="alert">{{ telegramError }}</p>
      <p v-if="telegramMessage" class="message message--success" role="status">{{ telegramMessage }}</p>
    </section>
  </section>
</template>

<style scoped>
.account-page {
  display: grid;
  gap: 1rem;
  margin: 0 auto;
  max-width: 46rem;
}

.page-heading h1 {
  color: #23392d;
  font-size: 1.5rem;
  margin: 0;
}

.page-heading p:not(.eyebrow) {
  color: #758078;
  font-size: 0.86rem;
  margin: 0.35rem 0 0;
}

.eyebrow {
  color: #678076;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.15em;
  margin: 0 0 0.4rem;
}

.account-card {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.95rem;
  display: grid;
  gap: 0.85rem;
  padding: 1.1rem;
}

.account-card h2 {
  color: #30493b;
  font-size: 1rem;
  margin: 0;
}

.account-form {
  display: grid;
  gap: 0.8rem;
  max-width: 30rem;
}

.field {
  display: grid;
  gap: 0.35rem;
}

.field span {
  color: #65756b;
  font-size: 0.8rem;
  font-weight: 700;
}

.field input {
  background: #fff;
  border: 1px solid #d5dfd8;
  border-radius: 0.6rem;
  color: #23392d;
  font: inherit;
  min-height: 2.7rem;
  padding: 0.55rem 0.65rem;
}

.field small,
.help-text,
.section-heading p {
  color: #758078;
  font-size: 0.78rem;
  line-height: 1.5;
  margin: 0;
}

.section-heading h2 {
  margin-bottom: 0.35rem;
}

.connection-row {
  align-items: center;
  border-top: 1px solid #edf1ed;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  justify-content: space-between;
  padding-top: 0.8rem;
}

.connection-row > div {
  display: grid;
  gap: 0.2rem;
}

.connection-row strong {
  color: #30493b;
}

.connection-row span {
  color: #758078;
  font-size: 0.8rem;
}

.google-button {
  min-height: 2.5rem;
  min-width: 13rem;
}

.message {
  border-radius: 0.65rem;
  font-size: 0.83rem;
  margin: 0;
  padding: 0.7rem 0.85rem;
}

.message--error {
  background: #fff1ef;
  color: #8d322c;
}

.message--success {
  background: #edf7ee;
  color: #386348;
}
</style>
