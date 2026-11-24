import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, getApiProblem, setActiveTenantSlug } from '@/api/client'
import type { ApiResponse } from '@/api/types'

export interface Tenant {
  id: number
  name: string
  slug: string
  timezone: string
  currency: string
  demo_payments_enabled: boolean
}

export const useTenantStore = defineStore('tenant', () => {
  const tenant = ref<Tenant | null>(null)
  const loading = ref(true)
  const error = ref('')
  const slug = ref<string | null>(null)

  function setSlug(value: string | null): boolean {
    const nextSlug = value || null
    const changed = slug.value !== nextSlug

    if (changed) {
      tenant.value = null
      error.value = ''
      loading.value = Boolean(nextSlug)
    }

    slug.value = nextSlug
    setActiveTenantSlug(nextSlug)

    if (!nextSlug) loading.value = false

    return changed
  }

  async function load(): Promise<void> {
    if (!slug.value) {
      tenant.value = null
      error.value = ''
      loading.value = false
      return
    }

    loading.value = true
    error.value = ''

    try {
      const response = await api.get<ApiResponse<Tenant>>('/tenant')
      tenant.value = response.data.data
    } catch (requestError) {
      error.value = getApiProblem(requestError, 'Не удалось загрузить организацию.').message
    } finally {
      loading.value = false
    }
  }

  return { tenant, loading, error, slug, setSlug, load }
})
