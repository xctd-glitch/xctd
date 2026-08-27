<?php

declare(strict_types=1);

$privateFile = __DIR__ . '/private.php';
if (!is_file($privateFile)) {
    throw new RuntimeException('Application configuration is missing.');
}

/** @var array<string, mixed> $config */
$config = require $privateFile;

return $config;
