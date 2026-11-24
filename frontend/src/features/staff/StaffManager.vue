<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, getApiProblem } from '@/api/client'
import type { ApiResponse, Service, StaffMember } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'

interface Location {
  id: number
  name: string
  address: string | null
  timezone: string
}

interface StaffDraft {
  display_name: string
  location_id: number | null
  service_ids: number[]
}

const auth = useAuthStore()
const tenant = useTenantStore()
const staff = ref<StaffMember[]>([])
const locations = ref<Location[]>([])
const services = ref<Service[]>([])
const editingId = ref<number | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const showLocationForm = ref(false)
const accountTarget = ref<StaffMember | null>(null)
const accountDraft = reactive({
  username: '',
  email: '',
  password: '',
  password_confirmation: '',
})
const locationDraft = reactive({
  name: '',
  address: '',
  timezone: tenant.tenant?.timezone ?? 'UTC',
})
const draft = reactive<StaffDraft>({
  display_name: '',
  location_id: null,
  service_ids: [],
})

const activeServices = computed(() => services.value.filter((service) => service.is_active))

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const [staffResponse, locationsResponse, servicesResponse] = await Promise.all([
      api.get<ApiResponse<StaffMember[]>>('/manage/staff'),
      api.get<ApiResponse<Location[]>>('/locations'),
      api.get<ApiResponse<Service[]>>('/manage/services'),
    ])
    staff.value = staffResponse.data.data
    locations.value = locationsResponse.data.data
    services.value = servicesResponse.data.data
    if (!draft.location_id && locations.value.length) {
      draft.location_id = locations.value[0].id
    }
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить команду.').message
  } finally {
    loading.value = false
  }
}

function resetForm(): void {
  editingId.value = null
  draft.display_name = ''
  draft.location_id = locations.value[0]?.id ?? null
  draft.service_ids = []
}

function edit(member: StaffMember): void {
  editingId.value = member.id
  draft.display_name = member.display_name
  draft.location_id = member.location.id
  draft.service_ids = member.services.map((service) => service.id)
  error.value = ''
  notice.value = ''
}

function toggleService(id: number): void {
  draft.service_ids = draft.service_ids.includes(id)
    ? draft.service_ids.filter((serviceId) => serviceId !== id)
    : [...draft.service_ids, id]
}

async function saveStaff(): Promise<void> {
  if (!draft.location_id) {
    error.value = 'Сначала добавьте филиал.'
    return
  }

  saving.value = true
  error.value = ''
  notice.value = ''
  const payload = {
    display_name: draft.display_name.trim(),
    location_id: draft.location_id,
    service_ids: draft.service_ids,
  }

  try {
    if (editingId.value) {
      await api.patch(`/staff/${editingId.value}`, payload)
      notice.value = 'Специалист обновлён.'
    } else {
      await api.post('/staff', payload)
      notice.value = 'Специалист добавлен.'
    }
    resetForm()
    await load()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось сохранить специалиста.').message
  } finally {
    saving.value = false
  }
}

async function setActive(member: StaffMember, active: boolean): Promise<void> {
  error.value = ''
  notice.value = ''

  try {
    await api.patch(`/staff/${member.id}`, { is_active: active })
    notice.value = active ? 'Специалист снова доступен для записи.' : 'Специалист отключён.'
    await load()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось изменить специалиста.').message
  }
}

async function createLocation(): Promise<void> {
  saving.value = true
  error.value = ''
  notice.value = ''

  try {
    await api.post('/locations', {
      name: locationDraft.name.trim(),
      address: locationDraft.address.trim() || null,
      timezone: locationDraft.timezone,
    })
    locationDraft.name = ''
    locationDraft.address = ''
    showLocationForm.value = false
    notice.value = 'Филиал добавлен.'
    await load()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось добавить филиал.').message
  } finally {
    saving.value = false
  }
}

function openAccountForm(member: StaffMember): void {
  accountTarget.value = member
  accountDraft.username = ''
  accountDraft.email = ''
  accountDraft.password = ''
  accountDraft.password_confirmation = ''
  error.value = ''
  notice.value = ''
}

