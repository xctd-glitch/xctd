<?php

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\ReportingPresenter;
use App\ReportingRepository;
use App\Security;
use App\UserRepository;

require dirname(__DIR__) . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);
header('Content-Type: application/json; charset=UTF-8');

/** @param array<string,mixed> $payload */
function reportingRespondJson(int $status, array $payload): never
{
    http_response_code($status);
    try {
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('Reporting JSON encoding failure: ' . $e->getMessage());
        http_response_code(500);
        echo '{"ok":false,"message":"Unable to encode response."}';
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    reportingRespondJson(405, ['ok' => false, 'message' => 'Method not allowed.']);
}
if (Auth::user() === null) {
    reportingRespondJson(401, ['ok' => false, 'message' => 'Authentication required.']);
}

try {
    /** @var array<string,mixed> $config */
    $config = require dirname(__DIR__) . '/config/app.php';
    $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
    $appConfig = is_array($config['app'] ?? null) ? $config['app'] : [];
    $timezone = isset($appConfig['timezone']) && is_string($appConfig['timezone']) ? $appConfig['timezone'] : 'Asia/Jakarta';
    try {
        new DateTimeZone($timezone);
    } catch (Throwable $e) {
        $timezone = 'Asia/Jakarta';
    }

    $pdo = Database::connect($dbConfig);
    $users = new UserRepository($pdo);
    if (!Auth::syncUserForApi($users)) {
        reportingRespondJson(401, ['ok' => false, 'message' => 'Authentication required.']);
    }

    $report = ReportingPresenter::present((new ReportingRepository($pdo))->report(null, $timezone));
    reportingRespondJson(200, ['ok' => true, 'report' => $report]);
} catch (Throwable $e) {
    error_log('Reporting endpoint failure: ' . $e->getMessage());
    reportingRespondJson(500, ['ok' => false, 'message' => 'Reporting is temporarily unavailable.']);
}
