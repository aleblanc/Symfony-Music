<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the PWA assets: manifest, service worker and app icon.
 *
 * These are plain, dynamically-generated responses rather than physical
 * files under public/ on purpose: with this project's dev workflow
 * (`php -S -t public public/index.php`), the PHP built-in server's static
 * file handler intercepts requests for any file that physically exists in
 * the docroot *before* Symfony's front controller ever runs — it served
 * `.json` with a wrong `Content-Type` (`text/html`) and, worse, tried to
 * execute `.svg` as a PHP script (extension not in its built-in static
 * mime table → parse error). Routing them through a controller guarantees
 * correct headers regardless of the underlying web server.
 */
class PwaController extends AbstractController
{
    #[Route('/manifest.json', name: 'app_pwa_manifest')]
    public function manifest(): JsonResponse
    {
        $response = new JsonResponse([
            'name' => 'PHP Music',
            'short_name' => 'Music',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0a0a0a',
            'theme_color' => '#0a0a0a',
            'description' => 'A simple, fast music player with user accounts and uploads.',
            'icons' => [
                [
                    'src' => '/icon.svg',
                    'sizes' => '192x192',
                    'type' => 'image/svg+xml',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => '/icon.svg',
                    'sizes' => '512x512',
                    'type' => 'image/svg+xml',
                    'purpose' => 'any maskable',
                ],
            ],
        ]);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }

    #[Route('/sw.js', name: 'app_pwa_service_worker')]
    public function serviceWorker(): Response
    {
        $js = <<<'SW'
            // Minimal PWA service worker: caches the app shell and static assets
            // (CSS/JS/images/fonts) so the app can still boot when offline. Deliberately
            // does not attempt to cache audio streaming or any dynamic JSON/HTML
            // endpoint (range requests, non-GET requests, and anything that is not a
            // navigation or a recognizably-static file are left untouched).
            const CACHE_NAME = 'symfony-music-cache-v2';
            const APP_SHELL_URL = '/';
            const PRECACHE_URLS = [APP_SHELL_URL, '/manifest.json', '/icon.svg'];

            // Extensions considered "static assets" eligible for cache-first serving.
            const STATIC_ASSET_PATTERN = /\.(css|js|mjs|svg|png|jpe?g|webp|gif|ico|woff2?|ttf)$/i;

            self.addEventListener('install', (event) => {
              event.waitUntil(
                caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS))
              );
              self.skipWaiting();
            });

            self.addEventListener('activate', (event) => {
              event.waitUntil(
                caches.keys().then((cacheNames) => Promise.all(
                  cacheNames
                    .filter((cacheName) => cacheName !== CACHE_NAME)
                    .map((cacheName) => caches.delete(cacheName))
                ))
              );
              self.clients.claim();
            });

            self.addEventListener('fetch', (event) => {
              const request = event.request;

              // Never intercept non-GET requests or range requests (audio streaming).
              if (request.method !== 'GET' || request.headers.get('range')) {
                return;
              }

              // App shell navigations: try the network first (fresh content), fall
              // back to the cached shell when offline.
              if (request.mode === 'navigate') {
                event.respondWith(
                  fetch(request)
                    .then((networkResponse) => {
                      const responseToCache = networkResponse.clone();
                      caches.open(CACHE_NAME).then((cache) => cache.put(request, responseToCache));
                      return networkResponse;
                    })
                    .catch(() => caches.match(request).then((cached) => cached || caches.match(APP_SHELL_URL)))
                );
                return;
              }

              const url = new URL(request.url);

              // The app's own scripts and styles: network first (so an update is
              // picked up on the next load), cached copy when offline.
              if (url.origin === self.location.origin && /\.(css|js|mjs)$/i.test(url.pathname)) {
                event.respondWith(
                  fetch(request)
                    .then((networkResponse) => {
                      if (networkResponse && networkResponse.ok) {
                        const responseToCache = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, responseToCache));
                      }
                      return networkResponse;
                    })
                    .catch(() => caches.match(request).then((cached) => cached || Response.error()))
                );
                return;
              }

              // Other static assets: cache-first, populate the cache lazily from the network.
              if (url.origin === self.location.origin && STATIC_ASSET_PATTERN.test(url.pathname)) {
                event.respondWith(
                  caches.match(request).then((cachedResponse) => {
                    if (cachedResponse) {
                      return cachedResponse;
                    }
                    return fetch(request).then((networkResponse) => {
                      if (networkResponse && networkResponse.ok) {
                        const responseToCache = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, responseToCache));
                      }
                      return networkResponse;
                    });
                  })
                );
              }

              // Anything else (dynamic JSON/HTML fragments, API-like GETs) is left to
              // the browser's default network handling.
            });

            SW;

        $response = new Response($js);
        $response->headers->set('Content-Type', 'text/javascript; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        // A service worker's own script is not allowed to control a scope
        // wider than its own directory unless this header grants it.
        $response->headers->set('Service-Worker-Allowed', '/');

        return $response;
    }

    #[Route('/icon.svg', name: 'app_pwa_icon')]
    public function icon(): Response
    {
        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg width="512" height="512" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
            . '<rect width="24" height="24" rx="6" fill="#0a0a0a"/>'
            . '<path d="M0 24L24 0V24H0Z" fill="#141414" clip-path="inset(0px round 6px)"/>'
            . '<path d="M4 10V13" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round"/>'
            . '<path d="M16 10V13" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round"/>'
            . '<path d="M7 7L7 16" stroke="#ff0044" stroke-width="1.7" stroke-linecap="round"/>'
            . '<path d="M13 7L13 16" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round"/>'
            . '<path d="M19 7L19 16" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round"/>'
            . '<path d="M10 4L10 19" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round"/>'
            . '</svg>';

        $response = new Response($svg);
        $response->headers->set('Content-Type', 'image/svg+xml');
        $response->setPublic();
        $response->setMaxAge(31536000);

        return $response;
    }
}
