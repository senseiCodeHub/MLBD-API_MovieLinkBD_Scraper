<?php
/**
 * Saved library + the chunked sync console.
 *
 * @var string $query
 * @var string $category
 * @var string $status
 * @var int $page
 * @var list<array<string, mixed>> $results
 * @var int $total
 * @var string|null $error
 * @var bool $mysql
 * @var array<string, mixed>|null $stats
 * @var array<string, mixed> $sync
 * @var array<string, mixed> $params
 * @var array<string, mixed> $cron
 * @var array<string, mixed>|null $freshness
 */
$categories = [
    'all'    => 'All',
    'movie'  => 'Movies',
    'series' => 'Series',
    'drama'  => 'Drama',
    'anime'  => 'Anime',
    'adult'  => '18+',
];
$statuses = [
    'saved'  => 'Saved',
    'all'    => 'All',
    'empty'  => 'No streams',
    'failed' => 'Failed',
];
$pages = (int) ceil($total / 24);
$perStep = max(1, (int) ($params['titles_per_step'] ?? 1));
$running = (bool) ($sync['running'] ?? false);
$percent = (float) ($sync['percent'] ?? 0);
$counters = [
    ['saved', 'saved', (int) ($sync['saved'] ?? 0)],
    ['empty', 'no streams', (int) ($sync['empty'] ?? 0)],
    ['failed', 'failed', (int) ($sync['failed'] ?? 0)],
    ['skipped', 'skipped', (int) ($sync['skipped'] ?? 0)],
];
$offset = ($page - 1) * 24;
?>

<section class="page-head">
    <div>
        <p class="eyebrow">MySQL mirror</p>
        <h1 class="display small-display">The library</h1>
        <p class="lede narrow">
            Every scrape is stored with its links, so this page keeps working when MovieLinkBD
            is unreachable. Sync walks the catalogue in short steps and resumes wherever it left off.
        </p>
    </div>
</section>

<?php if (!$mysql): ?>
    <div class="alert alert-note">
        <p class="alert-title">MySQL is not configured</p>
        <p>Fill in the <code>db</code> block in <code>config.php</code> (host, user, password, database) to keep a mirror. Search and live scraping work without it.</p>
    </div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert">
        <p class="alert-title">Library error</p>
        <p><?= e($error) ?></p>
    </div>
<?php endif; ?>

<?php if ($mysql && is_array($stats)): ?>
    <section class="stats" aria-label="Library totals">
        <?php
        $tiles = [
            ['catalogue', (int) ($stats['titles']['total'] ?? 0)],
            ['saved', (int) ($stats['titles']['saved'] ?? 0)],
            ['pending', (int) ($stats['titles']['pending'] ?? 0)],
            ['no streams', (int) ($stats['titles']['empty'] ?? 0)],
            ['failed', (int) ($stats['titles']['failed'] ?? 0)],
            ['links', (int) ($stats['tables']['links'] ?? 0)],
        ];
        foreach ($tiles as [$label, $value]): ?>
            <div class="stat">
                <span class="stat-value mono"><?= number_format($value) ?></span>
                <span class="stat-label mono"><?= e($label) ?></span>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($mysql): ?>
