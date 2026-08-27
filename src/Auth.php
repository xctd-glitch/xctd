<?php

declare(strict_types=1);

namespace App;

final class Auth
{
    private const SESSION_KEY = 'auth_user';

    /** @param array{id:int,username:string,role:string} $user */
    public static function login(array $user): void
    {
        if (!in_array($user['role'], ['admin', 'user'], true)) {
            return;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ];
        $_SESSION['auth_login_at'] = time();
        $_SESSION['auth_last_activity'] = time();
        unset($_SESSION['csrf_token']);
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => 'Strict',
            ]);
        }

        session_destroy();
    }

    /** @return array{id:int,username:string,role:string}|null */
    public static function user(): ?array
    {
        $user = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($user)) {
            return null;
        }

        $id = $user['id'] ?? null;
        $username = $user['username'] ?? null;
        $role = $user['role'] ?? null;

        if (!is_int($id) || $id <= 0 || !is_string($username) || !in_array($role, ['admin', 'user'], true)) {
            return null;
        }

        $_SESSION['auth_last_activity'] = time();

        return [
            'id' => $id,
            'username' => $username,
            'role' => $role,
        ];
    }

    public static function syncUser(UserRepository $repository): void
    {
        $sessionUser = self::user();
        if ($sessionUser === null) {
            self::requireLogin();
        }

        $freshUser = $repository->findActiveById($sessionUser['id']);
        if ($freshUser === null) {
            self::logout();
            header('Location: login.php', true, 302);
            exit;
        }

        $_SESSION[self::SESSION_KEY] = $freshUser;
    }

    public static function syncUserForApi(UserRepository $repository): bool
    {
        $sessionUser = self::user();
        if ($sessionUser === null) {
            return false;
        }

        $freshUser = $repository->findActiveById($sessionUser['id']);
        if ($freshUser === null) {
            self::logout();
            return false;
        }

        $_SESSION[self::SESSION_KEY] = $freshUser;

        return true;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();

        return is_array($user) && $user['role'] === 'admin';
    }

    public static function requireLogin(): void
    {
        if (self::user() !== null) {
            return;
        }

        header('Location: login.php', true, 302);
        exit;
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();

        if (self::isAdmin()) {
            return;
        }

        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Forbidden';
        exit;
    }
}
