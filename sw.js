/* sw.js — Service Worker (offline + notifications)
 * -------------------------------------------------
 * - Precaches core shell assets for offline use
 * - Runtime caching for same-origin GET requests
 * - Shows push notifications and handles clicks
 * - Versioned cache keys for safe roll-outs
 */

const SW_VERSION = 'permits-sw-v2.4.0';
const PRECACHE = `permits-precache-${SW_VERSION}`;
const RUNTIME = `permits-runtime-${SW_VERSION}`;

const PRECACHE_URLS = [
  '/offline.html',
  '/manifest.webmanifest',
  '/assets/app.css',
  '/assets/app.js',
  '/assets/phase3-status.js',
  '/assets/phase3c-picker.js',
  '/assets/brand-identity.css',
  '/favicon.svg',
];

// Immediately take control on install
self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(PRECACHE);
    await cache.addAll(PRECACHE_URLS);
    await self.skipWaiting();
  })());
});

// Claim clients and clear stale caches, including the retired blue P icons.
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter((key) => key.startsWith('permits-') && key !== PRECACHE && key !== RUNTIME)
        .map((key) => caches.delete(key))
    );
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET' || !request.url.startsWith(self.location.origin)) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(networkOnlyNavigation(request));
    return;
  }

  const url = new URL(request.url);
  const isStaticAsset = url.pathname.startsWith('/assets/')
    || url.pathname === '/manifest.webmanifest'
    || url.pathname === '/favicon.svg';

  // Never cache API responses, permit/QR URLs, downloads or other dynamic data.
  if (!isStaticAsset) {
    event.respondWith(fetch(request));
    return;
  }

  event.respondWith(cacheFirst(request));
});

async function networkOnlyNavigation(request) {
  try {
    return await fetch(request);
  } catch (error) {
    const cache = await caches.open(PRECACHE);
    return (await cache.match('/offline.html')) || Response.error();
  }
}

async function cacheFirst(request) {
  const cache = await caches.open(PRECACHE);
  const cached = await cache.match(request);
  if (cached) {
    return cached;
  }

  try {
    const response = await fetch(request);
    const runtime = await caches.open(RUNTIME);
    runtime.put(request, response.clone());
    return response;
  } catch (error) {
    return cached || Response.error();
  }
}

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

// Use the same hard-hat/check artwork as the browser favicon everywhere.
const DEFAULT_ICON  = '/favicon.svg';
const DEFAULT_BADGE = '/favicon.svg';

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
  let targetUrl = '/';
  try {
    const candidate = new URL(typeof url === 'string' && url.length ? url : '/', self.location.origin);
    if (candidate.origin === self.location.origin) {
      targetUrl = `${candidate.pathname}${candidate.search}${candidate.hash}`;
    }
  } catch {
    targetUrl = '/';
  }

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
