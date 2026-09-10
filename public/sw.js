const CACHE_NAME = 'tramatch-shell-v2';

const PRECACHE = [
    '/offline.html',
    '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE);
        })
    );

    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            );
        })
    );

    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const url = new URL(event.request.url);

    const privatePaths = [
        '/dashboard',
        '/preferences',
        '/recommendations',
        '/discover',
        '/itineraries',
        '/admin',
    ];

    const isPrivate = privatePaths.some((path) => {
        return url.pathname.startsWith(path);
    });

    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => {
                return caches.match('/offline.html');
            })
        );

        return;
    }

    if (url.origin !== self.location.origin || isPrivate) {
        return;
    }

    const isStaticAsset = [
        'style',
        'script',
        'image',
        'font',
    ].includes(event.request.destination);

    if (!isStaticAsset) {
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cachedResponse) => {
            if (cachedResponse) {
                return cachedResponse;
            }

            return fetch(event.request).then((response) => {
                if (!response.ok) {
                    return response;
                }

                const copy = response.clone();

                caches.open(CACHE_NAME).then((cache) => {
                    cache.put(event.request, copy);
                });

                return response;
            });
        })
    );
});