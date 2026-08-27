<?php

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\LoginThrottle;
use App\Security;
use App\UserRepository;

require __DIR__ . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);

if (Auth::user() !== null) {
    header('Location: index.php', true, 302);
    exit;
}

$errorMessage = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'Request validation failed. Reload the page and try again.';
    } else {
        // Session check runs BEFORE try - prevents message being swallowed by catch.
        // It is only the cheap first line: a client can reset it by discarding
        // the cookie, so LoginThrottle below is the authoritative limit.
        $blockedUntil = $_SESSION['login_block_until'] ?? 0;

        if (is_int($blockedUntil) && $blockedUntil > time()) {
            usleep(250_000);
            http_response_code(429);
            $errorMessage = 'Too many sign-in attempts. Try again later.';
        } else {
            $username = isset($_POST['username']) && is_string($_POST['username'])
                ? $_POST['username']
                : '';

            $password = isset($_POST['password']) && is_string($_POST['password'])
                ? $_POST['password']
                : '';

            try {
                /** @var array<string, mixed> $config */
                $config = require __DIR__ . '/config/app.php';

                $dbConfig = is_array($config['db'] ?? null)
                    ? $config['db']
                    : [];

                $securityConfig = is_array($config['security'] ?? null)
                    ? $config['security']
                    : [];

                $trustedHeader = isset($securityConfig['trusted_proxy_header'])
                    && is_string($securityConfig['trusted_proxy_header'])
                    && $securityConfig['trusted_proxy_header'] !== ''
                    ? $securityConfig['trusted_proxy_header']
                    : null;

                $pdo = Database::connect($dbConfig);

                $throttle = new LoginThrottle($pdo);
                $address = LoginThrottle::clientAddress($trustedHeader);
                $retryAfter = $address !== null ? $throttle->retryAfter($address) : 0;

                if ($retryAfter > 0) {
                    usleep(250_000);
                    header('Retry-After: ' . (string) $retryAfter);
                    http_response_code(429);
                    $errorMessage = 'Too many sign-in attempts. Try again later.';
                } else {
                    $repository = new UserRepository($pdo);

                    $user = $repository->authenticate($username, $password);

                    if ($user === null) {
                        usleep(250_000);

                        if ($address !== null) {
                            $throttle->recordFailure($address, $username);
                        }

                        $failures = $_SESSION['login_failures'] ?? 0;
                        $failures = is_int($failures) ? $failures + 1 : 1;

                        if ($failures >= 5) {
                            $_SESSION['login_block_until'] = time() + 300;
                            $_SESSION['login_failures'] = 0;
                            http_response_code(429);
                            $errorMessage = 'Too many sign-in attempts. Try again later.';
                        } else {
                            $_SESSION['login_failures'] = $failures;
                            $errorMessage = 'Invalid username or password.';
                        }
                    } else {
                        if ($address !== null) {
                            $throttle->clear($address);
                        }

                        unset(
                            $_SESSION['login_failures'],
                            $_SESSION['login_block_until']
                        );

                        Auth::login($user);

                        header('Location: index.php', true, 302);
                        exit;
                    }
                }
            } catch (Throwable $e) {
                error_log('Login failure: ' . $e->getMessage());
                $errorMessage = 'Unable to sign in right now.';
            }
        }
    }
}

$csrfToken = Security::csrfToken();

