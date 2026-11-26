<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import EmailVerificationPrompt from '@/features/auth/EmailVerificationPrompt.vue'
import { getApiProblem } from '@/api/client'
import { useAuthStore, type EmailVerificationChallenge } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const message = ref('Проверяем ссылку подтверждения…')
const error = ref('')
const challenge = ref<EmailVerificationChallenge | null>(null)

function queryValue(name: string): string | null {
  const value = route.query[name]
  return typeof value === 'string' ? value : null
}

async function completeVerification(): Promise<void> {
  const challengeId = queryValue('challenge')
  const token = queryValue('token')

  if (!challengeId || !token) {
    error.value = 'Ссылка подтверждения неполная или повреждена.'
    return
  }

  try {
    await auth.verifyEmailLink(challengeId, token)
    message.value = 'Email подтверждён. Переходим в аккаунт…'
    const tenantSlug = route.params.tenantSlug
    if (typeof tenantSlug === 'string') {
      await router.replace({ name: 'account', params: { tenantSlug } })
    }
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось подтвердить email.').message
    message.value = ''
  }
}

function setChallenge(): void {
  const challengeId = queryValue('challenge')
  const email = queryValue('email')
  const channel = queryValue('channel')

  if (!challengeId || !email || (channel !== 'code' && channel !== 'link')) {
    error.value = 'Данные подтверждения отсутствуют. Запросите письмо повторно.'
    return
  }

  challenge.value = {
    challenge_id: challengeId,
    email,
    channel,
    expires_at: '',
  }
  message.value = ''
}

function onVerified(): void {
  const tenantSlug = route.params.tenantSlug
  if (typeof tenantSlug === 'string') {
    void router.replace({ name: 'account', params: { tenantSlug } })
  }
}

onMounted(() => {
  if (queryValue('token')) {
    void completeVerification()
  } else {
    setChallenge()
  }
})
</script>

<template>
  <section class="verification-page">
    <h1>Подтверждение email</h1>
    <p v-if="message" role="status">{{ message }}</p>
    <EmailVerificationPrompt
      v-if="challenge"
      :challenge="challenge"
      @verified="onVerified"
      @challenge-updated="(updated) => { challenge = updated }"
    />
    <p v-if="error" class="error" role="alert">{{ error }}</p>
  </section>
</template>

<style scoped>
.verification-page {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.95rem;
  display: grid;
  gap: 1rem;
  margin: 3rem auto;
  max-width: 34rem;
  padding: clamp(1rem, 5vw, 2rem);
}

h1 {
  color: #23392d;
  font-size: 1.4rem;
  margin: 0;
}

.verification-page > p {
  color: #68776e;
  margin: 0;
}

.error {
  background: #fff1ef;
  border-radius: 0.65rem;
  color: #8d322c;
  margin: 0;
  padding: 0.75rem;
}
</style>
