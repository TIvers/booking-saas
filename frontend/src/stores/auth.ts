import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ensureCsrfCookie, getApiProblem } from '@/api/client'
import { useTenantStore } from '@/stores/tenant'
import type { TenantRole } from '@/api/types'

export interface AuthUser {
  id: number
  name: string
  email: string
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
    email: string
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
    email: string
    password: string
    password_confirmation: string
  }): Promise<void> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<AuthPayload>>('/auth/register', credentials)
    setAuth(response.data.data)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
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
    clear,
    reset,
    loadCurrentUser,
    login,
    register,
    logout,
  }
})
