<?php

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\Security;
use App\UserRepository;

require __DIR__ . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);
Auth::requireLogin();
$currentUser = Auth::user();

$errorMessage = null;

// Mutations answer with a redirect (see the POST branch below), so the outcome has
// to survive one hop. A page-specific key keeps it from being consumed by the
// dashboard, which reads its own 'flash_success'.
$successMessage = isset($_SESSION['flash_users_success']) && is_string($_SESSION['flash_users_success'])
    ? $_SESSION['flash_users_success']
    : null;
unset($_SESSION['flash_users_success']);

/**
 * Post/Redirect/Get for the three mutating actions on this page. Rendering the
 * result directly from the POST left the form resubmittable by reload, which for
 * 'create' means a second account attempt and for 'password' a second reset.
 */
function usersRedirectWithFlash(string $message): never
{
    $_SESSION['flash_users_success'] = $message;
    header('Location: users.php', true, 303);
    exit;
}

try {
    /** @var array<string, mixed> $config */
    $config = require __DIR__ . '/config/app.php';
    $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
    $repository = new UserRepository(Database::connect($dbConfig));
    Auth::syncUser($repository);
    Auth::requireAdmin();
    $currentUser = Auth::user();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!Security::validateCsrf($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            throw new RuntimeException('Request validation failed. Reload the page and try again.');
        }

        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

        if ($action === 'create') {
            $username = isset($_POST['username']) && is_string($_POST['username']) ? $_POST['username'] : '';
            $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
            $role = isset($_POST['role']) && is_string($_POST['role']) ? $_POST['role'] : '';
            $repository->create($username, $password, $role);
            usersRedirectWithFlash('User created.');
        } elseif ($action === 'access') {
            $id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $role = isset($_POST['role']) && is_string($_POST['role']) ? $_POST['role'] : '';
            $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1';
            if (!is_int($id) || !is_array($currentUser)) {
                throw new RuntimeException('Invalid user.');
            }
            $repository->updateAccess($id, $role, $isActive, $currentUser['id']);
            usersRedirectWithFlash('Access updated.');
        } elseif ($action === 'password') {
            $id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
            if (!is_int($id)) {
                throw new RuntimeException('Invalid user.');
            }
            $repository->resetPassword($id, $password);
            usersRedirectWithFlash('Password updated.');
        } else {
            throw new RuntimeException('Invalid action.');
        }
    }

    $users = $repository->findAll();
} catch (Throwable $e) {
    error_log('User management failure: ' . $e->getMessage());
    $errorMessage = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) && !$e instanceof PDOException
        ? $e->getMessage()
        : 'Unable to update users.';
    $users = [];
    if (isset($repository)) {
        try {
            $users = $repository->findAll();
        } catch (Throwable $readError) {
            error_log('User list failure: ' . $readError->getMessage());
        }
    }
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
    <title>User access</title>
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
            outline:1px solid rgba(37,99,235,0);
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

        .card>strong{
            display:block;
            margin:0 0 6px;
            font-size:.72rem;
            line-height:1.25;
        }

        .grid{
            display:grid;
            grid-template-columns:1.2fr 1.2fr .8fr auto;
            gap:5px;
            margin-top:0;
            align-items:center;
        }

        input,select{
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
        input:hover,select:hover{border-color:var(--line-strong)}
        input:focus,select:focus{
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
        .secondary{
            border-color:var(--line);
            background:rgba(255,255,255,.86);
            color:var(--text);
            box-shadow:none;
        }
        .secondary:hover{border-color:var(--line-strong);background:#fff}

        .table-gap{margin-top:7px}
        .table{
            overflow:auto;
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:rgba(255,255,255,.78);
            scrollbar-width: none;
        }
        table{width:100%;min-width:820px;border-collapse:collapse}
        th,td{
            padding:6px 7px;
            border-bottom:1px solid rgba(226,232,240,.82);
            text-align:left;
            font-size:.63rem;
            line-height:1.3;
            vertical-align:middle;
            white-space:nowrap;
        }
        th{
            background:rgba(248,250,252,.72);
            color:var(--muted);
            font-size:.55rem;
            font-weight:700;
            letter-spacing:.03em;
            text-transform:uppercase;
        }
        tr:last-child td{border-bottom:0}
        tbody tr:hover td{background:rgba(248,250,252,.56)}

        .inline{
            display:flex;
            align-items:center;
            gap:5px;
        }
        .inline select{width:auto;min-width:88px}
        .inline input[type="password"]{min-width:165px}

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
            .grid{grid-template-columns:1fr}
            .grid .btn{width:100%}
            .table{overflow:auto}
            .inline{min-width:360px}
        }

        @media(min-width:700px){
            body{padding:12px}
            .top{min-height:50px;padding:8px 9px;margin-bottom:7px}
            .brand-icon{width:36px;height:36px;flex-basis:36px}
            .title{font-size:1.05rem}
            .muted{font-size:.60rem}
            .card{padding:9px;margin-bottom:7px}
        }

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
            .install-btn,.tab,input,select,.btn{transition:none}
        }
    </style>
</head>
<body data-pwa-gate>
<main class="wrap">
    <header class="top"><div class="brand"><img class="brand-icon" src="img.svg" alt=""><div><h1 class="title">User access</h1><div class="muted">Admin only · role-based server authorization</div></div></div><button class="install-btn" type="button" data-pwa-install hidden>Install</button></header>
    <nav class="tabs" aria-label="Admin navigation"><a class="tab" href="index.php">Dashboard</a><a class="tab" href="statistics.php">Statistics</a><a class="tab" href="senders.php">Setting</a><a class="tab active" href="users.php">Users</a></nav>
    <?php if ($successMessage !== null): ?><div class="msg ok"><?= Security::e($successMessage) ?></div><?php endif; ?>
    <?php if ($errorMessage !== null): ?><div class="msg error"><?= Security::e($errorMessage) ?></div><?php endif; ?>

    <section class="card">
        <strong>Create user</strong>
        <form method="post" class="grid" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="action" value="create">
            <input type="text" name="username" placeholder="username" maxlength="50" required autocomplete="off">
            <input type="password" name="password" placeholder="password" minlength="<?= UserRepository::MIN_PASSWORD_LENGTH ?>" maxlength="128" required autocomplete="new-password">
            <select name="role" required><option value="user">user · read only</option><option value="admin">admin · full</option></select>
            <button class="btn" type="submit">Create</button>
        </form>
    </section>

    <section class="card">
        <strong>Accounts</strong>
        <div class="table">
            <table>
                <thead><tr><th>Username</th><th>Role / status</th><th>Last login</th><th>Password</th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= Security::e($user['username']) ?><?= is_array($currentUser) && $currentUser['id'] === $user['id'] ? ' (you)' : '' ?></td>
                        <td>
                            <?php if (is_array($currentUser) && $currentUser['id'] === $user['id']): ?>
                                <?= Security::e($user['role']) ?> · active
                            <?php else: ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="access">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <select name="role"><option value="user"<?= $user['role'] === 'user' ? ' selected' : '' ?>>user</option><option value="admin"<?= $user['role'] === 'admin' ? ' selected' : '' ?>>admin</option></select>
                                    <select name="is_active"><option value="1"<?= $user['is_active'] === 1 ? ' selected' : '' ?>>active</option><option value="0"<?= $user['is_active'] !== 1 ? ' selected' : '' ?>>disabled</option></select>
                                    <button class="btn secondary" type="submit">Save</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><?= Security::e($user['last_login_at'] ?? 'Never') ?></td>
                        <td>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                                <input type="hidden" name="action" value="password">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <input type="password" name="password" placeholder="new password" minlength="<?= UserRepository::MIN_PASSWORD_LENGTH ?>" maxlength="128" required autocomplete="new-password">
                                <button class="btn secondary" type="submit">Reset</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script src="assets/pwa.php" data-sw="sw.php"></script>
</body>
</html>
