# EA Workshop — Android app (staff)

The staff Android app is the same ERP in a native shell (Capacitor 6, folder `mobile/`). It opens the ERP at the
address saved on the phone, so every web update reaches phones without a new APK. What the app adds over the browser:

- **Location keeps going with the screen locked** while the employee is punched in (a foreground service with
  the notification "Sharing your location until you punch out"). In the browser, location is sent only while
  the ERP is open on screen.
- An icon on the home screen and full-screen use.

iPhones keep using the web app (installed from Safari). Their location is shared only while the app is open.

## The server address is entered on the phone

The first time the app opens it asks for the ERP's address. The owner sees it, with a QR code, under
**Settings › App Address & Customer App**. One APK therefore works while testing on the workshop Wi-Fi
(`http://<PC's Wi-Fi IP>:8000`) and later on the live site. To change it: **Settings › Change server address**
inside the app, or "Change server address" on the "Can't reach the workshop system" screen.

Testing against this PC: run `php artisan serve --host 0.0.0.0 --port 8000`, find the PC's address with
`ipconfig` (IPv4, e.g. 192.168.1.20), and enter `http://192.168.1.20:8000` on the phone (same Wi-Fi).

## Build

Needs: Node 18+, JDK 17 (this PC: `C:\Users\JOEL\.jdks\ms-17.0.16`), Android SDK (`%LOCALAPPDATA%\Android\Sdk`).

```bash
cd mobile
npm install
set JAVA_HOME=C:\Users\JOEL\.jdks\ms-17.0.16
npm run build:debug
# -> mobile/android/app/build/outputs/apk/debug/app-debug.apk
```

Optional: pre-fill the setup screen with `set EAERP_APP_URL=https://erp.expressautocare.in` before building.
`mobile/android/local.properties` holds the SDK path (`sdk.dir=C:/Users/JOEL/AppData/Local/Android/Sdk`, forward
slashes); Android Studio writes it by itself if you open `mobile/android` there instead.

### Release (signed) APK

1. Make a signing key once and keep it safe (losing it means phones can't update the app):
   `keytool -genkey -v -keystore ea-workshop.jks -keyalg RSA -keysize 2048 -validity 10000 -alias eaworkshop`
2. In Android Studio: *Build › Generate Signed App Bundle / APK* with that key, or add a `signingConfigs`
   block to `mobile/android/app/build.gradle`.
3. Share the APK by link / WhatsApp. Staff allow "Install unknown apps" once. A Play Store listing is optional
   (Google asks for a short "why location" declaration; the app uses a foreground service, not background-location
   permission, which keeps that simple).

## First run on a phone

1. Open the app, enter the server address, sign in, punch in, accept the location notice.
2. Allow **Location → While using the app** (precise) and **Notifications**.
3. Settings › Apps › EA Workshop › Battery → **Unrestricted** (otherwise some phones — Xiaomi, Oppo, Vivo,
   Realme, Samsung — stop the location service after a while).

## How it works

- `MainActivity.java` reads the saved address and starts the app on it (Capacitor's `server.url`); with none saved
  it shows `www/index.html`, the setup screen, which checks `GET /api/v1/app/ping` and saves the address through the
  small native plugin `ServerConfigPlugin.java` (get / set / reset, then the app restarts).
- `resources/js/composables/useLocationTracker.ts` picks the native `BackgroundGeolocation` plugin when it runs
  inside the app, else the browser's GPS. Positions are queued on the phone and sent in batches to
  `POST /api/v1/hr/me/locations`; the server keeps them only while the employee is punched in and says when to stop.
- The owner / managers see the result on *People › Live Staff Map*.

The customer app (Play Store) is a separate project: see `docs/CUSTOMER_APP.md`.
