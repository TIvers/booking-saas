import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import BookingWizard from '@/features/booking/BookingWizard.vue'
import BookingsCalendar from '@/features/bookings/BookingsCalendar.vue'
import BookingsList from '@/features/bookings/BookingsList.vue'
import MyBookingsView from '@/features/bookings/MyBookingsView.vue'
import DashboardView from '@/features/dashboard/DashboardView.vue'
import ScheduleEditor from '@/features/schedule/ScheduleEditor.vue'
import ManagementLayout from '@/layouts/ManagementLayout.vue'
import ServicesManager from '@/features/services/ServicesManager.vue'
import StaffManager from '@/features/staff/StaffManager.vue'
import LoginView from '@/views/LoginView.vue'
import TenantLandingView from '@/views/TenantLandingView.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'home',
      component: TenantLandingView,
    },
    {
      path: '/t/:tenantSlug',
      name: 'booking',
      component: BookingWizard,
    },
    {
      path: '/t/:tenantSlug/login',
      name: 'login',
      component: LoginView,
    },
    {
      path: '/t/:tenantSlug/my-bookings',
      name: 'my-bookings',
      component: MyBookingsView,
      meta: { requiresCustomer: true },
    },
    {
      path: '/t/:tenantSlug/manage',
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
  const requiresManager = to.matched.some((record) => record.meta.requiresManager)
  const requiresCustomer = to.matched.some((record) => record.meta.requiresCustomer)
  const tenantSlug = typeof to.params.tenantSlug === 'string' ? to.params.tenantSlug : null
  const tenant = useTenantStore()
  const auth = useAuthStore()
  const tenantChanged = tenant.setSlug(tenantSlug)

  if (tenantSlug && (tenantChanged || !tenant.tenant)) {
    await tenant.load()
  }

  if (!tenantSlug) {
    await auth.loadCurrentUser()
    return true
  }

  if (!tenant.tenant) {
    auth.reset()
    return true
  }

  await auth.loadCurrentUser()
  if (!requiresManager && !requiresCustomer) return true
  if (!auth.isAuthenticated) {
    return {
      name: 'login',
      params: { tenantSlug },
      query: { redirect: to.fullPath },
    }
  }

  if (requiresManager && !auth.canManageTenant) {
    return { name: 'booking', params: { tenantSlug } }
  }
  if (requiresCustomer && auth.role !== 'customer') {
    return auth.canManageTenant
      ? { name: 'manage-dashboard', params: { tenantSlug } }
      : { name: 'booking', params: { tenantSlug } }
  }

  return true
})
