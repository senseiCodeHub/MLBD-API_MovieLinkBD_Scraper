<?php
declare(strict_types=1);

namespace Mlbd\Library;

/**
 * Tiny append-only log of cron invocations.
 *
 * ``cli/sync.php`` writes one record per run (including runs that stop on the
 * time budget), so the library page can answer "how much did the cron update
 * today" without parsing the plain-text log. Counters are per-run deltas, not
 * the cumulative sync counters, because a paused run keeps its counters across
 * several cron invocations.
 */
final class CronLog
{
    private const MAX_RUNS = 40;

    private static function file(): string
    {
        return storage_path('cron-runs.json');
    }

    /** @param array<string, mixed> $run */
    public static function record(array $run): void
    {
        $runs = self::runs();
        array_unshift($runs, $run);
        $runs = array_slice($runs, 0, self::MAX_RUNS);

        $json = json_encode(
            ['runs' => $runs],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        if ($json === false) {
            return;
        }

        $path = self::file();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        // Write-then-rename: the web request must never read a half-written log.
        $tmp = $path . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /** @return list<array<string, mixed>> newest first */
    public static function runs(): array
    {
        $path = self::file();
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        $runs = is_array($decoded) && is_array($decoded['runs'] ?? null) ? $decoded['runs'] : [];
        return array_values(array_filter($runs, 'is_array'));
    }

    /**
     * Last run, today's totals and a short recent history for the UI.
     *
     * @return array{
     *     has_data: bool,
     *     last: array<string, mixed>|null,
     *     recent: list<array<string, mixed>>,
     *     today: array{runs: int, saved: int, failed: int, processed: int}
     * }
     */
    public static function summary(): array
    {
        $runs = self::runs();
        usort(
            $runs,
            static fn (array $a, array $b): int =>
                strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? ''))
        );
        $today = gmdate('Y-m-d');
        $todayRuns = 0;
        $saved = 0;
        $failed = 0;
        $processed = 0;

        foreach ($runs as $run) {
            if (substr((string) ($run['started_at'] ?? ''), 0, 10) !== $today) {
                continue;
            }
            $todayRuns++;
            $saved += (int) ($run['saved'] ?? 0);
            $failed += (int) ($run['failed'] ?? 0);
            $processed += (int) ($run['processed'] ?? 0);
        }

        return [
            'has_data' => $runs !== [],
            'last'     => $runs[0] ?? null,
            'recent'   => array_slice($runs, 0, 6),
            'today'    => [
                'runs'      => $todayRuns,
                'saved'     => $saved,
                'failed'    => $failed,
                'processed' => $processed,
            ],
        ];
    }
}
