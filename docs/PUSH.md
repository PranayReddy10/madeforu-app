# Real-time notifications (Firebase)

When a sale, payment, order change, expense or account movement is saved
(on the website, in the PWA or in the Android app), every other partner's
phone is told the moment it happens. That includes phones where the app
is closed.

How it works: after any request that saves something, the server
(`lib_push.php`) reads what changed and sends it through Firebase Cloud
Messaging. The person who made the change is not notified of it.

Until this is set up, nothing breaks. The apps fall back to checking by
themselves: the PWA every 30 seconds while it is open, and the Android
app every 30 seconds while open and about every 15 minutes while closed.

## One-time setup (about 10 minutes, free)

### 1. Create the Firebase project

1. Go to <https://console.firebase.google.com> → **Add project**. Name it
   `madeforu`. Google Analytics is not needed.
2. **Project settings** (the gear icon) → **General** → **Your apps**:
   - Click **</>** (Web). Nickname `MadeForU PWA`. Copy the `firebaseConfig`
     values it shows (apiKey, authDomain, projectId, messagingSenderId,
     appId).
   - Click the **Android** icon. Package name: `com.madeforu.sales`
     (a debug build is `com.madeforu.sales.debug`; add that too if you
     test with one). Skip downloading `google-services.json`. The app
     does not need it. Copy the **App ID** (`1:…:android:…`).
3. **Project settings** → **Cloud Messaging** → **Web Push certificates** →
   **Generate key pair**. Copy the key. This is `vapidKey`.
4. **Project settings** → **Service accounts** → **Generate new private
   key**. A `.json` file downloads. Treat it like a password.

### 2. Put the key on the server, outside public_html

In Hostinger hPanel → **File Manager**, go to your home folder, the one
*above* `public_html`. Upload the `.json` file there and rename it
`firebase-service-account.json`.

It must not be inside `public_html`. Anything in there can be downloaded
by anyone who guesses the URL.

### 3. Fill in firebase-config.php

Copy `firebase-config.example.php` to `firebase-config.php`, next to
`config.php` in `public_html`, and fill in:

- `FIREBASE_SERVICE_ACCOUNT`: the full path of the key from step 2, e.g.
  `/home/u291217659/firebase-service-account.json` (File Manager shows
  your home folder's path).
- `FIREBASE_WEB`: the web values from step 1.2, plus `vapidKey` from
  step 1.3.
- `FIREBASE_ANDROID`: the Android App ID from step 1.2, with the same
  apiKey, projectId and messagingSenderId.

`firebase-config.php` is in `.gitignore`. Never commit it, or the key.

### 4. Upload the code

Upload `lib_activity.php`, `lib_push.php`, `push_cron.php`, the changed
website pages, and the `api/` and `app/` folders. The `push_devices`
table creates itself the first time it is needed.

### 5. Turn it on in each app

- **PWA**: Settings → Notifications → **Allow notifications on this
  device**. It then says *Real time*. **Send a test notification** checks
  the whole path. On iPhone, this only works from the app added to the
  Home Screen (iOS 16.4 or later).
- **Android**: open the app once after signing in and allow notifications.
  Settings → Notifications → **Send a test notification**.

### Optional: a backstop for edits made outside the apps

Changes made directly in phpMyAdmin do not pass through the website or
the API. To have those pushed too, add a cron job in hPanel → Advanced →
Cron Jobs, every minute:

    php /home/u291217659/public_html/push_cron.php

## If a test notification does not arrive

- **Settings says push is not set up**: `firebase-config.php` is missing,
  or the path in `FIREBASE_SERVICE_ACCOUNT` is wrong.
- **"Google refused the service account"** in the PHP error log: the key
  file is not the one from step 1.4, or it was revoked.
- **Nothing on an Android phone**: the phone needs Google Play services.
  Also check that notifications are allowed for the app in Android's
  settings.
- **Nothing in the PWA on iPhone**: open it from the Home Screen icon, not
  from Safari.
