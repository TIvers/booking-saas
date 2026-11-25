<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { getApiProblem } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const name = ref(auth.user?.name ?? '')
const phone = ref(auth.user?.phone ?? '')
const email = ref(auth.user?.email ?? '')
const profileBusy = ref(false)
const emailBusy = ref(false)
const telegramBusy = ref(false)
const profileMessage = ref('')
const profileError = ref('')
const emailMessage = ref('')
const emailError = ref('')
const telegramMessage = ref('')
const telegramError = ref('')
const telegramInitData = computed(() => window.Telegram?.WebApp?.initData ?? '')
const canLinkTelegram = computed(() => auth.role === 'customer' || auth.role === 'staff')
const telegramBot = computed<'main' | 'tenant'>(() =>
  new URLSearchParams(window.location.search).get('tg_bot') === 'tenant' ? 'tenant' : 'main',
)

watch(
  () => auth.user?.id,
  () => {
    name.value = auth.user?.name ?? ''
    phone.value = auth.user?.phone ?? ''
    email.value = auth.user?.email ?? ''
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
    await auth.updateEmail(email.value.trim())
    emailMessage.value = 'Email обновлён.'
  } catch (error) {
    emailError.value = getApiProblem(error, 'Не удалось обновить email.').message
  } finally {
    emailBusy.value = false
  }
}

async function connectTelegram(): Promise<void> {
  if (!telegramInitData.value) return

  telegramBusy.value = true
  telegramMessage.value = ''
  telegramError.value = ''

  try {
    await auth.linkTelegram(telegramInitData.value, telegramBot.value)
    telegramMessage.value = 'Telegram привязан к аккаунту.'
  } catch (error) {
    telegramError.value = getApiProblem(error, 'Не удалось привязать Telegram.').message
  } finally {
    telegramBusy.value = false
  }
}
</script>

<template>
  <section class="account-page">
    <header class="page-heading">
      <div>
        <p class="eyebrow">ВАШ ПРОФИЛЬ</p>
        <h1>Личный кабинет</h1>
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
            v-model.trim="phone"
            type="tel"
            maxlength="32"
            autocomplete="tel"
            placeholder="+7 900 000-00-00"
          />
          <small>Номер сохранится в профиле; подтверждение по SMS пока не используется.</small>
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
    </section>

    <section class="account-card" aria-labelledby="connections-title">
      <div class="section-heading">
        <div>
          <h2 id="connections-title">Привязанные аккаунты</h2>
          <p>Подключённые сервисы помогают входить в аккаунт и получать уведомления.</p>
        </div>
      </div>
      <p class="help-text">
        Сейчас поддерживается привязка Telegram; другие сервисы пока недоступны.
      </p>
      <div class="connection-row">
        <div>
          <strong>Telegram</strong>
          <span>{{ auth.user?.telegram_linked ? 'Привязан' : 'Не привязан' }}</span>
        </div>
        <BaseButton
          v-if="!auth.user?.telegram_linked && canLinkTelegram && telegramInitData"
          variant="secondary"
          :loading="telegramBusy"
          @click="connectTelegram"
        >
          Привязать Telegram
        </BaseButton>
      </div>
      <p
        v-if="!auth.user?.telegram_linked && canLinkTelegram && !telegramInitData"
        class="help-text"
      >
        Чтобы привязать Telegram, откройте личный кабинет внутри Telegram.
      </p>
      <p v-if="!auth.user?.telegram_linked && !canLinkTelegram" class="help-text">
        Привязка Telegram сейчас доступна для аккаунтов клиентов и специалистов.
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
