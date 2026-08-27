<?php

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\Security;
use App\TeamRepository;
use App\UserRepository;

require __DIR__ . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);
Auth::requireLogin();
$currentUser = Auth::user();
$senders = [];
$errorMessage = null;

try {
    /** @var array<string,mixed> $config */
    $config = require __DIR__ . '/config/app.php';
    $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
    $appConfig = is_array($config['app'] ?? null) ? $config['app'] : [];
    $timezone = isset($appConfig['timezone']) && is_string($appConfig['timezone']) ? $appConfig['timezone'] : 'Asia/Jakarta';
    $pdo = Database::connect($dbConfig);
    $users = new UserRepository($pdo);
    Auth::syncUser($users);
    Auth::requireAdmin();
    $currentUser = Auth::user();
    $senders = (new TeamRepository($pdo, $timezone))->findAll();
} catch (Throwable $e) {
    error_log('Sender management page failure: ' . $e->getMessage());
    $errorMessage = 'Unable to load sender data.';
}

$csrfToken = Security::csrfToken();
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
    <title>Setting</title>
    <style nonce="<?= Security::e($nonce) ?>">
        /* v1.9.2 slim-fit density */        :root{
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

        button,input,select{font:inherit}
        button,a,input,select{-webkit-tap-highlight-color:transparent}
        button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible{
            outline:1px solid rgba(37, 99, 235, 0);
            outline-offset:1px;
        }

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
        .brand>div{min-width:0}
        .brand-icon{
            width:34px;
            height:34px;
            flex:0 0 34px;
            display:block;
            padding:3px;
            object-fit:contain;
        }

        .title{
            margin:0;
            font-size:.92rem;
            font-weight:800;
            line-height:1.2;
            letter-spacing:-.025em;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }

        .muted{
            display:block;
            margin:2px 0 0;
            color:var(--muted);
            font-size:.57rem;
            line-height:1.25;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }

        /* v1.9.6 install affordance */
        .install-btn{
            min-height:29px;
            padding:5px 8px;
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:rgba(255,255,255,.84);
            color:var(--text);
            font:inherit;
            font-size:.61rem;
            white-space:nowrap;
            cursor:pointer;
            transition:background .15s ease,border-color .15s ease,box-shadow .15s ease,transform .15s ease;
        }
        .install-btn:hover{border-color:var(--line-strong);background:#fff}
        .install-btn:active{transform:translateY(1px)}
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
            border:1px solid transparent;
            border-radius:var(--radius);
            background:transparent;
            color:var(--text);
            font-size:.73rem;
            font-weight:900;
            line-height:1.2;
            letter-spacing:-.025em;
            text-align:center;
            text-decoration:none;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            text-transform:uppercase;
            transition:background .15s ease,border-color .15s ease,color .15s ease;
        }
        .tab:hover{background:rgba(255,255,255,.72);color:var(--text)}
        .tab.active{
            border-color:rgba(37,99,235,.18);
            background:var(--accent-soft);
            color:var(--accent);
            box-shadow:inset 0 1px 0 rgba(255,255,255,.86);
        }

        .card{
            position:relative;
            padding:8px;
            margin-bottom:7px;
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:var(--panel);
            box-shadow: inset 0 0 5px var(--accent-soft), 0 0 0 0.2px var(--line), 0 10px 30px rgba(15,23,42,.05), inset 0 1px 0 rgba(255,255,255,.86);
            backdrop-filter:blur(18px) saturate(135%);
            -webkit-backdrop-filter:blur(18px) saturate(135%);
        }

        .msg{
            padding:7px 8px;
            margin-bottom:7px;
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:rgba(255,255,255,.86);
            color:var(--text);
            font-size:.62rem;
            line-height:1.4;
            box-shadow:0 6px 18px rgba(15,23,42,.035);
        }
        .msg.error{border-color:#fecaca;background:var(--danger-soft);color:#991b1b}
        .msg.ok{border-color:#a7f3d0;background:var(--ok-soft);color:#065f46}
        .msg[hidden]{display:none}

        .head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:8px;
            margin-bottom:6px;
        }
        .head h2{margin:0;font-size:.72rem;line-height:1.25}
        .head .muted{margin:0;text-align:right}

        .line-wrap{overflow-x:auto;padding-bottom:1px;scrollbar-width:thin}
        .add-line{
            display:grid;
            grid-template-columns:minmax(0,1fr) minmax(0,1fr);
            gap:5px;
            align-items:center;
        }
        .add-line .btn{
            grid-column:1/-1;
            width:100%;
        }

        .input,.select{
            width:100%;
            min-width:0;
            min-height:31px;
            padding:5px 7px;
            border:1px solid var(--line);
            border-radius:var(--radius);
            outline:0;
            background:rgba(255,255,255,.90);
            color:var(--text);
            font:inherit;
            font-size:.62rem;
            line-height:1.2;
            box-shadow:inset 0 1px 1px rgba(15,23,42,.02);
            transition:border-color .15s ease,box-shadow .15s ease,background .15s ease;
        }
        .input:hover,.select:hover{border-color:var(--line-strong)}
        .input:focus,.select:focus{
            border-color:#93c5fd;
            background:#fff;
            box-shadow:0 0 0 0px rgba(37,99,235,.08);
        }

        .btn{
            min-height:31px;
            padding:5px 8px;
            border:1px solid rgba(29,78,216,.16);
            border-radius:var(--radius);
            background:var(--accent);
            color:#fff;
            font:inherit;
            font-size:.61rem;
            font-weight:800;
            line-height:1;
            white-space:nowrap;
            cursor:pointer;
            box-shadow:0 5px 12px rgba(37,99,235,.13),inset 0 1px 0 rgba(255,255,255,.14);
            transition:background .15s ease,border-color .15s ease,box-shadow .15s ease,transform .15s ease;
        }
        .btn:hover{background:var(--accent-hover)}
        .btn:active{transform:translateY(1px)}
        .btn.secondary{
            border-color:var(--line);
            background:rgba(255,255,255,.86);
            color:var(--text);
            box-shadow:none;
        }
        .btn.secondary:hover{border-color:var(--line-strong);background:#fff}
        .btn.danger{
            border-color:#fecaca;
            background:rgba(255,255,255,.86);
            color:var(--danger);
            box-shadow:none;
        }
        .btn.danger:hover{background:var(--danger-soft);border-color:#fca5a5}

        .table-wrap{
            overflow-x:auto;
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:rgba(255,255,255,.78);
            scrollbar-width:thin;
        }
        table{width:100%;min-width:900px;border-collapse:collapse}
        th,td{
            padding:5px 6px;
            border-bottom:1px solid rgba(226,232,240,.82);
            text-align:left;
            font-size:.61rem;
            line-height:1.25;
            white-space:nowrap;
        }
        th{
            background:rgba(248,250,252,.72);
            color:var(--muted);
            font-size:.54rem;
            font-weight:700;
            letter-spacing:.03em;
            text-transform:uppercase;
        }
        tr:last-child td{border-bottom:0}
        tbody tr:hover td{background:rgba(248,250,252,.56)}

        .row-form{
            display:grid;
            grid-template-columns:minmax(170px,1fr) minmax(120px,.68fr) minmax(140px,.76fr) 76px 84px auto auto;
            gap:5px;
            align-items:center;
            min-width:850px;
        }

        .accounts-row{
            display:flex;
            flex-wrap:wrap;
            gap:6px;
            align-items:center;
            margin-top:6px;
            padding-top:6px;
            border-top:1px dashed var(--line);
            min-width:850px;
        }
        .accounts-list{display:flex;flex-wrap:wrap;gap:4px}
        .account-chip{
            display:inline-flex;
            align-items:center;
            gap:4px;
            padding:3px 6px;
            border:1px solid var(--line);
            border-radius:999px;
            background:rgba(255,255,255,.85);
            color:var(--muted);
            font-size:.56rem;
            white-space:nowrap;
        }
        .account-remove{border:0;background:transparent;color:var(--danger);cursor:pointer;font-size:.68rem;line-height:1;padding:0}
        .account-add-form{display:flex;gap:4px;flex-wrap:wrap;align-items:center}
        .account-add-form .select{width:auto;min-width:96px}
        .account-add-form .input{width:auto;min-width:140px}

        .toast-stack{
            position:fixed;
            left:50%;
            bottom:max(10px,env(safe-area-inset-bottom));
            z-index:99;
            display:grid;
            gap:5px;
            width:min(310px,calc(100vw - 20px));
            transform:translateX(-50%);
            pointer-events:none;
        }
        .toast{
            padding:7px 8px;
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:rgba(255,255,255,.96);
            box-shadow:0 10px 28px rgba(15,23,42,.13);
            color:var(--text);
            font-size:.62rem;
            line-height:1.35;
            text-align:center;
            opacity:0;
            transform:translateY(5px);
            transition:opacity .18s ease,transform .18s ease;
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
        }
        .toast.show{opacity:1;transform:none}
        .toast.error{color:#991b1b}

        @media(max-width:720px){
            .top{padding:6px}
            .brand{gap:6px}
            .brand-icon{width:30px;height:30px;flex-basis:30px}
            .title{font-size:.84rem}
            .muted{font-size:.53rem}
            .install-btn{min-height:27px;padding:4px 6px;font-size:.57rem}
            .tabs{padding:3px;gap:3px;margin-bottom:6px}
            .tab{min-height:28px;padding:4px 3px;font-size:.56rem}
            .card{padding:7px;margin-bottom:6px}
            .head{align-items:flex-start}
            .head .muted{max-width:58%;white-space:normal}
            .line-wrap,.table-wrap{scrollbar-width:none;-ms-overflow-style:none}
            .line-wrap::-webkit-scrollbar,.table-wrap::-webkit-scrollbar{display:none;width:0;height:0}
        }

        @media(min-width:700px){
            body{padding:12px}
            .top{min-height:50px;padding:8px 9px;margin-bottom:7px}
            .brand-icon{width:36px;height:36px;flex-basis:36px}
            .title{font-size:1.05rem}
            .muted{font-size:.60rem}
            .card{padding:9px;margin-bottom:7px}
            .toast-stack{left:auto;right:12px;transform:none;width:300px}
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
            .install-btn,.tab,.input,.select,.btn,.toast{transition:none}
        }
    </style>
</head>
<body data-senders-endpoint="api/senders.php" data-pwa-gate>
<main class="wrap">
    <header class="top"><div class="brand"><img class="brand-icon" src="img.svg" alt=""><div><h1 class="title">Setting</h1><div class="muted">SUBID is the unique identity.</div></div></div><button class="install-btn" type="button" data-pwa-install hidden>Install</button></header>
    <nav class="tabs" aria-label="Admin navigation"><a class="tab" href="index.php">Dashboard</a><a class="tab" href="statistics.php">Statistics</a><a class="tab active" href="senders.php">Setting</a><a class="tab" href="users.php">Users</a></nav>
    <?php if ($errorMessage !== null): ?><div class="msg error"><?= Security::e($errorMessage) ?></div><?php endif; ?>
    <div id="sender-message" class="msg" hidden></div>

    <section class="card">
        <div class="head"><h2>Add sender</h2></div>
        <div class="line-wrap"><form id="sender-create-form" class="add-line" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"><input type="hidden" name="action" value="create">
            <input class="input" type="text" name="sender_name" minlength="2" maxlength="100" placeholder="Sender" required>
            <input class="input" type="text" name="subid" maxlength="100" placeholder="SUBID" required>
            <input class="input" type="text" name="location" minlength="2" maxlength="120" placeholder="Location" required>
            <select class="select" name="team" required><option value="XCTD">XCTD</option><option value="MNX">MNX</option></select>
            <button class="btn" type="submit">Add</button>
        </form></div>
    </section>

    <section class="card">
        <div class="head"><h2>Registered senders</h2><span id="sender-count" class="muted"><?= count($senders) ?> records</span></div>
        <div class="table-wrap"><table><thead><tr><th>Sender</th><th>SUBID</th><th>Location</th><th>Team</th><th>Status</th><th>Save</th><th>Delete</th></tr></thead><tbody id="senders-body">
        <?php foreach ($senders as $sender): ?>
            <tr data-sender-id="<?= (int) $sender['id'] ?>"><td colspan="7"><form class="row-form sender-row-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="sender_id" value="<?= (int) $sender['id'] ?>">
                <input class="input" name="sender_name" value="<?= Security::e($sender['display_name']) ?>" maxlength="100" required>
                <input class="input" name="subid" value="<?= Security::e($sender['alias']) ?>" maxlength="100" placeholder="SUBID" required>
                <input class="input" name="location" value="<?= Security::e($sender['location']) ?>" maxlength="120" placeholder="Location" required>
                <select class="select" name="team"><option value="XCTD"<?= $sender['team'] === 'XCTD' ? ' selected' : '' ?>>XCTD</option><option value="MNX"<?= $sender['team'] === 'MNX' ? ' selected' : '' ?>>MNX</option></select>
                <select class="select" name="is_active"><option value="1"<?= $sender['is_active'] === 1 ? ' selected' : '' ?>>active</option><option value="0"<?= $sender['is_active'] !== 1 ? ' selected' : '' ?>>disabled</option></select>
                <button class="btn secondary" type="submit">Save</button><button class="btn danger sender-delete" type="button">Delete</button>
            </form>
            <div class="accounts-row">
                <div class="accounts-list" data-accounts-list>
                <?php foreach ($sender['accounts'] as $account): ?>
                    <span class="account-chip" data-account-id="<?= (int) $account['id'] ?>"><?= Security::e($account['bank_code']) ?> •••<?= Security::e(substr($account['account_number'], -4)) ?><button type="button" class="account-remove" data-account-id="<?= (int) $account['id'] ?>" aria-label="Remove account">×</button></span>
                <?php endforeach; ?>
                </div>
                <form class="account-add-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"><input type="hidden" name="action" value="add_account"><input type="hidden" name="sender_id" value="<?= (int) $sender['id'] ?>">
                    <select class="select account-bank" name="bank_code"></select>
                    <input class="input account-number" name="account_number" maxlength="30" placeholder="Account number">
                    <button class="btn secondary" type="submit">+ Account</button>
                </form>
            </div>
            </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="pager" id="senders-pager" hidden><button type="button" class="pager-btn" data-pager="senders" data-dir="-1">Prev</button><span class="pager-info" id="senders-pager-info">Page 1 / 1</span><button type="button" class="pager-btn" data-pager="senders" data-dir="1">Next</button></div>
    </section>
</main>
<div id="toast-stack" class="toast-stack" aria-live="polite"></div>
<script src="assets/jquery.php"></script><script src="assets/senders.php"></script><script src="assets/pwa.php" data-sw="sw.php"></script>
</body>
</html>
