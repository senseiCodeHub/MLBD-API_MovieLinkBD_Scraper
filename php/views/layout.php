<?php
/**
 * Shared page shell — "archive console" frame.
 *
 * @var array<string, mixed> $data
 * @var string $title
 * @var string $path
 * @var string $brand
 * @var array<string, mixed>|null $syncMini
 */
$pageTitle = ($title !== '' ? $title . ' · ' : '') . $brand;
$navHome = $path === '/' || $path === '';
$navSaved = str_starts_with($path, '/saved');

$mini = is_array($syncMini ?? null) ? $syncMini : null;
$miniRunning = (bool) ($mini['running'] ?? false);
$miniStatus = (string) ($mini['status'] ?? 'idle');
$miniPercent = (float) ($mini['percent'] ?? 0);
$miniLabel = $miniRunning
    ? sprintf('%d%% · %s/%s', (int) round($miniPercent), number_format((int) ($mini['processed'] ?? 0)), number_format((int) ($mini['total'] ?? 0)))
    : match ($miniStatus) {
        'finished'  => 'last run finished',
        'failed'    => 'last run stopped',
        'cancelled' => 'last run cancelled',
        default     => 'idle',
    };

// Cache-busting so a redeploy of CSS/JS is picked up without a hard refresh.
$styleVersion = @filemtime(base_path('assets/style.css')) ?: 1;
$scriptVersion = @filemtime(base_path('assets/app.js')) ?: 1;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0b0a09">
<meta name="description" content="Whole-catalogue MovieLinkBD index: search titles, read every stream and download, keep a MySQL mirror.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@400;500&family=Space+Grotesk:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/style.css?v=<?= (int) $styleVersion ?>">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<div class="route-progress" data-route-progress aria-hidden="true"></div>

<div class="app">
    <aside class="rail">
        <a class="brand" href="<?= e(page_url('/')) ?>">
            <span class="brand-name">MLBD</span>
            <span class="brand-sub">archive<span class="brand-dot">·</span>console</span>
        </a>

        <nav class="rail-nav" aria-label="Primary">
            <a href="<?= e(page_url('/')) ?>" class="<?= $navHome ? 'is-active' : '' ?>" <?= $navHome ? 'aria-current="page"' : '' ?>>
                <span class="rail-index">01</span>
                <span>Search</span>
            </a>
            <a href="<?= e(page_url('/saved')) ?>" class="<?= $navSaved ? 'is-active' : '' ?>" <?= $navSaved ? 'aria-current="page"' : '' ?>>
                <span class="rail-index">02</span>
                <span>Library</span>
            </a>
        </nav>

        <div class="rail-foot">
            <a class="rail-status <?= $miniRunning ? 'is-live' : '' ?>" href="<?= e(page_url('/saved')) ?>#sync" data-rail-status>
                <span class="rail-status-top">
                    <span class="lamp" aria-hidden="true"></span>
                    <span>sync</span>
                </span>
                <span class="rail-status-line" data-rail-line><?= e($miniLabel) ?></span>
            </a>
            <p class="rail-note">
                Cron refreshes the mirror every <?= (int) config('sync.refresh_hours', 6) ?>h.<br>
                Streams belong to MovieLinkBD.
            </p>
        </div>
    </aside>

    <div class="shell">
        <main id="main">
            <div class="perf" aria-hidden="true"></div>
            <?php require base_path('views/' . $view . '.php'); ?>
        </main>

        <footer class="foot">
            <span class="mono"><?= e(date('Y')) ?> · whole-catalogue index</span>
            <span class="foot-note">Reads MovieLinkBD's own player data · nothing is re-hosted.</span>
        </footer>
    </div>
</div>

<div class="scrape-veil" data-scrape-veil hidden aria-hidden="true">
    <div class="veil-inner">
        <div class="veil-reel" aria-hidden="true"><span></span><span></span><span></span></div>
        <p class="mono veil-label">reading the print</p>
        <p class="veil-title">Pulling the player payload — episodes, servers and qualities.</p>
        <p class="veil-note mono">A fresh scrape takes a few seconds.</p>
    </div>
</div>

<script src="assets/app.js?v=<?= (int) $scriptVersion ?>" defer></script>
</body>
</html>
