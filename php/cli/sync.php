<?php
declare(strict_types=1);

/**
 * Scheduled catalogue sync — run from cron, not the browser.
 *
 * Examples:
 *   php cli/sync.php                                  # keep the library fresh
 *   php cli/sync.php --mode=fresh                      # only never-scraped titles
 *   php cli/sync.php --minutes=25 --refresh-hours=6    # bounded rolling refresh
 *   php cli/sync.php --mode=force --category=series --limit=20
 *
 * ``--minutes`` is a time budget: when it runs out the job pauses with its
 * progress saved, and the next invocation continues from there. That keeps each
 * run inside shared-hosting limits while still covering the whole catalogue over
 * several runs.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script runs from the command line only.\n";
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use Mlbd\Library\CronLog;
use Mlbd\Library\SyncJob;

// CLI ignores the web request timeout that bootstrap sets.
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$options = getopt('', [
    'mode::',          // refresh | fresh | force | retry_empty
    'category::',      // all | movie | series | drama | anime | adult
    'limit::',         // stop after N catalogue titles in this run
    'minutes::',       // time budget for this cron invocation (0 = no limit)
    'refresh-hours::', // a title counts as fresh for this many hours
    'probe',           // HEAD each media URL for size (slower)
    'force',           // shorthand for --mode=force
    'retry-empty',     // shorthand for --mode=retry_empty
    'quiet',           // only log errors and the final line
]);

$quiet = isset($options['quiet']);
$mode = is_string($options['mode'] ?? null) ? (string) $options['mode'] : '';
if (isset($options['force'])) {
    $mode = 'force';
} elseif (isset($options['retry-empty'])) {
    $mode = 'retry_empty';
}
if ($mode === '') {
    $mode = 'refresh';
}
if (!in_array($mode, ['refresh', 'fresh', 'force', 'retry_empty'], true)) {
    fwrite(STDERR, "Unknown --mode={$mode}. Use refresh, fresh, force or retry_empty.\n");
    exit(2);
}

$minutes = isset($options['minutes']) && is_numeric($options['minutes'])
    ? max(0, (int) $options['minutes'])
    : (int) config('sync.cron_minutes', 25);

$params = [
    'mode'          => $mode,
    'category'      => is_string($options['category'] ?? null) ? (string) $options['category'] : 'all',
    'include_adult' => true,
    'probe'         => isset($options['probe']),
    'refresh_hours' => isset($options['refresh-hours']) && is_numeric($options['refresh-hours'])
        ? (int) $options['refresh-hours']
        : (int) config('sync.refresh_hours', 6),
    'limit'         => isset($options['limit']) && is_numeric($options['limit'])
        ? (int) $options['limit']
        : null,
];

$logFile = storage_path('logs/sync.log');
$log = static function (string $line) use ($quiet, $logFile): void {
    $entry = '[' . gmdate('Y-m-d H:i:s') . '] ' . $line;
    @file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
    if (!$quiet) {
        echo $entry . PHP_EOL;
    }
};

// One run at a time: a slow cron invocation must not overlap the next.
$lockPath = storage_path('sync.lock');
$lock = fopen($lockPath, 'c');
if ($lock === false) {
    $log('ERROR: cannot open the lock file at ' . $lockPath);
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    $log('Another sync is already running; skipping this invocation.');
    fclose($lock);
    exit(0);
}

try {
    $state = SyncJob::status();
    $runningMode = (string) (($state['params'] ?? [])['mode'] ?? '');
    $runningCategory = (string) (($state['params'] ?? [])['category'] ?? '');

    if ($state['running'] ?? false) {
        // Never hijack a run somebody started from the UI (or a different
        // category): finishing it with these options would change its meaning.
        if ($runningMode !== $mode || $runningCategory !== $params['category']) {
            $log(sprintf(
                'Another sync is already running (mode=%s, category=%s); skipping this invocation.',
                $runningMode !== '' ? $runningMode : 'unknown',
                $runningCategory !== '' ? $runningCategory : 'unknown'
            ));
            flock($lock, LOCK_UN);
            fclose($lock);
            exit(0);
        }

        $log(sprintf(
            'Resuming the %s sync at %d/%d (saved %d).',
            $mode,
            (int) $state['processed'],
            (int) $state['total'],
            (int) $state['saved']
        ));
    } else {
        $state = SyncJob::start($params);
        if (!($state['running'] ?? false)) {
            $log('Could not start the sync: ' . (string) ($state['message'] ?? 'unknown reason'));
            flock($lock, LOCK_UN);
            fclose($lock);
            exit(1);
        }
        $log(sprintf(
            'Started a %s sync (category=%s, refresh_hours=%d, limit=%s, budget=%s).',
            $mode,
            $params['category'],
            $params['refresh_hours'],
            $params['limit'] === null ? 'none' : (string) $params['limit'],
            $minutes === 0 ? 'none' : $minutes . 'm'
        ));
    }
} catch (\Throwable $exception) {
    $log('ERROR: could not start the sync: ' . $exception->getMessage());
    fclose($lock);
    exit(1);
}

$deadline = $minutes > 0 ? time() + $minutes * 60 : null;
$lastReport = 0;

// Record what *this* invocation did. The sync counters are cumulative across
// the invocations of one paused run, so store the delta since we started.
$baselineState = SyncJob::status();
$baseline = [
    'processed' => (int) ($baselineState['processed'] ?? 0),
    'saved'     => (int) ($baselineState['saved'] ?? 0),
    'failed'    => (int) ($baselineState['failed'] ?? 0),
    'empty'     => (int) ($baselineState['empty'] ?? 0),
];
$runStartedAt = gmdate('Y-m-d H:i:s');

register_shutdown_function(static function () use ($baseline, $runStartedAt, $mode, $params, $minutes): void {
    try {
        $state = SyncJob::status();
        $running = (bool) ($state['running'] ?? false);
        CronLog::record([
            'started_at'  => $runStartedAt,
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'mode'        => $mode,
            'category'    => (string) $params['category'],
            'minutes'     => $minutes,
            'outcome'     => $running ? 'paused' : (string) ($state['status'] ?? 'finished'),
            'total'       => (int) ($state['total'] ?? 0),
            'processed'   => max(0, (int) ($state['processed'] ?? 0) - $baseline['processed']),
            'saved'       => max(0, (int) ($state['saved'] ?? 0) - $baseline['saved']),
            'failed'      => max(0, (int) ($state['failed'] ?? 0) - $baseline['failed']),
            'empty'       => max(0, (int) ($state['empty'] ?? 0) - $baseline['empty']),
        ]);
    } catch (\Throwable $exception) {
        // Logging must never break a cron run.
    }
});

while (true) {
    try {
        $state = SyncJob::step();
    } catch (\Throwable $exception) {
        $log('ERROR: ' . $exception->getMessage());
        fclose($lock);
        exit(1);
    }

    if (!($state['running'] ?? false)) {
        break;
    }

    $now = time();
    if ($now - $lastReport >= 30) {
        $lastReport = $now;
        $log(sprintf(
            'Progress %d/%d · saved %d · empty %d · failed %d · %s',
            (int) $state['processed'],
            (int) $state['total'],
            (int) $state['saved'],
            (int) $state['empty'],
            (int) $state['failed'],
            (string) ($state['current'] ?? '')
        ));
    }

    if ($deadline !== null && $now >= $deadline) {
        $log('Time budget reached; pausing. The next run will continue here.');
        flock($lock, LOCK_UN);
        fclose($lock);
        exit(0);
    }

    usleep(200000);
}

$log(sprintf(
    'Done: %s · processed %d/%d · saved %d · empty %d · failed %d.',
    (string) ($state['status'] ?? 'finished'),
    (int) ($state['processed'] ?? 0),
    (int) ($state['total'] ?? 0),
    (int) ($state['saved'] ?? 0),
    (int) ($state['empty'] ?? 0),
    (int) ($state['failed'] ?? 0)
));

flock($lock, LOCK_UN);
fclose($lock);
exit(($state['status'] ?? '') === 'failed' ? 1 : 0);
