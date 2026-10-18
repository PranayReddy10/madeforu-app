/* Offline shell for the installed app. Pages are network-first so prices and stock are never stale. */
var CACHE = 'mfu-v2';
var SHELL = ['/css/store.css', '/js/store.js', '/images/logo.webp', '/images/logo.png', '/images/placeholder.svg', '/offline.html'];
self.addEventListener('install', function (e) { e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); })); });
self.addEventListener('activate', function (e) { e.waitUntil(caches.keys().then(function (ks) { return Promise.all(ks.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); })); }).then(function () { return self.clients.claim(); })); });
self.addEventListener('fetch', function (e) {
    var req = e.request, url = new URL(req.url);
    if (req.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith('/admin') || url.pathname.startsWith('/uploads')) return;
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(function () { return caches.match('/offline.html'); }));
        return;
    }
    if (/\.(css|js|svg|png|jpe?g|webp|woff2?)$/.test(url.pathname)) {
        e.respondWith(caches.match(req).then(function (hit) {
            var net = fetch(req).then(function (res) { if (res.ok) { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(req, copy); }); } return res; });
            return hit || net;
        }));
    }
});
