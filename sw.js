/* sw.js — Service Worker (notifications only)
 * -------------------------------------------
 * - Shows a notification on 'push' with payload {title, body, url, icon, badge, tag}
 * - Focuses an existing tab or opens a new one to data.url on click
 * - Versioned, safe for frequent deploys (skipWaiting + clients.claim)
 */

const SW_VERSION = 'permits-sw-v1.0.0';

// Immediately take control on install
self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

// Claim clients so updates apply without reload
self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

/**
 * Parse push data safely.
 * Accepts:
 *  - JSON string with {title, body, url, icon, badge, tag}
 *  - Plain text (becomes title), no URL
 */
function parsePushData(event) {
  try {
    if (!event.data) return {};
    const txt = event.data.text();
    try {
      const obj = JSON.parse(txt);
      return (obj && typeof obj === 'object') ? obj : { title: String(txt || 'Notification') };
    } catch {
      return { title: String(txt || 'Notification') };
    }
  } catch {
    return {};
  }
}

// Default icons (adjust paths if needed)
const DEFAULT_ICON  = '/assets/pwa/icon-192.png';
const DEFAULT_BADGE = '/assets/pwa/icon-32.png';

// Handle incoming push
self.addEventListener('push', (event) => {
  const data = parsePushData(event) || {};

  const title = data.title || 'Notification';
  const body  = data.body  || '';
  const url   = data.url   || '/'; // default to home if not provided

  const options = {
    body,
    icon:  data.icon  || DEFAULT_ICON,
    badge: data.badge || DEFAULT_BADGE,
    tag:   data.tag   || 'permits-push',
    data:  { url },
    renotify: false,
    requireInteraction: false,
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

// Click → focus an existing tab or open a new one
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const { url } = event.notification.data || {};
  const targetUrl = typeof url === 'string' && url.length ? url : '/';

  event.waitUntil((async () => {
    const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    // Try to focus an existing tab whose origin matches and navigate it if needed
    for (const client of allClients) {
      try {
        const clientUrl = new URL(client.url);
        const destUrl   = new URL(targetUrl, self.location.origin);
        if (clientUrl.origin === destUrl.origin) {
          await client.focus();
          // Only navigate if different path/query/hash
          if (clientUrl.href !== destUrl.href) {
            client.navigate(destUrl.href).catch(() => {/* ignore */});
          }
          return;
        }
      } catch {/* ignore */}
    }

    // Otherwise, open a new tab
    await self.clients.openWindow(targetUrl);
  })());
});

// If the browser drops the push subscription, tell pages to re-subscribe
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of allClients) {
      client.postMessage({ type: 'PUSH_SUBSCRIPTION_CHANGED' });
    }
  })());
});

// Optional: handle messages from pages (e.g., for version checks)
self.addEventListener('message', (event) => {
  if (!event.data) return;
  if (event.data.type === 'SW_VERSION') {
    event.ports?.[0]?.postMessage?.({ version: SW_VERSION });
  }
});
