import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ensureCsrfCookie, getApiProblem } from '@/api/client'
import { useTenantStore } from '@/stores/tenant'
import type { TenantRole } from '@/api/types'

export interface AuthUser {
  id: number
  name: string
  email: string | null
  phone: string | null
  username: string | null
  telegram_linked: boolean
}

interface AuthPayload {
  user: AuthUser
  role: TenantRole
}

interface ApiResponse<T> {
  data: T
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const role = ref<TenantRole | null>(null)
  const initialized = ref(false)
  let initializedTenantSlug: string | null = null
  const isAuthenticated = computed(() => user.value !== null)
  const canManageTenant = computed(() => role.value === 'owner' || role.value === 'manager')
  const requiresUsername = computed(
    () => (role.value === 'customer' || role.value === 'staff')
      && user.value !== null
      && user.value.username === null
      && !user.value.telegram_linked,
  )

  function setAuth(payload: AuthPayload): void {
    user.value = payload.user
    role.value = payload.role
  }

  function clear(): void {
    user.value = null
    role.value = null
  }

  function reset(): void {
    clear()
    initialized.value = false
    initializedTenantSlug = null
  }

  async function loadCurrentUser(): Promise<void> {
    const slug = useTenantStore().slug

    if (initialized.value && initializedTenantSlug === slug) return

    initialized.value = false
    initializedTenantSlug = slug
    clear()

    if (!slug) {
      initialized.value = true
      return
    }

    try {
      const response = await api.get<ApiResponse<AuthPayload>>('/auth/me')
      setAuth(response.data.data)
    } catch (error) {
      const problem = getApiProblem(error)
      clear()

      if (problem.status !== 401) {
        console.error('Unable to restore the current session:', problem.message)
      }
    } finally {
      initialized.value = true
    }
  }

  async function login(credentials: {
    login: string
    password: string
    remember: boolean
  }): Promise<void> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<AuthPayload>>('/auth/login', credentials)
    setAuth(response.data.data)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
  }

  async function register(credentials: {
    name: string
    username: string
    email?: string
    password: string
    password_confirmation: string
  }): Promise<void> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<AuthPayload>>('/auth/register', credentials)
    setAuth(response.data.data)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
  }

  async function loginWithTelegram(initData: string, bot: 'main' | 'tenant'): Promise<boolean> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<AuthPayload | { status: 'unlinked'; name: string }>>(
      '/auth/telegram',
      { init_data: initData, bot },
    )
    const payload = response.data.data

    if (!('user' in payload)) return false

    setAuth(payload)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
    return true
  }

  async function registerWithTelegram(initData: string, bot: 'main' | 'tenant'): Promise<void> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<AuthPayload>>('/auth/telegram/register', {
      init_data: initData,
      bot,
    })
    setAuth(response.data.data)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
  }

  async function linkTelegram(initData: string, bot: 'main' | 'tenant'): Promise<void> {
    await api.post('/auth/telegram/link', { init_data: initData, bot })
    if (user.value) user.value.telegram_linked = true
  }

  async function updateEmail(email: string): Promise<void> {
    const response = await api.put<ApiResponse<{ email: string }>>('/auth/email', { email })
    if (user.value) user.value.email = response.data.data.email
  }

  async function updateProfile(name: string, phone: string | null): Promise<void> {
    const response = await api.put<ApiResponse<{ name: string; phone: string | null }>>(
      '/auth/profile',
      { name, phone },
    )
    if (user.value) {
      user.value.name = response.data.data.name
      user.value.phone = response.data.data.phone
    }
  }

  async function updateUsername(username: string): Promise<void> {
    const response = await api.put<ApiResponse<{ username: string }>>('/auth/username', {
      username,
    })
    if (user.value) user.value.username = response.data.data.username
  }

  async function logout(): Promise<void> {
    await api.post('/auth/logout')
    clear()
  }

  return {
    user,
    role,
    initialized,
    isAuthenticated,
    canManageTenant,
    requiresUsername,
    clear,
    reset,
    loadCurrentUser,
    login,
    register,
    loginWithTelegram,
    registerWithTelegram,
    linkTelegram,
    updateEmail,
    updateProfile,
    updateUsername,
    logout,
  }
})
