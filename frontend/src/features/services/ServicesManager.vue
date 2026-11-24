<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, getApiProblem } from '@/api/client'
import type { ApiResponse, Service } from '@/api/types'
import { formatMoney } from '@/composables/useTimezone'
import { useTenantStore } from '@/stores/tenant'

interface ServiceDraft {
  name: string
  description: string
  duration: string
  buffer: string
  price: string
  deposit: string
}

const tenant = useTenantStore()
const services = ref<Service[]>([])
const editingId = ref<number | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const draft = reactive<ServiceDraft>(emptyDraft())

function emptyDraft(): ServiceDraft {
  return {
    name: '',
    description: '',
    duration: '30',
    buffer: '0',
    price: '',
    deposit: '0',
  }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get<ApiResponse<Service[]>>('/manage/services')
    services.value = response.data.data
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить услуги.').message
  } finally {
    loading.value = false
  }
}

function resetForm(): void {
  editingId.value = null
  Object.assign(draft, emptyDraft())
  notice.value = ''
}

function edit(service: Service): void {
  editingId.value = service.id
  Object.assign(draft, {
    name: service.name,
    description: service.description ?? '',
    duration: String(service.duration_min),
    buffer: String(service.buffer_min),
    price: (service.price_cents / 100).toFixed(2),
    deposit: (service.deposit_cents / 100).toFixed(2),
  })
  error.value = ''
  notice.value = ''
}

function toCents(value: string): number | null {
  if (!value.trim()) return null
  const amount = Number(value)
  return Number.isFinite(amount) && amount >= 0 ? Math.round(amount * 100) : null
}

async function save(): Promise<void> {
  const price = toCents(draft.price)
  const deposit = toCents(draft.deposit)

  if (price === null || deposit === null) {
    error.value = 'Укажите корректные значения цены и предоплаты.'
    return
  }
  if (deposit > price) {
    error.value = 'Предоплата не может быть выше стоимости услуги.'
    return
  }

  saving.value = true
  error.value = ''
  notice.value = ''
  const wasEditing = editingId.value !== null
  const payload = {
    name: draft.name.trim(),
    description: draft.description.trim() || null,
    duration_min: Number(draft.duration),
    buffer_min: Number(draft.buffer),
    price_cents: price,
    deposit_cents: deposit,
  }

  try {
    if (wasEditing) {
      await api.patch(`/services/${editingId.value}`, payload)
    } else {
      await api.post('/services', payload)
    }
    resetForm()
    notice.value = wasEditing ? 'Услуга обновлена.' : 'Услуга добавлена.'
    await load()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось сохранить услугу.').message
  } finally {
    saving.value = false
  }
}

async function setActive(service: Service, active: boolean): Promise<void> {
  error.value = ''
  notice.value = ''

  try {
    if (active) {
      await api.patch(`/services/${service.id}`, { is_active: true })
    } else {
      await api.delete(`/services/${service.id}`)
    }
    notice.value = active ? 'Услуга снова доступна для записи.' : 'Услуга отключена.'
    await load()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось изменить услугу.').message
  }
}

onMounted(load)
</script>

