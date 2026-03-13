/**
 * Unified Support Schedule - Service Worker
 * Provides offline support and fast repeat loads via cache-first strategy
 */

const CACHE_NAME = 'schedule-v2';
const STATIC_CACHE = 'schedule-static-v2';

// Static assets to cache on install (shell)
const SHELL_ASSETS = [
  '/styles.css',
  '/mobile.css',
];

// ─── Install: cache static shell ─────────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => cache.addAll(SHELL_ASSETS))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting()) // Don't block install if CDN assets fail
  );
});

// ─── Activate: clean up old caches ───────────────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(key => key !== CACHE_NAME && key !== STATIC_CACHE)
          .map(key => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  );
});

// ─── Fetch: smart caching strategy ───────────────────────────────────────────
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Skip non-GET and cross-origin requests
  if (event.request.method !== 'GET') return;
  if (url.origin !== location.origin) return;

  // Skip POST form submissions and API calls
  if (url.pathname.includes('action=') || url.search.includes('action=')) return;

  // Strategy: CSS/JS/images → Cache First (fast)
  if (isStaticAsset(url.pathname)) {
    event.respondWith(cacheFirst(event.request));
    return;
  }

  // Strategy: PHP pages → Network First with offline fallback
  if (url.pathname.endsWith('.php') || url.pathname === '/') {
    event.respondWith(networkFirstWithFallback(event.request));
    return;
  }
});

function isStaticAsset(pathname) {
  return pathname.endsWith('.css') ||
         pathname.endsWith('.js') ||
         pathname.endsWith('.png') ||
         pathname.endsWith('.jpg') ||
         pathname.endsWith('.svg') ||
         pathname.endsWith('.ico') ||
         pathname.endsWith('.woff') ||
         pathname.endsWith('.woff2');
}

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;

  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(STATIC_CACHE);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    return new Response('/* offline */', {
      headers: { 'Content-Type': 'text/css' }
    });
  }
}

async function networkFirstWithFallback(request) {
  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    // Offline: return last cached version of this page
    const cached = await caches.match(request);
    if (cached) return cached;

    // Last resort: serve cached index
    const index = await caches.match('/Index.php');
    if (index) return index;

    // No cache at all
    return new Response(offlinePage(), {
      headers: { 'Content-Type': 'text/html' }
    });
  }
}

function offlinePage() {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Offline - Unified Schedule</title>
  <style>
    body { font-family: Arial, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #333399; color: white; text-align: center; padding: 20px; }
    .card { background: rgba(255,255,255,0.15); border-radius: 16px; padding: 40px; max-width: 400px; }
    h1 { font-size: 48px; margin: 0 0 10px; }
    h2 { margin: 0 0 15px; font-size: 22px; }
    p { opacity: 0.85; line-height: 1.6; }
    button { margin-top: 20px; padding: 12px 28px; border-radius: 8px; border: none; background: white; color: #333399; font-size: 16px; font-weight: bold; cursor: pointer; }
  </style>
</head>
<body>
  <div class="card">
    <div>📡</div>
    <h2>You're Offline</h2>
    <p>The schedule app needs a connection to load fresh data. Check your network and try again.</p>
    <button onclick="location.reload()">Try Again</button>
  </div>
</body>
</html>`;
}
