// ── Service Worker — Café PWA ───────────────────────────────────────────────
// Versión: cambia este número para forzar actualización del caché en todos los usuarios
const CACHE_VERSION = 'cafe-v1';

// Archivos que se cachean al instalar (app shell — funciona sin internet)
const SHELL_FILES = [
  './index.html',
  './manifest.json',
  'https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap'
];

// ── Instalación: precachea el shell ────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then(cache => {
      // Cacheamos lo que podamos; si falla la fuente (CORS) no bloqueamos
      return Promise.allSettled(
        SHELL_FILES.map(url => cache.add(url).catch(() => {}))
      );
    }).then(() => self.skipWaiting())
  );
});

// ── Activación: borra cachés viejos ────────────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.filter(k => k !== CACHE_VERSION).map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── Fetch: estrategia según tipo de recurso ────────────────────────────────
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Las llamadas a la API siempre van a la red (datos en tiempo real)
  // Si falla la red, devolvemos un error JSON claro
  if (url.pathname.includes('api.php')) {
    event.respondWith(
      fetch(event.request).catch(() =>
        new Response(
          JSON.stringify({ error: 'Sin conexión. Revisa tu internet.' }),
          { status: 503, headers: { 'Content-Type': 'application/json' } }
        )
      )
    );
    return;
  }

  // Google Fonts — cache first (raramente cambian)
  if (url.hostname.includes('fonts.g')) {
    event.respondWith(
      caches.match(event.request).then(cached => cached || fetch(event.request))
    );
    return;
  }

  // App shell (HTML, manifest) — network first con fallback a caché
  // Así siempre tienen la versión más reciente cuando hay internet
  event.respondWith(
    fetch(event.request)
      .then(response => {
        // Actualiza la caché con la versión más reciente
        const clone = response.clone();
        caches.open(CACHE_VERSION).then(cache => cache.put(event.request, clone));
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});
