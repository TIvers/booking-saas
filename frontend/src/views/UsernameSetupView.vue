<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
import { getApiProblem } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const username = ref('')
const saving = ref(false)
const message = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

async function save(): Promise<void> {
  saving.value = true
  message.value = ''
  fieldErrors.value = {}

  try {
    await auth.updateUsername(username.value)
    const tenantSlug = route.params.tenantSlug
    const redirect = route.query.redirect
    const destination =
      typeof tenantSlug === 'string'
      && typeof redirect === 'string'
      && redirect.startsWith(`/t/${tenantSlug}/`)
        ? redirect
        : auth.role === 'staff'
          ? { name: 'specialist-calendar', params: { tenantSlug } }
          : { name: 'booking', params: { tenantSlug } }

    await router.replace(destination)
  } catch (error) {
    const problem = getApiProblem(error, 'Не удалось сохранить логин.')
    message.value = problem.message
    fieldErrors.value = problem.errors
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="username-setup">
    <p class="eyebrow">НАСТРОЙКА АККАУНТА</p>
    <h1>Создайте логин</h1>
    <p class="intro">
      Укажите уникальный логин, чтобы входить по нему и использовать его во всех организациях.
    </p>

    <form class="username-form" @submit.prevent="save">
      <label class="field">
        <span>Логин</span>
        <input
          v-model.trim="username"
          required
          minlength="3"
          maxlength="32"
          pattern="[a-z0-9][a-z0-9_.-]{2,31}"
          autocapitalize="off"
          autocomplete="username"
          autofocus
        />
        <small>3–32 символа: латинские буквы, цифры, точка, дефис или подчёркивание.</small>
        <small v-if="fieldErrors.username" class="field-error">{{ fieldErrors.username[0] }}</small>
      </label>

      <p v-if="message" class="form-message" role="alert">{{ message }}</p>
      <BaseButton type="submit" :loading="saving">Сохранить логин</BaseButton>
    </form>
  </section>
</template>

<style scoped>
.username-setup {
  background: #fff;
  border: 1px solid #e8ede8;
  border-radius: 1.1rem;
  box-shadow: 0 18px 55px #20372a0d;
  margin: 3rem auto;
  max-width: 34rem;
  padding: clamp(1.25rem, 5vw, 2.2rem);
}

.eyebrow {
  color: #678076;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.15em;
  margin: 0 0 0.45rem;
}

h1 {
  color: #1b3026;
  font-size: 1.7rem;
  margin: 0;
}

.intro {
  color: #68776e;
  line-height: 1.6;
  margin: 0.7rem 0 1.2rem;
}

.username-form,
.field {
  display: grid;
  gap: 0.8rem;
}

.field span {
  color: #354a40;
  font-size: 0.88rem;
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
}

.field small {
  color: #7a867d;
  font-size: 0.78rem;
}

.field .field-error {
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
