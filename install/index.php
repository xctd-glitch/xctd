<?php

declare(strict_types=1);

use App\Auth;
use App\Installer;
use App\Security;

require dirname(__DIR__) . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);

$rootPath = dirname(__DIR__);
$installer = new Installer($rootPath);
$errorMessage = null;
$installed = $installer->isInstalled();
$checks = $installer->preflight();

/** @return string */
function installPostString(string $key, int $maxLength = 255): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);
    if (strlen($value) > $maxLength) {
        return '';
    }

    return $value;
}

if (!$installed && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
    try {
        if (!Security::validateCsrf(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
            http_response_code(419);
            throw new RuntimeException('Request validation failed. Reload the installer and try again.');
        }

        $host = installPostString('db_host', 253);
        $name = installPostString('db_name', 64);
        $user = installPostString('db_user', 64);
        $pass = isset($_POST['db_pass']) && is_string($_POST['db_pass']) && strlen($_POST['db_pass']) <= 255
            ? $_POST['db_pass']
            : '';
        $portRaw = installPostString('db_port', 5);
        $username = installPostString('username', 50);
        $password = isset($_POST['password']) && is_string($_POST['password']) && strlen($_POST['password']) <= 128
            ? $_POST['password']
            : '';
        $passwordConfirm = isset($_POST['password_confirm']) && is_string($_POST['password_confirm'])
            ? $_POST['password_confirm']
            : '';

        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException('Database host, name, and user are required.');
        }
        if (preg_match('/^[A-Za-z0-9_$.-]{1,64}$/D', $name) !== 1) {
            throw new RuntimeException('Database name contains unsupported characters.');
        }
        if (preg_match('/^[A-Za-z0-9_$.-]{1,64}$/D', $user) !== 1) {
            throw new RuntimeException('Database user contains unsupported characters.');
        }

        $port = filter_var($portRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (!is_int($port)) {
            throw new RuntimeException('Database port is invalid.');
        }
        if ($password === '' || !hash_equals($password, $passwordConfirm)) {
            throw new RuntimeException('Administrator passwords do not match.');
        }

        $dbConfig = [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
        ];

        $admin = $installer->install($dbConfig, $username, $password);
        Auth::login($admin);
        header('Location: ../index.php', true, 303);
        exit;
    } catch (Throwable $e) {
        error_log('Install request failure: ' . $e->getMessage());
        $errorMessage = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) && !$e instanceof PDOException
            ? $e->getMessage()
            : 'Installation could not be completed.';
    }
}

