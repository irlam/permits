/**
 * Permits System - Service Worker
 * 
 * Description: Progressive Web App service worker for offline functionality and asset caching
 * Name: sw.js
 * Last Updated: 21/10/2025 19:22:30 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Enable offline access to the permits application
 * - Cache static assets (CSS, JS, manifest) for faster loading
 * - Implement cache-first strategy for performance
 * - Automatically clean up old cache versions
 * - Provide seamless update experience
 * 
 * Features:
 * - Install event: Download and cache static assets
 * - Activate event: Clean up old caches
 * - Fetch event: Serve from cache with network fallback
 * - Message event: Handle skip waiting commands
 * - Smart caching: Never cache API endpoints or dynamic pages
 * 
 * Version Management:
 * IMPORTANT: Increment CACHE_VERSION every time you deploy changes!
 * This forces browsers to download the new service worker and clear old cache
 * Format: permits-v[number] or permits-YYYYMMDD-HHMM
 */

// Cache version identifier - update this on each deployment
const CACHE_VERSION = 'permits-v3-quickwins';

// Static assets to cache (CSS, JS, manifest)
const STATIC_ASSETS = [
  '/assets/app.css',
  '/assets/app.js',
  '/manifest.webmanifest'
];

// Paths that should NEVER be cached (always fetch fresh)
const NEVER_CACHE = [
  '/api/',           // All API endpoints
  '/form/',          // Form view/edit pages
  '/new/',           // New form creation
  '/admin-templates' // Admin panel
];

/**
 * Install Event - Downloads and caches static assets
 */
self.addEventListener('install', event => {
  console.log('[SW] Installing version:', CACHE_VERSION);
  
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then(cache => {
        console.log('[SW] Caching static assets');
        return cache.addAll(STATIC_ASSETS);
      })
      .then(() => {
        console.log('[SW] Installation complete, activating immediately');
        return self.skipWaiting(); // Force immediate activation
      })
  );
});

/**
 * Activate Event - Cleans up old caches
 */
self.addEventListener('activate', event => {
  console.log('[SW] Activating version:', CACHE_VERSION);
  
  event.waitUntil(
    caches.keys()
      .then(cacheNames => {
        // Delete all old caches
        return Promise.all(
          cacheNames
            .filter(cacheName => cacheName !== CACHE_VERSION)
            .map(cacheName => {
              console.log('[SW] Deleting old cache:', cacheName);
              return caches.delete(cacheName);
            })
        );
      })
      .then(() => {
        console.log('[SW] Claiming all clients');
        return self.clients.claim(); // Take control of all pages immediately
      })
  );
});

/**
 * Fetch Event - Handles network requests with smart caching
 */
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  
  // Only handle GET requests
  if (event.request.method !== 'GET') {
    return;
  }
  
  // Check if this path should never be cached
  const shouldNeverCache = NEVER_CACHE.some(path => url.pathname.startsWith(path));
  
  if (shouldNeverCache) {
    // Always fetch fresh, never cache
    event.respondWith(fetch(event.request));
    return;
  }
  
  // For static assets: Cache first, then network
  event.respondWith(
    caches.match(event.request)
      .then(cachedResponse => {
        if (cachedResponse) {
          console.log('[SW] Serving from cache:', url.pathname);
          return cachedResponse;
        }
        
        // Not in cache, fetch from network
        return fetch(event.request)
          .then(response => {
            // Only cache successful responses
            if (response.status === 200) {
              const responseClone = response.clone();
              caches.open(CACHE_VERSION)
                .then(cache => {
                  cache.put(event.request, responseClone);
                });
            }
            return response;
          })
          .catch(() => {
            // Network failed, try to return homepage from cache as fallback
            return caches.match('/');
          });
      })
  );
});

/**
 * Message Event - Allows pages to communicate with service worker
 */
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});