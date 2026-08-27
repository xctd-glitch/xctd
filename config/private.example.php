<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'payment_ocr',
        'user' => 'payment_ocr_user',
        'pass' => 'CHANGE_ME',
    ],
    'app' => [
        'timezone' => 'Asia/Jakarta',
    ],
    'ocr' => [
        'mode' => 'browser',
        'language' => 'eng',
        'max_text_bytes' => 100_000,
    ],
    'upload' => [
        'max_bytes' => 8 * 1024 * 1024,
        'max_pixels' => 40_000_000,
    ],
    'security' => [
        // Sign-in throttling counts failures per source address. Behind a
        // reverse proxy or CDN every request carries the proxy's address, so
        // all clients would share one bucket; naming the forwarded-for header
        // here restores per-client accuracy (Cloudflare: 'CF-Connecting-IP').
        // Leave null unless the edge is guaranteed to overwrite that header on
        // every request - a client-settable header reinstates the bypass.
        'trusted_proxy_header' => null,
    ],
    'realtime' => [
        'poll_ms' => 2500,
        'hidden_poll_ms' => 10000,
        'max_rows' => 200,
    ],
];
