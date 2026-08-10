// OPES service worker: Web Push receive + click-through only. No offline
// caching strategy is implemented here - this file exists solely to give
// the browser something to call `showNotification` from when a push
// arrives, which is a hard requirement of the Push API (a page cannot
// receive push events directly; only its registered service worker can).

self.addEventListener('push', function (event) {
    let data = { title: 'OPES SCHOOL', body: '', url: '/dashboard' };

    if (event.data) {
        try {
            data = Object.assign(data, event.data.json());
        } catch (e) {
            data.body = event.data.text();
        }
    }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body || '',
            icon: '/favicon.ico',
            data: { url: data.url || '/dashboard' },
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || '/dashboard';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windowClients) {
            for (const client of windowClients) {
                if (client.url.endsWith(url) && 'focus' in client) {
                    return client.focus();
                }
            }

            if (clients.openWindow) {
                return clients.openWindow(url);
            }

            return undefined;
        })
    );
});