$configured = is_file(__DIR__ . '/config/private.php')
    || is_file(__DIR__ . '/config/installed.lock');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width,initial-scale=1,viewport-fit=cover"
    >

    <meta name="theme-color" content="#f1f5f9">
    <meta name="color-scheme" content="light">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <link rel="manifest" href="index.php?asset=app-meta">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link
        rel="icon"
        type="image/png"
        sizes="192x192"
        href="assets/icons/icon-192.png"
    >
    <link
        rel="apple-touch-icon"
        sizes="180x180"
        href="assets/icons/apple-touch-icon.png"
    >

    <title>Sign in · Bank Receipt Extractor</title>

    <style nonce="<?= Security::e($nonce) ?>">        /* v1.9.2 slim-fit density */        :root{
            color-scheme:light;
            --bg:#f1f5f9;
            --surface:rgba(255,255,255,.80);
            --surface-strong:rgba(255,255,255,.96);
            --border:rgba(148,163,184,.30);
            --line:#dbe3ec;
            --text:#0f172a;
            --muted:#64748b;
            --soft:#94a3b8;
            --accent:#2563eb;
            --accent-hover:#1d4ed8;
            --accent-soft:#eff6ff;
            --danger:#dc2626;
            --danger-bg:#fef2f2;
            --danger-border:#fecaca;
            --radius:.3rem;
        }

        *,
        *::before,
        *::after{
            box-sizing:border-box;
        }

        *{
            scrollbar-width:none;
            -ms-overflow-style:none;
        }

        *::-webkit-scrollbar{
            display:none;
            width:0;
            height:0;
        }

        html{
            min-height:100%;
            background:var(--bg);
        }

        body{
            margin:0;
            min-height:100dvh;
            display:grid;
            align-items:start;
            justify-items:center;
            padding:
                max(48px,env(safe-area-inset-top))
                max(14px,env(safe-area-inset-right))
                max(18px,env(safe-area-inset-bottom))
                max(14px,env(safe-area-inset-left));
            overflow-x:hidden;
            background:
                radial-gradient(
                    circle at 15% 8%,
                    rgba(37,99,235,.08),
                    transparent 32%
                ),
                radial-gradient(
                    circle at 88% 88%,
                    rgba(100,116,139,.09),
                    transparent 34%
                ),
                linear-gradient(
                    180deg,
                    #f8fafc 0%,
                    #f1f5f9 100%
                );
            color:var(--text);
            font-family:
                ui-monospace,
                SFMono-Regular,
                "SF Mono",
                Menlo,
                Monaco,
                Consolas,
                "Liberation Mono",
                monospace;
            -webkit-font-smoothing:antialiased;
            -webkit-tap-highlight-color:transparent;
        }

        .card{
            position:relative;
            width:min(382px,100%);
            padding:15px;
            overflow:hidden;
            border:1px solid var(--border);
            border-radius:var(--radius);
            background:var(--surface);
            box-shadow:
                inset 0 0 5px var(--accent-soft),
                0 0 0 0.2px var(--line),
                0 18px 48px rgba(15,23,42,.09),
                0 2px 8px rgba(15,23,42,.04),
                inset 0 1px 0 rgba(255,255,255,.88);
            backdrop-filter:blur(22px) saturate(145%);
            -webkit-backdrop-filter:blur(22px) saturate(145%);
        }

        .card::before{
            content:"";
            position:absolute;
            inset:0;
            pointer-events:none;
            background:
                linear-gradient(
                    135deg,
                    rgba(255,255,255,.48),
                    transparent 42%
                );
        }

        .content{
            position:relative;
            z-index:1;
        }

        .brand-row{
            display:flex;
            align-items:center;
            gap:11px;
            margin-bottom:14px;
        }

        .app-icon-shell{
            width:42px;
            height:42px;
            flex:0 0 42px;
            display:grid;
            place-items:center;
            overflow:hidden;
        }

        .app-icon{
            display:block;
            width:34px;
            height:34px;
            object-fit:contain;
        }

        .brand-copy{
            min-width:0;
            flex:1;
        }

        .eyebrow{
            display:flex;
            align-items:center;
            gap:6px;
            margin:0 0 3px;
            color:var(--accent);
            font-size:.61rem;
            font-weight:800;
            line-height:1.2;
            letter-spacing:.08em;
            text-transform:uppercase;
        }

        .status-dot{
            width:6px;
            height:6px;
            flex:0 0 6px;
            border-radius:50%;
            background:#22c55e;
            box-shadow:0 0 0 3px rgba(34,197,94,.10);
        }

        h1{
            margin:0;
            overflow:hidden;
            color:var(--text);
            font-size:.94rem;
            font-weight:800;
            line-height:1.25;
            letter-spacing:-.025em;
            text-overflow:ellipsis;
            white-space:nowrap;
        }

        .sub{
            margin:3px 0 0;
            overflow:hidden;
            color:var(--muted);
            font-size:.64rem;
            line-height:1.4;
            text-overflow:ellipsis;
            white-space:nowrap;
        }

        .divider{
            height:1px;
            margin:13px 0;
            background:
                linear-gradient(
                    90deg,
                    transparent,
                    rgba(148,163,184,.34) 10%,
                    rgba(148,163,184,.34) 90%,
                    transparent
                );
        }

        form{
            margin:0;
        }

        .field{
            display:grid;
            gap:4px;
            margin:0 0 9px;
            color:var(--muted);
            font-size:.64rem;
            font-weight:700;
            line-height:1.3;
        }

        input{
            width:100%;
            min-height:36px;
            padding:8px 10px;
            border:1px solid var(--line);
            border-radius:var(--radius);
            outline:0;
            background:rgba(255,255,255,.94);
            box-shadow:
                inset 0 1px 1px rgba(15,23,42,.02),
                0 1px 0 rgba(255,255,255,.90);
            color:var(--text);
            font:inherit;
            font-size:.68rem;
            line-height:1.2;
            transition:
                border-color .16s ease,
                box-shadow .16s ease,
                background .16s ease;
        }

        input:hover{
            border-color:#cbd5e1;
        }

        input:focus{
            border-color:#93c5fd;
            background:#fff;
            box-shadow:
                0 0 0 0px!important;
        }

        button{
            width:100%;
            min-height:36px;
            display:flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            border-radius:var(--radius);
            font:inherit;
            font-size:.68rem;
            font-weight:800;
            line-height:1;
            cursor:pointer;
            transition:
                background .16s ease,
                border-color .16s ease,
                box-shadow .16s ease,
                transform .16s ease;
        }

        .submit{
            margin-top:2px;
            border:1px solid rgba(29,78,216,.16);
            background:var(--accent);
            box-shadow:
                0 7px 16px rgba(37,99,235,.18),
                inset 0 1px 0 rgba(255,255,255,.16);
            color:#fff;
        }

        .submit:hover{
            background:var(--accent-hover);
            box-shadow:
                0 8px 18px rgba(37,99,235,.22),
                inset 0 1px 0 rgba(255,255,255,.16);
        }

        .submit:active,
        .install:active{
            transform:translateY(1px);
        }

        button:focus-visible,
        a:focus-visible{
            outline:1px solid rgba(37,99,235,0);
            outline-offset:1px;
        }

        .install{
            margin-top:8px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.76);
            color:#475569;
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,.90);
        }

        .install:hover{
            border-color:#cbd5e1;
            background:#fff;
            color:var(--text);
        }

        .install[hidden]{
            display:none;
        }

        .error{
            margin-top:10px;
            padding:8px 9px;
            border:1px solid var(--danger-border);
            border-radius:var(--radius);
            background:var(--danger-bg);
            color:#991b1b;
            font-size:.63rem;
            line-height:1.45;
        }

        .setup{
            margin:10px 1px 0;
            color:var(--soft);
            font-size:.61rem;
            line-height:1.45;
        }

        .setup a{
            color:var(--accent);
            font-weight:700;
            text-decoration:none;
        }

        .setup a:hover{
            text-decoration:underline;
        }

        @media(max-width:420px){
            body{
                padding-top:max(36px,env(safe-area-inset-top));
            }

            .card{
                padding:13px;
            }

            .brand-row{
                gap:9px;
                margin-bottom:12px;
            }

            .app-icon-shell{
                width:40px;
                height:40px;
                flex-basis:40px;
            }

            .app-icon{
                width:32px;
                height:32px;
            }

            h1{
                font-size:.88rem;
            }

            .sub{
                font-size:.61rem;
            }
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
            input,
            button{
                transition:none;
            }
        }
    </style>
