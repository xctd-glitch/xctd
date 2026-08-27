<?php

declare(strict_types=1);

namespace App;

use Throwable;

final class PwaManifest
{
    public static function isRequest(): bool
    {
        return isset($_GET['asset'])
            && is_string($_GET['asset'])
            && hash_equals('app-meta', $_GET['asset']);
    }

    public static function respond(string $rootPath): never
    {
        header_remove('X-Powered-By');
        header('Content-Type: application/manifest+json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=300, no-transform');
        header('Referrer-Policy: no-referrer');

        $path = rtrim($rootPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'config'
            . DIRECTORY_SEPARATOR
            . 'pwa-manifest.json';

        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            echo '{}';
            exit;
        }

        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            http_response_code(500);
            echo '{}';
            exit;
        }

        try {
            json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            http_response_code(500);
            echo '{}';
            exit;
        }

        echo $content;
        exit;
    }
}
