// MyTube Service Worker — PWA
const CACHE_NAME = 'mytube-v4';
const STATIC_ASSETS = [
  '/my/assets/css/main.css',
  '/my/assets/css/tiktok.css',
  '/my/assets/css/comments.css',
  '/my/assets/css/feed.css',
  '/my/assets/css/splash.css',
  '/my/assets/js/tiktok.js',
  '/my/assets/js/feed-ajax.js',
  '/my/assets/js/comments-new.js',
  '/my/assets/js/network-quality.js',
  '/my/assets/js/modal-mobile-helper.js',
  '/my/assets/js/avatar-fallback.js',
  '/my/assets/js/notifications.js',
  '/my/assets/js/like-sync.js',
  '/my/assets/js/comment-sync.js',
  '/my/assets/images/logo.png',
  '/my/assets/images/logo_icon.png',
  '/my/assets/images/default-avatar.svg',
];

// ─── Install: pre-cache static assets ───────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return Promise.allSettled(
        STATIC_ASSETS.map(url =>
          cache.add(url).catch(err => console.warn('[SW] Falha ao cachear:', url, err))
        )
      );
    }).then(() => self.skipWaiting())
  );
});

// ─── Activate: limpar caches antigos ────────────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(key => key !== CACHE_NAME)
          .map(key => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  );
});

// ─── Fetch: estratégia híbrida ────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Ignorar extensões não-HTTP, chrome-extension, etc.
  if (!url.protocol.startsWith('http')) return;

  // Ignorar requests de API / PHP dinâmico / uploads de vídeo
  const isDynamic =
    url.pathname.includes('/api/') ||
    url.pathname.includes('/uploads/') ||
    url.pathname.includes('chat-server') ||
    (url.pathname.endsWith('.php') && request.method === 'POST');

  if (isDynamic) {
    // Network-only para conteúdo dinâmico
    return;
  }

  // Para assets estáticos: Network First → Cache fallback
  // Evita ficar preso com CSS/JS antigos após deploy.
  const isStaticAsset =
    /\.(css|js|png|jpg|jpeg|webp|svg|gif|woff2?|ttf|eot|ico)$/i.test(url.pathname);

  if (isStaticAsset) {
    event.respondWith(
      fetch(request)
        .then(response => {
          if (response && response.status === 200 && response.type !== 'opaque') {
            const clone = response.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
          }
          return response;
        })
        .catch(() => caches.match(request))
    );
    return;
  }

  // Para páginas PHP: Network First → Cache fallback (mostar cache se offline)
  if (url.pathname.endsWith('.php') || url.pathname.endsWith('/')) {
    event.respondWith(
      fetch(request)
        .then(response => {
          // Cachear só respostas válidas de GET
          if (request.method === 'GET' && response.status === 200) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
          }
          return response;
        })
        .catch(() => caches.match(request))
    );
  }
});

// ─── Push Notifications (base para futuro) ───────────────────────────────────
self.addEventListener('push', event => {
  if (!event.data) return;
  let data = {};
  try { data = event.data.json(); } catch (e) { data = { title: 'MyTube', body: event.data.text() }; }

  event.waitUntil(
    self.registration.showNotification(data.title || 'MyTube', {
      body: data.body || '',
      icon: '/my/assets/images/logo_icon.png',
      badge: '/my/assets/images/logo_icon.png',
      data: data.url ? { url: data.url } : {},
      vibrate: [200, 100, 200],
    })
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const targetUrl = (event.notification.data && event.notification.data.url) || '/my/index.php';
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then(clientList => {
      for (const client of clientList) {
        if (client.url === targetUrl && 'focus' in client) return client.focus();
      }
      if (clients.openWindow) return clients.openWindow(targetUrl);
    })
  );
});
