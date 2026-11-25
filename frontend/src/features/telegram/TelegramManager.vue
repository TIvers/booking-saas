<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { api, getApiProblem } from '@/api/client'
import type { ApiResponse, Service } from '@/api/types'

interface BotInfo {
  connected: boolean
  username: string | null
  webhook_ready: boolean
  service_link_template: string | null
  mini_app_url: string
}

const bot = ref<BotInfo | null>(null)
const services = ref<Service[]>([])
const token = ref('')
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')

const links = computed(() => services.value
  .filter((service) => service.is_active)
  .map((service) => ({
    id: service.id,
    name: service.name,
    url: `https://t.me/${bot.value?.username}?startapp=service-${service.id}`,
  })))

async function load(): Promise<void> {
  loading.value = true
  error.value = ''

  try {
    const [botResponse, servicesResponse] = await Promise.all([
      api.get<ApiResponse<BotInfo>>('/manage/telegram-bot'),
      api.get<ApiResponse<Service[]>>('/manage/services'),
    ])
    bot.value = botResponse.data.data
    services.value = servicesResponse.data.data
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось загрузить настройки Telegram.').message
  } finally {
    loading.value = false
  }
}

async function connect(): Promise<void> {
  saving.value = true
  error.value = ''
  notice.value = ''

  try {
    await api.put('/manage/telegram-bot', { token: token.value.trim() })
    token.value = ''
    notice.value = 'Бот подключён. Webhook настроен автоматически.'
    await load()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось подключить Telegram-бота.').message
  } finally {
    saving.value = false
  }
}

async function disconnect(): Promise<void> {
  saving.value = true
  error.value = ''
  notice.value = ''

  try {
    await api.delete('/manage/telegram-bot')
    notice.value = 'Бот отключён. Telegram-подписки клиентов удалены.'
    await load()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось отключить Telegram-бота.').message
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="telegram-manager">
    <header>
      <p class="eyebrow">ИНТЕГРАЦИИ</p>
      <h2>Telegram-бот организации</h2>
      <p>Подключите созданного через @BotFather бота для ссылок на услуги и уведомлений.</p>
    </header>

    <p v-if="error" class="message message--error" role="alert">{{ error }}</p>
    <p v-if="notice" class="message message--success" role="status">{{ notice }}</p>
    <div v-if="loading" class="panel" role="status">Загружаем настройки…</div>

    <template v-else>
      <form class="panel connect-form" @submit.prevent="connect">
        <div v-if="bot?.connected" class="connection-status">
          Подключён: <strong>@{{ bot.username }}</strong>
          <span v-if="!bot.webhook_ready" class="connection-warning">
            Настройка webhook не завершена. Повторно сохраните токен бота.
          </span>
        </div>
        <label class="field">
          <span>{{ bot?.connected ? 'Токен подключённого бота' : 'Токен от @BotFather' }}</span>
          <input
            v-model="token"
            type="password"
            required
            autocomplete="new-password"
            placeholder="123456:AA..."
          />
        </label>
        <p class="hint">
          Токен хранится зашифрованным и не показывается после сохранения. При подключении
          webhook настраивается автоматически. Чтобы заменить бота, сначала отключите текущего.
        </p>
        <div class="actions">
          <BaseButton type="submit" :loading="saving">Подключить бота</BaseButton>
          <BaseButton
            v-if="bot?.connected"
            type="button"
            variant="ghost"
            :disabled="saving"
            @click="disconnect"
          >
            Отключить
          </BaseButton>
        </div>
      </form>

      <section v-if="bot?.connected" class="panel instructions">
        <h3>Настройка WebApp в @BotFather</h3>
        <ol>
          <li>Откройте настройки подключённого бота в @BotFather.</li>
          <li>Задайте домен приложения и включите Main Mini App для WebApp по адресу ниже.</li>
          <li>Используйте ссылки на услуги ниже: они откроют запись с выбранной услугой.</li>
        </ol>
        <p><strong>URL Mini App:</strong> <a :href="bot.mini_app_url">{{ bot.mini_app_url }}</a></p>
        <p>При запуске бота через ссылку сервиса клиент также сможет подключить уведомления.</p>
        <h3>Ссылки на услуги</h3>
        <ul v-if="links.length">
          <li v-for="link in links" :key="link.id">
            <span>{{ link.name }}</span>
            <a :href="link.url" target="_blank" rel="noreferrer">{{ link.url }}</a>
          </li>
        </ul>
        <p v-else class="hint">Добавьте активные услуги, чтобы получить ссылки.</p>
      </section>
    </template>
  </section>
</template>

<style scoped>
.telegram-manager {
  display: grid;
  gap: 1rem;
}

header h2 {
  color: #23392d;
  margin: 0;
}

header > p:last-child,
.hint,
.instructions p {
  color: #6f7d73;
  font-size: 0.88rem;
  line-height: 1.55;
}

.eyebrow {
  color: #678076;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.15em;
  margin: 0 0 0.45rem;
}

.panel {
  background: #fff;
  border: 1px solid #e6ece6;
  border-radius: 0.9rem;
  padding: 1.1rem;
}

.connect-form {
  display: grid;
  gap: 0.8rem;
}

.connection-status {
  color: #31574e;
}

.connection-warning {
  color: #986a23;
  display: block;
  font-size: 0.82rem;
  margin-top: 0.3rem;
}

.field {
  display: grid;
  gap: 0.4rem;
}

.field span {
  color: #354a40;
  font-size: 0.86rem;
  font-weight: 700;
}

.field input {
  border: 1px solid #d5dfd8;
  border-radius: 0.65rem;
  font: inherit;
  min-height: 2.8rem;
  padding: 0.6rem 0.75rem;
}

.hint {
  margin: 0;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
}

.instructions h3 {
  color: #31574e;
  font-size: 1rem;
  margin: 0.3rem 0 0.6rem;
}

.instructions ol {
  color: #58695e;
  display: grid;
  gap: 0.35rem;
  padding-left: 1.3rem;
}

.instructions ul {
  display: grid;
  gap: 0.65rem;
  list-style: none;
  margin: 0;
  padding: 0;
}

.instructions li {
  display: grid;
  gap: 0.2rem;
}

.instructions a {
  color: #285447;
  overflow-wrap: anywhere;
}

.message {
  border-radius: 0.65rem;
  margin: 0;
  padding: 0.75rem 0.9rem;
}

.message--error {
  background: #fff1ef;
  color: #8d322c;
}

.message--success {
  background: #eaf5e9;
  color: #315f3c;
}
</style>
