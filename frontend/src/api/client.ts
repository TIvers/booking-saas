import axios from 'axios'

const tenantId = String(import.meta.env.VITE_TENANT_ID ?? '').trim()
export const tenantIsConfigured = /^[1-9]\d*$/.test(tenantId)
export const tenantConfigurationMessage =
  'Сайт ещё не настроен: укажите числовой VITE_TENANT_ID для организации.'

export const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.request.use((config) => {
  if (!tenantIsConfigured) {
    return Promise.reject(new Error(tenantConfigurationMessage))
  }

  config.headers['X-Tenant'] = tenantId
  return config
})

const csrfClient = axios.create({
  withCredentials: true,
  withXSRFToken: true,
})

export async function ensureCsrfCookie(): Promise<void> {
  await csrfClient.get('/sanctum/csrf-cookie')
}

export interface ApiProblem {
  message: string
  status: number | null
  code: string | null
  errors: Record<string, string[]>
}

export function getApiProblem(error: unknown, fallback = 'Не удалось выполнить запрос.'): ApiProblem {
  if (!axios.isAxiosError(error)) {
    return {
      message: error instanceof Error ? error.message : fallback,
      status: null,
      code: null,
      errors: {},
    }
  }

  const response = error.response
  const payload = response?.data as
    | { message?: unknown; error?: unknown; errors?: unknown }
    | undefined
  const errors =
    payload?.errors && typeof payload.errors === 'object'
      ? (payload.errors as Record<string, string[]>)
      : {}

  return {
    message: typeof payload?.message === 'string' ? payload.message : fallback,
    status: response?.status ?? null,
    code: typeof payload?.error === 'string' ? payload.error : null,
    errors,
  }
}
