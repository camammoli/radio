const CACHE_NAME = 'radio-ar-v4';
const PRECACHE   = ['/radio/manifest.json'];
const ASSET_RE   = /\.(js|css|png|jpg|jpeg|svg|webp|woff2?|ico|gif)(\?|$)/i;

self.addEventListener('install', function(e) {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE_NAME).then(function(c) { return c.addAll(PRECACHE).catch(function(){}); }));
});

self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(keys.filter(function(k){ return k !== CACHE_NAME; }).map(function(k){ return caches.delete(k); }));
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function(e) {
  if (e.request.method !== 'GET') return;
  var url = new URL(e.request.url);
  if (!url.pathname.startsWith('/radio/')) return;
  if (url.pathname.startsWith('/radio/api/')) return;  // API: nunca cachear

  if (ASSET_RE.test(url.pathname)) {
    // Assets estáticos: cache-first (son inmutables o versionados)
    e.respondWith(
      caches.match(e.request).then(function(cached) {
        if (cached) return cached;
        return fetch(e.request).then(function(resp) {
          if (resp.ok) {
            var cl = resp.clone();
            caches.open(CACHE_NAME).then(function(c) { c.put(e.request, cl); });
          }
          return resp;
        });
      })
    );
  } else {
    // Páginas PHP: network-first, cache solo como fallback offline
    // Nunca cachear si el servidor dice no-store
    e.respondWith(
      fetch(e.request).then(function(resp) {
        if (resp.ok) {
          var cc = resp.headers.get('Cache-Control') || '';
          if (!cc.includes('no-store') && !cc.includes('no-cache')) {
            var cl = resp.clone();
            caches.open(CACHE_NAME).then(function(c) { c.put(e.request, cl); });
          }
        }
        return resp;
      }).catch(function() {
        // Offline: servir desde cache si hay
        return caches.match(e.request);
      })
    );
  }
});