<template>
  <section class="services-page">
    <header class="page-heading">
      <div>
        <h2>Услуги</h2>
        <p>Управляйте ценами, длительностью и предоплатой.</p>
      </div>
    </header>

    <p v-if="error" class="message message--error" role="alert">{{ error }}</p>
    <p v-if="notice" class="message message--success" role="status">{{ notice }}</p>

    <section class="service-form">
      <header class="section-heading">
        <h3>{{ editingId ? 'Изменить услугу' : 'Добавить услугу' }}</h3>
        <button v-if="editingId" type="button" class="text-button" @click="resetForm">Отмена</button>
      </header>
      <form class="form-grid" @submit.prevent="save">
        <label class="field field--wide">
          <span>Название</span>
          <input v-model.trim="draft.name" maxlength="255" required />
        </label>
        <label class="field field--wide">
          <span>Описание</span>
          <textarea v-model.trim="draft.description" rows="2" maxlength="10000"></textarea>
        </label>
        <label class="field">
          <span>Длительность, мин</span>
          <input v-model="draft.duration" type="number" min="1" max="1440" step="1" required />
        </label>
        <label class="field">
          <span>Буфер, мин</span>
          <input v-model="draft.buffer" type="number" min="0" max="1440" step="1" required />
        </label>
        <label class="field">
          <span>Стоимость, {{ tenant.tenant?.currency ?? 'USD' }}</span>
          <input v-model="draft.price" type="number" min="0" step="0.01" required />
        </label>
        <label class="field">
          <span>Предоплата, {{ tenant.tenant?.currency ?? 'USD' }}</span>
          <input v-model="draft.deposit" type="number" min="0" step="0.01" required />
        </label>
        <div class="form-actions">
          <BaseButton type="submit" :loading="saving">
            {{ editingId ? 'Сохранить изменения' : 'Добавить услугу' }}
          </BaseButton>
        </div>
      </form>
    </section>

    <div v-if="loading" class="state-card" role="status">Загружаем услуги…</div>
    <div v-else-if="!services.length" class="state-card">Услуг пока нет.</div>
    <div v-else class="service-list">
      <article
        v-for="service in services"
        :key="service.id"
        class="service-card"
        :class="{ 'service-card--inactive': !service.is_active }"
      >
        <div class="service-card__main">
          <span class="service-card__status">{{ service.is_active ? 'Активна' : 'Отключена' }}</span>
          <h3>{{ service.name }}</h3>
          <p v-if="service.description">{{ service.description }}</p>
          <small>{{ service.duration_min }} мин · буфер {{ service.buffer_min }} мин</small>
        </div>
        <div class="service-card__price">
          <strong>{{ formatMoney(service.price_cents, tenant.tenant?.currency ?? 'USD') }}</strong>
          <small v-if="service.deposit_cents">
            Предоплата: {{ formatMoney(service.deposit_cents, tenant.tenant?.currency ?? 'USD') }}
          </small>
        </div>
        <div class="service-card__actions">
          <BaseButton variant="secondary" @click="edit(service)">Изменить</BaseButton>
          <BaseButton
            v-if="service.is_active"
            variant="ghost"
            @click="setActive(service, false)"
          >
            Отключить
          </BaseButton>
          <BaseButton v-else variant="ghost" @click="setActive(service, true)">Включить</BaseButton>
        </div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.services-page {
  display: grid;
  gap: 1rem;
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

.service-form,
.service-card,
.state-card {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.95rem;
  padding: 1rem;
}

.section-heading,
.service-card,
.service-card__actions {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.7rem;
  justify-content: space-between;
}

.section-heading h3 {
  color: #30493b;
  font-size: 1rem;
  margin: 0;
}

.form-grid {
  display: grid;
  gap: 0.7rem;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  margin-top: 0.9rem;
}

.field {
  display: grid;
  gap: 0.35rem;
}

.field--wide {
  grid-column: 1 / -1;
}

.field span {
  color: #65756b;
  font-size: 0.73rem;
  font-weight: 700;
}

.field input,
.field textarea {
  background: #fff;
  border: 1px solid #d5dfd8;
  border-radius: 0.6rem;
  color: #23392d;
  font: inherit;
  min-height: 2.6rem;
  padding: 0.55rem 0.65rem;
  width: 100%;
}

.field textarea {
  resize: vertical;
}

.form-actions {
  align-self: end;
}

.service-list {
  display: grid;
  gap: 0.7rem;
}

.service-card {
  justify-content: initial;
}

.service-card--inactive {
  background: #fafbf9;
  opacity: 0.75;
}

.service-card__main {
  flex: 1 1 15rem;
}

.service-card__main h3 {
  color: #2a4134;
  font-size: 1rem;
  margin: 0.3rem 0;
}

.service-card__main p,
.service-card__main small,
.service-card__price small {
  color: #77847c;
  font-size: 0.78rem;
  margin: 0;
}

.service-card__status {
  color: #688372;
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.service-card__price {
  display: grid;
  flex: 0 1 11rem;
  gap: 0.25rem;
}

.service-card__price strong {
  color: #31574e;
}

.service-card__actions {
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

@media (max-width: 760px) {
  .form-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 520px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
