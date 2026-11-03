<?php
declare(strict_types=1);

namespace Mlbd\Library;

/**
 * Persistent progress file for the one-click library sync.
 *
 * A shared host cannot hold a long-running background task, so the sync is
 * chunked: each ``/library/sync/step`` call scrapes a few titles and writes
 * the new progress here. The UI polls until ``status`` leaves ``running``.
 */
final class SyncState
{
    private const FILE = 'sync.json';

    /**
     * Read the stored state, with defaults for a run that never started.
     *
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        $defaults = [
            'status'                => 'idle',
            'running'               => false,
            'cancelling'            => false,
            'total'                 => 0,
            'processed'             => 0,
            'saved'                 => 0,
            'failed'                => 0,
            'empty'                 => 0,
            'skipped'               => 0,
            'cursor'                => 0,
            'consecutive_failures'  => 0,
            'current'               => null,
            'started_at'            => null,
            'finished_at'           => null,
            'message'               => null,
            'params'                => [],
        ];

        $path = storage_path(self::FILE);
        if (!is_file($path)) {
            return $defaults;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        $state = array_merge($defaults, $decoded);
        $state['running'] = $state['status'] === 'running';
        return $state;
    }

    /**
     * Replace the stored state.
     *
     * @param array<string, mixed> $state
     */
    public static function write(array $state): void
    {
        $state['running'] = $state['status'] === 'running';
        $path = storage_path(self::FILE);
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }

        // Write to a temporary file and rename so a concurrent poll never reads
        // a half-written file.
        $temporary = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $json) !== false) {
            @rename($temporary, $path);
        }
    }

    /**
     * Derive the UI-facing snapshot (percent, rate, ETA) from stored state.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function snapshot(array $state): array
    {
        $total = (int) ($state['total'] ?? 0);
        $processed = (int) ($state['processed'] ?? 0);
        $skipped = (int) ($state['skipped'] ?? 0);
        $running = ($state['status'] ?? 'idle') === 'running';

        $elapsed = 0.0;
        if (!empty($state['started_at'])) {
            $started = strtotime((string) $state['started_at']);
            if ($started !== false) {
                $end = !empty($state['finished_at']) && !$running
                    ? (int) strtotime((string) $state['finished_at'])
                    : time();
                $elapsed = max(0.001, $end - $started);
            }
        }

        $done = max(0, $processed - $skipped);
        $rate = ($elapsed > 0 && $done > 0) ? $done / $elapsed : 0.0;
        $remaining = max(0, $total - $processed);
        $eta = ($running && $rate > 0) ? (int) ($remaining / $rate) : null;
        $percent = $total > 0 ? round(($processed / $total) * 100, 2) : 0.0;

        return [
            'running'               => $running,
            'cancelling'            => (bool) ($state['cancelling'] ?? false),
            'status'                => $state['status'] ?? 'idle',
            'total'                 => $total,
            'processed'             => $processed,
            'saved'                 => (int) ($state['saved'] ?? 0),
            'failed'                => (int) ($state['failed'] ?? 0),
            'empty'                 => (int) ($state['empty'] ?? 0),
            'skipped'               => $skipped,
            'consecutive_failures'  => (int) ($state['consecutive_failures'] ?? 0),
            'percent'               => $percent,
            'current'               => $state['current'] ?? null,
            'eta_seconds'           => $eta,
            'rate_per_minute'       => round($rate * 60, 2),
            'started_at'            => $state['started_at'] ?? null,
            'finished_at'           => $state['finished_at'] ?? null,
            'message'               => $state['message'] ?? null,
            'params'                => $state['params'] ?? [],
        ];
    }
}