$csrfToken = Security::csrfToken();
$allChecksPassed = true;
foreach ($checks as $check) {
    if ($check['ok'] !== true) {
        $allChecksPassed = false;
        break;
    }
}
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
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="../index.php?asset=app-meta">
    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="../assets/icons/icon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/icons/apple-touch-icon.png">
    <title>Auto installer · Bank Receipt Extractor</title>
    <style nonce="<?= Security::e($nonce) ?>">
        :root{color-scheme:light;--bg:#f8fafc;--panel:#fff;--surface:#fff;--line:#dbe3ec;--text:#0f172a;--muted:#64748b;--accent:#2563eb;--ok:#059669;--danger:#dc2626}*{box-sizing:border-box}*{scrollbar-width:none;-ms-overflow-style:none}*::-webkit-scrollbar{display:none;width:0;height:0}body{margin:0;min-height:100dvh;background:linear-gradient(180deg,#fff 0,var(--bg) 42%,#f1f5f9 100%);color:var(--text);font-family:ui-monospace,SFMono-Regular,SF Mono,Menlo,Consolas,Liberation Mono,monospace;padding:max(12px,env(safe-area-inset-top)) 12px max(16px,env(safe-area-inset-bottom))}.wrap{width:min(760px,100%);margin:auto}.head{margin:4px 0 12px}.head h1{font-size:1.15rem;margin:0 0 5px}.head p,.hint{margin:0;color:var(--muted);font-size:.72rem;line-height:1.5}.card{border:1px solid var(--line);background:var(--panel);border-radius:.3rem;padding:13px;margin-bottom:10px;box-shadow:0 10px 28px rgba(15,23,42,.06)}.checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.check{border:1px solid var(--line);border-radius:.3rem;padding:8px;background:#f8fafc;font-size:.68rem}.check b{display:block;font-size:.7rem}.pass b{color:var(--ok)}.fail b{color:var(--danger)}.form-grid{display:grid;gap:9px}.two{display:grid;grid-template-columns:minmax(0,1fr) 90px;gap:8px}.field{display:grid;gap:5px;color:var(--muted);font-size:.7rem}.input{width:100%;min-width:0;border:1px solid var(--line);border-radius:.3rem;background:#fff;color:var(--text);font:inherit;padding:10px;outline:none}.input:focus{border-color:#93c5fd;box-shadow:0 0 0 2px #dbeafe}.btn{width:100%;border:0;border-radius:.3rem;background:var(--accent);color:#fff;font:inherit;font-weight:800;padding:11px;cursor:pointer}.btn:disabled{opacity:.45}.error,.locked{border:1px solid #fecaca;background:#fef2f2;border-radius:.3rem;padding:10px;color:#991b1b;font-size:.72rem;line-height:1.5}.locked a{color:var(--accent)}.section-title{margin:0 0 9px;font-size:.78rem}@media(min-width:700px){body{padding:24px}.card{padding:16px}.form-grid.cols{grid-template-columns:1fr 1fr}.checks{grid-template-columns:repeat(3,minmax(0,1fr))}}

        /* v1.9.2 slim-fit density */
        body{padding:max(7px,env(safe-area-inset-top)) 7px max(10px,env(safe-area-inset-bottom))}.head{margin:2px 0 8px}.head h1{font-size:1rem;margin-bottom:3px}.head p,.hint{font-size:.66rem;line-height:1.4}.card{padding:9px;margin-bottom:7px;box-shadow:0 6px 18px rgba(15,23,42,.05)}.checks{gap:5px}.check{padding:6px;font-size:.63rem}.check b{font-size:.65rem}.form-grid{gap:6px}.two{gap:5px}.field{gap:3px;font-size:.65rem}.input{min-height:31px;padding:6px 7px;font-size:.66rem}.btn{min-height:32px;padding:7px;font-size:.67rem}.error,.locked{padding:7px;font-size:.66rem;line-height:1.4}.section-title{margin-bottom:6px;font-size:.72rem}@media(min-width:700px){body{padding:14px}.card{padding:10px}.checks{grid-template-columns:repeat(3,minmax(0,1fr))}}
    </style>
</head>
<body>
<main class="wrap">
    <header class="head"><h1>Auto installer</h1><p>cPanel/shared-host wizard · browser OCR · PHP 8.3 · MySQL/MariaDB · creates schema + first admin + locks itself.</p></header>

    <section class="card">
        <h2 class="section-title">Server preflight</h2>
        <div class="checks">
            <?php foreach ($checks as $check): ?>
                <div class="check <?= $check['ok'] ? 'pass' : 'fail' ?>">
                    <b><?= $check['ok'] ? 'PASS' : 'FAIL' ?> · <?= Security::e($check['label']) ?></b>
                    <span><?= Security::e($check['detail']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($installed): ?>
        <section class="card"><div class="locked">Installer locked. Existing configuration was detected. <a href="../login.php">Open sign in</a>.</div></section>
    <?php else: ?>
        <?php if ($errorMessage !== null): ?><div class="error" role="status"><?= Security::e($errorMessage) ?></div><?php endif; ?>
        <form method="post" class="form-grid" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
            <section class="card form-grid">
                <h2 class="section-title">1 · Database</h2>
                <p class="hint">Create an empty database + database user in cPanel first. The installer creates only application tables; it never creates or modifies cPanel accounts.</p>
                <div class="two">
                    <label class="field">Host<input class="input" name="db_host" value="<?= Security::e(installPostString('db_host') ?: 'localhost') ?>" maxlength="253" required></label>
                    <label class="field">Port<input class="input" name="db_port" inputmode="numeric" value="<?= Security::e(installPostString('db_port') ?: '3306') ?>" maxlength="5" required></label>
                </div>
                <div class="form-grid cols">
                    <label class="field">Database name<input class="input" name="db_name" value="<?= Security::e(installPostString('db_name', 64)) ?>" maxlength="64" required></label>
                    <label class="field">Database user<input class="input" name="db_user" value="<?= Security::e(installPostString('db_user', 64)) ?>" maxlength="64" required></label>
                </div>
                <label class="field">Database password<input class="input" type="password" name="db_pass" maxlength="255" autocomplete="new-password"></label>
            </section>

            <section class="card form-grid">
                <h2 class="section-title">2 · Initial administrator</h2>
                <label class="field">Username<input class="input" name="username" value="<?= Security::e(installPostString('username', 50)) ?>" minlength="3" maxlength="50" autocomplete="username" required></label>
                <div class="form-grid cols">
                    <label class="field">Password<input class="input" type="password" name="password" minlength="5" maxlength="128" autocomplete="new-password" required></label>
                    <label class="field">Confirm password<input class="input" type="password" name="password_confirm" minlength="5" maxlength="128" autocomplete="new-password" required></label>
                </div>
                <button class="btn" type="submit"<?= $allChecksPassed ? '' : ' disabled' ?>>Install production app</button>
                <?php if (!$allChecksPassed): ?><p class="hint">Resolve every FAIL item before installation.</p><?php endif; ?>
            </section>
        </form>
    <?php endif; ?>
</main>
<script src="../assets/pwa.php" data-sw="../sw.php"></script>
</body>
</html>
