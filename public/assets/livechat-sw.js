/* Zen Cortext — Live Chat Service Worker
 * Handles push notifications and notification click routing.
 * No caching strategy — the live chat page always needs fresh data.
 */

self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'New message', body: '' };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'Zen Cortext', {
            body: data.body || '',
            icon: '/biometrics.png',
            badge: '/biometrics.png',
            tag: data.tag || 'zen-livechat',
            renotify: true,
            data: { url: data.url || '/zen-livechat/' }
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/zen-livechat/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            // If there's already a tab with the live chat open, navigate it
            // to the specific chat URL (which includes ?open_chat=uid) and focus.
            for (var i = 0; i < list.length; i++) {
                if (list[i].url.indexOf('/zen-livechat/') !== -1) {
                    list[i].navigate(url);
                    return list[i].focus();
                }
            }
            // Otherwise open a new window/tab.
            return clients.openWindow(url);
        })
    );
});

// Skip waiting and claim clients immediately on install/activate
// so the SW is active right away, not on the next page load.
self.addEventListener('install', function (event) {
    self.skipWaiting();
});
self.addEventListener('activate', function (event) {
    event.waitUntil(clients.claim());
});
