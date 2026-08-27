<?php

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\ReportingPresenter;
use App\ReportingRepository;
use App\Security;
use App\UserRepository;

require __DIR__ . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);
Auth::requireLogin();
$currentUser = Auth::user();
$isAdmin = false;
$report = [];
$errorMessage = null;

try {
    /** @var array<string,mixed> $config */
    $config = require __DIR__ . '/config/app.php';
    $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
    $appConfig = is_array($config['app'] ?? null) ? $config['app'] : [];
    $timezone = isset($appConfig['timezone']) && is_string($appConfig['timezone']) ? $appConfig['timezone'] : 'Asia/Jakarta';
    try { new DateTimeZone($timezone); } catch (Throwable $e) { $timezone = 'Asia/Jakarta'; }
    $pdo = Database::connect($dbConfig);
    $users = new UserRepository($pdo);
    Auth::syncUser($users);
    $currentUser = Auth::user();
    $isAdmin = Auth::isAdmin();
    $report = ReportingPresenter::present((new ReportingRepository($pdo))->report(null, $timezone));
} catch (Throwable $e) {
    error_log('Statistics page failure: ' . $e->getMessage());
    $errorMessage = 'Unable to load reporting data.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f8fafc"><meta name="color-scheme" content="light">
    <meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="index.php?asset=app-meta">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png"><link rel="apple-touch-icon" sizes="180x180" href="assets/icons/apple-touch-icon.png">
    <title>Statistics</title>
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
            --accent2:#0f766e;
            --accent2-soft:#f0fdfa;
            --danger:#dc2626;
            --danger-soft:#fef2f2;
            --ok:#059669;
            --ok-soft:#ecfdf5;
            --warn:#d97706;
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

        button,a{font:inherit;-webkit-tap-highlight-color:transparent}
        button:focus-visible,a:focus-visible,summary:focus-visible{outline:1px solid rgba(37,99,235,0);outline-offset:1px}

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
        .title{margin:0;font-size:.92rem;font-weight:800;line-height:1.2;letter-spacing:-.025em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .muted{margin:2px 0 0;color:var(--muted);font-size:.57rem;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

        .top-actions{display:flex;align-items:center;gap:5px;min-width:0}
        .live{
            display:inline-flex;
            align-items:center;
            min-height:29px;
            padding:5px 7px;
            border-radius:var(--radius);
            color:#047857;
            font-size:.58rem;
            font-weight:800;
            white-space:nowrap;
        }
        .live::before{content:"";width:6px;height:6px;margin-right:5px;border-radius:50%;background:var(--ok);box-shadow:0 0 0 3px rgba(16,185,129,.10)}
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
            cursor:pointer;
            white-space:nowrap;
            transition:background .15s ease,border-color .15s ease,transform .15s ease;
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
        .tab.active{border-color:rgba(37,99,235,.18);background:var(--accent-soft);color:var(--accent);box-shadow:inset 0 1px 0 rgba(255,255,255,.86)}

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

        .grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:5px;margin-bottom:7px}
        .grid3 .card{margin-bottom:0;background:linear-gradient(135deg,rgba(255,255,255,.92),rgba(239,246,255,.58))}
        .metric-label{color:var(--muted);font-size:.53rem;font-weight:700;line-height:1.25;text-transform:uppercase;letter-spacing:.035em}
        .metric-value{margin:4px 0 2px;color:var(--text);font-size:.83rem;font-weight:900;line-height:1.15;letter-spacing:-.025em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .metric-prev{color:var(--muted);font-size:.54rem;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .delta{display:inline-flex;align-items:center;margin-top:3px;font-size:.58rem;font-weight:900;line-height:1.2}
        .delta.up{color:var(--ok)}
        .delta.down{color:var(--danger)}
        .delta.flat{color:var(--muted)}

        .split{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(260px,.75fr);gap:7px}
        .split>.card{min-width:0}
        .section-title{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:7px}
        .section-title h2{margin:0;color:var(--text);font-size:.71rem;font-weight:800;line-height:1.25;letter-spacing:-.015em}
        .section-title .muted{margin:0;font-size:.55rem}

        .compare{display:grid;gap:7px}
        .compare-row{display:grid;grid-template-columns:40px minmax(0,1fr) auto;gap:7px;align-items:center;min-width:0;color:var(--text);font-size:.59rem}
        .compare-row>b{font-weight:900}
        .compare-row>span{color:var(--muted);font-size:.57rem;white-space:nowrap}
        .bar-svg{display:block;width:100%;height:8px;border-radius:999px;overflow:hidden;background:#e2e8f0}
        .bar-bg{fill:#e2e8f0}
        .bar-xctd{fill:var(--accent)}
        .bar-mnx{fill:var(--accent2)}

        .donut-wrap{display:grid;grid-template-columns:100px minmax(0,1fr);gap:9px;align-items:center;min-width:0}
        .donut-svg{display:block;width:100px;height:100px;filter:drop-shadow(0 4px 10px rgba(15,23,42,.05))}
        .donut-total{margin-bottom:2px;color:var(--text);font-size:.70rem;font-weight:900;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .legend{display:grid;gap:5px;min-width:0;color:var(--muted);font-size:.57rem}
        .legend-row{display:flex;align-items:center;justify-content:space-between;gap:8px;min-width:0}
        .legend-row span{white-space:nowrap}
        .legend-dot{display:inline-block;width:7px;height:7px;margin-right:5px;border-radius:50%;background:var(--accent)}
        .legend-dot.mnx{background:var(--accent2)}

        .report-list{overflow-x:auto;border:1px solid rgba(148,163,184,.26);border-radius:var(--radius);background:rgba(255,255,255,.82)}
        .weekly-history table{width:100%;border-collapse:collapse;min-width:560px}
        .weekly-history th,.weekly-history td{padding:5px 7px;border-bottom:1px solid rgba(226,232,240,.82);font-size:.58rem;line-height:1.4;color:var(--text);text-align:left;white-space:nowrap}
        .weekly-history th{background:rgba(248,250,252,.96);color:var(--muted);font-size:.52rem;font-weight:800;text-transform:uppercase;letter-spacing:.035em}
        .weekly-history tbody tr:last-child td{border-bottom:0}
        .weekly-history tbody tr:hover{background:rgba(248,250,252,.72)}
        .weekly-history .wh-num{text-align:right;font-variant-numeric:tabular-nums}
        .weekly-history .wh-total{font-weight:800}
        .weekly-history .wh-count{color:var(--muted)}
        .weekly-history .wh-foot td{border-top:1px solid rgba(148,163,184,.35);background:rgba(248,250,252,.96);font-weight:900}

        .msg{margin-bottom:7px;padding:7px 8px;border:1px solid #fecaca;border-radius:var(--radius);background:rgba(254,242,242,.92);box-shadow:0 4px 12px rgba(15,23,42,.035);color:#991b1b;font-size:.61rem;line-height:1.4;overflow-wrap:anywhere}

        .reporting-details{padding:0;overflow:hidden}
        .reporting-details>summary{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:8px;
            min-height:34px;
            padding:8px;
            list-style:none;
            color:var(--text);
            font-size:.70rem;
            font-weight:800;
            cursor:pointer;
            user-select:none;
        }
        .reporting-details>summary::-webkit-details-marker{display:none}
        .reporting-details>summary::after{content:'Show';flex:0 0 auto;color:var(--accent);font-size:.57rem;font-weight:800}
        .reporting-details[open]>summary::after{content:'Hide'}
        .reporting-details[open]>summary{border-bottom:1px solid rgba(148,163,184,.20)}
        .reporting-details>summary>.muted{margin-left:auto;font-size:.54rem;font-weight:600}
        .reporting-details .details-body{padding:7px}

        @media(max-width:700px){
            .top{padding:6px}
            .brand{gap:6px}
            .brand-icon{width:30px;height:30px;flex-basis:30px}
            .title{font-size:.84rem}
            .muted{font-size:.53rem}
            .live{min-height:27px;padding:4px 6px;font-size:.54rem}
            .install-btn{min-height:27px;padding:4px 6px;font-size:.57rem}
            .tabs{margin-bottom:6px}
            .tab{min-height:28px;padding:4px 3px;font-size:.56rem}
            .grid3{grid-template-columns:1fr;gap:5px}
            .metric-value{font-size:.78rem}
            .split{grid-template-columns:1fr;gap:0}
            .donut-wrap{grid-template-columns:92px minmax(0,1fr);gap:8px}
            .donut-svg{width:92px;height:92px}
            .reporting-details>summary>.muted{display:none}
            ::-webkit-scrollbar{display:none;width:0;height:0}
            .report-list{scrollbar-width:none;-ms-overflow-style:none}
        }

        @media(max-width:370px){
            .top-actions{gap:4px}
            .live{padding:4px 5px}
            .install-btn{padding:4px 6px}
            .section-title{align-items:flex-start}
            .section-title h2{font-size:.67rem}
            .compare-row{grid-template-columns:36px minmax(0,1fr) auto;gap:5px}
        }

        @media(min-width:700px){
            body{padding:12px}
            .top{padding:8px 9px;margin-bottom:7px}
            .brand{gap:9px}
            .brand-icon{width:38px;height:38px;flex-basis:38px}
            .title{font-size:1.04rem}
            .muted{font-size:.59rem}
            .tabs{margin-bottom:8px}
            .tab{min-height:33px}
            .card{padding:10px;margin-bottom:8px}
            .grid3{gap:7px;margin-bottom:8px}
            .metric-label{font-size:.56rem}
            .metric-value{font-size:.90rem}
            .metric-prev{font-size:.57rem}
            .delta{font-size:.61rem}
            .section-title h2{font-size:.76rem}
            .compare-row{font-size:.62rem}
            .compare-row>span{font-size:.60rem}
            .donut-wrap{grid-template-columns:108px minmax(0,1fr)}
            .donut-svg{width:108px;height:108px}
            .donut-total{font-size:.75rem}
            .legend{font-size:.60rem}
            .reporting-details>summary{padding:9px 10px;font-size:.75rem}
            .reporting-details .details-body{padding:8px 10px 10px}
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
            .install-btn,.tab{transition:none}
        }
    </style>
</head>
<body data-reporting-endpoint="api/reporting.php" data-pwa-gate>
<main class="wrap">
    <header class="top"><div class="brand"><img class="brand-icon" src="img.svg" alt=""><div><h1 class="title">Statistics</h1><div class="muted">Team comparison · weekly history.</div></div></div><div class="top-actions"><span id="report-live" class="live">Live</span><button class="install-btn" type="button" data-pwa-install hidden>Install</button></div></header>
    <nav class="tabs" aria-label="Application navigation"><a class="tab" href="index.php">Dashboard</a><a class="tab active" href="statistics.php">Statistics</a><?php if ($isAdmin): ?><a class="tab" href="senders.php">Setting</a><a class="tab" href="users.php">Users</a><?php endif; ?></nav>
    <?php if ($errorMessage !== null): ?><div class="msg"><?= Security::e($errorMessage) ?></div><?php endif; ?>

    <section class="grid3" aria-label="Period changes">
        <?php foreach (['week','month','year'] as $period): $item = $report['changes'][$period] ?? ['label'=>ucfirst($period),'current'=>'IDR 0','previous'=>'IDR 0','direction'=>'flat','percent'=>0]; ?>
        <article class="card" data-change-period="<?= Security::e($period) ?>"><div class="metric-label"><?= Security::e((string) $item['label']) ?> vs previous</div><div class="metric-value" data-change-current><?= Security::e((string) $item['current']) ?></div><div class="metric-prev">Previous <span data-change-previous><?= Security::e((string) $item['previous']) ?></span></div><div class="delta <?= Security::e((string) $item['direction']) ?>" data-change-delta><?= $item['direction']==='up'?'▲':($item['direction']==='down'?'▼':'■') ?> <?= Security::e(number_format((float) ($item['percent'] ?? 0),1)) ?>%</div></article>
        <?php endforeach; ?>
    </section>

    <section class="split">
        <article class="card"><div class="section-title"><h2>Team comparison · current month</h2><span id="month-label" class="muted"><?= Security::e((string) ($report['current_month']['label'] ?? '')) ?></span></div><div class="compare">
            <div class="compare-row"><b>XCTD</b><svg class="bar-svg" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><rect class="bar-bg" x="0" y="0" width="100" height="10"></rect><rect id="bar-xctd" class="bar-xctd" x="0" y="0" width="0" height="10"></rect></svg><span id="compare-xctd"><?= Security::e((string) ($report['current_month']['teams']['XCTD']['total'] ?? 'IDR 0')) ?></span></div>
            <div class="compare-row"><b>MNX</b><svg class="bar-svg" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><rect class="bar-bg" x="0" y="0" width="100" height="10"></rect><rect id="bar-mnx" class="bar-mnx" x="0" y="0" width="0" height="10"></rect></svg><span id="compare-mnx"><?= Security::e((string) ($report['current_month']['teams']['MNX']['total'] ?? 'IDR 0')) ?></span></div>
        </div></article>
        <article class="card"><div class="section-title"><h2>Team share</h2><span class="muted">Current month</span></div><div class="donut-wrap"><svg id="donut-chart" class="donut-svg" viewBox="0 0 120 120" role="img" aria-label="Team share donut chart"></svg><div class="legend"><div class="donut-total" id="donut-total"><?= Security::e((string) ($report['current_month']['total'] ?? 'IDR 0')) ?></div><div class="legend-row"><span><i class="legend-dot"></i>XCTD</span><span id="share-xctd">0%</span></div><div class="legend-row"><span><i class="legend-dot mnx"></i>MNX</span><span id="share-mnx">0%</span></div></div></div></article>
    </section>

    <details class="card reporting-details"><summary><span>Weekly history</span><span class="muted">12 weeks · default hidden</span></summary><div class="details-body"><div id="weekly-history-body" class="report-list weekly-history"></div></div></details>
</main>
<script id="initial-report" type="application/json" nonce="<?= Security::e($nonce) ?>"><?= json_encode($report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="assets/jquery.php"></script><script src="assets/statistics.php"></script><script src="assets/pwa.php" data-sw="sw.php"></script>
</body>
</html>
