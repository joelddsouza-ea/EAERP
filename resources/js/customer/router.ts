import { createRouter, createWebHistory } from 'vue-router'
import { session } from './session'

const router = createRouter({
  history: createWebHistory('/app/'),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    { path: '/login', name: 'login', component: () => import('./views/Login.vue'), meta: { public: true } },
    // Opened from a WhatsApp link: /app/link/job/<token> or /app/link/crm/<token>
    { path: '/link/:kind(job|crm)/:token', name: 'link', component: () => import('./views/LinkSignIn.vue'), meta: { public: true } },
    { path: '/', name: 'home', component: () => import('./views/Home.vue') },
    { path: '/jobs', name: 'jobs', component: () => import('./views/Jobs.vue') },
    { path: '/jobs/:id(\\d+)', name: 'job', component: () => import('./views/Job.vue') },
    { path: '/cars/new', name: 'add-car', component: () => import('./views/AddCar.vue') },
    { path: '/cars/:id(\\d+)', name: 'car', component: () => import('./views/ServiceBook.vue') },
    { path: '/book', name: 'book', component: () => import('./views/Book.vue') },
    { path: '/bookings', name: 'bookings', component: () => import('./views/Bookings.vue') },
    { path: '/inbox', name: 'inbox', component: () => import('./views/Inbox.vue') },
    { path: '/wallet', name: 'wallet', component: () => import('./views/Wallet.vue') },
    { path: '/documents', name: 'documents', component: () => import('./views/Documents.vue') },
    { path: '/assistant', name: 'assistant', component: () => import('./views/Assistant.vue') },
    { path: '/more', name: 'more', component: () => import('./views/More.vue') },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach((to) => {
  if (!to.meta.public && !session.token) return { name: 'login', query: to.fullPath !== '/' ? { next: to.fullPath } : {} }
  if (to.name === 'login' && session.token) return { name: 'home' }
})

export default router
