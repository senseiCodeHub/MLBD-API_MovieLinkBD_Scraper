<?php
declare(strict_types=1);

namespace Mlbd\Library;

use Mlbd\Scraper\Catalog;
use Mlbd\Scraper\CloudflareBlocked;
use Mlbd\Scraper\Scraper;
use Mlbd\Scraper\Taxonomy;
use Mlbd\Store\Database;
use Mlbd\Store\Library;

/**
 * One-click catalogue sync into MySQL, executed in short resumable steps.
 *
 * PHP-FPM/Webuzo cannot keep a background worker alive, so ``step()`` scrapes a
 * small batch of titles and returns. The UI calls it repeatedly; every call
 * reloads its queue from the catalogue and the database, which also makes the
 * job safe to resume after a timeout or a closed browser tab.
 */
final class SyncJob
{
    public const CATEGORIES = ['all', 'movie', 'series', 'drama', 'anime', 'adult'];

    /**
     * Start a run (or report that one is already running).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function start(array $options): array
    {
        $current = SyncState::read();
        if ($current['status'] === 'running') {
            return SyncState::snapshot($current);
        }

        if (!Database::configured()) {
            throw new \RuntimeException(
                'MySQL is not configured: set db.host and db.database in config.php'
            );
        }

        $category = (string) ($options['category'] ?? 'all');
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'all';
        }

        $force = (bool) ($options['force'] ?? false);
        $retryEmpty = (bool) ($options['retry_empty'] ?? false);
        $mode = (string) ($options['mode'] ?? '');
        if (!in_array($mode, ['fresh', 'refresh', 'force', 'retry_empty'], true)) {
            $mode = $force ? 'force' : ($retryEmpty ? 'retry_empty' : 'fresh');
        }
        if ($mode === 'force') {
            $force = true;
            $retryEmpty = false;
        } elseif ($mode === 'retry_empty') {
            $force = false;
            $retryEmpty = true;
        } else {
            $force = false;
            $retryEmpty = false;
        }

        $params = [
            'category'       => $category,
            'mode'           => $mode,
            'include_adult'  => (bool) ($options['include_adult'] ?? true),
            'force'          => $force,
            'retry_empty'    => $retryEmpty,
            'probe'          => (bool) ($options['probe'] ?? false),
            'require_streams'=> (bool) ($options['require_streams'] ?? true),
            'refresh_hours'  => clamp_int(
                $options['refresh_hours'] ?? null,
                1,
                720,
                (int) config('sync.refresh_hours', 6)
            ),
            'concurrency'    => clamp_int(
                $options['concurrency'] ?? null,
                1,
                (int) config('sync.max_concurrency', 12),
                (int) config('sync.default_concurrency', 3)
            ),
            'limit'          => isset($options['limit']) && is_numeric($options['limit'])
                ? max(1, (int) $options['limit'])
                : null,
        ];

        $state = [
            'status'               => 'running',
            'cancelling'           => false,
            'total'                => 0,
            'processed'            => 0,
            'saved'                => 0,
            'failed'               => 0,
            'empty'                => 0,
            'skipped'              => 0,
            'attempted'            => 0,
            'initialized'          => false,
            'consecutive_failures' => 0,
            'current'              => null,
            'started_at'           => now_iso(),
            'finished_at'          => null,
            'message'              => 'Loading catalogue…',
            'params'               => $params,
        ];

        SyncState::write($state);
        return SyncState::snapshot($state);
    }

    /** Ask a running sync to stop after the current batch. */
    public static function cancel(): array
    {
        $state = SyncState::read();
        if ($state['status'] === 'running') {
            $state['cancelling'] = true;
            $state['message'] = 'Cancelling…';
            SyncState::write($state);
        }
        return SyncState::snapshot($state);
    }

    /** Current progress without doing any work. */
    public static function status(): array
    {
        return SyncState::snapshot(SyncState::read());
    }

