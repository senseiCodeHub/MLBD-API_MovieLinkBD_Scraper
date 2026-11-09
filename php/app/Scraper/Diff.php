<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

/**
 * Structural comparison between a stored scrape and a fresh live scrape.
 *
 * Episodes are matched by ``(season, number)`` when both sides have them, then
 * by label. Sources match by ``stream_url`` and downloads by ``url``.
 */
final class Diff
{
    /**
     * @param array<string, mixed>|null $stored
     * @param array<string, mixed>      $live
     * @return array<string, mixed>
     */
    public static function compare(?array $stored, array $live, string $pageUrl): array
    {
        $stored = $stored ?? [];
        $liveEpisodes = [];
        foreach (($live['episodes'] ?? []) as $episode) {
            if (is_array($episode)) {
                $liveEpisodes[] = self::normalizeEpisode($episode);
            }
        }

        $diff = [
            'page_url' => $pageUrl,
            'title'    => self::field($stored['title'] ?? null, $live['title'] ?? null),
            'poster'   => self::field($stored['poster'] ?? null, $live['poster'] ?? null),
            'category' => self::field($stored['category'] ?? null, $live['category'] ?? null),
            'completed'=> self::field($stored['completed'] ?? null, $live['completed'] ?? null),
            'adult'    => self::field($stored['adult'] ?? null, $live['adult'] ?? null),
            'episodes' => [
                'added'         => [],
                'removed'       => [],
                'changed'       => [],
                'same'          => 0,
                'added_count'   => 0,
                'removed_count' => 0,
                'changed_count' => 0,
            ],
            'sources' => ['added_total' => 0, 'removed_total' => 0, 'changed_total' => 0],
            'downloads' => ['added_total' => 0, 'removed_total' => 0, 'changed_total' => 0],
        ];

        if ($stored === []) {
            foreach ($liveEpisodes as $episode) {
                $sourceDiff = self::diffLists([], $episode['sources'], [self::class, 'keySource']);
                $downloadDiff = self::diffLists([], $episode['downloads'], [self::class, 'keyDownload']);
                $diff['episodes']['added'][] = self::episodeEntry($episode, 'added', $sourceDiff, $downloadDiff);
                $diff['sources']['added_total'] += count($sourceDiff['added']);
                $diff['downloads']['added_total'] += count($downloadDiff['added']);
            }
            $diff['episodes']['added_count'] = count($diff['episodes']['added']);
            return $diff;
        }

        $storedEpisodes = [];
        foreach (($stored['episodes'] ?? []) as $episode) {
            if (is_array($episode)) {
                $storedEpisodes[] = self::normalizeEpisode($episode);
            }
        }

        $storedByKey = [];
        foreach ($storedEpisodes as $episode) {
            $storedByKey[self::keyEpisode($episode)] ??= $episode;
        }
        $liveByKey = [];
        foreach ($liveEpisodes as $episode) {
            $liveByKey[self::keyEpisode($episode)] ??= $episode;
        }

        foreach ($storedByKey as $key => $storedEpisode) {
            if (!isset($liveByKey[$key])) {
                continue;
            }
            $liveEpisode = $liveByKey[$key];
            $sourceDiff = self::diffLists($storedEpisode['sources'], $liveEpisode['sources'], [self::class, 'keySource']);
            $downloadDiff = self::diffLists($storedEpisode['downloads'], $liveEpisode['downloads'], [self::class, 'keyDownload']);

            if ($sourceDiff['added'] || $sourceDiff['removed'] || $sourceDiff['changed']
                || $downloadDiff['added'] || $downloadDiff['removed'] || $downloadDiff['changed']) {
                $diff['episodes']['changed'][] = self::episodeEntry($liveEpisode, 'changed', $sourceDiff, $downloadDiff);
                $diff['sources']['added_total'] += count($sourceDiff['added']);
                $diff['sources']['removed_total'] += count($sourceDiff['removed']);
                $diff['sources']['changed_total'] += count($sourceDiff['changed']);
                $diff['downloads']['added_total'] += count($downloadDiff['added']);
                $diff['downloads']['removed_total'] += count($downloadDiff['removed']);
                $diff['downloads']['changed_total'] += count($downloadDiff['changed']);
            } else {
                $diff['episodes']['same']++;
            }
        }

        foreach ($liveByKey as $key => $episode) {
            if (isset($storedByKey[$key])) {
                continue;
            }
            $sourceDiff = self::diffLists([], $episode['sources'], [self::class, 'keySource']);
            $downloadDiff = self::diffLists([], $episode['downloads'], [self::class, 'keyDownload']);
            $diff['episodes']['added'][] = self::episodeEntry($episode, 'added', $sourceDiff, $downloadDiff);
            $diff['sources']['added_total'] += count($sourceDiff['added']);
            $diff['downloads']['added_total'] += count($downloadDiff['added']);
        }

        foreach ($storedByKey as $key => $episode) {
            if (isset($liveByKey[$key])) {
                continue;
            }
            $diff['episodes']['removed'][] = self::episodeEntry($episode, 'removed', [], []);
        }

        $diff['episodes']['added_count'] = count($diff['episodes']['added']);
        $diff['episodes']['removed_count'] = count($diff['episodes']['removed']);
        $diff['episodes']['changed_count'] = count($diff['episodes']['changed']);

        return $diff;
    }

