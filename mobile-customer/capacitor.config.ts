import type { CapacitorConfig } from '@capacitor/cli'

/**
 * The customer app opens the live site's /app, so app updates need no new release. Customers
 * can't type a server address, so it is set when building (the public HTTPS address):
 *   EAERP_APP_URL=https://erp.expressautocare.in npm run build:release
 */
const site = (process.env.EAERP_APP_URL || 'https://erp.expressautocare.in').replace(/\/+$/, '')

const config: CapacitorConfig = {
  appId: 'in.expressautocare.app',
  appName: 'Express Autocare',
  webDir: 'www',
  server: {
    url: `${site}/app`,
    // Only for testing against a computer on the same Wi-Fi (http://192.168.x.x:8000)
    cleartext: site.startsWith('http://'),
    androidScheme: 'https',
    errorPath: 'error.html',
  },
  android: {
    allowMixedContent: false,
    backgroundColor: '#ffffff',
  },
}

export default config
