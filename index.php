<?php

declare(strict_types=1);

use App\Auth;
use App\BankReceiptParser;
use App\Database;
use App\MoneyFormatter;
use App\PaymentCalculator;
use App\PwaManifest;
use App\ReceiptData;
use App\Security;
use App\SummaryPresenter;
use App\SummaryRepository;
use App\TeamRepository;
use App\TransactionPresenter;
use App\TransactionRepository;
use App\UploadService;
use App\UserRepository;
use App\WeeklyObligationPresenter;
use App\WeeklyObligationService;

require __DIR__ . '/src/Autoload.php';

if (PwaManifest::isRequest()) {
    PwaManifest::respond(__DIR__);
}

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);
Auth::requireLogin();
$currentUser = Auth::user();
$isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$errorMessage = null;
$successMessage = isset($_SESSION['flash_success']) && is_string($_SESSION['flash_success'])
    ? $_SESSION['flash_success']
    : null;
unset($_SESSION['flash_success']);

/** @param array<string,mixed> $payload */
function dashboardRespondJson(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    try {
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('Dashboard JSON encoding failure: ' . $e->getMessage());
        http_response_code(500);
        echo '{"ok":false,"message":"Unable to encode response."}';
    }
    exit;
}

/** @param array<string,int|string|null> $row */
function dashboardRespondDuplicateReceipt(
    bool $isAjaxRequest,
    array $row,
    array $summary,
    array $weekly
): never {
    if ($isAjaxRequest) {
        dashboardRespondJson(200, [
            'ok' => true,
            'duplicate' => true,
            'message' => 'Receipt already saved.',
            'transaction' => TransactionPresenter::present($row),
            'summary' => $summary,
            'weekly' => $weekly,
        ]);
    }

    $_SESSION['flash_success'] = 'Receipt already saved.';
    header('Location: index.php', true, 303);
    exit;
}

