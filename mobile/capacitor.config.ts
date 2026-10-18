import type { CapacitorConfig } from '@capacitor/cli'

/**
 * The ERP address is entered on the phone the first time the app opens (and can be changed
 * from Settings), so one APK works for local testing and the live site. An address given at
 * build time is only offered on that screen:  EAERP_APP_URL=https://erp.example.in npm run build:debug
 */
const suggested = process.env.EAERP_APP_URL || ''

const config: CapacitorConfig = {
  appId: 'in.expressautocare.erp',
  appName: 'EA Workshop',
  webDir: 'www',
  server: {
    ...(suggested ? { url: suggested } : {}),
    androidScheme: 'https',
  },
  android: {
    allowMixedContent: false,
    backgroundColor: '#ffffff',
  },
}

export default config
