import { useQuery } from '@tanstack/vue-query'
import { computed, type Ref } from 'vue'
import { api } from '@/api/client'
import type { ApiResponse, Slot } from '@/api/types'

export function useSlots(
  serviceId: Ref<number | null>,
  staffId: Ref<number | null>,
  date: Ref<string>,
) {
  return useQuery({
    queryKey: computed(() => ['slots', serviceId.value, staffId.value, date.value]),
    queryFn: async (): Promise<Slot[]> => {
      const response = await api.get<ApiResponse<Slot[]>>('/slots', {
        params: {
          service_id: serviceId.value,
          staff_id: staffId.value,
          date: date.value,
        },
      })

      return response.data.data
    },
    enabled: computed(() => Boolean(serviceId.value && staffId.value && date.value)),
    staleTime: 15_000,
    retry: false,
  })
}
