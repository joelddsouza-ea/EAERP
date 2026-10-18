/**
 * Express Autocare customer app (served at /app): sign in with a WhatsApp link or mobile + code,
 * then track jobs, approve work, pay by UPI, book, keep documents and ask the assistant.
 */
import { createApp } from 'vue'
import router from './router'
import App from './App.vue'
import '../../css/app.css'

createApp(App).use(router).mount('#customer-app')

// Installable app + phone notifications (service worker scoped to /app/)
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/customer-sw.js', { scope: '/app/' }).catch(() => {})
  })
}
