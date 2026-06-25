const CACHE_NAME = 'radio-ar-v2';
const PRECACHE = ['/radio/', '/radio/manifest.json'];

self.addEventListener('install', function(e) {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE_NAME).then(function(c) { return c.addAll(PRECACHE).catch(function(){}); }));
});
self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(keys.filter(function(k){return k!==CACHE_NAME;}).map(function(k){return caches.delete(k);}));
    })
  );
  self.clients.claim();
});
self.addEventListener('fetch', function(e) {
  if (e.request.method !== 'GET') return;
  var url = new URL(e.request.url);
  if (!url.pathname.startsWith('/radio/')) return;
  var skip = ['proxy.php','nowplaying.php','listeners.php','survey.php','log.php','status.json'];
  if (skip.some(function(s){return url.pathname.indexOf(s)!==-1;})) return;
  e.respondWith(
    caches.match(e.request).then(function(cached) {
      return cached || fetch(e.request).then(function(resp) {
        if (resp.ok) {
          var cl = resp.clone();
          caches.open(CACHE_NAME).then(function(c){c.put(e.request,cl);});
        }
        return resp;
      });
    })
  );
});
