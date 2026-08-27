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

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
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

    if ($method === 'POST') {
        if (!Auth::isAdmin()) {
            realtimeRespondJson(403, ['ok' => false, 'message' => 'Administrator access required.']);
        }
        if (!Security::validateCsrf(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
            realtimeRespondJson(419, ['ok' => false, 'message' => 'Request validation failed. Reload the page and try again.']);
        }
        if (!isset($_POST['action']) || $_POST['action'] !== 'delete') {
            realtimeRespondJson(422, ['ok' => false, 'message' => 'Invalid action.']);
        }

        $idRaw = $_POST['id'] ?? null;
        $id = is_string($idRaw) && preg_match('/^[1-9]\d{0,18}$/D', $idRaw) === 1 ? (int) $idRaw : 0;
        if ($id <= 0) {
            realtimeRespondJson(422, ['ok' => false, 'message' => 'Invalid transaction record.']);
        }

        (new TransactionRepository($pdo))->delete($id);

        $weeklyService = new WeeklyObligationService($pdo, $timezone);
        $weeklyService->sync();

        realtimeRespondJson(200, [
            'ok' => true,
            'message' => 'Transaction deleted.',
            'id' => $id,
            'summary' => SummaryPresenter::present((new SummaryRepository($pdo))->dashboard(null, $timezone)),
            'weekly' => WeeklyObligationPresenter::present($weeklyService->dashboard()),
        ]);
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
    $safe = ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) && !$e instanceof PDOException;
    realtimeRespondJson($safe ? 422 : 500, [
        'ok' => false,
        'message' => $safe ? $e->getMessage() : 'Realtime synchronization is temporarily unavailable.',
    ]);
}