</head>

<body data-pwa-gate>

<section class="card" aria-labelledby="login-title">
    <div class="content">

        <header class="brand-row">
            <div class="app-icon-shell">
                <img
                    class="app-icon"
                    src="img.svg"
                    alt=""
                    aria-hidden="true"
                >
            </div>

            <div class="brand-copy">
                <p class="eyebrow">
                    <span class="status-dot" aria-hidden="true"></span>
                    Secure access
                </p>

                <h1 id="login-title">Bank Receipt Extractor</h1>

                <p class="sub">
                    Auto OCR &#8594; registered sender validation.
                </p>
            </div>
        </header>

        <div class="divider" aria-hidden="true"></div>

        <form method="post" autocomplete="off">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= Security::e($csrfToken) ?>"
            >

            <label class="field">
                Username
                <input
                    type="text"
                    name="username"
                    maxlength="50"
                    required
                    autocomplete="username"
                    spellcheck="false"
                    autocapitalize="none"
                >
            </label>

            <label class="field">
                Password
                <input
                    type="password"
                    name="password"
                    maxlength="128"
                    required
                    autocomplete="current-password"
                >
            </label>

            <button class="submit" type="submit">
                Sign in
            </button>
        </form>

        <button
            class="install"
            type="button"
            data-pwa-install
            hidden
        >
            Install app
        </button>

        <?php if ($errorMessage !== null): ?>
            <div class="error" role="status">
                <?= Security::e($errorMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (!$configured): ?>
            <p class="setup">
                First deployment?
                <a href="install/">Open auto installer</a>.
            </p>
        <?php endif; ?>

    </div>
</section>

<script
    src="assets/pwa.php"
    data-sw="sw.php"
    nonce="<?= Security::e($nonce) ?>"
></script>

</body>
</html>