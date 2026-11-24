import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import BookingWizard from '@/features/booking/BookingWizard.vue'
import BookingsCalendar from '@/features/bookings/BookingsCalendar.vue'
import BookingsList from '@/features/bookings/BookingsList.vue'
import DashboardView from '@/features/dashboard/DashboardView.vue'
import ScheduleEditor from '@/features/schedule/ScheduleEditor.vue'
import ManagementLayout from '@/layouts/ManagementLayout.vue'
import ServicesManager from '@/features/services/ServicesManager.vue'
import StaffManager from '@/features/staff/StaffManager.vue'
import LoginView from '@/views/LoginView.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'booking',
      component: BookingWizard,
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
    },
    {
      path: '/manage',
      component: ManagementLayout,
      meta: { requiresManager: true },
      children: [
        {
          path: '',
          name: 'manage-dashboard',
          component: DashboardView,
        },
        {
          path: 'calendar',
          name: 'manage-calendar',
          component: BookingsCalendar,
        },
        {
          path: 'bookings',
          name: 'manage-bookings',
          component: BookingsList,
        },
        {
          path: 'schedule',
          name: 'manage-schedule',
          component: ScheduleEditor,
        },
        {
          path: 'services',
          name: 'manage-services',
          component: ServicesManager,
        },
        {
          path: 'staff',
          name: 'manage-staff',
          component: StaffManager,
        },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  if (!to.matched.some((record) => record.meta.requiresManager)) return true

  const auth = useAuthStore()
  await auth.loadCurrentUser()

  if (!auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (!auth.canManageTenant) return { name: 'booking' }

  return true
})
