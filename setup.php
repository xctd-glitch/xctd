<?php

declare(strict_types=1);

$configured = is_file(__DIR__ . '/config/private.php') || is_file(__DIR__ . '/config/installed.lock');
header('Location: ' . ($configured ? 'login.php' : 'install/'), true, 302);
exit;
