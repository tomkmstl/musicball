const CACHE_NAME = 'musicball-static-v10';

const APP_SHELL = [
    '/',
    'index.php',
    'offline.html',
    'styles.css',
    'manifest.json'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return Promise.all(
                APP_SHELL.map(function (url) {
                    return cache.add(url).catch(function () {
                        return Promise.resolve();
                    });
                })
            );
        })
    );

    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.map(function (key) {
                    if (key !== CACHE_NAME) {
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

self.addEventListener('push', function (event) {
    let payload = {};

    if (event.data) {
        try {
            payload = event.data.json();
        } catch (error) {
            payload = { body: event.data.text() };
        }
    }

    const title = payload.title || 'Musicball';
    const options = {
        body: payload.body || 'You have an unfinished Musicball deadline.',
        icon: 'assets/pwa/icons/icon-192.png',
        badge: 'assets/pwa/icons/icon-192.png',
        tag: payload.tag || 'musicball-deadline-reminder',
        renotify: true,
        data: {
            url: payload.url || 'season.php'
        }
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    let targetUrl;
    try {
        targetUrl = new URL(event.notification.data && event.notification.data.url
            ? event.notification.data.url
            : 'season.php', self.location.origin);
    } catch (error) {
        targetUrl = new URL('season.php', self.location.origin);
    }

    if (targetUrl.origin !== self.location.origin) {
        targetUrl = new URL('season.php', self.location.origin);
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (const client of clientList) {
                if (new URL(client.url).origin !== self.location.origin) {
                    continue;
                }

                if ('navigate' in client) {
                    return client.navigate(targetUrl.href).then(function () {
                        return client.focus();
                    });
                }

                return client.focus();
            }

            return self.clients.openWindow(targetUrl.href);
        })
    );
});