    /**
     * @param mixed $stored
     * @param mixed $live
     * @return array{stored:mixed,live:mixed,changed:bool}
     */
    private static function field($stored, $live): array
    {
        return [
            'stored'  => $stored,
            'live'    => $live,
            'changed' => !self::deepEqual($stored, $live),
        ];
    }

    /**
     * @param array<string, mixed> $episode
     * @param array<string, list<array<string, mixed>>> $sourceDiff
     * @param array<string, list<array<string, mixed>>> $downloadDiff
     * @return array<string, mixed>
     */
    private static function episodeEntry(array $episode, string $status, array $sourceDiff, array $downloadDiff): array
    {
        return [
            'label'             => (string) ($episode['label'] ?? ''),
            'number'            => $episode['number'] ?? null,
            'season'            => $episode['season'] ?? null,
            'status'            => $status,
            'sources_added'     => $sourceDiff['added'] ?? [],
            'sources_removed'   => $sourceDiff['removed'] ?? [],
            'sources_changed'   => $sourceDiff['changed'] ?? [],
            'downloads_added'   => $downloadDiff['added'] ?? [],
            'downloads_removed' => $downloadDiff['removed'] ?? [],
            'downloads_changed' => $downloadDiff['changed'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $episode
     * @return array<string, mixed>
     */
    private static function normalizeEpisode(array $episode): array
    {
        $sources = $episode['sources'] ?? [];
        $downloads = $episode['downloads'] ?? [];
        return [
            'label'     => $episode['label'] ?? null,
            'number'    => $episode['number'] ?? null,
            'season'    => $episode['season'] ?? null,
            'kind'      => $episode['kind'] ?? null,
            'sources'   => is_array($sources) ? $sources : [],
            'downloads' => is_array($downloads) ? $downloads : [],
        ];
    }

    /**
     * @param array<string, mixed> $episode
     */
    public static function keyEpisode(array $episode): string
    {
        $season = $episode['season'] ?? null;
        $number = $episode['number'] ?? null;
        if (is_int($season) && is_int($number)) {
            return 'n:' . $season . ':' . $number;
        }
        return 'l:' . strtolower(trim((string) ($episode['label'] ?? '')));
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function keySource(array $source): string
    {
        return (string) ($source['stream_url'] ?? '');
    }

    /**
     * @param array<string, mixed> $download
     */
    public static function keyDownload(array $download): string
    {
        return (string) ($download['url'] ?? '');
    }

    /**
     * @param list<array<string, mixed>> $stored
     * @param list<array<string, mixed>> $live
     * @param callable(array):string     $key
     * @return array{added:list<array<string,mixed>>,removed:list<array<string,mixed>>,changed:list<array<string,mixed>>}
     */
    private static function diffLists(array $stored, array $live, callable $key): array
    {
        $storedByKey = [];
        foreach ($stored as $item) {
            if (is_array($item)) {
                $storedByKey[$key($item)] ??= $item;
            }
        }
        $liveByKey = [];
        foreach ($live as $item) {
            if (is_array($item)) {
                $liveByKey[$key($item)] ??= $item;
            }
        }

        $added = [];
        foreach ($liveByKey as $itemKey => $item) {
            if (!isset($storedByKey[$itemKey])) {
                $added[] = $item;
            }
        }
        $removed = [];
        foreach ($storedByKey as $itemKey => $item) {
            if (!isset($liveByKey[$itemKey])) {
                $removed[] = $item;
            }
        }
        $changed = [];
        foreach ($storedByKey as $itemKey => $storedItem) {
            if (isset($liveByKey[$itemKey]) && !self::deepEqual($storedItem, $liveByKey[$itemKey])) {
                $changed[] = [
                    'stored' => $storedItem,
                    'live'   => $liveByKey[$itemKey],
                    'key'    => $itemKey,
                ];
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * Python-like equality: arrays compare by key/value, numbers by value.
     *
     * @param mixed $a
     * @param mixed $b
     */
    private static function deepEqual($a, $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::deepEqual($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        return $a === $b;
    }
}