    /**
     * Scrape the next batch of titles and persist progress.
     *
     * @return array<string, mixed>
     */
    public static function step(): array
    {
        $state = SyncState::read();
        if ($state['status'] !== 'running') {
            return SyncState::snapshot($state);
        }

        if ($state['cancelling'] ?? false) {
            return self::finish($state, 'cancelled', 'Cancelled');
        }

        $params = is_array($state['params'] ?? null) ? $state['params'] : [];
        $perStep = max(1, (int) config('sync.titles_per_step', 1));
        $maxFailures = max(1, (int) config('sync.max_consecutive_failures', 15));

        try {
            $catalog = new Catalog();
            $items = $catalog->items((string) ($params['category'] ?? 'all'));
            if (!($params['include_adult'] ?? true)) {
                $items = array_values(array_filter(
                    $items,
                    static fn (array $item): bool => empty($item['adult'])
                ));
            }
            $limit = $params['limit'] ?? null;
            // Seed every title in scope, not just the ``--limit`` slice, so a
            // handful-of-titles test run still leaves a usable titles table.
            $catalogueItems = $items;
            if (is_int($limit) && $limit > 0) {
                $items = array_slice($items, 0, $limit);
            }

            $library = new Library();
            $force = (bool) ($params['force'] ?? false);
            $retryEmpty = (bool) ($params['retry_empty'] ?? false);
            $mode = (string) ($params['mode'] ?? '');
            if ($mode === '') {
                $mode = $force ? 'force' : ($retryEmpty ? 'retry_empty' : 'fresh');
            }

            if ($force) {
                $candidates = $items;
            } elseif ($retryEmpty) {
                $slugs = array_fill_keys($library->getEmptyScrapedSlugs(), true);
                $candidates = array_values(array_filter(
                    $items,
                    static fn (array $item): bool => isset($slugs[trim((string) ($item['slug'] ?? ''))])
                ));
            } elseif ($mode === 'refresh') {
                $refreshHours = max(1, (int) ($params['refresh_hours'] ?? 6));
                $fresh = array_fill_keys($library->getFreshSlugs($refreshHours * 3600), true);
                $candidates = array_values(array_filter(
                    $items,
                    static fn (array $item): bool => !isset($fresh[trim((string) ($item['slug'] ?? ''))])
                ));

                // Oldest refresh first. A budgeted run only gets through a slice
                // of the catalogue, so taking titles in catalogue order would
                // re-scrape the same head every run and starve the tail.
                $lastSeen = $library->lastScrapeTimes();
                usort($candidates, static function (array $a, array $b) use ($lastSeen): int {
                    $a = $lastSeen[trim((string) ($a['slug'] ?? ''))] ?? '';
                    $b = $lastSeen[trim((string) ($b['slug'] ?? ''))] ?? '';
                    return $a <=> $b;
                });
            } else {
                $slugs = array_fill_keys($library->getScrapedSlugs(), true);
                $candidates = array_values(array_filter(
                    $items,
                    static fn (array $item): bool => !isset($slugs[trim((string) ($item['slug'] ?? ''))])
                ));
            }

            if (empty($state['initialized'])) {
                // Mirror the catalogue into mlbd_titles first: the scraping
                // pass only writes snapshots, so without this seed the titles
                // table (and every db-scoped search) stays empty.
                [$version] = $catalog->rows();
                // The shards carry no language badge, so the Bangla and Hindi
                // shelves would read an empty column. The listing pages carry
                // it; a crawl failure must not stop the sync, the shelves
                // simply keep the badges they already have.
                try {
                    $taxonomy = new Taxonomy();
                    if ($taxonomy->ensure() > 0) {
                        $catalogueItems = $taxonomy->enrich($catalogueItems);
                    }
                } catch (\Throwable $exception) {
                    error_log('MLBD sync: badge index unavailable: ' . $exception->getMessage());
                }
                $library->upsertTitles($catalogueItems, $version);

                $state['total'] = count($items);
                $state['skipped'] = $force ? 0 : max(0, count($items) - count($candidates));
                $state['initialized'] = true;
                $state['processed'] = (int) $state['skipped'];
                SyncState::write($state);
            }

            $attempted = (int) ($state['attempted'] ?? 0);
            $offset = $force ? $attempted : 0;
            $batch = array_slice($candidates, $offset, $perStep);

            if ($batch === []) {
                if (count($candidates) > 0) {
                    $message = 'Finished';
                } elseif ($retryEmpty) {
                    $message = 'No no-stream titles to retry';
                } elseif ($mode === 'refresh') {
                    $message = 'Everything is already fresh';
                } else {
                    $message = 'Everything is already saved';
                }
                return self::finish($state, 'finished', $message);
            }

            $state['message'] = sprintf(
                'Scraping %d title%s',
                count($batch),
                count($batch) === 1 ? '' : 's'
            );
            SyncState::write($state);

            $scraper = new Scraper();
            foreach ($batch as $item) {
                if ($state['cancelling'] ?? false) {
                    break;
                }

                $state['current'] = (string) ($item['title'] ?? '');
                SyncState::write($state);

                $attempted++;
                $state['attempted'] = $attempted;
                $state['processed'] = (int) $state['skipped'] + $attempted;

                $slug = trim((string) ($item['slug'] ?? ''));
                $title = (string) ($item['title'] ?? $slug);
                $url = (string) ($item['url'] ?? '');

                try {
                    $result = $scraper->scrape($url, 30, (bool) ($params['probe'] ?? false));
                    $totalSources = 0;
                    foreach ((array) ($result['episodes'] ?? []) as $episode) {
                        $totalSources += count((array) ($episode['sources'] ?? []));
                    }
                    $hasStreams = $totalSources > 0;
                    $state['consecutive_failures'] = 0;

                    $requireStreams = (bool) ($params['require_streams'] ?? true);
                    $status = null;
                    if ($hasStreams) {
                        $state['saved'] = (int) $state['saved'] + 1;
                        $error = null;
                    } elseif ($requireStreams) {
                        $state['empty'] = (int) $state['empty'] + 1;
                        $error = 'No stream links found';
                        $status = 'empty';
                    } else {
                        $state['saved'] = (int) $state['saved'] + 1;
                        $error = null;
                    }

                    $library->recordScrapeResult(
                        (string) ($result['page_url'] ?? $url),
                        $slug,
                        $title,
                        $hasStreams,
                        $error,
                        $result,
                        $status
                    );
                } catch (\Throwable $exception) {
                    $library->recordScrapeResult(
                        $url,
                        $slug,
                        $title,
                        false,
                        get_class($exception) . ': ' . $exception->getMessage(),
                        []
                    );
                    $state['failed'] = (int) $state['failed'] + 1;
                    $state['consecutive_failures'] = (int) $state['consecutive_failures'] + 1;

                    // A challenge follows the IP, not the title: retrying the
                    // remaining queue only burns time and looks like abuse.
                    if ($exception instanceof CloudflareBlocked) {
                        SyncState::write($state);
                        return self::finish(
                            $state,
                            'failed',
                            'MovieLinkBD is challenging this host (Cloudflare) — stopped instead of retrying.'
                        );
                    }

                    // Back off while the site keeps failing (timeouts, 5xx) so a
                    // bad patch does not hammer it with back-to-back requests.
                    $failures = (int) $state['consecutive_failures'];
                    if ($failures > 1) {
                        usleep(min(4000, 350 * $failures) * 1000);
                    }
                }

                SyncState::write($state);

                if ((int) $state['consecutive_failures'] >= $maxFailures) {
                    return self::finish(
                        $state,
                        'failed',
                        sprintf('Stopped after %d consecutive failures', $maxFailures)
                    );
                }
            }

            if ($state['cancelling'] ?? false) {
                return self::finish($state, 'cancelled', 'Cancelled');
            }

            // Is there anything left after this batch?
            $done = $force
                ? $attempted >= count($items)
                : count($candidates) <= count($batch);
            if ($done) {
                return self::finish($state, 'finished', 'Finished');
            }

            $state['current'] = null;
            $state['message'] = 'Running…';
            SyncState::write($state);
            return SyncState::snapshot($state);
        } catch (\Throwable $exception) {
            return self::finish($state, 'failed', 'Sync failed: ' . $exception->getMessage());
        }
    }

    /**
     * Mark the run as no longer running.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function finish(array $state, string $status, string $message): array
    {
        $state['status'] = $status;
        $state['cancelling'] = false;
        $state['current'] = null;
        $state['message'] = $message;
        $state['finished_at'] = now_iso();
        SyncState::write($state);
        return SyncState::snapshot($state);
    }
}
