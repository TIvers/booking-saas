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
  company_description: string | null
  contact_url: string | null
  marketing_subscribers: number
}

const bot = ref<BotInfo | null>(null)
const services = ref<Service[]>([])
const token = ref('')
const companyDescription = ref('')
const contactUrl = ref('')
const broadcastMessage = ref('')
const loading = ref(true)
const saving = ref(false)
const savingProfile = ref(false)
const sendingBroadcast = ref(false)
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
    companyDescription.value = bot.value.company_description ?? ''
    contactUrl.value = bot.value.contact_url ?? ''
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
    notice.value = 'Бот подключён. Меню, команды и обработчик сообщений настроены автоматически.'
    await load()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось подключить Telegram-бота.').message
  } finally {
    saving.value = false
  }
}

async function saveProfile(): Promise<void> {
  savingProfile.value = true
  error.value = ''
  notice.value = ''

  try {
    await api.put('/manage/telegram-bot/profile', {
      company_description: companyDescription.value.trim() || null,
      contact_url: contactUrl.value.trim() || null,
    })
    notice.value = 'Информация о компании обновлена в боте.'
    await load()
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось сохранить информацию о компании.').message
  } finally {
    savingProfile.value = false
  }
}

async function sendBroadcast(): Promise<void> {
  sendingBroadcast.value = true
  error.value = ''
  notice.value = ''

  try {
    const response = await api.post<ApiResponse<{ queued: number }>>(
      '/manage/telegram-broadcasts',
      { message: broadcastMessage.value.trim() },
    )
    notice.value = `В очередь поставлено сообщений: ${response.data.data.queued}.`
    broadcastMessage.value = ''
  } catch (requestError) {
    error.value = getApiProblem(requestError, 'Не удалось поставить рассылку в очередь.').message
  } finally {
    sendingBroadcast.value = false
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
          webhook, меню и команды настраиваются автоматически. Чтобы заменить бота, сначала отключите текущего.
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

      <form v-if="bot?.connected" class="panel profile-form" @submit.prevent="saveProfile">
        <h3>Информация о компании</h3>
        <label class="field">
          <span>Описание для команды /about и профиля бота</span>
          <textarea
            v-model="companyDescription"
            maxlength="512"
            rows="4"
            placeholder="Коротко расскажите о компании и услугах"
          />
        </label>
        <label class="field">
          <span>Сайт или ссылка для связи</span>
          <input
            v-model="contactUrl"
            type="url"
            maxlength="2048"
            placeholder="https://example.com"
          />
        </label>
        <p class="hint">
          Кнопка меню Mini App, описание бота и команды устанавливаются автоматически.
          Для прямых ссылок startapp настройте Main Mini App в @BotFather.
        </p>
        <BaseButton type="submit" :loading="savingProfile">Сохранить информацию</BaseButton>
      </form>

      <section v-if="bot?.connected" class="panel instructions">
        <h3>Mini App и услуги</h3>
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

      <form v-if="bot?.connected" class="panel broadcast-form" @submit.prevent="sendBroadcast">
        <h3>Рассылка новостей</h3>
        <p class="hint">
          Подписчиков: {{ bot.marketing_subscribers }}. Сообщение получат только клиенты,
          которые отдельно нажали «Подписаться на новости» в боте. Подписка на напоминания
          о записях для рассылки не используется.
        </p>
        <label class="field">
          <span>Текст сообщения</span>
          <textarea
            v-model="broadcastMessage"
            maxlength="4096"
            rows="5"
            required
            placeholder="Текст новости для подписчиков"
          />
        </label>
        <BaseButton
          type="submit"
          :loading="sendingBroadcast"
          :disabled="bot.marketing_subscribers === 0"
        >
          Поставить рассылку в очередь
        </BaseButton>
      </form>
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

.profile-form,
.broadcast-form {
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

.field textarea {
  border: 1px solid #d5dfd8;
  border-radius: 0.65rem;
  font: inherit;
  padding: 0.65rem 0.75rem;
  resize: vertical;
}

.hint {
  margin: 0;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
}

.instructions h3,
.profile-form h3,
.broadcast-form h3 {
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
