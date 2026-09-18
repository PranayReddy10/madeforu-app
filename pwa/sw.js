/*
 * Service worker: an offline shell, nothing more.
 *
 * The app's files are cached so it opens instantly and still opens with no
 * signal. API responses are deliberately NOT cached — an order total or a
 * partner balance served from a stale cache is worse than an error message,
 * because it looks exactly like a fresh one.
 *
 * Bump CACHE when the shell changes, or phones keep serving the old files.
 */
const CACHE = 'madeforu-shell-v2';
const SHELL = [
  './',
  './index.html',
  './app.css',
  './app.js',
  './manifest.webmanifest',
  './icons/icon-192.png',
  './icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  // Anything under the API is live or it is nothing.
  if (url.pathname.includes('/api/')) return;

  event.respondWith(
    caches.match(request).then((hit) => {
      if (hit) {
        // Refresh in the background so the next launch is current, but
        // answer now from the cache.
        fetch(request).then((fresh) => {
          if (fresh && fresh.ok) caches.open(CACHE).then((c) => c.put(request, fresh.clone()));
        }).catch(() => {});
        return hit;
      }
      return fetch(request).then((fresh) => {
        if (fresh && fresh.ok && url.origin === self.location.origin) {
          const copy = fresh.clone();
          caches.open(CACHE).then((c) => c.put(request, copy));
        }
        return fresh;
      }).catch(() => caches.match('./index.html'));
    })
  );
});