try {
    /** @var array<string,mixed> $config */
    $config = require __DIR__ . '/config/app.php';
    $uploadConfig = is_array($config['upload'] ?? null) ? $config['upload'] : [];
    $ocrConfig = is_array($config['ocr'] ?? null) ? $config['ocr'] : [];
    $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
    $realtimeConfig = is_array($config['realtime'] ?? null) ? $config['realtime'] : [];
    $appConfig = is_array($config['app'] ?? null) ? $config['app'] : [];
    $timezone = isset($appConfig['timezone']) && is_string($appConfig['timezone']) ? $appConfig['timezone'] : 'Asia/Jakarta';
    try {
        new DateTimeZone($timezone);
    } catch (Throwable $e) {
        $timezone = 'Asia/Jakarta';
    }

    $pollMs = max(1000, min((int) ($realtimeConfig['poll_ms'] ?? 2500), 60000));
    $hiddenPollMs = max($pollMs, min((int) ($realtimeConfig['hidden_poll_ms'] ?? 10000), 120000));
    $maxRows = max(1, min((int) ($realtimeConfig['max_rows'] ?? 200), 500));

    $pdo = Database::connect($dbConfig);
    $userRepository = new UserRepository($pdo);
    Auth::syncUser($userRepository);
    $currentUser = Auth::user();
    $isAdmin = Auth::isAdmin();
    $transactionRepository = new TransactionRepository($pdo);
    $summaryRepository = new SummaryRepository($pdo);
    $weeklyService = new WeeklyObligationService($pdo, $timezone);
    $weeklyService->sync();
    $weekly = WeeklyObligationPresenter::present($weeklyService->dashboard());

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Auth::requireAdmin();
        if (!Security::validateCsrf(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
            http_response_code(419);
            throw new RuntimeException('Request validation failed. Reload the page and try again.');
        }

        $upload = null;
        try {
            $uploadService = new UploadService(
                __DIR__ . '/storage/uploads',
                (int) ($uploadConfig['max_bytes'] ?? 8 * 1024 * 1024),
                (int) ($uploadConfig['max_pixels'] ?? 40_000_000)
            );
            $file = $_FILES['receipt'] ?? null;
            if (!is_array($file)) {
                throw new RuntimeException('Please select a receipt image.');
            }
            $upload = $uploadService->receive($file);

            // exit() does not run finally, and every success path below exits: the
            // AJAX 201, the 303 redirect, and dashboardRespondDuplicateReceipt().
            // The finally block therefore only ever fired on throw, so a validated
            // image survived in storage/uploads on each successful save. A shutdown
            // handler is the one cleanup that outlives exit; the is_file() guard
            // keeps it idempotent with the finally below.
            $uploadPath = $upload->path;
            register_shutdown_function(static function () use ($uploadPath): void {
                if (is_file($uploadPath)) {
                    @unlink($uploadPath);
                }
            });

            $existingRow = $transactionRepository->findByImageSha256($upload->sha256);
            if ($existingRow !== null) {
                dashboardRespondDuplicateReceipt(
                    $isAjaxRequest,
                    $existingRow,
                    SummaryPresenter::present($summaryRepository->dashboard(null, $timezone)),
                    $weekly
                );
            }

            $ocrText = $_POST['ocr_text'] ?? null;
            $maxOcrTextBytes = max(1_000, min((int) ($ocrConfig['max_text_bytes'] ?? 100_000), 250_000));
            if (!is_string($ocrText)) {
                throw new RuntimeException('Browser OCR result is missing. Reload the page and try again.');
            }
            $ocrText = trim($ocrText);
            if ($ocrText === '' || strlen($ocrText) > $maxOcrTextBytes || str_contains($ocrText, "\0")) {
                throw new RuntimeException('Browser OCR result is invalid.');
            }

            $receipt = (new BankReceiptParser())->parse($ocrText);
            $selectedSenderIdRaw = $_POST['selected_sender_id'] ?? null;
            $selectedSenderId = null;
            if ($selectedSenderIdRaw !== null && $selectedSenderIdRaw !== '') {
                if (!is_string($selectedSenderIdRaw) || preg_match('/^[1-9]\d{0,18}$/D', $selectedSenderIdRaw) !== 1) {
                    throw new RuntimeException('Selected SUBID is invalid. Transaction rejected.');
                }
                $selectedSenderId = (int) $selectedSenderIdRaw;
            }

            // Database membership is authoritative. For duplicate sender names the browser must select
            // one active SUBID first; the server revalidates that selected record against OCR sender data.
            $teamRepository = new TeamRepository($pdo, $timezone);
            if ($receipt->senderName !== '') {
                $sender = $teamRepository->findActiveSender($receipt->senderName, $selectedSenderId);
            } elseif ($receipt->sourceBankCode !== null && $receipt->sourceAccountMask !== null) {
                // No sender name was printed on the receipt (e.g. myBCA's own "Transfer
                // Berhasil" screen); fall back to matching the masked source account
                // against a registered account number instead.
                $sender = $teamRepository->findActiveSenderByAccount($receipt->sourceBankCode, $receipt->sourceAccountMask, $selectedSenderId);
            } else {
                throw new RuntimeException('Sender could not be identified from this receipt.');
            }
            $teamMemberId = (int) $sender['id'];
            $lockName = 'receipt-subid-' . (string) $teamMemberId;
            $lockStatement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
            $lockStatement->execute(['lock_name' => $lockName]);
            if ((int) $lockStatement->fetchColumn() !== 1) {
                throw new RuntimeException('SUBID is busy. Please retry.');
            }

            try {
                $weeklyService->sync();
                if (!$weeklyService->canAcceptPayment($teamMemberId)) {
                    throw new RuntimeException('SUBID is already paid for this week.');
                }

                $team = $sender['team'];
                $canonicalReceipt = new ReceiptData(
                $sender['display_name'],
                $receipt->sourceAccountLast4,
                $receipt->originalAmount,
                $receipt->referenceNo,
                $receipt->receiptDate,
                $receipt->receiptTime
            );
            $adjustedAmount = (new PaymentCalculator())->adjustedAmount($canonicalReceipt->originalAmount, $team);
            try {
                $transactionId = $transactionRepository->save(
                    $canonicalReceipt,
                    $teamMemberId,
                    (string) $sender['alias'],
                    $team,
                    $adjustedAmount,
                    $upload->sha256
                );
            } catch (Throwable $e) {
                if (!TransactionRepository::isDuplicateKeyException($e)) {
                    throw $e;
                }

                $existingRow = $transactionRepository->findByImageSha256($upload->sha256);
                if ($existingRow === null) {
                    throw $e;
                }

                // dashboardRespondDuplicateReceipt() exits and exit() skips the
                // finally below, so the named lock must be handed back here. No
                // mutation is left under the lock at this point: the insert already
                // failed on the duplicate key and only the existing row is reported.
                // RELEASE_LOCK on a name this session no longer holds is a no-op,
                // so this stays safe next to the finally.
                $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releaseStatement->execute(['lock_name' => $lockName]);

                dashboardRespondDuplicateReceipt(
                    $isAjaxRequest,
                    $existingRow,
                    SummaryPresenter::present($summaryRepository->dashboard(null, $timezone)),
                    $weekly
                );
            }

                $weeklyService->sync();
                $weekly = WeeklyObligationPresenter::present($weeklyService->dashboard());
            } finally {
                $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releaseStatement->execute(['lock_name' => $lockName]);
            }

            $message = sprintf(
                '%s %s %s',
                $canonicalReceipt->senderName,
                $team,
                MoneyFormatter::formatIdr($adjustedAmount)
            );

            if ($isAjaxRequest) {
                $savedRow = $transactionRepository->findById($transactionId);
                if ($savedRow === null) {
                    throw new RuntimeException('Transaction was saved but could not be reloaded.');
                }
                dashboardRespondJson(201, [
                    'ok' => true,
                    'message' => $message,
                    'transaction' => TransactionPresenter::present($savedRow),
                    'summary' => SummaryPresenter::present($summaryRepository->dashboard(null, $timezone)),
                    'weekly' => $weekly,
                ]);
            }

            $_SESSION['flash_success'] = $message;
            header('Location: index.php', true, 303);
            exit;
        } finally {
            if ($upload !== null && is_file($upload->path)) {
                @unlink($upload->path);
            }
        }
    }

    $transactions = $transactionRepository->findRecent($maxRows);
    $summary = SummaryPresenter::present($summaryRepository->dashboard(null, $timezone));
} catch (Throwable $e) {
    error_log('Application failure: ' . $e->getMessage());
    $errorMessage = ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) && !$e instanceof PDOException
        ? $e->getMessage()
        : 'Unable to load the application data.';

    if ($isAjaxRequest && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
        $status = http_response_code();
        if ($status < 400) {
            $status = ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) && !$e instanceof PDOException ? 422 : 500;
        }
        dashboardRespondJson($status, ['ok' => false, 'message' => $errorMessage]);
    }

    $transactions = [];
    $summary = [
        'week' => ['label' => 'Current week', 'total' => 'IDR 0', 'count' => 0, 'teams' => ['XCTD' => 'IDR 0', 'MNX' => 'IDR 0']],
        'month' => ['label' => 'Current month', 'total' => 'IDR 0', 'count' => 0, 'teams' => ['XCTD' => 'IDR 0', 'MNX' => 'IDR 0']],
        'year' => ['label' => 'Current year', 'total' => 'IDR 0', 'count' => 0, 'teams' => ['XCTD' => 'IDR 0', 'MNX' => 'IDR 0']],
        'all' => ['label' => 'All time', 'total' => 'IDR 0', 'count' => 0, 'teams' => ['XCTD' => 'IDR 0', 'MNX' => 'IDR 0']],
    ];
    $weekly = [
        'label' => 'Current week',
        'week_start' => '',
        'week_end' => '',
        'paid' => 0,
        'pending' => 0,
        'outstanding_senders' => 0,
        'outstanding_weeks' => 0,
        'rows' => [],
    ];
    $pollMs = 2500;
    $hiddenPollMs = 10000;
    $maxRows = 200;
}

