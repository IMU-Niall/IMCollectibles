// IMCollectibles Service Worker (PWA + OneSignal compatible)
// Note: OneSignal uses its own worker at /OneSignalSDKWorker.js

self.addEventListener('install', e => {
    console.log('[SW] Installed');
    self.skipWaiting();
});

self.addEventListener('activate', e => {
    console.log('[SW] Activated');
    e.waitUntil(self.clients.claim());
});

self.addEventListener('notificationclick', e => {
    console.log('[SW] Notification clicked', e.notification);
    e.notification.close();
    e.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientsList => {
            const url = e.notification.data?.url || 'https://imcollectibles.io/profile/';
            for (let client of clientsList) {
                if (client.url === url && 'focus' in client) return client.focus();
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});