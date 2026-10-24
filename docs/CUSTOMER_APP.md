# Express Autocare — customer app

Customers use one app, at **`<public address>/app`**: installable from the browser on any phone (Android and
iPhone: *Add to Home Screen*), and as an Android app for the Play Store (`mobile-customer/`). Every job / booking
link sent on WhatsApp has an **Open in the Express Autocare app** button.

Switch it off or on in **Settings › Modules › Customer app** (job and CRM links keep working either way).

## What customers get

| Screen | What it does |
|---|---|
| **Sign in** | Open any link we sent on WhatsApp (signed in at once), or mobile number + 6-digit code. While Interakt is off, the code shows on the customer's page in the ERP (*Customer app* card) for the advisor to read out; the advisor of their latest job also gets a bell. With Interakt on and a `customer_otp` template mapped, the code goes by WhatsApp. |
| **Home** | Jobs in progress with what needs them (approve, pay, driver on the way), their cars with service / insurance / PUC due dates, quick actions. |
| **Job** | Live driver / technician on a map with ETA, **Pay by UPI** (opens their UPI app with the amount filled in, or a QR), then they send the UPI reference. Below it, the same job page as the WhatsApp link: stages, approve / decline each item with photos, photos and videos, report and bill. With the assistant on, each estimate item has **Why is this needed?** |
| **Service book** | Every visit of a car (open it for report, bill, photos), work recommended earlier, due dates, **History PDF** for resale. |
| **Book** | Car → plan at *that car's* price (or a repair type) → drive-in / pickup & drop / home + free slot. Goes to the CRM bookings with a desk alert, like web bookings. Cancel upcoming ones. |
| **Inbox** | Job updates, payment confirmed / not found, reminders: service due (21 / 7 / 0 days), insurance and PUC (30 / 7 days), documents, work recommended 30 / 90 days ago. Phone notifications for the installed web app (Web Push). |
| **Wallet** | Credit for the next visit, referral code and share link (reward from the CRM settings), current offer. |
| **Documents** | Insurance, PUC, RC, licence as photo / PDF with expiry. The car's insurance / PUC dates follow the latest one; reminders before expiry. Staff see them on the customer's page. |
| **Ask** (AI) | *What's wrong with my car?* (text + up to 3 photos → urgency, likely causes, which of our services fix it with price ranges from our lists, things to check, "Book a visit") and *Accident help* (damage photos → damage list, rough range from the body-shop guide, safe to drive?, claim advice and steps, "Get it inspected" creates a body-shop lead). |

## Staff side