<section class="console <?= $running ? 'is-live' : '' ?>" id="sync"
         data-sync
         data-state="<?= e(json_encode($sync, JSON_UNESCAPED_SLASHES) ?: '{}') ?>"
         data-endpoint="?r=/library/sync">
    <header class="console-head">
        <div class="console-title">
            <span class="lamp" aria-hidden="true"></span>
            <h2>Sync console</h2>
            <span class="console-hint mono"><?= $perStep ?> title<?= $perStep === 1 ? '' : 's' ?> per request</span>
        </div>
        <div class="console-actions">
            <button class="btn btn-go" type="button" data-sync-start <?= $running ? 'disabled' : '' ?>>
                <?= $running ? 'Syncing…' : 'Start sync' ?>
            </button>
            <button class="btn btn-quiet" type="button" data-sync-cancel <?= $running ? '' : 'disabled' ?>>Cancel</button>
        </div>
    </header>

    <div class="console-rail">
        <div class="console-bar"><span data-sync-bar style="width: <?= $percent ?>%"></span></div>
        <span class="console-percent mono" data-sync-percent><?= (int) round($percent) ?>%</span>
    </div>

    <p class="console-message" data-sync-message><?= e((string) ($sync['message'] ?? 'Idle')) ?></p>
    <p class="console-current mono" data-sync-current></p>

    <div class="console-counts">
        <?php foreach ($counters as [$key, $label, $value]): ?>
            <div class="counter" data-counter="<?= e($key) ?>">
                <span class="counter-value mono"><?= number_format($value) ?></span>
                <span class="counter-label mono"><?= e($label) ?></span>
            </div>
        <?php endforeach; ?>
        <div class="counter counter-wide">
            <span class="counter-value mono" data-sync-total><?= number_format((int) ($sync['processed'] ?? 0)) ?>/<?= number_format((int) ($sync['total'] ?? 0)) ?></span>
            <span class="counter-label mono">processed</span>
        </div>
    </div>

    <details class="console-options" <?= $running ? '' : 'open' ?>>
        <summary class="mono">Run options</summary>
        <form class="options" data-sync-options>
            <label class="field">
                <span class="field-label mono">category</span>
                <select name="category">
                    <?php foreach ($categories as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="toggle">
                <input type="checkbox" name="include_adult" value="1" checked>
                <span class="toggle-box" aria-hidden="true"></span>
                <span>Include 18+</span>
            </label>
            <label class="toggle">
                <input type="checkbox" name="force" value="1">
                <span class="toggle-box" aria-hidden="true"></span>
                <span>Re-scrape saved</span>
            </label>
            <label class="toggle">
                <input type="checkbox" name="retry_empty" value="1">
                <span class="toggle-box" aria-hidden="true"></span>
                <span>Retry no-stream</span>
            </label>
            <label class="toggle">
                <input type="checkbox" name="probe" value="1">
                <span class="toggle-box" aria-hidden="true"></span>
                <span>Probe file sizes</span>
            </label>
        </form>
        <p class="console-note">
            Closing the tab pauses the run; press Start sync to resume. Cron keeps working without a browser.
        </p>
    </details>
</section>
<?php endif; ?>

<?php if ($mysql && is_array($cron) && !empty($cron['has_data'])): ?>
    <?php
    $lastRun = is_array($cron['last'] ?? null) ? $cron['last'] : null;
    $today = (array) ($cron['today'] ?? []);
    $outcome = (string) ($lastRun['outcome'] ?? 'finished');
    $outcomeLabel = $outcome === 'paused' ? 'paused, resumes next run' : $outcome;
    $lastUpdated = is_array($freshness ?? null) ? ($freshness['last_at'] ?? null) : null;
    ?>
    <section class="cron" aria-labelledby="cron-title">
        <header class="section-head">
            <h2 id="cron-title" class="section-title">
                <span class="mono faint">cron</span> Scheduled runs
            </h2>
            <p class="section-meta mono">
                <?php if ($lastRun): ?>
                    last run <?= e(time_ago((string) ($lastRun['finished_at'] ?? ''))) ?>
                <?php else: ?>
                    no runs recorded yet
                <?php endif; ?>
                · <?= number_format((int) ($today['runs'] ?? 0)) ?> run<?= (int) ($today['runs'] ?? 0) === 1 ? '' : 's' ?> today
            </p>
        </header>

        <section class="stats cron-stats" aria-label="Cron totals for today">
            <?php
            $cronTiles = [
                ['updated today', (int) ($today['saved'] ?? 0)],
                ['checked today', (int) ($today['processed'] ?? 0)],
                ['failed today', (int) ($today['failed'] ?? 0)],
                ['runs today', (int) ($today['runs'] ?? 0)],
            ];
            foreach ($cronTiles as [$label, $value]): ?>
                <div class="stat">
                    <span class="stat-value mono"><?= number_format($value) ?></span>
                    <span class="stat-label mono"><?= e($label) ?></span>
                </div>
            <?php endforeach; ?>
        </section>

        <?php if ($lastRun !== null): ?>
            <p class="cron-note mono">
                last pass · <?= e((string) ($lastRun['mode'] ?? 'refresh')) ?>
                <?php $runCategory = (string) ($lastRun['category'] ?? 'all'); ?>
                <?= $runCategory !== 'all' ? ' · ' . e($runCategory) : '' ?>
                · +<?= number_format((int) ($lastRun['saved'] ?? 0)) ?> updated
                · <?= e($outcomeLabel) ?>
                <?php if ($lastUpdated !== null): ?> · library updated <?= e(time_ago((string) $lastUpdated)) ?><?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if (($cron['recent'] ?? []) !== []): ?>
            <ol class="cron-log" aria-label="Recent scheduled runs">
                <?php foreach ($cron['recent'] as $run): ?>
                    <?php
                    $runSaved = (int) ($run['saved'] ?? 0);
                    $runOutcome = (string) ($run['outcome'] ?? 'finished');
                    $runMode = (string) ($run['mode'] ?? 'refresh');
                    $runDead = (int) ($run['dead'] ?? 0);
                    if ($runMode === 'repair') {
                        $runChip = $runDead > 0 ? 'chip-error' : 'chip-ok';
                        $runLabel = $runOutcome === 'preview'
                            ? ($runDead > 0 ? $runDead . ' dead' : 'all live')
                            : ($runSaved > 0 ? '+' . number_format($runSaved) : 'no change');
                    } else {
                        $runChip = $runOutcome === 'failed' ? 'chip-error' : ($runSaved > 0 ? 'chip-ok' : 'chip-quiet');
                        $runLabel = $runOutcome === 'paused' ? 'paused' : ($runSaved > 0 ? '+' . number_format($runSaved) : 'no change');
                    }
                    ?>
                    <li class="cron-row">
                        <span class="mono faint"><?= e(stamp_utc((string) ($run['started_at'] ?? ''))) ?></span>
                        <span class="mono"><?= e($runMode) ?> · <?= e((string) ($run['category'] ?? 'all')) ?></span>
                        <span class="mono"><?= number_format((int) ($run['processed'] ?? 0)) ?> checked</span>
                        <span class="mono faint"><?= $runMode === 'repair' ? $runDead . ' dead' : (int) ($run['failed'] ?? 0) . ' failed' ?></span>
                        <span class="chip <?= $runChip ?>"><?= e($runLabel) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="results" aria-labelledby="ledger-title">
    <header class="section-head">
        <h2 id="ledger-title" class="section-title">
            <span class="mono faint">ledger</span> Saved titles
        </h2>
        <form class="filters" method="get" action="index.php">
            <input type="hidden" name="r" value="/saved">
            <label class="field">
                <span class="sr-only">Search saved titles</span>
                <input type="search" name="q" value="<?= e($query) ?>" placeholder="Filter by title…">
            </label>
            <label class="field compact">
                <span class="sr-only">Category</span>
                <select name="category">
                    <?php foreach ($categories as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $category === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field compact">
                <span class="sr-only">Status</span>
                <select name="status">
                    <?php foreach ($statuses as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-quiet" type="submit">Filter</button>
        </form>
    </header>

    <?php if ($results === []): ?>
        <div class="empty">
            <p class="empty-mark" aria-hidden="true">▤</p>
            <h3>Nothing on the shelf yet.</h3>
            <p>
                <?= $mysql
                    ? 'Start a sync above, or open any title from search — it is stored the moment it is scraped.'
                    : 'Configure MySQL, then run a sync to fill the library.' ?>
            </p>
            <p><a class="btn btn-go" href="<?= e(page_url('/')) ?>">Find a title</a></p>
        </div>
    <?php else: ?>
        <ol class="ledger">
            <?php foreach ($results as $index => $row): ?>
                <?php
                $rowStatus = (string) ($row['status'] ?? 'saved');
                $statusClass = match ($rowStatus) {
                    'saved'  => 'chip-ok',
                    'failed' => 'chip-error',
                    'empty'  => 'chip-warn',
                    default  => 'chip-quiet',
                };
                $updatedAt = (string) ($row['updated_at'] ?? '');
                ?>
                <li>
                    <a class="ledger-row" href="<?= e(page_url('/saved/title', ['url' => $row['page_url'] ?? ''])) ?>">
                        <span class="ledger-index mono"><?= str_pad((string) ($offset + $index + 1), 3, '0', STR_PAD_LEFT) ?></span>
                        <span class="ledger-main">
                            <span class="ledger-title"><?= e((string) ($row['title'] ?? 'Untitled')) ?></span>
                            <span class="ledger-slug mono"><?= e((string) ($row['slug'] ?? '')) ?></span>
                        </span>
                        <span class="ledger-side">
                            <span class="chip <?= $statusClass ?>"><?= e($rowStatus === 'empty' ? 'no streams' : $rowStatus) ?></span>
                            <span class="mono faint ledger-when" title="<?= e(stamp_utc($updatedAt)) ?>"><?= e(time_ago($updatedAt)) ?></span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a class="btn" href="<?= e(page_url('/saved', ['q' => $query, 'category' => $category, 'status' => $status, 'page' => $page - 1])) ?>">← Previous</a>
                <?php else: ?>
                    <span class="btn is-disabled">← Previous</span>
                <?php endif; ?>
                <span class="mono faint">page <?= (int) $page ?> / <?= (int) $pages ?> · <?= number_format($total) ?> titles</span>
                <?php if ($page < $pages): ?>
                    <a class="btn" href="<?= e(page_url('/saved', ['q' => $query, 'category' => $category, 'status' => $status, 'page' => $page + 1])) ?>">Next →</a>
                <?php else: ?>
                    <span class="btn is-disabled">Next →</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
