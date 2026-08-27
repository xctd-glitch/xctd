<?php

declare(strict_types=1);

use App\Auth;
use App\BankReceiptParser;
use App\Database;
use App\Security;
use App\TeamRepository;
use App\UserRepository;
use App\WeeklyObligationService;

require dirname(__DIR__) . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-transform, max-age=0');

/** @param array<string,mixed> $payload */
function senderOptionsRespondJson(int $status, array $payload): never
{
    http_response_code($status);
    try {
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('Sender options JSON encoding failure: ' . $e->getMessage());
        http_response_code(500);
        echo '{"ok":false,"message":"Unable to encode response."}';
    }
    exit;
}

if (Auth::user() === null) {
    senderOptionsRespondJson(401, ['ok' => false, 'message' => 'Authentication required.']);
}

try {
    /** @var array<string,mixed> $config */
    $config = require dirname(__DIR__) . '/config/app.php';
    $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
    $ocrConfig = is_array($config['ocr'] ?? null) ? $config['ocr'] : [];
    $appConfig = is_array($config['app'] ?? null) ? $config['app'] : [];
    $timezone = isset($appConfig['timezone']) && is_string($appConfig['timezone'])
        ? $appConfig['timezone']
        : 'Asia/Jakarta';

    $pdo = Database::connect($dbConfig);
    $userRepository = new UserRepository($pdo);
    if (!Auth::syncUserForApi($userRepository)) {
        senderOptionsRespondJson(401, ['ok' => false, 'message' => 'Authentication required.']);
    }
    if (!Auth::isAdmin()) {
        senderOptionsRespondJson(403, ['ok' => false, 'message' => 'Administrator access required.']);
    }

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        header('Allow: POST');
        senderOptionsRespondJson(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    if (!Security::validateCsrf(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        senderOptionsRespondJson(419, ['ok' => false, 'message' => 'Request validation failed. Reload the page and try again.']);
    }

    $ocrText = $_POST['ocr_text'] ?? null;
    $maxOcrTextBytes = max(1_000, min((int) ($ocrConfig['max_text_bytes'] ?? 100_000), 250_000));
    if (!is_string($ocrText)) {
        throw new RuntimeException('Browser OCR result is missing.');
    }
    $ocrText = trim($ocrText);
    if ($ocrText === '' || strlen($ocrText) > $maxOcrTextBytes || str_contains($ocrText, "\0")) {
        throw new RuntimeException('Browser OCR result is invalid.');
    }

    $receipt = (new BankReceiptParser())->parse($ocrText);
    $resolution = (new TeamRepository($pdo, $timezone))->resolveActiveSenderOptions($receipt->senderName);

    $weeklyService = new WeeklyObligationService($pdo, $timezone);
    $weeklyService->sync();
    $eligibility = $weeklyService->paymentEligibility();
    $options = [];
    foreach ($resolution['options'] as $option) {
        $id = (int) ($option['id'] ?? 0);
        $state = $eligibility[$id] ?? null;
        if ($id <= 0 || !is_array($state)) {
            continue;
        }
        $status = (string) ($state['current_status'] ?? 'pending');
        $carry = max(0, (int) ($state['outstanding_weeks'] ?? 0));
        if ($status === 'paid' && $carry === 0) {
            continue;
        }
        $options[] = [
            'id' => $id,
            'display_name' => (string) ($option['display_name'] ?? ''),
            'subid' => (string) ($option['alias'] ?? ''),
            'location' => (string) ($option['location'] ?? ''),
            'team' => (string) ($option['team'] ?? ''),
            'current_status' => $status,
            'carry_weeks' => $carry,
        ];
    }

    if ($options === []) {
        throw new RuntimeException('All SUBIDs for this sender are already paid for this week.');
    }

    senderOptionsRespondJson(200, [
        'ok' => true,
        'sender_name' => $resolution['sender_name'],
        'requires_selection' => count($options) > 1,
        'selected_id' => count($options) === 1 ? (int) $options[0]['id'] : null,
        'options' => $options,
    ]);
} catch (Throwable $e) {
    error_log('Sender options endpoint failure: ' . $e->getMessage());
    $safe = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) && !$e instanceof PDOException;
    senderOptionsRespondJson($safe ? 422 : 500, [
        'ok' => false,
        'message' => $safe ? $e->getMessage() : 'Unable to resolve sender SUBIDs.',
    ]);
}
