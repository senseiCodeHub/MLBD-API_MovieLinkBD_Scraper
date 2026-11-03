<?php
declare(strict_types=1);

/**
 * Dead-link repair: re-scrape only the titles whose stored stream links expired.
 *
 *   php cli/repair.php                      # preview: count the dead links
 *   php cli/repair.php --apply              # re-scrape the affected titles
 *   php cli/repair.php --apply --limit=50 --concurrency=12
 *
 * MovieLinkBD serves opaque per-request stream tokens that expire after a few
 * hours, so a full refresh of 14k detail pages (~7.5 h) is mostly wasted work.
 * Checking the stored links instead is ~1/6 of the cost: the CDN answers a
 * ranged GET in a fraction of a second, and only the titles with a dead link
 * need their page read again.
 *
 * Exit codes: 0 when the pass completed, 1 on a configuration or lock error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script runs from the command line only.\n";
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use Mlbd\Library\CronLog;
use Mlbd\Scraper\CloudflareBlocked;
use Mlbd\Scraper\LinkChecker;
use Mlbd\Scraper\Scraper;
use Mlbd\Store\Database;
use Mlbd\Store\Library;

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$options = getopt('', ['apply', 'all-links', 'concurrency::', 'limit::', 'minutes::', 'sample::', 'timeout::', 'quiet']);
$apply = isset($options['apply']);
$allLinks = isset($options['all-links']);
$quiet = isset($options['quiet']);
$concurrency = isset($options['concurrency']) && is_numeric($options['concurrency'])
    ? clamp_int($options['concurrency'], 1, 32, 12)
    : 12;
$limit = isset($options['limit']) && is_numeric($options['limit']) ? max(0, (int) $options['limit']) : 0;
$minutes = isset($options['minutes']) && is_numeric($options['minutes']) ? max(0, (int) $options['minutes']) : 0;
$sample = isset($options['sample']) && is_numeric($options['sample']) ? max(0, (int) $options['sample']) : 0;
$timeout = isset($options['timeout']) && is_numeric($options['timeout']) ? max(3, (int) $options['timeout']) : 10;

$logFile = storage_path('logs/sync.log');
$log = static function (string $line) use ($quiet, $logFile): void {
    $entry = '[' . gmdate('Y-m-d H:i:s') . '] repair: ' . $line;
    @file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
    if (!$quiet) {
        echo $entry . PHP_EOL;
    }
};

if (!Database::configured()) {
    $log('ERROR: MySQL is not configured, so there is nothing to repair.');
    exit(1);
}

$lock = fopen(storage_path('sync.lock'), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log('Another sync, prune or repair is running; skipping this invocation.');
    exit(1);
}

$startedAt = gmdate('Y-m-d H:i:s');

try {
    $pdo = Database::pdo();

    // Streams only: download rows reuse stream_url and would double the work.
    // By default one link per title is probed: every URL of a snapshot is issued
    // in the same scrape, so they expire together and one probe answers for all
    // of them. `--all-links` probes every stored stream instead.
    $sql = 'SELECT page_url, MAX(title) AS title, ' . ($allLinks ? 'stream_url' : 'MAX(stream_url) AS stream_url')
        . ' FROM ' . Database::LINKS_TABLE
        . " WHERE stream_url LIKE 'http%' AND (download_url IS NULL OR download_url <> stream_url)"
        . ($allLinks ? '' : ' GROUP BY page_url');

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if ($sample > 0 && count($rows) > $sample) {
        shuffle($rows);
        $rows = array_slice($rows, 0, $sample);
    }

    if ($rows === []) {
        $log('No stored stream links to check yet.');
        exit(0);
    }

    $urls = [];
    foreach ($rows as $row) {
        $urls[] = (string) $row['stream_url'];
    }

    $log(sprintf(
        'Checking %s link(s) across %s title(s) at concurrency %d…',
        number_format(count($urls)),
        number_format(count($rows)),
        $concurrency
    ));

    $checker = new LinkChecker($timeout);
    $results = $checker->check($urls, $concurrency);

    $deadByPage = [];
    $dead = 0;
    $checked = 0;
    $unknown = 0;
    foreach ($rows as $row) {
        $url = (string) $row['stream_url'];
        if (!isset($results[$url])) {
            continue;
        }
        $checked++;
        if ($results[$url]['ok']) {
            continue;
        }
        if (!empty($results[$url]['unknown'])) {
            $unknown++;
            continue;
        }
        $dead++;
        $page = (string) $row['page_url'];
        $deadByPage[$page][] = $url;
    }

    $log(sprintf(
        'Checked %s · %s dead link(s) across %s title(s)%s.',
        number_format($checked),
        number_format($dead),
        number_format(count($deadByPage)),
        $unknown > 0 ? ' · ' . number_format($unknown) . ' unreachable (network/TLS, ignored)' : ''
    ));

    // Previews are logged too, so a host without SSH can read the result from
    // the library page's cron panel instead of a terminal.
    $recordPreview = static function () use ($startedAt, $minutes, $checked, $dead, $unknown, $deadByPage): void {
        CronLog::record([
            'started_at'  => $startedAt,
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'mode'        => 'repair',
            'category'    => 'preview',
            'minutes'     => $minutes,
            'outcome'     => 'preview',
            'total'       => count($deadByPage),
            'processed'   => $checked,
            'saved'       => 0,
            'failed'      => 0,
            'dead'        => $dead,
            'unknown'     => $unknown,
            'empty'       => 0,
        ]);
    };

    if ($deadByPage === []) {
        $log('Nothing to repair — every stored link still answers.');
        $recordPreview();
        exit(0);
    }

    if (!$apply) {
        $log('Preview only. Re-run with --apply to re-scrape the affected titles.');
        $recordPreview();
        exit(0);
    }

    $library = new Library();
    $scraper = new Scraper();
    $requestedStatement = $pdo->prepare(
        'SELECT requested_url FROM ' . Database::SCRAPES_TABLE . ' WHERE page_url = ? LIMIT 1'
    );

    $deadline = $minutes > 0 ? time() + $minutes * 60 : null;
    $repaired = 0;
    $failed = 0;

    foreach ($deadByPage as $pageUrl => $streams) {
        if ($limit > 0 && $repaired + $failed >= $limit) {
            $log('Limit reached; stopping here. The next run checks the rest.');
            break;
        }
        if ($deadline !== null && time() >= $deadline) {
            $log('Time budget reached; stopping here. The next run checks the rest.');
            break;
        }

        // Re-scrape the catalogue URL, not the canonical mKs_ page, so the
        // snapshot is updated in place instead of creating a second row.
        $requestedStatement->execute([$pageUrl]);
        $target = (string) ($requestedStatement->fetchColumn() ?: $pageUrl);

        try {
            $result = $scraper->scrape($target);
            $library->saveScrape($result, $target);
            $repaired++;
            $log(sprintf('repaired %s (%d dead link%s)', (string) ($result['title'] ?? $pageUrl), count($streams), count($streams) === 1 ? '' : 's'));
        } catch (CloudflareBlocked $exception) {
            $log('Cloudflare is challenging the site; stopping instead of hammering it.');
            $failed++;
            break;
        } catch (\Throwable $exception) {
            $failed++;
            $log('failed ' . $target . ' — ' . $exception->getMessage());
        }

        // Stay polite between detail pages.
        usleep(300000);
    }

    $log(sprintf('Done: repaired %d, failed %d.', $repaired, $failed));

    CronLog::record([
        'started_at'  => $startedAt,
        'finished_at' => gmdate('Y-m-d H:i:s'),
        'mode'        => 'repair',
        'category'    => 'all',
        'minutes'     => $minutes,
        'outcome'     => 'finished',
        'total'       => count($deadByPage),
        'processed'   => $repaired + $failed,
        'saved'       => $repaired,
        'failed'      => $failed,
        'empty'       => 0,
    ]);

    exit(0);
} catch (\Throwable $exception) {
    $log('ERROR: ' . $exception->getMessage());
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
