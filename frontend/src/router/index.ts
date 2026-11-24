import { createRouter, createWebHistory } from 'vue-router'
import BookingWizard from '@/features/booking/BookingWizard.vue'
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
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
  scrollBehavior: () => ({ top: 0 }),
})
