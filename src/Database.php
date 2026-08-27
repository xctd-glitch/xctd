<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    /** @param array<string, mixed> $config */
    public static function connect(array $config): PDO
    {
        try {
            $host = self::stringValue($config, 'host');
            $name = self::stringValue($config, 'name');
            $user = self::stringValue($config, 'user');
            $pass = self::stringValue($config, 'pass');
            $port = self::intValue($config, 'port', 3306, 1, 65535);

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $host,
                $port,
                $name
            );

            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
        } catch (Throwable $e) {
            error_log('Database connection failure: ' . $e->getMessage());
            throw new RuntimeException('Database service is unavailable.');
        }
    }

    /** @param array<string, mixed> $config */
    private static function stringValue(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Invalid database configuration.');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $config */
    private static function intValue(array $config, string $key, int $default, int $min, int $max): int
    {
        $value = $config[$key] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new RuntimeException('Invalid database configuration.');
        }

        return $value;
    }
}