$csrfToken = Security::csrfToken();
$lastId = isset($transactions[0]['id']) ? (int) $transactions[0]['id'] : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f8fafc">
    <meta name="color-scheme" content="light">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="index.php?asset=app-meta">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/icons/apple-touch-icon.png">
    <title>Bank Receipt Extractor</title>
    <style nonce="<?= Security::e($nonce) ?>">        /* v1.9.2 slim-fit density */        :root{
            color-scheme:light;
            --bg:#f1f5f9;
            --panel:rgba(255,255,255,.80);
            --panel-strong:rgba(255,255,255,.96);
            --surface:rgba(248,250,252,.90);
            --line:rgba(148,163,184,.30);
            --line-strong:#cbd5e1;
            --text:#0f172a;
            --muted:#64748b;
            --soft:#94a3b8;
            --accent:#2563eb;
            --accent-hover:#1d4ed8;
            --accent-soft:#eff6ff;
            --danger:#dc2626;
            --danger-soft:#fef2f2;
            --ok:#059669;
            --ok-soft:#ecfdf5;
            --warn:#d97706;
            --warn-soft:#fffbeb;
            --radius:.3rem;
        }

        *,*::before,*::after{box-sizing:border-box}
        *{scrollbar-width:none;-ms-overflow-style:none}
        *::-webkit-scrollbar{display:none;width:0;height:0}
        html{-webkit-text-size-adjust:100%;min-height:100%;background:var(--bg)}

        body{
            margin:0;
            min-height:100dvh;
            padding:max(10px,env(safe-area-inset-top)) max(8px,env(safe-area-inset-right)) max(14px,env(safe-area-inset-bottom)) max(8px,env(safe-area-inset-left));
            overflow-x:hidden;
            background:
                radial-gradient(circle at 12% 5%,rgba(37,99,235,.075),transparent 30%),
                radial-gradient(circle at 88% 92%,rgba(100,116,139,.09),transparent 32%),
                linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);
            color:var(--text);
            font-family:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Monaco,Consolas,"Liberation Mono",monospace;
            -webkit-font-smoothing:antialiased;
            -webkit-tap-highlight-color:transparent;
        }

        button,input{font:inherit}
        button,a,input{-webkit-tap-highlight-color:transparent}
        button:focus-visible,a:focus-visible,input:focus-visible{outline:1px solid rgba(37,99,235,0);outline-offset:1px}

        .wrap{width:100%;max-width:1180px;margin:0 auto}

        /* v1.9.3 harmonized top */
        .top{
            display:grid;
            grid-template-columns:minmax(0,1fr) auto;
            align-items:center;
            gap:7px;
            min-height:42px;
            margin:0 0 6px;
            padding:7px;
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:var(--panel);
            box-shadow:0 10px 28px rgba(15,23,42,.055),inset 0 1px 0 rgba(255,255,255,.90);
            backdrop-filter:blur(20px) saturate(140%);
            -webkit-backdrop-filter:blur(20px) saturate(140%);
        }

        .brand{display:flex;align-items:center;gap:8px;min-width:0}
        .brand-icon{
            width:34px;
            height:34px;
            flex:0 0 34px;
            display:block;
            padding:3px;
            object-fit:contain;
        }

        .brand>div{min-width:0}
        .title{margin:0;font-size:.92rem;font-weight:800;line-height:1.2;letter-spacing:-.025em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .sub{display:block;margin:2px 0 0;color:var(--muted);font-size:.57rem;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

        .top-actions{display:flex;align-items:center;gap:5px;min-width:0}
        .logout-form{margin:0}
        .role{
            display:none;
            align-items:center;
            min-height:29px;
            max-width:210px;
            padding:5px 7px;
            overflow:hidden;
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:rgba(255,255,255,.70);
            color:var(--muted);
            font-size:.59rem;
            white-space:nowrap;
            text-overflow:ellipsis;
        }

        .link,.logout,.install-btn,.tab,.alias-cancel,.alias-option{
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:rgba(255,255,255,.84);
            color:var(--text);
            font:inherit;
            text-decoration:none;
            cursor:pointer;
            transition:background .15s ease,border-color .15s ease,color .15s ease,box-shadow .15s ease,transform .15s ease;
        }

        .link,.logout,.install-btn{min-height:29px;padding:5px 8px;font-size:.61rem;white-space:nowrap}
        .link:hover,.logout:hover,.install-btn:hover,.alias-cancel:hover{border-color:var(--line-strong);background:#fff}
        .logout:active,.install-btn:active,.primary:active,.alias-cancel:active,.alias-option:active{transform:translateY(1px)}
        .install-btn[hidden]{display:none}

        /* v1.9.4 equal-width navigation tabs */
        .tabs{
            display:flex;
            width:100%;
            gap:4px;
            margin:0 0 7px;
            padding:4px;
            overflow:visible;
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:var(--panel);
            box-shadow:0 6px 18px rgba(15,23,42,.035),inset 0 1px 0 rgba(255,255,255,.84);
            backdrop-filter:blur(18px) saturate(135%);
            -webkit-backdrop-filter:blur(18px) saturate(135%);
        }

        .tab{
            flex:1 1 0;
            display:flex;
            align-items:center;
            justify-content:center;
            min-width:0;
            min-height:31px;
            padding:5px 7px;
            border-color:transparent;
            background:transparent;
            color:var(--text);
            font-size:.73rem;
            font-weight:900;
            line-height:1.2;
            letter-spacing:-.025em;
            text-align:center;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            text-transform:uppercase;
        }
        .tab:hover{background:rgba(255,255,255,.72);color:var(--text)}
        .tab.active{border-color:rgba(37,99,235,.18);background:var(--accent-soft);color:var(--accent);box-shadow:inset 0 1px 0 rgba(255,255,255,.86)}

        .card,.summary{
            position:relative;
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:var(--panel);
            box-shadow:0 10px 30px rgba(15,23,42,.05),inset 0 1px 0 rgba(255,255,255,.86);
            backdrop-filter:blur(18px) saturate(135%);
            -webkit-backdrop-filter:blur(18px) saturate(135%);
        }

        .card{padding:8px;margin-bottom:7px;box-shadow: inset 0 0 5px var(--accent-soft), 0 0 0 0.2px var(--line), 0 10px 30px rgba(15,23,42,.05), inset 0 1px 0 rgba(255,255,255,.86)}
        .summary-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:5px;margin-bottom:7px}
        .summary{min-width:0;padding:8px 9px;background:linear-gradient(135deg,rgba(255,255,255,.92),rgba(239,246,255,.68))}
        .summary-label{color:var(--muted);font-size:.55rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .summary-total{margin:3px 0 2px;color:var(--text);font-size:.84rem;font-weight:900;letter-spacing:-.025em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .summary-teams{display:flex;gap:10px;color:var(--muted);font-size:.55rem;white-space:nowrap;overflow:hidden}
        .summary-teams b{color:var(--text);font-weight:800}

        .section-title{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:7px}
        .section-title h2{margin:0;color:var(--text);font-size:.71rem;font-weight:800;letter-spacing:-.015em}
        .meta{display:flex;align-items:center;gap:7px}
        .count,.live-state{color:var(--muted);font-size:.58rem;white-space:nowrap}
        .live-state:before{content:"";display:inline-block;width:6px;height:6px;margin-right:4px;border-radius:50%;background:var(--muted);vertical-align:1px}
        .live-state.live-ok{color:#047857}
        .live-state.live-ok:before{background:var(--ok);box-shadow:0 0 0 3px rgba(16,185,129,.10)}
        .live-state.live-warn{color:#92400e}
        .live-state.live-warn:before{background:var(--warn);box-shadow:0 0 0 3px rgba(217,119,6,.09)}

        .weekly-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:5px;margin-bottom:7px}
        .weekly-metric{min-width:0;padding:6px 7px;border:1px solid rgba(148,163,184,.24);border-radius:var(--radius);background:rgba(248,250,252,.78)}
        .weekly-metric span{display:block;color:var(--muted);font-size:.52rem;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .weekly-metric b{display:block;margin-top:3px;font-size:.75rem;font-weight:900;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

        .weekly-table,.table-wrap{overflow:auto;border:1px solid rgba(148,163,184,.26);border-radius:var(--radius);background:rgba(255,255,255,.82)}
        .weekly-table table{min-width:520px}
        table{width:100%;border-collapse:collapse;table-layout:fixed}
        th,td{padding:6px 5px;border-bottom:1px solid rgba(226,232,240,.82);text-align:left;font-size:.59rem;line-height:1.25;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        th{background:rgba(248,250,252,.72);color:var(--muted);font-size:.51rem;font-weight:700;text-transform:uppercase;letter-spacing:.025em}
        tbody tr:last-child td{border-bottom:0}
        tbody tr:hover td{background:rgba(248,250,252,.72)}
        th:nth-child(1),td:nth-child(1){width:29%}
        th:nth-child(2),td:nth-child(2){width:12%}
        th:nth-child(3),td:nth-child(3){width:20%}
        th:nth-child(4),td:nth-child(4){width:39%}
        .right{text-align:right}
        .receipt-date{display:none}
        .final{color:var(--accent);font-weight:900}
        .empty{padding:14px;color:var(--muted);text-align:center}

        .status-pill{display:inline-flex;align-items:center;min-height:18px;padding:2px 6px;border:1px solid transparent;border-radius:999px;background:#f1f5f9;color:#475569;font-size:.53rem;font-weight:800;line-height:1}
        .status-pill.paid{border-color:#a7f3d0;background:var(--ok-soft);color:#047857}
        .status-pill.pending{border-color:#fde68a;background:var(--warn-soft);color:#92400e}
        .status-pill.disabled{border-color:#e2e8f0;background:#f1f5f9;color:#64748b}
        .carry{color:#b45309;font-weight:900}
        .carry.zero{color:var(--muted);font-weight:600}

        .upload-grid{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px;align-items:end}
        .field{display:grid;gap:3px;color:var(--muted);font-size:.58rem;font-weight:700}
        .file{width:100%;min-width:0;height:32px;padding:4px 6px;border:1px dashed var(--line-strong);border-radius:var(--radius);outline:0;background:rgba(255,255,255,.90);color:var(--muted);font:inherit;font-size:.58rem}
        .file:hover{border-color:#94a3b8;background:#fff}
        .file:focus{border-color:#93c5fd;box-shadow:0 0 0 0px rgba(37,99,235,.08)}

        .primary{min-height:32px;padding:5px 10px;border:1px solid rgba(29,78,216,.16);border-radius:var(--radius);background:var(--accent);box-shadow:0 6px 15px rgba(37,99,235,.17),inset 0 1px 0 rgba(255,255,255,.16);color:#fff;font:inherit;font-size:.62rem;font-weight:900;cursor:pointer;white-space:nowrap;transition:background .15s ease,box-shadow .15s ease,transform .15s ease}
        .primary:hover{background:var(--accent-hover);box-shadow:0 7px 17px rgba(37,99,235,.22),inset 0 1px 0 rgba(255,255,255,.16)}
        .primary:disabled{opacity:.55;cursor:wait}
        .auto-note{margin-top:4px;color:var(--muted);font-size:.55rem}

        .alias-choice{margin-top:7px;padding:7px;border:1px solid #bfdbfe;border-radius:var(--radius);background:rgba(239,246,255,.82)}
        .alias-choice[hidden]{display:none}
        .alias-choice-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}
        .alias-choice-head>div{display:flex;align-items:baseline;gap:6px;min-width:0}
        .alias-choice-head b{font-size:.64rem}
        .alias-choice-head span{color:var(--muted);font-size:.57rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .alias-cancel{padding:4px 7px;color:var(--muted);font-size:.56rem}
        .alias-options{display:flex;flex-wrap:wrap;gap:5px}
        .alias-option{padding:5px 7px;border-color:#93c5fd;background:#fff;color:#1e40af;font-size:.58rem;font-weight:800}
        .alias-option small{margin-left:4px;color:var(--muted);font-size:.51rem;font-weight:600}
        .alias-option:hover,.alias-option:focus-visible{background:#dbeafe}
        .muted{margin:0;color:var(--muted);font-size:.56rem;line-height:1.4}

        .ocr-progress{margin-top:7px}
        .ocr-progress[hidden]{display:none}
        .ocr-progress-head{display:flex;justify-content:space-between;margin-bottom:4px;color:var(--muted);font-size:.57rem}
        .ocr-progress-track{height:3px;overflow:hidden;border-radius:99px;background:#e2e8f0}
        .ocr-progress-track progress{display:block;width:100%;height:3px;border:0;background:#e2e8f0;appearance:none}
        .ocr-progress-track progress::-webkit-progress-bar{background:#e2e8f0}
        .ocr-progress-track progress::-webkit-progress-value{background:var(--accent)}
        .ocr-progress-track progress::-moz-progress-bar{background:var(--accent)}

        .msg{margin-bottom:7px;padding:7px 8px;border:1px solid var(--line);border-radius:var(--radius);background:rgba(255,255,255,.86);box-shadow:0 4px 12px rgba(15,23,42,.035);font-size:.61rem;line-height:1.4;overflow-wrap:anywhere}
        .msg[hidden]{display:none}
        .msg.error{border-color:#fecaca;background:rgba(254,242,242,.92);color:#991b1b}
        .msg.ok{border-color:#a7f3d0;background:rgba(236,253,245,.92);color:#065f46}
        .read-only{margin:0;color:var(--muted);font-size:.61rem;line-height:1.45}

        .toast-stack{position:fixed;left:50%;bottom:max(10px,env(safe-area-inset-bottom));z-index:9999;display:grid;gap:5px;width:min(310px,calc(100vw - 20px));transform:translateX(-50%);pointer-events:none}
        .toast{padding:6px 8px;border:1px solid var(--line);border-radius:var(--radius);background:rgba(255,255,255,.96);box-shadow:0 12px 30px rgba(15,23,42,.14);font-size:.60rem;text-align:center;opacity:0;transform:translateY(5px);transition:opacity .18s ease,transform .18s ease;backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
        .toast-show{opacity:1;transform:none}
        .toast-ok{color:#065f46}
        .toast-error{color:#991b1b}

        @media(max-width:520px){
            .top{padding:6px}
            .brand{gap:6px}
            .brand-icon{width:30px;height:30px;flex-basis:30px}
            .title{font-size:.84rem}
            .sub{font-size:.53rem}
            .top-actions{gap:4px}
            .logout,.install-btn{min-height:27px;padding:4px 6px;font-size:.57rem}
            .tab{min-height:28px;padding:4px 3px;font-size:.56rem}
        }

        @media(max-width:370px){
            .upload-grid{grid-template-columns:1fr}
            .primary{width:100%}
            .summary-total{font-size:.76rem}
            .weekly-metric{padding:5px}
            .weekly-metric span{font-size:.49rem}
            .weekly-metric b{font-size:.67rem}
        }

        @media(min-width:700px){
            body{padding:12px;}
            .top{padding:8px 9px;margin-bottom:7px}
            .brand{gap:9px}
            .brand-icon{width:38px;height:38px;flex-basis:38px;padding:3px}
            .title{font-size:1.04rem}
            .sub{font-size:.59rem}
            .role{display:inline-flex}
            .tabs{margin-bottom:8px}
            .tab{min-height:33px}
            .card{padding:10px;margin-bottom:8px}
            .summary{padding:9px 10px}
            .summary-label{font-size:.59rem}
            .summary-total{font-size:.92rem}
            .summary-teams{font-size:.58rem}
            .section-title h2{font-size:.76rem}
            .weekly-metric{padding:7px 8px}
            .weekly-metric span{font-size:.55rem}
            .weekly-metric b{font-size:.80rem}
            .field{font-size:.62rem}
            .file{height:34px;padding:5px 7px;font-size:.62rem}
            .primary{min-height:34px;padding:6px 11px;font-size:.65rem}
            .table-wrap{overflow:auto}
            table{min-width:700px;table-layout:auto}
            th,td{padding:7px 8px;font-size:.64rem}
            th{font-size:.55rem}
            th:nth-child(n),td:nth-child(n){width:auto}
            .receipt-date{display:table-cell}
            .toast-stack{left:auto;right:14px;width:300px;transform:none}
            .toast{text-align:left}
        }

        /* v1.10.1 pagination */
        .pager{display:flex;align-items:center;justify-content:flex-end;gap:6px;margin-top:6px}
        .pager[hidden]{display:none}
        .pager-info{color:var(--muted);font-size:.56rem;font-weight:700;white-space:nowrap}
        .pager-btn{min-height:27px;padding:4px 9px;border:1px solid var(--line);border-radius:var(--radius);background:rgba(255,255,255,.86);color:var(--text);font:inherit;font-size:.56rem;font-weight:800;cursor:pointer}
        .pager-btn:disabled{opacity:.45;cursor:default}
        .page-hidden{display:none!important}

        /* v1.10.1 PWA gate */
        .pwa-gate{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.55);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}
        .pwa-gate-card{width:100%;max-width:360px;padding:16px 14px;border:1px solid rgba(148,163,184,.30);border-radius:.3rem;background:rgba(255,255,255,.97);box-shadow:0 24px 60px rgba(15,23,42,.25),inset 0 1px 0 rgba(255,255,255,.92);color:#0f172a;text-align:center;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
        .pwa-gate-card h2{margin:0 0 6px;font-size:.95rem;font-weight:900;letter-spacing:-.02em}
        .pwa-gate-card p{margin:0 0 10px;color:#64748b;font-size:.62rem;line-height:1.55}
        .pwa-gate-steps{display:grid;gap:5px;margin:0 0 12px;padding:8px;border:1px solid rgba(148,163,184,.26);border-radius:.3rem;background:rgba(248,250,252,.85);text-align:left;font-size:.59rem;line-height:1.5;color:#334155}
        .pwa-gate-steps b{display:block;margin-bottom:2px;color:#0f172a;font-size:.60rem}
        .pwa-gate-btn{display:block;width:100%;min-height:34px;margin-bottom:6px;border:1px solid rgba(29,78,216,.16);border-radius:.3rem;background:#2563eb;color:#fff;font:inherit;font-size:.64rem;font-weight:800;cursor:pointer}
        .pwa-gate-dismiss{display:inline-block;color:#64748b;font-size:.56rem;text-decoration:underline;cursor:pointer;background:none;border:0;font:inherit;padding:0}
        body.pwa-gate-locked{overflow:hidden}

        @media(prefers-reduced-motion:reduce){
            .link,.logout,.install-btn,.tab,.alias-cancel,.alias-option,.primary,.toast{transition:none}
        }
    </style>
</head>
<body
    data-realtime-endpoint="api/transactions.php"
    data-sender-options-endpoint="api/sender-options.php"
    data-last-id="<?= $lastId ?>"
    data-poll-ms="<?= $pollMs ?>"
    data-hidden-poll-ms="<?= $hiddenPollMs ?>"
    data-max-rows="<?= $maxRows ?>"
    data-ocr-language="eng"
    data-ocr-worker="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/worker.min.js"
    data-ocr-core="https://cdn.jsdelivr.net/npm/tesseract.js-core@5.1.1"
    data-ocr-lang="https://cdn.jsdelivr.net/npm/@tesseract.js-data/eng@1.0.0/4.0.0_best_int"
    data-pwa-gate
>
<main class="wrap">
    <header class="top">
        <div class="brand"><img class="brand-icon" src="img.svg" alt=""><div><h1 class="title">Bank Receipt Extractor</h1><p class="sub">Auto OCR → registered sender validation.</p></div></div>
        <div class="top-actions">
            <?php if (is_array($currentUser)): ?><span class="role"><?= Security::e($currentUser['username']) ?> · <?= Security::e($currentUser['role']) ?></span><?php endif; ?>
            <button class="install-btn" type="button" data-pwa-install hidden>Install</button>
            <form class="logout-form" method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"><button class="logout" type="submit">Sign out</button></form>
        </div>
    </header>
    <nav class="tabs" aria-label="Application navigation"><a class="tab active" href="index.php">Dashboard</a><a class="tab" href="statistics.php">Statistics</a><?php if ($isAdmin): ?><a class="tab" href="senders.php">Setting</a><a class="tab" href="users.php">Users</a><?php endif; ?></nav>

    <?php if ($successMessage !== null): ?><div class="msg ok" role="status"><?= Security::e($successMessage) ?></div><?php endif; ?>
    <?php if ($errorMessage !== null): ?><div class="msg error" role="status"><?= Security::e($errorMessage) ?></div><?php endif; ?>
    <div id="ajax-message" class="msg" role="status" hidden></div>

    <section class="summary-grid" aria-label="This week payment summary">
        <?php foreach (['week'] as $period): $item = $summary[$period]; ?>
            <article class="summary" data-summary-period="<?= Security::e($period) ?>">
                <div class="summary-label" data-summary-label><?= Security::e((string) $item['label']) ?> · <span data-summary-count><?= (int) $item['count'] ?></span> rows</div>
                <div class="summary-total" data-summary-total><?= Security::e((string) $item['total']) ?></div>
                <div class="summary-teams"><span>XCTD <b data-summary-team="XCTD"><?= Security::e((string) $item['teams']['XCTD']) ?></b></span><span>MNX <b data-summary-team="MNX"><?= Security::e((string) $item['teams']['MNX']) ?></b></span></div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="card" id="weekly-obligations" aria-label="Weekly payment obligations">
        <div class="section-title"><h2>Weekly payment status</h2><span id="weekly-label" class="count"><?= Security::e((string) ($weekly['label'] ?? '')) ?></span></div>
        <div class="weekly-metrics">
            <div class="weekly-metric"><span>Paid this week</span><b id="weekly-paid"><?= (int) ($weekly['paid'] ?? 0) ?></b></div>
            <div class="weekly-metric"><span>Pending this week</span><b id="weekly-pending"><?= (int) ($weekly['pending'] ?? 0) ?></b></div>
            <div class="weekly-metric"><span>Carry-forward</span><b id="weekly-outstanding"><?= (int) ($weekly['outstanding_weeks'] ?? 0) ?> weeks</b></div>
        </div>
        <div class="weekly-table"><table aria-label="Weekly sender payment status"><thead><tr><th>SUBID</th><th>Location</th><th>Team</th><th>This week</th><th class="right">Carry</th></tr></thead><tbody id="weekly-status-body">
        <?php $weeklyRows = is_array($weekly['rows'] ?? null) ? $weekly['rows'] : []; ?>
        <?php if ($weeklyRows === []): ?><tr id="weekly-empty-row"><td colspan="5" class="empty">No registered sender obligations.</td></tr><?php else: ?>
            <?php foreach ($weeklyRows as $row): $status = (string) ($row['current_status'] ?? 'pending'); $carry = (int) ($row['outstanding_weeks'] ?? 0); ?>
                <tr data-weekly-sender-id="<?= (int) ($row['sender_id'] ?? 0) ?>"><td><?= Security::e((string) ($row['alias'] ?? '')) ?></td><td><?= Security::e((string) ($row['location'] ?? '')) ?></td><td><?= Security::e((string) ($row['team'] ?? '')) ?></td><td><span class="status-pill <?= Security::e($status) ?>"><?= Security::e(ucfirst($status)) ?></span></td><td class="right <?= $carry > 0 ? 'carry' : 'carry zero' ?>"><?= $carry > 0 ? Security::e((string) $carry . ' wk') : '—' ?></td></tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody></table></div>
        <div class="pager" id="weekly-pager" hidden><button type="button" class="pager-btn" data-pager="weekly" data-dir="-1">Prev</button><span class="pager-info" id="weekly-pager-info">Page 1 / 1</span><button type="button" class="pager-btn" data-pager="weekly" data-dir="1">Next</button></div>
    </section>

    <?php if ($isAdmin): ?>
        <section class="card">
            <div class="section-title"><h2>Upload receipt</h2><span class="count">Auto extract &amp; save</span></div>
            <form id="upload-form" method="post" action="index.php" enctype="multipart/form-data" autocomplete="off" class="upload-grid">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input id="ocr-text" type="hidden" name="ocr_text" value="">
                <input id="selected-sender-id" type="hidden" name="selected_sender_id" value="">
                <label class="field">Receipt image · max 8 MB<input id="receipt-file" class="file" type="file" name="receipt" accept="image/jpeg,image/png,image/webp" required></label>
                <button id="upload-submit" class="primary" type="submit">Extract &amp; Save</button>
            </form>
            <div id="alias-choice" class="alias-choice" role="group" aria-labelledby="alias-choice-title" hidden>
                <div class="alias-choice-head"><div><b id="alias-choice-title">Choose SUBID</b><span id="alias-choice-sender"></span></div><button id="alias-choice-cancel" class="alias-cancel" type="button">Cancel</button></div>
                <p class="muted">This sender name has more than one active SUBID: choose one before the image is uploaded to the server.</p>
                <div id="alias-options" class="alias-options"></div>
            </div>
            <div id="ocr-progress" class="ocr-progress" role="status" aria-live="polite" hidden><div class="ocr-progress-head"><span id="ocr-progress-label">Preparing OCR…</span><span id="ocr-progress-value">0%</span></div><div class="ocr-progress-track"><progress id="ocr-progress-bar" max="100" value="0"></progress></div></div>
        </section>
    <?php else: ?>
        <section class="card"><p class="read-only">Read-only account. Live totals and transactions remain enabled; write actions are blocked server-side.</p></section>
    <?php endif; ?>

    <section class="card">
        <div class="section-title"><h2>Final output</h2><div class="meta"><span id="live-status" class="live-state live-ok">Live</span><span id="row-count" class="count"><?= count($transactions) ?> rows</span></div></div>
        <div class="table-wrap"><table aria-label="Final transaction output"><thead><tr><th>SUBID</th><th>Team</th><th class="right">Final</th><th class="receipt-date">Receipt date</th></tr></thead><tbody id="transactions-body">
        <?php if ($transactions === []): ?><tr id="empty-row"><td colspan="4" class="empty">No transactions found.</td></tr><?php else: ?>
            <?php foreach ($transactions as $transaction): ?><tr data-id="<?= (int) ($transaction['id'] ?? 0) ?>"><td><?= Security::e((string) (($transaction['sender_alias'] ?? null) ?: '—')) ?></td><td><?= Security::e((string) ($transaction['team'] ?? '')) ?></td><td class="right final"><?= Security::e(MoneyFormatter::formatIdr((string) ($transaction['adjusted_amount'] ?? '0'))) ?></td><td class="receipt-date"><?= Security::e((string) (($transaction['receipt_date'] ?? null) ?: ($transaction['created_at'] ?? ''))) ?></td></tr><?php endforeach; ?>
        <?php endif; ?>
        </tbody></table></div>
        <div class="pager" id="transactions-pager" hidden><button type="button" class="pager-btn" data-pager="transactions" data-dir="-1">Prev</button><span class="pager-info" id="transactions-pager-info">Page 1 / 1</span><button type="button" class="pager-btn" data-pager="transactions" data-dir="1">Next</button></div>
    </section>
</main>
<div id="toast-stack" class="toast-stack" aria-live="polite" aria-atomic="false"></div>
<script src="assets/jquery.php"></script>
<!-- SRI hash of tesseract.js@5.1.1/dist/tesseract.min.js (66695 bytes). CSP already
     confines scripts to this origin, but the origin itself stays trusted blindly
     without it: a tampered bundle would run inside an admin session that can post
     transactions. Recompute and update this hash whenever the pinned version above
     changes, otherwise the browser refuses the script and OCR stops loading. -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js" integrity="sha384-GJqSu7vueQ9qN0E9yLPb3Wtpd7OrgK8KmYzC8T1IysG1bcvxvIO4qtYR/D3A991F" crossorigin="anonymous"></script>
<script src="assets/app.php"></script>
<script src="assets/pwa.php" data-sw="sw.php"></script>
</body>
</html>
