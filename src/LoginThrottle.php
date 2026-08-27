<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Durable sign-in throttling.
 *
 * Failed attempts used to live in $_SESSION only, so discarding the session
 * cookie reset the counter and made the limit decorative. Attempts are now
 * counted server-side per source address, which a client cannot reset.
 *
 * Counting is deliberately per-address and not per-username: a username-keyed
 * lockout would let an attacker lock the real administrator out on demand.
 * The username is recorded for forensics only.
 *
 * All windows are evaluated against the database clock so the application and
 * MySQL timezones cannot drift apart.
 */
final class LoginThrottle
{
    /** Failures tolerated per source address inside WINDOW_SECONDS. */
    private const MAX_ATTEMPTS = 20;

    /** Sliding window, and the block length once MAX_ATTEMPTS is reached. */
    private const WINDOW_SECONDS = 900;

    /** Attempts older than this are pruned; nothing reads them. */
    private const RETENTION_SECONDS = 3600;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Packed binary form of the caller's address, or null when it cannot be
     * determined (CLI, malformed REMOTE_ADDR) and throttling must be skipped.
     *
     * $trustedHeader is opt-in: behind a reverse proxy every request otherwise
     * carries the proxy's address and would share one bucket. It must stay
     * unset unless the deployment guarantees the header cannot be spoofed by
     * the client, because trusting it blindly reinstates the bypass.
     */
    public static function clientAddress(?string $trustedHeader = null): ?string
    {
        $candidates = [];

        if ($trustedHeader !== null && $trustedHeader !== '') {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $trustedHeader));
            $raw = isset($_SERVER[$key]) && is_string($_SERVER[$key]) ? $_SERVER[$key] : '';
            foreach (explode(',', $raw) as $part) {
                $candidates[] = trim($part);
            }
        }

        $candidates[] = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '';

        foreach ($candidates as $candidate) {
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $packed = @inet_pton($candidate);
            if (is_string($packed)) {
                return $packed;
            }
        }

        return null;
    }

    /** Seconds the address must wait, or 0 when it may attempt a sign-in. */
    public function retryAfter(string $address): int
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*) AS failures,
                    COALESCE(TIMESTAMPDIFF(SECOND, NOW(), MIN(attempted_at) + INTERVAL %d SECOND), 0) AS retry_after
             FROM login_attempts
             WHERE ip_address = :ip AND attempted_at > NOW() - INTERVAL %d SECOND',
            self::WINDOW_SECONDS,
            self::WINDOW_SECONDS
        ));
        $statement->execute(['ip' => $address]);
        $row = $statement->fetch();

        if (!is_array($row) || (int) ($row['failures'] ?? 0) < self::MAX_ATTEMPTS) {
            return 0;
        }

        return max(1, (int) ($row['retry_after'] ?? 0));
    }

    public function recordFailure(string $address, string $username): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (ip_address, username) VALUES (:ip, :username)'
        );
        $statement->execute([
            'ip' => $address,
            'username' => substr($username, 0, 50),
        ]);

        $this->prune();
    }

    /** Clears the address's history after a successful sign-in. */
    public function clear(string $address): void
    {
        $statement = $this->pdo->prepare('DELETE FROM login_attempts WHERE ip_address = :ip');
        $statement->execute(['ip' => $address]);
    }

    public function prune(): void
    {
        $this->pdo->exec(sprintf(
            'DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL %d SECOND',
            self::RETENTION_SECONDS
        ));
    }
}
