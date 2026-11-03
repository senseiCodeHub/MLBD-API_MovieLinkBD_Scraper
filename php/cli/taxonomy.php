<?php
declare(strict_types=1);

/**
 * Refresh the listing-badge index and backfill mlbd_titles from it.
 *
 * The catalogue's search-suggest shards carry no language, so every "Bangla",
 * "Bangla Dubbed" or "Hindi Dubbed" shelf used to read an empty column. The
 * listing pages do carry the badge on each card; this command walks them, caches
 * the index and writes `language` (plus the adult section's Movie/Series split)
 * onto the titles already stored.
 *
 * Examples:
 *   php cli/taxonomy.php                 # refresh the index and backfill
 *   php cli/taxonomy.php --dry-run       # report what would change
 *   php cli/taxonomy.php --force         # ignore the on-disk cache
 *   php cli/taxonomy.php --index-only    # only refresh the cache
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script runs from the command line only.\n";
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use Mlbd\Scraper\Catalog;
use Mlbd\Scraper\Taxonomy;
use Mlbd\Store\Database;

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$options = getopt('', ['force', 'dry-run', 'index-only', 'quiet']);
$force = isset($options['force']);
$dryRun = isset($options['dry-run']);
$indexOnly = isset($options['index-only']);
$quiet = isset($options['quiet']);

$log = static function (string $line) use ($quiet): void {
    $entry = '[' . gmdate('Y-m-d H:i:s') . '] ' . $line;
    @file_put_contents(storage_path('logs/taxonomy.log'), $entry . PHP_EOL, FILE_APPEND);
    if (!$quiet) {
        echo $entry . PHP_EOL;
    }
};

// Same lock as the sync job: this walks every listing page, and the site is
// not happy with two crawlers at once. Unlike repair/prune it WAITS for a
// running scrape to finish (up to five minutes) instead of skipping, so a daily
// slot is not lost just because a three-minute sync happened to be running.
$lock = fopen(storage_path('sync.lock'), 'c');
if ($lock === false) {
    $log('ERROR: cannot open the lock file at ' . storage_path('sync.lock'));
    exit(1);
}
$waitUntil = time() + 300;
while (!flock($lock, LOCK_EX | LOCK_NB)) {
    if (time() >= $waitUntil) {
        $log('Another job is still running; skipping this run.');
        fclose($lock);
        exit(0);
    }
    usleep(3000000);
}
register_shutdown_function(static function () use ($lock): void {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

$started = microtime(true);

try {
    $taxonomy = new Taxonomy();
    $count = $taxonomy->ensure($force);
} catch (Throwable $exception) {
    $log('ERROR: could not build the badge index: ' . $exception->getMessage());
    exit(1);
}

$log(sprintf(
    'Badge index ready: %d titles (%s, %.1fs).',
    $count,
    $taxonomy->fetchedAt() > 0 ? gmdate('Y-m-d H:i:s', (int) $taxonomy->fetchedAt()) . ' UTC' : 'unknown age',
    microtime(true) - $started
));

if ($indexOnly) {
    exit(0);
}

if (!Database::configured()) {
    $log('ERROR: MySQL is not configured, so there is nothing to backfill.');
    exit(1);
}

$pdo = Database::pdo();
$table = Database::TITLES_TABLE;

$rows = $pdo->query(
    "SELECT slug, title, kind, adult, language FROM {$table}"
)->fetchAll();

$log(sprintf('Stored titles: %d.', count($rows)));

$updates = [];
$matched = 0;
$withLanguage = 0;
$adultSplit = 0;
$unmatched = 0;

foreach ($rows as $row) {
    $badge = $taxonomy->badgesFor((string) ($row['title'] ?? ''));
    if ($badge === null) {
        $unmatched++;
        continue;
    }
    $matched++;

    $language = $badge['language'];
    $kind = (string) ($row['kind'] ?? '');
    $adult = (int) ($row['adult'] ?? 0);

    // The 18+ section serves films and series from one path; the card's own
    // badge is the only thing that tells them apart. Without it the API can
    // only guess the adult shelves' split from the title.
    $newKind = $kind;
    if ($kind === 'download18plus' && in_array((string) ($badge['type'] ?? ''), ['movie', 'series'], true)) {
        $newKind = (string) $badge['type'];
    }

    $newAdult = $badge['adult'] ? 1 : $adult;

    $changes = [];
    if ($language !== null && $language !== ($row['language'] ?? null)) {
        $changes['language'] = $language;
        $withLanguage++;
    }
    if ($newKind !== $kind) {
        $changes['kind'] = $newKind;
        $adultSplit++;
    }
    if ($newAdult !== $adult) {
        $changes['adult'] = $newAdult;
    }
    if ($changes === []) {
        continue;
    }

    $updates[] = ['slug' => (string) $row['slug']] + $changes;
}

$log(sprintf(
    'Matched %d of %d stored titles · %d with a language badge · %d adult rows re-kinded · %d unmatched.',
    $matched,
    count($rows),
    $withLanguage,
    $adultSplit,
    $unmatched
));

if ($dryRun) {
    $log('Dry run: ' . count($updates) . ' rows would be updated.');
    foreach (array_slice($updates, 0, 15) as $row) {
        $log('  ' . $row['slug'] . ' ' . json_encode(array_diff_key($row, ['slug' => true]), JSON_UNESCAPED_UNICODE));
    }
    exit(0);
}

if ($updates === []) {
    $log('Nothing to update; the stored badges already match the listings.');
    exit(0);
}

$pdo->beginTransaction();
try {
    $statement = $pdo->prepare("UPDATE {$table} SET language = :language WHERE slug = :slug");
    $kindStatement = $pdo->prepare("UPDATE {$table} SET kind = :kind WHERE slug = :slug");
    $adultStatement = $pdo->prepare("UPDATE {$table} SET adult = :adult WHERE slug = :slug");

    $written = 0;
    foreach ($updates as $row) {
        if (isset($row['language'])) {
            $statement->execute([':language' => $row['language'], ':slug' => $row['slug']]);
            $written += $statement->rowCount();
        }
        if (isset($row['kind'])) {
            $kindStatement->execute([':kind' => $row['kind'], ':slug' => $row['slug']]);
        }
        if (isset($row['adult'])) {
            $adultStatement->execute([':adult' => $row['adult'], ':slug' => $row['slug']]);
        }
    }
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    $log('ERROR: backfill failed, nothing written: ' . $exception->getMessage());
    exit(1);
}

$log(sprintf(
    'Backfill done: %d rows updated in %.1fs.',
    count($updates),
    microtime(true) - $started
));

// A quick self-check, so a run reports the shelves it just repaired.
foreach (['Bangla', 'Bangla Dubbed', 'Hindi', 'Hindi Dubbed'] as $badgeName) {
    $statement = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE language = :language");
    $statement->execute([':language' => $badgeName]);
    $log(sprintf('  %-14s %d titles', $badgeName, (int) $statement->fetchColumn()));
}

exit(0);
