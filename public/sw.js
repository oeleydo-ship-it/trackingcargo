const CACHE_NAME = 'cargoflow-shell-v1';
const SHELL_URLS = ['/my-deliveries', '/manifest.json', '/icon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(SHELL_URLS))
            .catch(() => undefined),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))),
    );
    self.clients.claim();
});

// Network-first with a cache fallback for the app shell, so the driver PWA
// stays installable/usable on flaky connectivity without a full
// background-sync/offline-queue engine — see docs/phases/PHASE_07.md for why
// that fuller scope was deliberately deferred.
self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                const copy = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy)).catch(() => undefined);
                return response;
            })
            .catch(() => caches.match(event.request)),
    );
});
