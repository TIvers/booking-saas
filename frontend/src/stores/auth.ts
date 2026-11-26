import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ensureCsrfCookie, getApiProblem } from '@/api/client'
import { useTenantStore } from '@/stores/tenant'
import type { TenantRole } from '@/api/types'

export interface AuthUser {
  id: number
  account_type: 'customer' | 'organization'
  name: string
  email: string | null
  email_verified: boolean
  phone: string | null
  username: string | null
  has_password: boolean
  telegram_linked: boolean
  google_linked: boolean
}

interface AuthPayload {
  user: AuthUser
  role: TenantRole
}

interface ApiResponse<T> {
  data: T
}

interface AccountLinkResponse extends AuthPayload {
  linked: boolean
  merged?: boolean
  already_linked?: boolean
}

interface AccountLinkOutcome {
  merged: boolean
  alreadyLinked: boolean
}

export interface EmailVerificationChallenge {
  challenge_id: string
  email: string
  channel: 'code' | 'link'
  expires_at: string
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
      && user.value.email === null
      && !user.value.telegram_linked
      && !user.value.google_linked,
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
    verification_channel: 'code' | 'link'
  }): Promise<EmailVerificationChallenge | null> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<
      AuthPayload | {
        status: 'verification_required'
        verification: EmailVerificationChallenge
      }
    >>('/auth/login', credentials)
    if ('status' in response.data.data) {
      return response.data.data.verification
    }

    setAuth(response.data.data)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
    return null
  }

  async function loginOrganization(credentials: {
    login: string
    password: string
    verification_channel: 'code' | 'link'
  }): Promise<EmailVerificationChallenge | null> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<
      AuthPayload | {
        status: 'verification_required'
        verification: EmailVerificationChallenge
      }
    >>('/auth/organization/login', credentials)
    if ('status' in response.data.data) {
      return response.data.data.verification
    }

    setAuth(response.data.data)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
    return null
  }

  async function register(credentials: {
    name: string
    username: string
    email: string
    password: string
    password_confirmation: string
    verification_channel: 'code' | 'link'
  }): Promise<EmailVerificationChallenge> {
    await ensureCsrfCookie()
    const response = await api.post<
      ApiResponse<{ status: 'verification_required'; verification: EmailVerificationChallenge }>
    >('/auth/register', credentials)
    return response.data.data.verification
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

  async function loginWithTelegramOidc(idToken: string): Promise<boolean> {
    await ensureCsrfCookie()
    const response = await api.post<
      ApiResponse<AuthPayload | { status: 'unlinked'; name: string }>
    >('/auth/telegram/oidc', { id_token: idToken })
    const payload = response.data.data

    if (!('user' in payload)) return false

    setAuth(payload)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
    return true
  }

  async function loginWithGoogle(credential: string): Promise<void> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<AuthPayload>>('/auth/google', { credential })
    setAuth(response.data.data)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
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

  async function registerWithTelegramOidc(): Promise<void> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<AuthPayload>>('/auth/telegram/oidc/register')
    setAuth(response.data.data)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
  }

  async function linkTelegram(
    initData: string,
    bot: 'main' | 'tenant',
  ): Promise<AccountLinkOutcome> {
    const response = await api.post<ApiResponse<AccountLinkResponse>>('/auth/telegram/link', {
      init_data: initData,
      bot,
    })
    setAuth(response.data.data)
    return {
      merged: response.data.data.merged === true,
      alreadyLinked: response.data.data.already_linked === true,
    }
  }

  async function linkTelegramOidc(): Promise<AccountLinkOutcome> {
    const response = await api.post<ApiResponse<AccountLinkResponse>>('/auth/telegram/oidc/link')
    setAuth(response.data.data)
    return {
      merged: response.data.data.merged === true,
      alreadyLinked: response.data.data.already_linked === true,
    }
  }

  async function linkTelegramOidcWithToken(idToken: string): Promise<AccountLinkOutcome> {
    const response = await api.post<ApiResponse<AccountLinkResponse>>(
      '/auth/telegram/oidc/link',
      { id_token: idToken },
    )
    setAuth(response.data.data)
    return {
      merged: response.data.data.merged === true,
      alreadyLinked: response.data.data.already_linked === true,
    }
  }

  async function linkGoogle(credential: string): Promise<AccountLinkOutcome> {
    const response = await api.post<ApiResponse<AccountLinkResponse>>('/auth/google/link', {
      credential,
    })
    setAuth(response.data.data)
    return {
      merged: response.data.data.merged === true,
      alreadyLinked: response.data.data.already_linked === true,
    }
  }

  async function updateEmail(
    email: string,
    verificationChannel: 'code' | 'link',
  ): Promise<EmailVerificationChallenge | null> {
    const response = await api.put<ApiResponse<{
      email: string
      email_verified: boolean
      verification_required: boolean
      verification?: EmailVerificationChallenge
    }>>('/auth/email', {
      email,
      verification_channel: verificationChannel,
    })
    if (user.value && !response.data.data.verification_required) {
      user.value.email = response.data.data.email
      user.value.email_verified = response.data.data.email_verified
    }

    return response.data.data.verification ?? null
  }

  async function setPassword(password: string, passwordConfirmation: string): Promise<void> {
    await ensureCsrfCookie()
    await api.put('/auth/password', {
      password,
      password_confirmation: passwordConfirmation,
    })
    if (user.value) user.value.has_password = true
  }

  async function verifyEmailCode(challengeId: string, code: string): Promise<void> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<AuthPayload>>('/auth/email/verification/code', {
      challenge_id: challengeId,
      code,
    })
    setAuth(response.data.data)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
  }

  async function verifyEmailLink(challengeId: string, token: string): Promise<void> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<AuthPayload>>('/auth/email/verification/link', {
      challenge_id: challengeId,
      token,
    })
    setAuth(response.data.data)
    initializedTenantSlug = useTenantStore().slug
    initialized.value = true
  }

  async function resendEmailVerification(
    challengeId: string,
    verificationChannel: 'code' | 'link',
  ): Promise<EmailVerificationChallenge> {
    await ensureCsrfCookie()
    const response = await api.post<ApiResponse<EmailVerificationChallenge>>(
      '/auth/email/verification/resend',
      {
        challenge_id: challengeId,
        verification_channel: verificationChannel,
      },
    )
    return response.data.data
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
    loginOrganization,
    register,
    loginWithTelegram,
    loginWithTelegramOidc,
    loginWithGoogle,
    registerWithTelegram,
    registerWithTelegramOidc,
    linkTelegram,
    linkTelegramOidc,
    linkTelegramOidcWithToken,
    linkGoogle,
    updateEmail,
    setPassword,
    verifyEmailCode,
    verifyEmailLink,
    resendEmailVerification,
    updateProfile,
    updateUsername,
    logout,
  }
})
