const swUrl = new URL(self.location.href);
const DEV_MODE = swUrl.searchParams.get('dev_mode') === '1';
const CACHE_NAME = DEV_MODE ? 'musicball-dev-cache' : 'musicball-static-v2';
const APP_SHELL = [
    '/',
    'index.php',
    'offline.html',
    'styles.css',
    'questions.js',
    'ml_progress.js',
    'ml_user_router.js',
    'pwa.js',
    'manifest.json',
    'images/musicball_logo.png',
    'images/next_season.png',
    'images/app-icons/favicon-16x16.png',
    'images/app-icons/favicon-32x32.png',
    'images/app-icons/apple-touch-icon.png',
    'images/app-icons/icon-192.png',
    'images/app-icons/icon-512.png'
];

self.addEventListener('install', function (event) {
    if (DEV_MODE) {
        self.skipWaiting();
        return;
    }

    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.addAll(APP_SHELL);
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.map(function (key) {
                    if (DEV_MODE || key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                    return Promise.resolve();
                })
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', function (event) {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const requestUrl = new URL(request.url);

    if (requestUrl.origin !== self.location.origin) {
        return;
    }

    if (DEV_MODE) {
        event.respondWith(
            fetch(request, { cache: 'no-store' }).catch(function () {
                if (request.mode === 'navigate') {
                    return caches.match('offline.html');
                }
                return caches.match(request);
            })
        );
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then(function (response) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) {
                        cache.put(request, responseClone);
                    });
                    return response;
                })
                .catch(function () {
                    return caches.match(request).then(function (cachedPage) {
                        return cachedPage || caches.match('offline.html');
                    });
                })
        );
        return;
    }

    if (
        requestUrl.pathname.endsWith('.css') ||
        requestUrl.pathname.endsWith('.js') ||
        requestUrl.pathname.endsWith('.json')
    ) {
        event.respondWith(
            fetch(request)
                .then(function (networkResponse) {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then(function (cache) {
                        cache.put(request, responseClone);
                    });
                    return networkResponse;
                })
                .catch(function () {
                    return caches.match(request);
                })
        );
        return;
    }

    if (
        requestUrl.pathname.endsWith('.png') ||
        requestUrl.pathname.endsWith('.jpg') ||
        requestUrl.pathname.endsWith('.jpeg') ||
        requestUrl.pathname.endsWith('.gif') ||
        requestUrl.pathname.endsWith('.webp') ||
        requestUrl.pathname.endsWith('.ico')
    ) {
        event.respondWith(
            caches.match(request).then(function (cachedResponse) {
                if (cachedResponse) {
                    return cachedResponse;
                }

                return fetch(request).then(function (networkResponse) {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then(function (cache) {
                        cache.put(request, responseClone);
                    });
                    return networkResponse;
                });
            })
        );
    }
});
