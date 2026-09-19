/*
 * Service worker: an offline shell, nothing more.
 *
 * API responses are deliberately NOT cached — an order total or a partner
 * balance served from a stale cache is worse than an error message,
 * because it looks exactly like a fresh one.
 *
 * The app's own files ARE cached, but network-first, and that is a
 * deliberate change from the first version. Cache-first meant a deploy
 * only appeared on the *second* launch after it — the first launch served
 * the old files and quietly refreshed them in the background. On a phone
 * that is opened once a day that reads as "I uploaded it and nothing
 * changed", which is exactly the confusion this app does not need.
 *
 * Network-first costs one request on a good connection and still opens
 * instantly offline, because the cache is right there when the fetch
 * fails. Correctness over a few milliseconds.
 *
 * Bump CACHE whenever the shell changes; keep it in step with BUILD in
 * app.js, which is what Settings prints.
 */
const VERSION = '2026-09-19.2';
const CACHE = 'madeforu-shell-' + VERSION;
const SHELL = [
  './',
  './index.html',
  './app.css?v=' + VERSION,
  './app.js?v=' + VERSION,
  './manifest.webmanifest',
  './icons/icon-192.png',
  './icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      // reload bypasses the browser's own HTTP cache, so a fresh install
      // cannot pick up the very files it is meant to be replacing.
      .then((cache) => cache.addAll(SHELL.map((u) => new Request(u, { cache: 'reload' }))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// Settings' "Force a fresh copy" asks the worker to stand aside.
self.addEventListener('message', (event) => {
  if (event.data === 'skip-waiting') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  // Anything under the API is live or it is nothing.
  if (url.pathname.includes('/api/')) return;
  if (url.origin !== self.location.origin) return;

  event.respondWith(
    fetch(request)
      .then((fresh) => {
        if (fresh && fresh.ok) {
          const copy = fresh.clone();
          caches.open(CACHE).then((c) => c.put(request, copy)).catch(() => {});
        }
        return fresh;
      })
      // No signal: fall back to whatever was cached, and to the shell for
      // a navigation, so the app still opens on a train.
      .catch(() => caches.match(request)
        .then((hit) => hit || caches.match('./index.html')))
  );
});