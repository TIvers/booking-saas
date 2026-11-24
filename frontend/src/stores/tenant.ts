import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, getApiProblem, tenantConfigurationMessage, tenantIsConfigured } from '@/api/client'
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

  async function load(): Promise<void> {
    loading.value = true
    error.value = ''

    if (!tenantIsConfigured) {
      error.value = tenantConfigurationMessage
      loading.value = false
      return
    }

    try {
      const response = await api.get<ApiResponse<Tenant>>('/tenant')
      tenant.value = response.data.data
    } catch (requestError) {
      error.value = getApiProblem(requestError, 'Не удалось загрузить организацию.').message
    } finally {
      loading.value = false
    }
  }

  return { tenant, loading, error, load }
})
