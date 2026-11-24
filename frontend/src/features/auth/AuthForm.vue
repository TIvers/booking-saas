<script setup lang="ts">
import { ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { getApiProblem } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const emit = defineEmits<{
  authenticated: []
}>()

const auth = useAuthStore()
const mode = ref<'login' | 'register'>('register')
const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const remember = ref(true)
const busy = ref(false)
const message = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

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
        email: email.value,
        password: password.value,
        password_confirmation: passwordConfirmation.value,
      })
    } else {
      await auth.login({
        email: email.value,
        password: password.value,
        remember: remember.value,
      })
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
</script>

<template>
  <section class="auth-form">
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

      <label class="field">
        <span>Электронная почта</span>
        <input
          v-model.trim="email"
          type="email"
          autocomplete="email"
          required
          maxlength="255"
        />
        <small v-if="fieldErrors.email">{{ fieldErrors.email[0] }}</small>
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

      <BaseButton type="submit" :loading="busy" class="auth-form__submit">
        {{ mode === 'register' ? 'Создать аккаунт и записаться' : 'Войти и записаться' }}
      </BaseButton>
    </form>
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
</style>
