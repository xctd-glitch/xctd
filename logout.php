<?php

declare(strict_types=1);

use App\Auth;
use App\Security;

require __DIR__ . '/src/Autoload.php';

Security::startSession();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!Security::validateCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit;
}

Auth::logout();
header('Location: login.php', true, 302);
exit;
