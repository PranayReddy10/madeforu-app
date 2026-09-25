<?php
/**
 * Real-time notifications through Firebase Cloud Messaging.
 *
 * Copy this file to firebase-config.php, next to config.php, and fill it
 * in. Until firebase-config.php exists, nothing is pushed and both apps
 * carry on exactly as before. Step-by-step: docs/PUSH.md.
 */

/*
 * The service-account key: Firebase console → Project settings → Service
 * accounts → Generate new private key. It is a password to your Firebase
 * project. Put it OUTSIDE public_html, so no URL can ever serve it.
 */
define('FIREBASE_SERVICE_ACCOUNT', '/home/u291217659/firebase-service-account.json');

/*
 * The web app's settings: Project settings → General → Your apps → the
 * web app (</>), "SDK setup and configuration" → Config. vapidKey is
 * Project settings → Cloud Messaging → Web Push certificates → Key pair.
 * These are public by design; every browser that uses the PWA sees them.
 */
define('FIREBASE_WEB', [
    'apiKey'            => '',
    'authDomain'        => '',
    'projectId'         => '',
    'messagingSenderId' => '',
    'appId'             => '',
    'vapidKey'          => '',
]);

/*
 * The Android app's settings: Project settings → General → Your apps →
 * the Android app (package com.madeforu.sales). appId is its "App ID"
 * (1:...:android:...). apiKey, projectId and messagingSenderId are the
 * same values as above.
 */
define('FIREBASE_ANDROID', [
    'apiKey'            => '',
    'appId'             => '',
    'projectId'         => '',
    'messagingSenderId' => '',
]);
