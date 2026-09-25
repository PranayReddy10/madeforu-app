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
     test with one). Download the `google-services.json` it offers. The
     Notifications page reads the App ID out of it (the app itself does
     not need the file).
3. **Project settings** → **Cloud Messaging** → **Web Push certificates** →
   **Generate key pair**. Copy the key. This is `vapidKey`.
4. **Project settings** → **Service accounts** → **Generate new private
   key**. A `.json` file downloads. Treat it like a password.

### 2. Enter it all on the website

Sign in to the website → **Notifications** (in the sidebar, under Admins):

1. **Service-account key**: upload the `.json` from step 1.4. It is stored
   in the database, never in a folder a URL can reach, and only the account
   it belongs to is shown back.
2. **Web app**: paste the whole `firebaseConfig` block from step 1.2, and
   the Web Push key from step 1.3.
3. **Android app**: upload the `google-services.json` Firebase offers for
   the Android app, or type its App ID.

The top of the page shows a tick for each part, and how many phones and
browsers are registered.

(For anyone who prefers a file instead: `firebase-config.example.php`
copied to `firebase-config.php` takes priority over the page. It is
gitignored; never commit it or the key.)

### 3. Upload the code (do this before step 2)

Upload `lib_activity.php`, `lib_push.php`, `push_settings.php`,
`push_cron.php`, `layout.php`, the changed website pages, and the `api/`
and `app/` folders. The `push_devices` table creates itself the first time
it is needed.

### 4. Turn it on in each app

- **PWA**: Settings → Notifications → **Allow notifications on this
  device**. It then says *Real time*. **Send a test notification** checks
  the whole path; so does the button at the top of the website's
  Notifications page. On iPhone, this only works from the app added to the
  Home Screen (iOS 16.4 or later).
- **Android**: open the app once after signing in and allow notifications.
  Settings → Notifications → **Send a test notification**.

### Optional: a backstop for edits made outside the apps

Changes made directly in phpMyAdmin do not pass through the website or
the API. To have those pushed too, add a cron job in hPanel → Advanced →
Cron Jobs, every minute:

    php /home/u291217659/public_html/push_cron.php

## If a test notification does not arrive

- **Settings says push is not set up**: the key has not been uploaded on
  the Notifications page yet.
- **"Google refused the service account"** in the PHP error log: the key
  is not the one from step 1.4, or it was revoked. Upload a fresh one.
- **Nothing on an Android phone**: the phone needs Google Play services.
  Also check that notifications are allowed for the app in Android's
  settings.
- **Nothing in the PWA on iPhone**: open it from the Home Screen icon, not
  from Safari.
