import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import BookingWizard from '@/features/booking/BookingWizard.vue'
import BookingSettingsView from '@/features/settings/BookingSettingsView.vue'
import BookingsCalendar from '@/features/bookings/BookingsCalendar.vue'
import BookingsList from '@/features/bookings/BookingsList.vue'
import MyBookingsView from '@/features/bookings/MyBookingsView.vue'
import DashboardView from '@/features/dashboard/DashboardView.vue'
import ScheduleEditor from '@/features/schedule/ScheduleEditor.vue'
import MyScheduleView from '@/features/schedule/MyScheduleView.vue'
import ManagementLayout from '@/layouts/ManagementLayout.vue'
import SpecialistLayout from '@/layouts/SpecialistLayout.vue'
import ServicesManager from '@/features/services/ServicesManager.vue'
import StaffManager from '@/features/staff/StaffManager.vue'
import TelegramManager from '@/features/telegram/TelegramManager.vue'
import LoginView from '@/views/LoginView.vue'
import UsernameSetupView from '@/views/UsernameSetupView.vue'
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
      path: '/t/:tenantSlug/account/username',
      name: 'account-username',
      component: UsernameSetupView,
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
        {
          path: 'booking-settings',
          name: 'manage-booking-settings',
          component: BookingSettingsView,
          meta: { requiresOwner: true },
        },
        {
          path: 'telegram',
          name: 'manage-telegram',
          component: TelegramManager,
          meta: { requiresOwner: true },
        },
      ],
    },
    {
      path: '/t/:tenantSlug/specialist',
      component: SpecialistLayout,
      meta: { requiresSpecialist: true },
      children: [
        {
          path: '',
          redirect: { name: 'specialist-calendar' },
        },
        {
          path: 'calendar',
          name: 'specialist-calendar',
          component: BookingsCalendar,
        },
        {
          path: 'bookings',
          name: 'specialist-bookings',
          component: BookingsList,
        },
        {
          path: 'schedule',
          name: 'specialist-schedule',
          component: MyScheduleView,
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
  const requiresOwner = to.matched.some((record) => record.meta.requiresOwner)
  const requiresSpecialist = to.matched.some((record) => record.meta.requiresSpecialist)
  const requiresCustomer = to.matched.some((record) => record.meta.requiresCustomer)
  const isUsernameSetup = to.name === 'account-username'
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
  if (
    !auth.isAuthenticated
    && (requiresManager || requiresSpecialist || requiresCustomer || isUsernameSetup)
  ) {
    return {
      name: 'login',
      params: { tenantSlug },
      query: { redirect: to.fullPath },
    }
  }

  if (auth.requiresUsername && !isUsernameSetup) {
    return {
      name: 'account-username',
      params: { tenantSlug },
      query: { redirect: to.fullPath },
    }
  }

  if (isUsernameSetup && !auth.requiresUsername) {
    return auth.role === 'staff'
      ? { name: 'specialist-calendar', params: { tenantSlug } }
      : { name: 'booking', params: { tenantSlug } }
  }

  if (!requiresManager && !requiresSpecialist && !requiresCustomer) return true
  if (requiresManager && !auth.canManageTenant) {
    return auth.role === 'staff'
      ? { name: 'specialist-calendar', params: { tenantSlug } }
      : { name: 'booking', params: { tenantSlug } }
  }
  if (requiresOwner && auth.role !== 'owner') {
    return { name: 'manage-dashboard', params: { tenantSlug } }
  }
  if (requiresSpecialist && auth.role !== 'staff') {
    return auth.canManageTenant
      ? { name: 'manage-dashboard', params: { tenantSlug } }
      : { name: 'booking', params: { tenantSlug } }
  }
  if (requiresCustomer && auth.role !== 'customer') {
    if (auth.role === 'staff') {
      return { name: 'specialist-calendar', params: { tenantSlug } }
    }

    return auth.canManageTenant
      ? { name: 'manage-dashboard', params: { tenantSlug } }
      : { name: 'booking', params: { tenantSlug } }
  }

  return true
})
