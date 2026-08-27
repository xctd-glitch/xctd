<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class UserRepository
{
    /** Shared by the repository and by the minlength attribute on every password form. */
    public const MIN_PASSWORD_LENGTH = 12;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:int,username:string,role:string}|null */
    public function authenticate(string $username, string $password): ?array
    {
        $username = self::normalizeUsername($username);
        if ($username === '' || $password === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, username, password_hash, role
             FROM users
             WHERE username = :username AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();

        if (!is_array($row) || !isset($row['password_hash']) || !is_string($row['password_hash'])) {
            password_verify($password, '$2y$12$spGav01819W6igAr6HTp7egvSRRs4ysJ2Qk1LurDPZnR.XkIVktAm');
            return null;
        }

        if (!password_verify($password, $row['password_hash'])) {
            return null;
        }

        $id = isset($row['id']) ? (int) $row['id'] : 0;
        $storedUsername = $row['username'] ?? null;
        $role = $row['role'] ?? null;

        if ($id <= 0 || !is_string($storedUsername) || !in_array($role, ['admin', 'user'], true)) {
            return null;
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            if (is_string($newHash)) {
                $update = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                $update->execute(['password_hash' => $newHash, 'id' => $id]);
            }
        }

        $updateLogin = $this->pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
        $updateLogin->execute(['id' => $id]);

        return [
            'id' => $id,
            'username' => $storedUsername,
            'role' => $role,
        ];
    }


    /** @return array{id:int,username:string,role:string}|null */
    public function findActiveById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, username, role FROM users WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $username = $row['username'] ?? null;
        $role = $row['role'] ?? null;
        if (!is_string($username) || !in_array($role, ['admin', 'user'], true)) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => $username,
            'role' => $role,
        ];
    }

    public function countUsers(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) AS total FROM users');
        $row = $statement->fetch();

        return is_array($row) && isset($row['total']) ? (int) $row['total'] : 0;
    }

    public function create(string $username, string $password, string $role): int
    {
        $username = self::validateUsername($username);
        self::validatePassword($password);
        self::validateRole($role);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Unable to secure the password.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, role, is_active)
             VALUES (:username, :password_hash, :role, 1)'
        );
        try {
            $statement->execute([
                'username' => $username,
                'password_hash' => $hash,
                'role' => $role,
            ]);
        } catch (PDOException $e) {
            // Callers classify PDOException as unsafe and replace it with a generic
            // message, so a taken username surfaced as "Unable to update users."
            // Only the username is unique on this table, so 1062 has one meaning.
            if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
                throw new RuntimeException('That username is already taken.');
            }

            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array{id:int,username:string,role:string,is_active:int,last_login_at:?string,created_at:string}> */
    public function findAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, username, role, is_active, last_login_at, created_at
             FROM users
             ORDER BY username ASC'
        );

        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'username' => (string) ($row['username'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
                'is_active' => (int) ($row['is_active'] ?? 0),
                'last_login_at' => isset($row['last_login_at']) && is_string($row['last_login_at'])
                    ? $row['last_login_at']
                    : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $result;
    }

    public function updateAccess(int $id, string $role, bool $isActive, int $actorId): void
    {
        if ($id <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('Invalid user.');
        }

        if ($id === $actorId) {
            throw new RuntimeException('You cannot change your own role or active status.');
        }

        self::validateRole($role);

        $statement = $this->pdo->prepare(
            'UPDATE users SET role = :role, is_active = :is_active WHERE id = :id'
        );
        $statement->execute([
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
            'id' => $id,
        ]);

        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
            $exists->execute(['id' => $id]);
            if ($exists->fetch() === false) {
                throw new RuntimeException('User not found.');
            }
        }
    }

    public function resetPassword(int $id, string $password): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid user.');
        }

        self::validatePassword($password);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Unable to secure the password.');
        }

        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $statement->execute([
            'password_hash' => $hash,
            'id' => $id,
        ]);

        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
            $exists->execute(['id' => $id]);
            if ($exists->fetch() === false) {
                throw new RuntimeException('User not found.');
            }
        }
    }

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    private static function validateUsername(string $username): string
    {
        $username = self::normalizeUsername($username);

        if (preg_match('/^[a-z0-9._-]{3,50}$/D', $username) !== 1) {
            throw new InvalidArgumentException('Username must be 3-50 characters using letters, numbers, dot, underscore, or hyphen.');
        }

        return $username;
    }

    /**
     * Length is the only rule on purpose: composition rules (mixed case, symbols)
     * push people towards predictable substitutions without adding real entropy.
     * The floor applies to create() and resetPassword() only, so accounts that
     * predate it keep working until their password is next changed.
     */
    private static function validatePassword(string $password): void
    {
        $length = strlen($password);
        if ($length < self::MIN_PASSWORD_LENGTH || $length > 128) {
            throw new InvalidArgumentException(
                'Password must be ' . self::MIN_PASSWORD_LENGTH . '-128 characters.'
            );
        }
    }

    private static function validateRole(string $role): void
    {
        if (!in_array($role, ['admin', 'user'], true)) {
            throw new InvalidArgumentException('Invalid role.');
        }
    }
}
