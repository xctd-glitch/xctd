<?php

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\Security;
use App\SummaryPresenter;
use App\SummaryRepository;
use App\TransactionPresenter;
use App\TransactionRepository;
use App\UserRepository;
use App\WeeklyObligationPresenter;
use App\WeeklyObligationService;

require dirname(__DIR__) . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);
header('Content-Type: application/json; charset=UTF-8');

/** @param array<string,mixed> $payload */
function realtimeRespondJson(int $status, array $payload): never
{
    http_response_code($status);
    try {
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('Realtime JSON encoding failure: ' . $e->getMessage());
        http_response_code(500);
        echo '{"ok":false,"message":"Unable to encode response."}';
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    realtimeRespondJson(405, ['ok' => false, 'message' => 'Method not allowed.']);
}
if (Auth::user() === null) {
    realtimeRespondJson(401, ['ok' => false, 'message' => 'Authentication required.']);
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
    $userRepository = new UserRepository($pdo);
    if (!Auth::syncUserForApi($userRepository)) {
        realtimeRespondJson(401, ['ok' => false, 'message' => 'Authentication required.']);
    }

    $afterIdRaw = $_GET['after_id'] ?? '0';
    $maxInt = (string) PHP_INT_MAX;
    $cursorTooLarge = is_string($afterIdRaw)
        && (strlen($afterIdRaw) > strlen($maxInt)
            || (strlen($afterIdRaw) === strlen($maxInt) && strcmp($afterIdRaw, $maxInt) > 0));
    if (!is_string($afterIdRaw) || preg_match('/^\d{1,19}$/D', $afterIdRaw) !== 1 || $cursorTooLarge) {
        realtimeRespondJson(422, ['ok' => false, 'message' => 'Invalid synchronization cursor.']);
    }

    $rows = (new TransactionRepository($pdo))->findAfterId((int) $afterIdRaw, 100);
    $transactions = [];
    foreach ($rows as $row) {
        $transactions[] = TransactionPresenter::present($row);
    }

    $payload = ['ok' => true, 'transactions' => $transactions];
    $includeSummary = isset($_GET['include_summary']) && $_GET['include_summary'] === '1';
    // Aggregate and weekly obligation queries run on new rows and on a low-frequency client refresh.
    if ($transactions !== [] || $includeSummary) {
        $payload['summary'] = SummaryPresenter::present((new SummaryRepository($pdo))->dashboard(null, $timezone));
        $weeklyService = new WeeklyObligationService($pdo, $timezone);
        $weeklyService->sync();
        $payload['weekly'] = WeeklyObligationPresenter::present($weeklyService->dashboard());
    }
    realtimeRespondJson(200, $payload);
} catch (Throwable $e) {
    error_log('Realtime endpoint failure: ' . $e->getMessage());
    realtimeRespondJson(500, ['ok' => false, 'message' => 'Realtime synchronization is temporarily unavailable.']);
}
