<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class Installer
{
    private const TABLES = ['login_attempts', 'weekly_payment_obligations', 'payment_transactions', 'team_members', 'users'];

    public function __construct(private readonly string $rootPath)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->rootPath . '/config/private.php')
            || is_file($this->rootPath . '/config/installed.lock');
    }

    /** @return array<string, array{ok:bool,label:string,detail:string}> */
    public function preflight(): array
    {
        $configDirectory = $this->rootPath . '/config';
        $uploadDirectory = $this->rootPath . '/storage/uploads';

        return [
            'php' => [
                'ok' => PHP_VERSION_ID >= 80300,
                'label' => 'PHP 8.3+',
                'detail' => PHP_VERSION,
            ],
            'pdo' => [
                'ok' => extension_loaded('pdo'),
                'label' => 'PDO',
                'detail' => extension_loaded('pdo') ? 'Available' : 'Missing',
            ],
            'pdo_mysql' => [
                'ok' => extension_loaded('pdo_mysql'),
                'label' => 'PDO MySQL',
                'detail' => extension_loaded('pdo_mysql') ? 'Available' : 'Missing',
            ],
            'fileinfo' => [
                'ok' => extension_loaded('fileinfo'),
                'label' => 'Fileinfo',
                'detail' => extension_loaded('fileinfo') ? 'Available' : 'Missing',
            ],
            'config_write' => [
                'ok' => is_dir($configDirectory) && is_writable($configDirectory),
                'label' => 'Config writable',
                'detail' => is_writable($configDirectory) ? 'Writable' : 'Not writable',
            ],
            'storage_write' => [
                'ok' => is_dir($uploadDirectory) && is_writable($uploadDirectory),
                'label' => 'Upload storage',
                'detail' => is_writable($uploadDirectory) ? 'Writable' : 'Not writable',
            ],
        ];
    }

    public function preflightPassed(): bool
    {
        foreach ($this->preflight() as $check) {
            if ($check['ok'] !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{host:string,port:int,name:string,user:string,pass:string} $dbConfig
     * @return array{id:int,username:string,role:string}
     */
    public function install(
        array $dbConfig,
        string $username,
        string $password
    ): array {
        if ($this->isInstalled()) {
            throw new RuntimeException('Installer is locked because application configuration already exists.');
        }

        if (!$this->preflightPassed()) {
            throw new RuntimeException('Server requirements are not satisfied.');
        }

        $pdo = Database::connect($dbConfig);
        $this->assertFreshDatabase($pdo);

        $configPath = $this->rootPath . '/config/private.php';
        $lockPath = $this->rootPath . '/config/installed.lock';
        $schemaStarted = false;
        $configWritten = false;

        try {
            $schemaStarted = true;
            $this->applySchema($pdo);

            $repository = new UserRepository($pdo);
            $adminId = $repository->create($username, $password, 'admin');
            $admin = $repository->authenticate($username, $password);
            if ($admin === null || $admin['id'] !== $adminId) {
                throw new RuntimeException('Initial administrator could not be verified.');
            }

            $config = [
                'db' => $dbConfig,
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
                    'setup_key' => bin2hex(random_bytes(32)),
                ],
                'realtime' => [
                    'poll_ms' => 2500,
                    'hidden_poll_ms' => 10000,
                    'max_rows' => 200,
                ],
            ];

            $this->atomicWritePhpConfig($configPath, $config);
            $configWritten = true;

            $lockData = json_encode([
                'version' => '1.10.0',
                'installed_at' => gmdate('c'),
                'instance' => bin2hex(random_bytes(16)),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->atomicWrite($lockPath, $lockData . PHP_EOL, 0600);

            return $admin;
        } catch (Throwable $e) {
            if ($configWritten && is_file($configPath)) {
                @unlink($configPath);
            }
            if (is_file($lockPath)) {
                @unlink($lockPath);
            }
            if ($schemaStarted) {
                $this->cleanupSchema($pdo);
            }

            error_log('Installer transaction failure: ' . $e->getMessage());
            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException('Installation could not be completed.');
        }
    }

    /** @param array{host:string,port:int,name:string,user:string,pass:string} $dbConfig */
    public function testDatabase(array $dbConfig): void
    {
        $pdo = Database::connect($dbConfig);
        $statement = $pdo->query('SELECT 1 AS ok');
        $row = $statement->fetch();
        if (!is_array($row) || (int) ($row['ok'] ?? 0) !== 1) {
            throw new RuntimeException('Database connection test failed.');
        }
    }

    private function assertFreshDatabase(PDO $pdo): void
    {
        $placeholders = implode(',', array_fill(0, count(self::TABLES), '?'));
        $statement = $pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (' . $placeholders . ')'
        );
        $statement->execute(self::TABLES);
        $row = $statement->fetch();
        $count = is_array($row) ? (int) ($row['total'] ?? 0) : 0;

        if ($count > 0) {
            throw new RuntimeException('Database already contains application tables. Use an empty database for a fresh install.');
        }
    }

    private function applySchema(PDO $pdo): void
    {
        $schemaPath = $this->rootPath . '/sql/schema.sql';
        $sql = file_get_contents($schemaPath);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Database schema is unavailable.');
        }

        $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($sql));
        if (!is_array($statements)) {
            throw new RuntimeException('Database schema could not be parsed.');
        }

        foreach ($statements as $statementSql) {
            $statementSql = trim($statementSql);
            if ($statementSql === '') {
                continue;
            }
            $pdo->exec($statementSql);
        }
    }

    private function cleanupSchema(PDO $pdo): void
    {
        foreach (self::TABLES as $table) {
            try {
                $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
            } catch (Throwable $cleanupError) {
                error_log('Installer cleanup failure: ' . $cleanupError->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function atomicWritePhpConfig(string $path, array $config): void
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . var_export($config, true)
            . ";\n";
        $this->atomicWrite($path, $content, 0600);
    }

    private function atomicWrite(string $path, string $content, int $mode): void
    {
        $directory = dirname($path);
        $temporaryPath = tempnam($directory, '.install-');
        if (!is_string($temporaryPath) || $temporaryPath === '') {
            throw new RuntimeException('Unable to create a temporary configuration file.');
        }

        try {
            $bytes = file_put_contents($temporaryPath, $content, LOCK_EX);
            if (!is_int($bytes) || $bytes !== strlen($content)) {
                throw new RuntimeException('Unable to write application configuration.');
            }
            @chmod($temporaryPath, $mode);
            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException('Unable to activate application configuration.');
            }
            @chmod($path, $mode);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}
