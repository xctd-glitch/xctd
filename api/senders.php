<?php

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\Security;
use App\TeamRepository;
use App\UserRepository;

require dirname(__DIR__) . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);
header('Content-Type: application/json; charset=UTF-8');

/** @param array<string,mixed> $payload */
function sendersRespondJson(int $status, array $payload): never
{
    http_response_code($status);
    try {
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('Senders JSON encoding failure: ' . $e->getMessage());
        http_response_code(500);
        echo '{"ok":false,"message":"Unable to encode response."}';
    }
    exit;
}


/** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
function sendersPresentRows(array $rows): array
{
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['subid'] = (string) ($row['alias'] ?? '');
        $result[] = $row;
    }

    return $result;
}

if (Auth::user() === null) {
    sendersRespondJson(401, ['ok' => false, 'message' => 'Authentication required.']);
}

try {
    /** @var array<string,mixed> $config */
    $config = require dirname(__DIR__) . '/config/app.php';
    $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
    $appConfig = is_array($config['app'] ?? null) ? $config['app'] : [];
    $timezone = isset($appConfig['timezone']) && is_string($appConfig['timezone']) ? $appConfig['timezone'] : 'Asia/Jakarta';
    $pdo = Database::connect($dbConfig);
    $userRepository = new UserRepository($pdo);
    if (!Auth::syncUserForApi($userRepository)) {
        sendersRespondJson(401, ['ok' => false, 'message' => 'Authentication required.']);
    }
    if (!Auth::isAdmin()) {
        sendersRespondJson(403, ['ok' => false, 'message' => 'Administrator access required.']);
    }

    $repository = new TeamRepository($pdo, $timezone);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        sendersRespondJson(200, ['ok' => true, 'senders' => sendersPresentRows($repository->findAll())]);
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        sendersRespondJson(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }

    if (!Security::validateCsrf(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        sendersRespondJson(419, ['ok' => false, 'message' => 'Request validation failed. Reload the page and try again.']);
    }

    $action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';
    $senderName = isset($_POST['sender_name']) && is_string($_POST['sender_name']) ? $_POST['sender_name'] : '';
    $subid = isset($_POST['subid']) && is_string($_POST['subid']) ? $_POST['subid'] : (isset($_POST['alias']) && is_string($_POST['alias']) ? $_POST['alias'] : '');
    $location = isset($_POST['location']) && is_string($_POST['location']) ? $_POST['location'] : '';
    $team = isset($_POST['team']) && is_string($_POST['team']) ? $_POST['team'] : '';

    if ($action === 'create') {
        $id = $repository->create($senderName, $subid, $location, $team);
        sendersRespondJson(201, ['ok' => true, 'message' => 'Sender added.', 'id' => $id, 'senders' => $repository->findAll()]);
    }

    $idRaw = $_POST['sender_id'] ?? null;
    $id = is_string($idRaw) && preg_match('/^[1-9]\d{0,18}$/D', $idRaw) === 1 ? (int) $idRaw : 0;
    if ($id <= 0) {
        sendersRespondJson(422, ['ok' => false, 'message' => 'Invalid sender record.']);
    }

    if ($action === 'update') {
        $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1';
        $repository->update($id, $senderName, $subid, $location, $team, $isActive);
        sendersRespondJson(200, ['ok' => true, 'message' => 'Sender updated.', 'senders' => $repository->findAll()]);
    }

    if ($action === 'delete') {
        $repository->delete($id);
        sendersRespondJson(200, ['ok' => true, 'message' => 'Sender deleted.', 'senders' => $repository->findAll()]);
    }

    sendersRespondJson(422, ['ok' => false, 'message' => 'Invalid action.']);
} catch (Throwable $e) {
    error_log('Senders endpoint failure: ' . $e->getMessage());
    $safe = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) && !$e instanceof PDOException;
    sendersRespondJson($safe ? 422 : 500, [
        'ok' => false,
        'message' => $safe ? $e->getMessage() : 'Unable to update senders.',
    ]);
}
