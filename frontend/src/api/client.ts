import axios from 'axios'

let activeTenantSlug = ''

export function setActiveTenantSlug(slug: string | null): void {
  activeTenantSlug = slug ?? ''
}

export const tenantConfigurationMessage = 'Сначала откройте страницу организации по её адресу.'

export const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.request.use((config) => {
  if (config.url === '/onboarding') {
    return config
  }

  if (!activeTenantSlug) {
    return Promise.reject(new Error(tenantConfigurationMessage))
  }

  config.headers['X-Tenant-Slug'] = activeTenantSlug
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
