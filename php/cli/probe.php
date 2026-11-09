<?php
declare(strict_types=1);

/**
 * Fill in the file size (and MIME type) of stored stream links.
 *
 * `cli/sync.php --probe` already probes while it scrapes, but it is off by
 * default: probing every link of a title adds eight requests to a scrape that a
 * three-minute cron budget can barely fit. The result is a library where most
 * links have no `size_bytes`, and the app's quality list shows no file sizes.
 *
 * This command walks the links that are still missing a size and asks the CDN
 * once per link with a ranged GET — the same request `LinkChecker` already uses
 * for liveness, whose `Content-Range: bytes 0-2048/<total>` header carries the
 * whole file's length. Nothing is re-scraped, and an expired token is simply
 * left alone for `cli/repair.php` to re-scrape.
 *
 * Examples:
 *   php cli/probe.php                       # backfill for 10 minutes
 *   php cli/probe.php --limit=200 --dry-run # report only
 *   php cli/probe.php --minutes=0           # keep going until nothing is missing
 *   php cli/probe.php --concurrency=16 --minutes=20
 *   php cli/probe.php --all-links           # re-measure links that already have a size
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script runs from the command line only.\n";
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use Mlbd\Scraper\LinkChecker;
use Mlbd\Store\Database;

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$options = getopt('', ['limit::', 'minutes::', 'concurrency::', 'all-links', 'dry-run', 'quiet']);

$allLinks = isset($options['all-links']);
$dryRun = isset($options['dry-run']);
$quiet = isset($options['quiet']);
$limit = isset($options['limit']) && is_numeric($options['limit']) ? max(0, (int) $options['limit']) : 0;
$minutes = isset($options['minutes']) && is_numeric($options['minutes']) ? max(0, (int) $options['minutes']) : 10;
$concurrency = isset($options['concurrency']) && is_numeric($options['concurrency'])
    ? clamp_int((int) $options['concurrency'], 1, 32, 8)
    : clamp_int((int) config('scraper.probe_concurrency', 8), 1, 32, 8);

$log = static function (string $line) use ($quiet): void {
    $entry = '[' . gmdate('Y-m-d H:i:s') . '] ' . $line;
    @file_put_contents(storage_path('logs/probe.log'), $entry . PHP_EOL, FILE_APPEND);
    if (!$quiet) {
        echo $entry . PHP_EOL;
    }
};

// Its own lock: a probe only talks to the CDN, so it may run alongside a
// scrape — but two probe runs must not pile onto the same links.
$lock = fopen(storage_path('probe.lock'), 'c');
if ($lock === false) {
    $log('ERROR: cannot open the lock file at ' . storage_path('probe.lock'));
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    $log('Another probe run is still going; skipping this invocation.');
    fclose($lock);
    exit(0);
}
register_shutdown_function(static function () use ($lock): void {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

if (!Database::configured()) {
    $log('ERROR: MySQL is not configured, so there is nothing to measure.');
    exit(1);
}

$pdo = Database::pdo();
$started = microtime(true);
$deadline = $minutes > 0 ? $started + $minutes * 60 : null;

$select = static function (int $cursor, int $size) use ($pdo, $allLinks): array {
    // Newest rows first: a MovieLinkBD token expires, so the oldest links are
    // the ones most likely to answer 403 and teach us nothing. The cursor walks
    // ids downwards, which also means a run never re-probes a row it just
    // failed on.
    $sql = "SELECT id, stream_url FROM mlbd_links
            WHERE stream_url LIKE 'http%'
              AND stream_url NOT LIKE '%.m3u8%'
              AND id < " . (int) $cursor;
    if (!$allLinks) {
        $sql .= ' AND (size_bytes IS NULL OR size_bytes = 0)';
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . (int) $size;
    return $pdo->query($sql)->fetchAll();
};

$batchSize = max(1, $concurrency * 4);
$checker = new LinkChecker(
    (int) config('scraper.probe_timeout', 8),
    rtrim((string) config('site.base_url', 'https://movielinkbd.tv'), '/') . '/'
);

$updateSize = $pdo->prepare('UPDATE mlbd_links SET size_bytes = ? WHERE id = ?');
$updateType = $pdo->prepare('UPDATE mlbd_links SET content_type = ? WHERE id = ?');

$cursor = PHP_INT_MAX;
$seen = 0;
$sized = 0;
$typed = 0;
$dead = 0;
$unknown = 0;
$noSize = 0;

$log(sprintf(
    '%s links missing a size · concurrency %d · budget %s%s.',
    $allLinks ? 'Re-measuring every http' : 'Measuring',
    $concurrency,
    $minutes === 0 ? 'none' : $minutes . 'm',
    $dryRun ? ' · dry run' : ''
));

while (true) {
    if ($deadline !== null && microtime(true) >= $deadline) {
        $log('Time budget reached; stopping. The next run continues where this one left off.');
        break;
    }
    if ($limit > 0 && $seen >= $limit) {
        break;
    }

    $batchLimit = $limit > 0 ? min($batchSize, $limit - $seen) : $batchSize;
    $rows = $select($cursor, $batchLimit);
    if ($rows === []) {
        break;
    }

    $urls = [];
    foreach ($rows as $row) {
        $cursor = min($cursor, (int) $row['id']);
        $seen++;
        $url = trim((string) ($row['stream_url'] ?? ''));
        if ($url !== '') {
            $urls[(int) $row['id']] = $url;
        }
    }
    if ($urls === []) {
        continue;
    }

    $results = $checker->check(array_values(array_unique($urls)), $concurrency);

    if (!$dryRun) {
        $pdo->beginTransaction();
    }
    foreach ($urls as $id => $url) {
        $result = $results[$url] ?? null;
        if ($result === null || !($result['ok'] ?? false)) {
            if ($result !== null && ($result['unknown'] ?? false)) {
                $unknown++;
            } else {
                $dead++;
            }
            continue;
        }

        $size = $result['size'] ?? null;
        $contentType = $result['content_type'] ?? null;

        if ($size === null) {
            $noSize++;
        } else {
            $sized++;
            if (!$dryRun) {
                $updateSize->execute([$size, $id]);
            }
        }

        if ($contentType !== null) {
            $typed++;
            if (!$dryRun) {
                $updateType->execute([$contentType, $id]);
            }
        }
    }
    if (!$dryRun) {
        $pdo->commit();
    }

    if ($limit === 0 && $seen % ($batchSize * 5) === 0) {
        $log(sprintf(
            'Progress: %d checked · %d sized · %d dead · %d no size',
            $seen,
            $sized,
            $dead,
            $noSize
        ));
    }
}

$log(sprintf(
    '%s %d links in %.0fs · sized %d · typed %d · dead %d · unknown %d · no size reported %d.',
    $dryRun ? 'Would have checked' : 'Checked',
    $seen,
    microtime(true) - $started,
    $sized,
    $typed,
    $dead,
    $unknown,
    $noSize
));

if ($dead > 0) {
    $log('Dead tokens are left alone: php cli/repair.php --apply re-scrapes those titles.');
}

exit(0);
