<?php

declare(strict_types=1);

// Every entry point requires this file before anything else, so it is the one
// place that can guarantee the production error posture. Diagnostics must reach
// the server log and never the response body: a rendered warning leaks absolute
// filesystem paths and injects unexpected markup into pages whose strict CSP and
// JSON contracts assume the body is exactly what the application wrote. Shared
// hosts frequently ship display_errors=On, so this cannot be left to php.ini.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === false || $relative === '') {
        return;
    }

    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