async function createStaffAccount(): Promise<void> {
  if (!accountTarget.value) return

  saving.value = true
  error.value = ''
  notice.value = ''

  try {
    await api.put(`/staff/${accountTarget.value.id}/account`, {
      username: accountDraft.username,
      email: accountDraft.email.trim() || null,
      password: accountDraft.password,
      password_confirmation: accountDraft.password_confirmation,
    })
    accountTarget.value = null
    notice.value = 'Аккаунт специалиста создан и привязан к карточке команды.'
    await load()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось создать аккаунт специалиста.').message
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="staff-page">
    <header class="page-heading">
      <div>
        <h2>Команда и филиалы</h2>
        <p>Настройте специалистов и услуги, которые они проводят.</p>
      </div>
      <BaseButton variant="secondary" @click="showLocationForm = !showLocationForm">
        {{ showLocationForm ? 'Скрыть форму филиала' : 'Добавить филиал' }}
      </BaseButton>
    </header>

    <p v-if="error" class="message message--error" role="alert">{{ error }}</p>
    <p v-if="notice" class="message message--success" role="status">{{ notice }}</p>

    <section v-if="showLocationForm" class="editor-card">
      <h3>Новый филиал</h3>
      <form class="location-form" @submit.prevent="createLocation">
        <label class="field">
          <span>Название</span>
          <input v-model.trim="locationDraft.name" maxlength="255" required />
        </label>
        <label class="field">
          <span>Адрес</span>
          <input v-model.trim="locationDraft.address" maxlength="2000" />
        </label>
        <label class="field">
          <span>Часовой пояс</span>
          <input v-model="locationDraft.timezone" maxlength="64" required />
        </label>
        <BaseButton type="submit" :loading="saving">Сохранить филиал</BaseButton>
      </form>
    </section>

    <section class="editor-card">
      <header class="section-heading">
        <h3>{{ editingId ? 'Изменить специалиста' : 'Добавить специалиста' }}</h3>
        <button v-if="editingId" type="button" class="text-button" @click="resetForm">Отмена</button>
      </header>
      <form class="staff-form" @submit.prevent="saveStaff">
        <label class="field">
          <span>Имя специалиста</span>
          <input v-model.trim="draft.display_name" maxlength="255" required />
        </label>
        <label class="field">
          <span>Филиал</span>
          <select v-model.number="draft.location_id" required>
            <option v-for="location in locations" :key="location.id" :value="location.id">
              {{ location.name }}
            </option>
          </select>
        </label>
        <fieldset class="service-options">
          <legend>Доступные услуги</legend>
          <label v-for="service in activeServices" :key="service.id">
            <input
              type="checkbox"
              :checked="draft.service_ids.includes(service.id)"
              @change="toggleService(service.id)"
            />
            <span>{{ service.name }}</span>
          </label>
          <p v-if="!activeServices.length" class="empty-note">
            Сначала добавьте активные услуги.
          </p>
        </fieldset>
        <BaseButton type="submit" :loading="saving" :disabled="!locations.length">
          {{ editingId ? 'Сохранить изменения' : 'Добавить специалиста' }}
        </BaseButton>
      </form>
    </section>

    <div v-if="loading" class="state-card" role="status">Загружаем команду…</div>
    <div v-else-if="!staff.length" class="state-card">
      Специалистов пока нет. Добавьте филиал и создайте первую карточку команды.
    </div>
    <div v-else class="staff-list">
      <article
        v-for="member in staff"
        :key="member.id"
        class="staff-card"
        :class="{ 'staff-card--inactive': !member.is_active }"
      >
        <div class="staff-card__identity">
          <span class="staff-card__status">{{ member.is_active ? 'Активен' : 'Отключён' }}</span>
          <h3>{{ member.display_name }}</h3>
          <p>{{ member.location.name }} · {{ member.location.timezone }}</p>
          <p v-if="member.account_linked" class="account-status">
            Аккаунт: <strong>{{ member.account_username }}</strong>
          </p>
        </div>
        <div class="staff-card__services">
          <span v-for="service in member.services" :key="service.id" class="service-chip">
            {{ service.name }}
          </span>
          <span v-if="!member.services.length" class="empty-note">Услуги не назначены</span>
        </div>
        <div class="staff-card__actions">
          <BaseButton variant="secondary" @click="edit(member)">Изменить</BaseButton>
          <BaseButton
            v-if="auth.role === 'owner' && !member.account_linked"
            variant="secondary"
            @click="openAccountForm(member)"
          >
            Создать аккаунт
          </BaseButton>
          <BaseButton
            variant="ghost"
            @click="setActive(member, !member.is_active)"
          >
            {{ member.is_active ? 'Отключить' : 'Включить' }}
          </BaseButton>
        </div>
        <form
          v-if="auth.role === 'owner' && accountTarget?.id === member.id"
          class="account-form"
          @submit.prevent="createStaffAccount"
        >
          <h4>Доступ специалиста</h4>
          <p>Логин будет работать только на странице этой организации.</p>
          <label class="field">
            <span>Логин</span>
            <input
              v-model.trim="accountDraft.username"
              required
              minlength="3"
              maxlength="32"
              pattern="[a-z0-9][a-z0-9_.-]{2,31}"
              autocapitalize="off"
              autocomplete="username"
            />
          </label>
          <label class="field">
            <span>Email для будущего подтверждения (необязательно)</span>
            <input v-model.trim="accountDraft.email" type="email" maxlength="255" autocomplete="email" />
          </label>
          <label class="field">
            <span>Пароль (не менее 12 символов)</span>
            <input
              v-model="accountDraft.password"
              type="password"
              required
              minlength="12"
              autocomplete="new-password"
            />
          </label>
          <label class="field">
            <span>Повторите пароль</span>
            <input
              v-model="accountDraft.password_confirmation"
              type="password"
              required
              minlength="12"
              autocomplete="new-password"
            />
          </label>
          <div class="account-form__actions">
            <BaseButton type="button" variant="ghost" :disabled="saving" @click="accountTarget = null">
              Отмена
            </BaseButton>
            <BaseButton type="submit" :loading="saving">Создать аккаунт</BaseButton>
          </div>
        </form>
      </article>
    </div>
  </section>
</template>

<style scoped>
.staff-page {
  display: grid;
  gap: 1rem;
}

.page-heading,
.section-heading,
.staff-card,
.staff-card__actions {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  justify-content: space-between;
}

.page-heading h2 {
  color: #23392d;
  font-size: 1.35rem;
  margin: 0;
}

.page-heading p {
  color: #758078;
  font-size: 0.84rem;
  margin: 0.3rem 0 0;
}

.editor-card,
.staff-card,
.state-card {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.95rem;
  padding: 1rem;
}

.editor-card h3,
.section-heading h3 {
  color: #30493b;
  font-size: 1rem;
  margin: 0;
}

.location-form,
.staff-form {
  align-items: end;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-top: 0.9rem;
}

.field {
  display: grid;
  flex: 1 1 13rem;
  gap: 0.35rem;
}

.field span,
.service-options legend {
  color: #65756b;
  font-size: 0.73rem;
  font-weight: 700;
}

.field input,
.field select {
  background: #fff;
  border: 1px solid #d5dfd8;
  border-radius: 0.6rem;
  color: #23392d;
  font: inherit;
  min-height: 2.65rem;
  padding: 0.55rem 0.65rem;
  width: 100%;
}

.staff-form {
  align-items: start;
}

.service-options {
  border: 1px solid #e4ebe4;
  border-radius: 0.7rem;
  display: flex;
  flex: 1 1 100%;
  flex-wrap: wrap;
  gap: 0.65rem 1rem;
  padding: 0.75rem;
}

.service-options legend {
  padding: 0 0.3rem;
}

.service-options label {
  align-items: center;
  color: #4b5e52;
  display: inline-flex;
  font-size: 0.83rem;
  gap: 0.4rem;
}

.service-options input {
  accent-color: #31574e;
}

.staff-list {
  display: grid;
  gap: 0.7rem;
}

.staff-card {
  justify-content: initial;
}

.staff-card--inactive {
  background: #fafbf9;
  opacity: 0.75;
}

.staff-card__identity {
  flex: 1 1 13rem;
}

.staff-card__identity h3 {
  color: #2a4134;
  font-size: 1rem;
  margin: 0.25rem 0;
}

.staff-card__identity p {
  color: #77847c;
  font-size: 0.78rem;
  margin: 0;
}

.staff-card__identity .account-status {
  color: #4a6a55;
  margin-top: 0.35rem;
}

.account-status strong {
  color: #31574e;
}

.account-form {
  background: #f7f9f6;
  border: 1px solid #e1e9e2;
  border-radius: 0.8rem;
  display: grid;
  flex: 1 1 100%;
  gap: 0.7rem;
  padding: 0.9rem;
}

.account-form h4 {
  color: #30493b;
  margin: 0;
}

.account-form > p {
  color: #758078;
  font-size: 0.78rem;
  margin: 0;
}

.account-form .field {
  max-width: 32rem;
}

.account-form__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  justify-content: flex-end;
}

.staff-card__status {
  color: #688372;
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.staff-card__services {
  display: flex;
  flex: 1 1 12rem;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.service-chip {
  background: #edf4ee;
  border-radius: 99px;
  color: #477153;
  font-size: 0.72rem;
  padding: 0.35rem 0.6rem;
}

.staff-card__actions {
  justify-content: flex-end;
}

.text-button {
  background: transparent;
  border: 0;
  color: #31574e;
  cursor: pointer;
  font: inherit;
  font-size: 0.8rem;
  font-weight: 700;
}

.empty-note {
  color: #879189;
  font-size: 0.78rem;
  margin: 0;
}

.state-card {
  color: #738077;
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
  background: #edf5ee;
  color: #3c674b;
}
</style>
