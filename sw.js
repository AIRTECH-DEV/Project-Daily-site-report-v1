const CACHE_NAME = 'pms-site-report-v3';
const OFFLINE_URLS = ['./', './index.php', './manifest.json'];

self.addEventListener('install', function (event) {
  event.waitUntil(caches.open(CACHE_NAME).then(function (cache) {
    return cache.addAll(OFFLINE_URLS);
  }).then(function () {
    return self.skipWaiting();
  }));
});

self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (key) { return key !== CACHE_NAME; }).map(function (key) { return caches.delete(key); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (event) {
  if (event.request.method !== 'GET') return;
  event.respondWith(
    fetch(event.request).catch(function () {
      return caches.match(event.request).then(function (cached) {
        return cached || caches.match('./');
      });
    })
  );
});
