<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/javascript; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');
?>
'use strict';

// Bump this whenever any cached asset (assets/*.php, icons, offline.html) changes,
// otherwise installed PWAs keep serving stale assets from the previous cache.
const CACHE_VERSION = 'bank-receipt-pwa-v1.10.2';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const OFFLINE_URL = './offline.html';
const STATIC_ASSETS = [
  './offline.html',
  './index.php?asset=app-meta',
  './assets/jquery.php',
  './assets/app.php',
  './assets/pwa.php',
  './assets/senders.php',
  './assets/statistics.php',
  './favicon.ico',
  './assets/icons/icon-72.png',
  './assets/icons/icon-96.png',
  './assets/icons/icon-128.png',
  './assets/icons/icon-144.png',
  './assets/icons/icon-152.png',
  './assets/icons/icon-192.png',
  './assets/icons/icon-384.png',
  './assets/icons/icon-512.png',
  './assets/icons/icon-maskable-192.png',
  './assets/icons/icon-maskable-512.png',
  './assets/icons/apple-touch-icon.png',
  './assets/icons/shortcut-96.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => (key.startsWith('bank-receipt-pwa-') || key.startsWith('mandiri-receipt-pwa-')) && key !== STATIC_CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request, {cache: 'no-store'}).catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }

  const staticEndpoint = ['/assets/jquery.php', '/assets/app.php', '/assets/pwa.php', '/assets/senders.php', '/assets/statistics.php']
    .some((suffix) => url.pathname.endsWith(suffix));

  if (url.pathname.endsWith('/index.php') && url.searchParams.get('asset') === 'app-meta') {
    event.respondWith(
      caches.match(request).then((cached) => cached || fetch(request).then((response) => {
        if (response && response.status === 200 && response.type === 'basic') {
          const clone = response.clone();
          caches.open(STATIC_CACHE).then((cache) => cache.put(request, clone));
        }
        return response;
      }))
    );
    return;
  }

  if ((url.pathname.includes('/api/') || url.pathname.endsWith('.php')) && !staticEndpoint) {
    event.respondWith(fetch(request, {cache: 'no-store'}));
    return;
  }

  event.respondWith(
    fetch(request, {cache: 'no-cache'}).then((response) => {
      if (!response || response.status !== 200 || response.type !== 'basic') {
        return response;
      }
      const clone = response.clone();
      caches.open(STATIC_CACHE).then((cache) => cache.put(request, clone));
      return response;
    }).catch(() => caches.match(request).then((cached) => cached || caches.match(OFFLINE_URL)))
  );
});
