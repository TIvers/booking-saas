<script setup lang="ts">
import { ref, watch } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { getApiProblem } from '@/api/client'
import { useAuthStore, type EmailVerificationChallenge } from '@/stores/auth'
import { preferredEmailVerificationChannel } from './emailVerification'

const props = defineProps<{
  challenge: EmailVerificationChallenge
}>()

const emit = defineEmits<{
  verified: []
  challengeUpdated: [challenge: EmailVerificationChallenge]
}>()

const auth = useAuthStore()
const currentChallenge = ref(props.challenge)
const code = ref('')
const busy = ref(false)
const error = ref('')
const message = ref('')

watch(() => props.challenge, (challenge) => {
  currentChallenge.value = challenge
})

async function verify(): Promise<void> {
  busy.value = true
  error.value = ''

  try {
    await auth.verifyEmailCode(currentChallenge.value.challenge_id, code.value)
    emit('verified')
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось подтвердить email.').message
  } finally {
    busy.value = false
  }
}

async function resend(): Promise<void> {
  busy.value = true
  error.value = ''
  message.value = ''

  try {
    currentChallenge.value = await auth.resendEmailVerification(
      currentChallenge.value.challenge_id,
      preferredEmailVerificationChannel(),
    )
    code.value = ''
    emit('challengeUpdated', currentChallenge.value)
    message.value = 'Новое письмо отправлено.'
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось отправить письмо повторно.').message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="verification" aria-labelledby="verification-title">
    <h2 id="verification-title">Подтвердите email</h2>
    <p>
      Письмо отправлено на <strong>{{ currentChallenge.email }}</strong>.
      Ссылка или код действуют 15 минут.
    </p>

    <form v-if="currentChallenge.channel === 'code'" @submit.prevent="verify">
      <label class="field">
        <span>Код из письма</span>
        <input
          v-model.trim="code"
          required
          inputmode="numeric"
          autocomplete="one-time-code"
          pattern="[0-9]{6}"
          maxlength="6"
          autofocus
        />
      </label>
      <BaseButton type="submit" :loading="busy">Подтвердить email</BaseButton>
    </form>

    <p v-else class="notice">
      Откройте письмо и перейдите по ссылке для подтверждения.
    </p>

    <p v-if="error" class="message message--error" role="alert">{{ error }}</p>
    <p v-if="message" class="message message--success" role="status">{{ message }}</p>
    <BaseButton variant="secondary" :disabled="busy" @click="resend">
      Отправить письмо повторно
    </BaseButton>
  </section>
</template>

<style scoped>
.verification {
  display: grid;
  gap: 0.8rem;
}

.verification h2,
.verification p {
  margin: 0;
}

.verification h2 {
  color: #30493b;
  font-size: 1rem;
}

.verification > p {
  color: #68776e;
  font-size: 0.85rem;
  line-height: 1.5;
}

.verification form,
.field {
  display: grid;
  gap: 0.7rem;
}

.field span {
  color: #65756b;
  font-size: 0.8rem;
  font-weight: 700;
}

.field input {
  border: 1px solid #d5dfd8;
  border-radius: 0.6rem;
  font: inherit;
  letter-spacing: 0.3em;
  min-height: 2.7rem;
  padding: 0.55rem 0.65rem;
}

.notice {
  background: #f3f7f3;
  border-radius: 0.65rem;
  padding: 0.75rem;
}

.message {
  border-radius: 0.65rem;
  font-size: 0.83rem;
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
