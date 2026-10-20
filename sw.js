/**
 * Tools for the Tough Days — service worker
 *
 * Only shows push notifications and opens the right page when one is tapped.
 * It deliberately does not cache pages or make the site work offline.
 * Payload (sent by lib/webpush.php): { title, body, url, tag }
 */
'use strict';

var DEFAULT_URL = '/dashboard.html';

self.addEventListener('install', function () {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
  var data = {};
  if (event.data) {
    try {
      data = event.data.json();
    } catch (_err) {
      data = { body: event.data.text() };
    }
  }

  var options = {
    body: data.body || '',
    icon: '/assets/icons/icon-192.png',
    data: { url: data.url || DEFAULT_URL }
  };
  if (data.tag) {
    options.tag = data.tag;
  }

  // Every push must show a notification; browsers revoke permission for silent pushes.
  event.waitUntil(self.registration.showNotification(data.title || 'Tools for the Tough Days', options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();

  var target = new URL((event.notification.data && event.notification.data.url) || DEFAULT_URL, self.location.origin);
  if (target.origin !== self.location.origin) {
    target = new URL(DEFAULT_URL, self.location.origin);
  }

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
      for (var i = 0; i < windows.length; i++) {
        if (windows[i].url === target.href && 'focus' in windows[i]) {
          return windows[i].focus();
        }
      }
      return self.clients.openWindow(target.href);
    })
  );
});
