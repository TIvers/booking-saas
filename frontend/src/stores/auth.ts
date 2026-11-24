import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ensureCsrfCookie, getApiProblem, tenantIsConfigured } from '@/api/client'

export interface AuthUser {
  id: number
  name: string
  email: string
}

interface AuthPayload {
  user: AuthUser
  role: string
}

interface ApiResponse<T> {
  data: T
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const role = ref<string | null>(null)
  const initialized = ref(false)
  const isAuthenticated = computed(() => user.value !== null)

  function setAuth(payload: AuthPayload): void {
    user.value = payload.user
    role.value = payload.role
  }

  function clear(): void {
    user.value = null
    role.value = null
  }

  async function loadCurrentUser(): Promise<void> {
    if (!tenantIsConfigured) {
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
    clear,
    loadCurrentUser,
    login,
    register,
    logout,
  }
})
