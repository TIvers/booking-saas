<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, ensureCsrfCookie, getApiProblem } from '@/api/client'

interface OnboardingResponse {
  data: {
    tenant: {
      slug: string
    }
  }
}

const router = useRouter()
const lookupSlug = ref('')
const organizationName = ref('')
const slug = ref('')
const timezone = ref('Europe/Moscow')
const currency = ref('RUB')
const ownerName = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const busy = ref(false)
const message = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

function openOrganization(): void {
  const organizationSlug = lookupSlug.value.trim()
  if (!organizationSlug) return

  void router.push({ name: 'booking', params: { tenantSlug: organizationSlug } })
}

async function createOrganization(): Promise<void> {
  busy.value = true
  message.value = ''
  fieldErrors.value = {}

  try {
    await ensureCsrfCookie()
    const response = await api.post<OnboardingResponse>('/onboarding', {
      organization_name: organizationName.value,
      slug: slug.value,
      timezone: timezone.value,
      currency: currency.value,
      owner_name: ownerName.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })

    await router.push({
      name: 'booking',
      params: { tenantSlug: response.data.data.tenant.slug },
    })
  } catch (error) {
    const problem = getApiProblem(error, 'Не удалось создать организацию. Проверьте данные.')
    message.value = problem.message
    fieldErrors.value = problem.errors
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="tenant-home">
    <header class="tenant-home__intro">
      <p class="eyebrow">BOOKING SAAS</p>
      <h1>Онлайн-запись для вашей организации</h1>
      <p>Откройте страницу записи своей компании или подключите новую организацию.</p>
    </header>

    <div class="tenant-home__columns">
      <section class="tenant-card">
        <p class="eyebrow">УЖЕ ЕСТЬ СТРАНИЦА?</p>
        <h2>Открыть организацию</h2>
        <form class="tenant-form" @submit.prevent="openOrganization">
          <label class="field">
            <span>Адрес организации</span>
            <div class="slug-input">
              <span>/t/</span>
              <input
                v-model.trim="lookupSlug"
                required
                maxlength="63"
                pattern="[a-z0-9]+(-[a-z0-9]+)*"
                autocapitalize="off"
                autocomplete="organization"
                placeholder="studio-oliva"
              />
            </div>
          </label>
          <BaseButton type="submit" variant="secondary">Перейти к записи</BaseButton>
        </form>
        <p class="tenant-card__hint">
          Ссылка на страницу записи выглядит так: <code>/t/название-компании</code>.
        </p>
      </section>

      <section class="tenant-card tenant-card--signup">
        <p class="eyebrow">НОВАЯ ОРГАНИЗАЦИЯ</p>
        <h2>Подключить компанию</h2>
        <form class="tenant-form" @submit.prevent="createOrganization">
          <label class="field">
            <span>Название организации</span>
            <input v-model.trim="organizationName" required maxlength="255" autocomplete="organization" />
            <small v-if="fieldErrors.organization_name">{{ fieldErrors.organization_name[0] }}</small>
          </label>

          <label class="field">
            <span>Короткий адрес страницы</span>
            <div class="slug-input">
              <span>/t/</span>
              <input
                v-model.trim="slug"
                required
                maxlength="63"
                pattern="[a-z0-9]+(-[a-z0-9]+)*"
                autocapitalize="off"
                placeholder="my-studio"
              />
            </div>
            <small v-if="fieldErrors.slug">{{ fieldErrors.slug[0] }}</small>
          </label>

          <div class="field-row">
            <label class="field">
              <span>Часовой пояс</span>
              <select v-model="timezone">
                <option value="Europe/Kaliningrad">Калининград</option>
                <option value="Europe/Moscow">Москва</option>
                <option value="Asia/Yekaterinburg">Екатеринбург</option>
                <option value="Asia/Novosibirsk">Новосибирск</option>
                <option value="Asia/Vladivostok">Владивосток</option>
                <option value="UTC">UTC</option>
              </select>
              <small v-if="fieldErrors.timezone">{{ fieldErrors.timezone[0] }}</small>
            </label>
            <label class="field">
              <span>Валюта</span>
              <select v-model="currency">
                <option value="RUB">RUB</option>
                <option value="USD">USD</option>
                <option value="EUR">EUR</option>
              </select>
              <small v-if="fieldErrors.currency">{{ fieldErrors.currency[0] }}</small>
            </label>
          </div>

          <div class="form-divider"><span>Владелец организации</span></div>

          <label class="field">
            <span>Ваше имя</span>
            <input v-model.trim="ownerName" required maxlength="255" autocomplete="name" />
            <small v-if="fieldErrors.owner_name">{{ fieldErrors.owner_name[0] }}</small>
          </label>

          <label class="field">
            <span>Электронная почта</span>
            <input v-model.trim="email" type="email" required maxlength="255" autocomplete="email" />
            <small v-if="fieldErrors.email">{{ fieldErrors.email[0] }}</small>
          </label>

          <label class="field">
            <span>Пароль (не менее 12 символов)</span>
            <input
              v-model="password"
              type="password"
              required
              minlength="12"
              autocomplete="new-password"
            />
            <small v-if="fieldErrors.password">{{ fieldErrors.password[0] }}</small>
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

          <p v-if="message" class="form-message" role="alert">{{ message }}</p>
          <BaseButton type="submit" :loading="busy" class="tenant-form__submit">
            Создать организацию
          </BaseButton>
        </form>
      </section>
    </div>
  </section>
</template>

<style scoped>
.tenant-home {
  display: grid;
  gap: 1.4rem;
  margin: 1.5rem auto;
  max-width: 62rem;
}

.tenant-home__intro {
  margin: 0 auto;
  max-width: 42rem;
  text-align: center;
}

.eyebrow {
  color: #678076;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.15em;
  margin: 0 0 0.45rem;
}

.tenant-home__intro h1 {
  color: #1b3026;
  font-size: clamp(1.8rem, 5vw, 2.7rem);
  letter-spacing: -0.045em;
  line-height: 1.1;
  margin: 0;
}

.tenant-home__intro > p:last-child {
  color: #68776e;
  line-height: 1.6;
  margin: 0.8rem 0 0;
}

.tenant-home__columns {
  align-items: start;
  display: grid;
  gap: 1rem;
  grid-template-columns: minmax(16rem, 0.8fr) minmax(20rem, 1.2fr);
}

.tenant-card {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 1rem;
  box-shadow: 0 18px 55px #20372a0d;
  padding: clamp(1rem, 3vw, 1.5rem);
}

.tenant-card h2 {
  color: #23392d;
  font-size: 1.25rem;
  margin: 0 0 1rem;
}

.tenant-form {
  display: grid;
  gap: 0.9rem;
}

.field {
  display: grid;
  gap: 0.4rem;
}

.field > span {
  color: #354a40;
  font-size: 0.84rem;
  font-weight: 650;
}

.field input,
.field select {
  background: #fff;
  border: 1px solid #d5dfd8;
  border-radius: 0.65rem;
  color: #1b2c24;
  font: inherit;
  min-height: 2.75rem;
  padding: 0.6rem 0.7rem;
  width: 100%;
}

.field input:focus,
.field select:focus {
  border-color: #568073;
  outline: 3px solid #5680732b;
}

.field small {
  color: #9c3d36;
  font-size: 0.78rem;
}

.slug-input {
  align-items: center;
  background: #f7f9f6;
  border: 1px solid #d5dfd8;
  border-radius: 0.65rem;
  color: #76847b;
  display: flex;
  gap: 0.15rem;
  padding-left: 0.7rem;
}

.slug-input input {
  background: transparent;
  border: 0;
  min-width: 0;
  outline: 0;
}

.slug-input:focus-within {
  border-color: #568073;
  outline: 3px solid #5680732b;
}

.field-row {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: 1fr 0.7fr;
}

.form-divider {
  align-items: center;
  color: #718078;
  display: flex;
  font-size: 0.75rem;
  font-weight: 700;
  gap: 0.7rem;
  margin: 0.25rem 0;
}

.form-divider::before,
.form-divider::after {
  background: #e6ece6;
  content: "";
  height: 1px;
  width: 100%;
}

.tenant-form__submit {
  width: 100%;
}

.tenant-card__hint {
  color: #7a867d;
  font-size: 0.8rem;
  line-height: 1.5;
  margin: 1rem 0 0;
}

.tenant-card__hint code {
  color: #31574e;
}

.form-message {
  background: #fff1ef;
  border-radius: 0.65rem;
  color: #8d322c;
  margin: 0;
  padding: 0.75rem 0.85rem;
}

@media (max-width: 760px) {
  .tenant-home__columns {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 420px) {
  .field-row {
    grid-template-columns: 1fr;
  }
}
</style>