- **Customer page › Customer app**: the sign-in code to read out (refreshes itself), how many phones are signed in, their documents.
- **Billing › UPI Payments to Confirm**: check the UTR in the bank, then *Confirm & record* (records a UPI payment on the job; the customer is told) or *Reject* with a reason the customer sees. Back office gets a bell for each one.
- **Settings › App Address & Customer App** (owner): the public address (used in every link sent out), UPI ID and payee name, helpline number, code validity, phone notifications, QR codes for staff phones and for the counter.
- **Settings › AI Assistant** (owner): off until an Anthropic API key is entered. Model (Opus 5.5 best / Sonnet 5.5 half the cost / Haiku 5.5 cheapest), questions per customer per day (estimate explanations don't count and are written once per item), monthly budget in rupees (the assistant pauses when reached), the body-shop price guide it may quote, this month's cost, bookings and leads it led to, recent questions.

## Fleet cabs and drivers

A cab owner is a customer marked **Cab fleet** (customer page › *Fleet* card). Switch the whole feature in
*Settings › Modules › Fleet cabs & drivers*. Fleets pay per job like any customer (the gate-pass rules don't change).

| Who | What they get in the app |
|---|---|
| **Fleet owner** (the customer's own number / links) | Everything a customer gets, plus **Fleet dashboard** (each cab at the workshop with its stage, approvals waiting and money due, or on the road with km, next service and open problems; spend this month / year per cab), **Drivers** (add with mobile number and cabs, switch off, sign out of all phones) and **Problem reports**. Approves any amount. |
| **Driver** (own mobile number, or the job link sent to them) | Only the cabs linked to them: jobs, approve / decline extra work, pay by UPI, book, cab documents, **Report a problem** (kind, details, photos / videos, km, location for a breakdown), **Log km**. No wallet, no adding cars, no drivers. |

- **Approval limit**: a driver approves extra work up to a total per job (GST incl.) — the default in *Masters ›
  Fleet Cabs*, or the fleet's own on its *Fleet* card. Lines above it stay pending:
  the owner gets an app note plus a WhatsApp message (template `fleet_owner_approval`, in *Messages to send*) and
  the advisor a bell. Declining frees that line's share of the limit. Lines the owner (or the advisor for them)
  decided are locked for drivers. The limit is checked on the server for the driver's own link (`customer_links.fleet_driver_id`),
  so it holds in the app and from WhatsApp alike.
- One number can be a customer and a driver (or drive for two fleets): after the code the app asks which.
- Staff: the job card's **Driver who brought the cab** (that driver gets stage notes and a "cab ready" WhatsApp
  message, `fleet_driver_ready`, with their own link); **Customers › Fleet Reports** (book in / pickup, open a job
  card, or close with a note; a new job card for the cab takes over its open reports).
- Km readings (`vehicle_odometer_readings`) come from drivers, the owner and job cards; the service-due date uses
  the latest one. `fleet:daily` (09:40) warns the owner and the cab's drivers once per due km when a cab is within
  *Warn when a cab is this close* km.

## How the AI stays safe and cheap

- It answers only from your service plans and prices for the car's type, the labour-charge master and the body-shop guide, plus the car's details and last visit's open observations. Likely causes, never a diagnosis; ranges, never a quote; brakes / steering / overheating / smoke / fuel smell / flashing engine light → *stop driving, call us*.
- Price lists sit in a cached system prompt (repeat questions cost about a tenth for that part); photos are shrunk to 1568 px; every call is logged with tokens and cost (`ai_requests`). Refusals fall back automatically (server-side fallback).
- Rough cost per question with one photo (including the model's thinking): about ₹4–8 on Opus 5.5, ₹2–4 on
  Sonnet 5.5, under ₹0.50 on Haiku 5.5; an estimate explanation about ₹1 on Opus 5.5. The real figures show in
  Settings › AI Assistant; the monthly budget caps the total.

## Android app for the Play Store (`mobile-customer/`)

The address is built in (customers never type it), so it is made once the site is live on HTTPS:

```bash
cd mobile-customer
npm install
set EAERP_APP_URL=https://erp.expressautocare.in
set JAVA_HOME=C:\Users\JOEL\.jdks\ms-17.0.16
npm run build:release      # -> android/app/build/outputs/bundle/release/app-release.aab (sign it)
```

Play Store: Google Play developer account (one-time US$25), app name "Express Autocare", package
`in.expressautocare.app`, privacy policy URL (what's stored: name, phone, cars, documents; nothing sold), screenshots
of Home / Job / Book / Ask. For a test build against this PC use `set EAERP_APP_URL=http://<PC IP>:8000` and add
`android:usesCleartextTraffic="true"` to the `<application>` tag in `mobile-customer/android/app/src/main/AndroidManifest.xml`
(remove it again for the Play Store build). iPhone App Store needs a Mac and an Apple developer account (later);
iPhone users install the web app meanwhile.

## Notes

- Phone notifications need HTTPS (or localhost) and the Web Push keys the server makes on first use. On Windows PHP
  set the `OPENSSL_CONF` environment variable to `C:\php\extras\ssl\openssl.cnf` for sending them; Linux servers need nothing.
- Daily reminders run from the scheduler (`customerapp:reminders`, 09:30). Like the other jobs, it needs
  `php artisan schedule:run` every minute on the server.
