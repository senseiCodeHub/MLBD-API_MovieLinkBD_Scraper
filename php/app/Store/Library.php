<?php
declare(strict_types=1);

namespace Mlbd\Store;

use Mlbd\Scraper\ScraperError;
use PDO;

/**
 * MySQL-backed store for catalogue titles, scrape snapshots and links.
 *
 * A 1:1 port of ``scraper/store.py``: the API can answer from MySQL when the
 * site is unreachable, and the sync job records what it has already saved.
 */
final class Library
{
    /** Map a site kind to the stored category. */
    public const CATEGORY_TO_STORED = [
        'movie'          => 'movie',
        'series'         => 'series',
        'drama'          => 'drama',
        'anime'          => 'anime',
        'adult'          => 'adult',
        'download18plus' => 'adult',
    ];

    /** Map a stored category back to the API's category names. */
    public const STORED_TO_CATEGORY = [
        'movie'  => 'movie',
        'series' => 'series',
        'drama'  => 'drama',
        'anime'  => 'anime',
        'adult'  => 'adult',
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    // -----------------------------------------------------------------------
    // Titles
    // -----------------------------------------------------------------------

    /**
     * Insert or update catalogue titles; returns the number of rows sent.
     *
     * ``language`` is COALESCEd: the web catalogue carries no badge for a
     * title, and a NULL here must not erase the language the Python sync
     * filled in from the listing badges (every shelf filter reads it).
     *
     * @param list<array<string, mixed>> $rows
     */
    public function upsertTitles(array $rows, ?string $version = null): int
    {
        if ($rows === []) {
            return 0;
        }

        $now = self::now();
        $payload = [];
        foreach ($rows as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $kind = $row['kind'] ?? $row['type'] ?? null;
            $payload[] = [
                $slug,
                (string) ($row['title'] ?? '') !== '' ? (string) $row['title'] : $slug,
                (string) ($row['url'] ?? ''),
                self::strOrNull($row['poster'] ?? null),
                self::strOrNull($kind),
                self::CATEGORY_TO_STORED[(string) ($row['category'] ?? $kind ?? '')] ?? 'other',
                self::strOrNull($row['year'] ?? null),
                !empty($row['adult']) ? 1 : 0,
                self::strOrNull($row['quality'] ?? null),
                self::strOrNull($row['language'] ?? null),
                self::strOrNull($row['rating'] ?? null),
                $version,
                $now,
            ];
        }

        $sql = sprintf(
            'INSERT INTO %s
                (slug, title, url, poster, kind, category, year, adult,
                 quality, language, rating, source_version, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title=VALUES(title),
                url=VALUES(url),
                poster=VALUES(poster),
                kind=VALUES(kind),
                category=VALUES(category),
                year=VALUES(year),
                adult=VALUES(adult),
                quality=VALUES(quality),
                language=COALESCE(VALUES(language), language),
                rating=VALUES(rating),
                source_version=VALUES(source_version),
                updated_at=VALUES(updated_at)',
            Database::TITLES_TABLE
        );

        $sent = 0;
        foreach (array_chunk($payload, Database::TITLE_BATCH) as $chunk) {
            $this->executemany($sql, $chunk);
            $sent += count($chunk);
        }
        return $sent;
    }

    /**
     * Search stored titles; returns ``[items, total]``.
     *
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    public function searchTitles(string $query = '', string $category = 'all', int $limit = 24, int $offset = 0): array
    {
        $limit = max(0, $limit);
        $offset = max(0, $offset);

        $where = [];
        $args = [];
        if ($category !== 'all') {
            $where[] = 'category = ?';
            $args[] = self::CATEGORY_TO_STORED[$category] ?? $category;
        }
        foreach (self::tokens($query) as $token) {
            $where[] = 'title LIKE ?';
            $args[] = '%' . $token . '%';
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $table = Database::TITLES_TABLE;

        $countStatement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table}{$clause}");
        $countStatement->execute($args);
        $total = (int) $countStatement->fetchColumn();

        $pageStatement = $this->pdo->prepare(
            "SELECT slug, title, url, poster, kind, category, year, adult,
                    quality, language, rating
             FROM {$table}{$clause}
             ORDER BY year DESC, title ASC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $pageStatement->execute($args);

        $items = [];
        foreach ($pageStatement->fetchAll() as $row) {
            $stored = (string) ($row['category'] ?? 'other');
            $items[] = [
                'title'    => (string) ($row['title'] ?? ''),
                'url'      => (string) ($row['url'] ?? ''),
                'slug'     => $row['slug'],
                'poster'   => $row['poster'],
                'type'     => $row['kind'],
                'category' => self::STORED_TO_CATEGORY[$stored] ?? 'other',
                'year'     => $row['year'],
                'adult'    => (bool) $row['adult'],
                'quality'  => $row['quality'],
                'language' => $row['language'],
                'rating'   => $row['rating'],
            ];
        }
        return [$items, $total];
    }

    public function titleCount(): int
    {
        return (int) $this->pdo
            ->query('SELECT COUNT(*) FROM ' . Database::TITLES_TABLE)
            ->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Scrapes and links
    // -----------------------------------------------------------------------

    /**
     * Store one scrape payload plus its flattened links.
     *
     * @param array<string, mixed> $result
     * @return array{links:int,episodes:int}
     */
    public function saveScrape(array $result, ?string $requestedUrl = null): array
    {
        $pageUrl = trim((string) ($result['page_url'] ?? ''));
        if ($pageUrl === '') {
            throw new ScraperError('Cannot store a scrape without page_url');
        }

        $episodes = is_array($result['episodes'] ?? null) ? $result['episodes'] : [];
        $sources = is_array($result['sources'] ?? null) ? $result['sources'] : [];
        $downloads = is_array($result['downloads'] ?? null) ? $result['downloads'] : [];
        $scrapedAt = (
            self::parseDate($result['scraped_at'] ?? null)
            ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        )->format('Y-m-d H:i:s');
        $requested = trim((string) ($requestedUrl ?? $pageUrl));
        if ($requested === '') {
            $requested = $pageUrl;
        }

        $payloadJson = self::json($result);
        if ($payloadJson === null) {
            throw new ScraperError('Could not encode the scrape payload as JSON');
        }

        $links = self::linkRows($pageUrl, $result['title'] ?? null, $result);

        $this->pdo->beginTransaction();
        try {
            // The site hands out a new canonical id per request, so the same
            // requested URL comes back under a different page_url. Drop the
            // older row for this request instead of storing a duplicate.
            $stale = $this->pdo->prepare(
                'SELECT page_url FROM ' . Database::SCRAPES_TABLE
                . ' WHERE requested_url = ? AND page_url <> ?'
            );
            $stale->execute([$requested, $pageUrl]);
            $this->deletePages($stale->fetchAll(PDO::FETCH_COLUMN));

            $insert = $this->pdo->prepare(sprintf(
                'INSERT INTO %s
                    (page_url, requested_url, slug, title, poster, content_type,
                     category, adult, completed, episode_count, source_count,
                     download_count, payload, scraped_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    requested_url=VALUES(requested_url),
                    slug=VALUES(slug),
                    title=VALUES(title),
                    poster=VALUES(poster),
                    content_type=VALUES(content_type),
                    category=VALUES(category),
                    adult=VALUES(adult),
                    completed=VALUES(completed),
                    episode_count=VALUES(episode_count),
                    source_count=VALUES(source_count),
                    download_count=VALUES(download_count),
                    payload=VALUES(payload),
                    scraped_at=VALUES(scraped_at),
                    updated_at=VALUES(updated_at)',
                Database::SCRAPES_TABLE
            ));
            $insert->execute([
                $pageUrl,
                $requested,
                // Prefer the requested (catalogue) URL: the canonical page URL
                // carries an opaque mKs_ id that would otherwise become the slug
                // and let the same title be stored twice.
                self::slugFromUrl($requested !== '' ? $requested : $pageUrl),
                self::strOrNull($result['title'] ?? null),
                self::strOrNull($result['poster'] ?? null),
                self::strOrNull($result['content_type'] ?? null),
                self::strOrNull($result['category'] ?? null),
                !empty($result['adult']) ? 1 : 0,
                isset($result['completed']) && is_bool($result['completed']) ? ($result['completed'] ? 1 : 0) : null,
                count($episodes),
                count($sources),
                count($downloads),
                $payloadJson,
                $scrapedAt,
                self::now(),
            ]);

            $this->replaceLinks($pageUrl, $links);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return ['links' => count($links), 'episodes' => count($episodes)];
    }

    /**
     * Return ``[payload, age_seconds]`` when a fresh snapshot is stored.
     *
     * @return array{0:array<string,mixed>,1:int}|null
     */
    public function cachedScrape(string $pageUrl, int $maxAgeSeconds): ?array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(0, $maxAgeSeconds));
        $statement = $this->pdo->prepare(sprintf(
            'SELECT payload, updated_at FROM %s
             WHERE (page_url = ? OR requested_url = ?) AND updated_at >= ?
             ORDER BY updated_at DESC LIMIT 1',
            Database::SCRAPES_TABLE
        ));
        $statement->execute([$pageUrl, $pageUrl, $cutoff]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $payload = $row['payload'];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($payload)) {
            return null;
        }
        $updated = self::parseDate($row['updated_at'] ?? null);
        $age = $updated === null ? 0 : max(0, time() - $updated->getTimestamp());
        return [$payload, $age];
    }

    /**
     * Return stored links for one page (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public function linksFor(string $pageUrl): array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT episode_label, season, episode_number, provider, quality, audio,
                    kind, codec, size_bytes, content_type, stream_url, download_url,
                    is_best
             FROM %s
             WHERE page_url = ?
             ORDER BY episode_number ASC, quality ASC',
            Database::LINKS_TABLE
        ));
        $statement->execute([$pageUrl]);
        return $statement->fetchAll();
    }

    // -----------------------------------------------------------------------
    // Library tracking (MySQL as the primary store)
    // -----------------------------------------------------------------------

    /**
     * Save a scrape result and record its status (saved / empty / failed).
     *
     * @param array<string, mixed> $payload
     */
    public function recordScrapeResult(
        string $pageUrl,
        string $slug,
        string $title,
        bool $hasStreams,
        ?string $errorMessage,
        array $payload,
        ?string $status = null
    ): void {
        // A title can carry a note ("No stream links found") and still be a
        // successful scrape, so the caller may pin the status explicitly.
        $status = $status ?? (
            $errorMessage !== null && $errorMessage !== ''
                ? 'failed'
                : ($hasStreams ? 'saved' : 'empty')
        );
        $now = self::now();
        $payloadJson = self::json($payload) ?? '{}';

        $this->pdo->beginTransaction();
        try {
            // The catalogue URL and the scraper's canonical page URL differ
            // (``/anime/title-slug`` vs ``/anime/mKs_...``), so one title can
            // be stored under two page_urls. Drop any older row for this slug
            // so a re-scrape updates the title instead of duplicating it.
            $stale = $this->pdo->prepare(
                'SELECT page_url FROM ' . Database::SCRAPES_TABLE
                . ' WHERE slug = ? AND page_url <> ?'
            );
            $stale->execute([$slug, $pageUrl]);
            $this->deletePages($stale->fetchAll(PDO::FETCH_COLUMN));

            $insert = $this->pdo->prepare(sprintf(
                'INSERT INTO %s
                    (page_url, slug, title, status, error, payload, scraped_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    slug=VALUES(slug),
                    title=VALUES(title),
                    status=VALUES(status),
                    error=VALUES(error),
                    payload=VALUES(payload),
                    scraped_at=VALUES(scraped_at),
                    updated_at=VALUES(updated_at)',
                Database::SCRAPES_TABLE
            ));
            $insert->execute([
                $pageUrl,
                $slug,
                $title,
                $status,
                $errorMessage,
                $payloadJson,
                $now,
                $now,
            ]);

            $this->replaceLinks($pageUrl, self::linkRows($pageUrl, $title, $payload));
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Return catalogue slugs that have already been scraped.
     *
     * The sync queue holds catalogue URLs while rows are stored under the
     * scraper's canonical URL, so slugs are the only stable join key.
     *
     * @return list<string>
     */
    public function getScrapedSlugs(): array
    {
        $statement = $this->pdo->query(
            'SELECT DISTINCT slug FROM ' . Database::SCRAPES_TABLE
            . " WHERE slug IS NOT NULL AND slug <> ''"
        );
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Return catalogue slugs whose last scrape had no stream links.
     *
     * @return list<string>
     */
    public function getEmptyScrapedSlugs(): array
    {
        $statement = $this->pdo->query(
            'SELECT DISTINCT slug FROM ' . Database::SCRAPES_TABLE
            . " WHERE status = 'empty' AND slug IS NOT NULL AND slug <> ''"
        );
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Return slugs refreshed within the last ``$maxAgeSeconds``.
     *
     * Used by the scheduled refresh: every title outside this set is due to be
     * re-scraped, and a re-scrape moves it back into the set. Titles that were
     * never scraped are simply not in it, so new catalogue entries are picked
     * up on the next run.
     *
     * @return list<string>
     */
    public function getFreshSlugs(int $maxAgeSeconds): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(0, $maxAgeSeconds));
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT slug FROM ' . Database::SCRAPES_TABLE
            . " WHERE slug IS NOT NULL AND slug <> '' AND updated_at >= ?"
        );
        $statement->execute([$cutoff]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Last refresh time per slug (``''`` for titles never scraped).
     *
     * The refresh queue is ordered by this, oldest first: with a time budget
     * shorter than one full pass, walking the catalogue in its own order would
     * keep re-scraping the first slice and never reach the tail.
     *
     * @return array<string, string>
     */
    public function lastScrapeTimes(): array
    {
        $statement = $this->pdo->query(
            'SELECT slug, MAX(updated_at) AS last_at FROM ' . Database::SCRAPES_TABLE
            . " WHERE slug IS NOT NULL AND slug <> '' GROUP BY slug"
        );

        $times = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $times[(string) $row['slug']] = (string) ($row['last_at'] ?? '');
        }
        return $times;
    }

    /**
     * When the library was last written, and how much moved in the last day.
     *
     * @return array{last_at: ?string, updated_24h: int}
     */
    public function freshness(): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
        $statement = $this->pdo->prepare(
            'SELECT MAX(updated_at) AS last_at, COUNT(*) AS recent FROM ' . Database::SCRAPES_TABLE
            . ' WHERE updated_at >= ?'
        );
        $statement->execute([$cutoff]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'last_at'     => isset($row['last_at']) && $row['last_at'] !== null ? (string) $row['last_at'] : null,
            'updated_24h' => (int) ($row['recent'] ?? 0),
        ];
    }

    /**
     * Return one stored scrape with its flattened links (detail view).
     *
     * @return array<string, mixed>|null
     */
    public function getScrapeDetail(string $pageUrl): ?array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT page_url, slug, title, status, error, payload, scraped_at, updated_at
             FROM %s
             WHERE page_url = ?
             ORDER BY updated_at DESC
             LIMIT 1',
            Database::SCRAPES_TABLE
        ));
        $statement->execute([$pageUrl]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        $payload = $row['payload'];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $episodes = [];
        foreach ((array) ($payload['episodes'] ?? []) as $episode) {
            if (!is_array($episode)) {
                continue;
            }
            $episodeSources = [];
            foreach ((array) ($episode['sources'] ?? []) as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $episodeSources[] = [
                    'id'              => $source['id'] ?? null,
                    'provider'        => $source['provider'] ?? null,
                    'quality'         => $source['quality'] ?? null,
                    'quality_label'   => $source['quality_label'] ?? null,
                    'audio'           => $source['audio'] ?? null,
                    'audio_languages' => $source['audio_languages'] ?? null,
                    'type'            => $source['type'] ?? null,
                    'name'            => $source['name'] ?? null,
                    'codec'           => $source['codec'] ?? null,
                    'hevc'            => $source['hevc'] ?? null,
                    'is_best'         => $source['is_best'] ?? null,
                    'stream_url'      => $source['stream_url'] ?? null,
                    'download_url'    => $source['download_url'] ?? null,
                    'size_bytes'      => $source['size_bytes'] ?? null,
                    'content_type'    => $source['content_type'] ?? null,
                ];
            }
            $episodes[] = [
                'id'          => $episode['id'] ?? null,
                'label'       => $episode['label'] ?? null,
                'number'      => $episode['number'] ?? null,
                'season'      => $episode['season'] ?? null,
                'range_start' => $episode['range_start'] ?? null,
                'range_end'   => $episode['range_end'] ?? null,
                'kind'        => $episode['kind'] ?? null,
                'sources'     => $episodeSources,
            ];
        }

        $downloads = [];
        foreach ((array) ($payload['downloads'] ?? []) as $download) {
            if (!is_array($download)) {
                continue;
            }
            $downloads[] = [
                'label'      => $download['label'] ?? null,
                'quality'    => $download['quality'] ?? null,
                'size'       => $download['size'] ?? null,
                'size_bytes' => $download['size_bytes'] ?? null,
                'episode'    => $download['episode'] ?? null,
                'url'        => $download['url'] ?? null,
                'type'       => $download['type'] ?? null,
                'audio'      => $download['audio'] ?? null,
            ];
        }

        $scrapedAt = self::iso($row['scraped_at'] ?? null);
        return [
            'page_url'   => $row['page_url'],
            'slug'       => $row['slug'],
            'title'      => $row['title'],
            'status'     => $row['status'],
            'error'      => $row['error'],
            'payload'    => $payload,
            'episodes'   => $episodes,
            'sources'    => $payload['sources'] ?? [],
            'downloads'  => $downloads,
            'links'      => $this->linksFor($pageUrl),
            'scraped_at' => $scrapedAt,
            'updated_at' => self::iso($row['updated_at'] ?? null),
            'saved_at'   => $scrapedAt,
        ];
    }

    /**
     * Search stored scrapes with optional status/category filtering.
     *
     * @return array<string, mixed>
     */
    public function searchScrapes(
        string $query = '',
        string $category = 'all',
        string $status = 'saved',
        int $limit = 24,
        int $offset = 0
    ): array {
        $limit = max(0, $limit);
        $offset = max(0, $offset);

        $where = [];
        $args = [];
        if ($status !== '' && $status !== 'all') {
            $where[] = 'status = ?';
            $args[] = $status;
        }
        foreach (self::tokens($query) as $token) {
            $where[] = 'title LIKE ?';
            $args[] = '%' . $token . '%';
        }
        if ($category !== '' && $category !== 'all') {
            $where[] = 'category = ?';
            $args[] = $category;
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $table = Database::SCRAPES_TABLE;

        $countStatement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table}{$clause}");
        $countStatement->execute($args);
        $total = (int) $countStatement->fetchColumn();

        $pageStatement = $this->pdo->prepare(
            "SELECT page_url, slug, title, status, error, scraped_at, updated_at
             FROM {$table}{$clause}
             ORDER BY updated_at DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $pageStatement->execute($args);

        $results = [];
        foreach ($pageStatement->fetchAll() as $row) {
            $scrapedAt = self::iso($row['scraped_at'] ?? null);
            $results[] = [
                'page_url'   => $row['page_url'],
                'slug'       => $row['slug'],
                'title'      => $row['title'],
                'status'     => $row['status'],
                'error'      => $row['error'],
                'scraped_at' => $scrapedAt,
                'updated_at' => self::iso($row['updated_at'] ?? null),
                'saved_at'   => $scrapedAt,
            ];
        }

        return [
            'query'    => $query,
            'category' => $category,
            'status'   => $status,
            'results'  => $results,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
        ];
    }

    /**
     * Row counts per table plus a lightweight connectivity check.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $counts = [];
        foreach ([
            Database::TITLES_TABLE  => 'titles',
            Database::SCRAPES_TABLE => 'pages',
            Database::LINKS_TABLE   => 'links',
        ] as $table => $key) {
            $counts[$key] = (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }

        $latest = [];
        $row = $this->pdo
            ->query(
                'SELECT title, page_url, updated_at FROM ' . Database::SCRAPES_TABLE
                . ' ORDER BY updated_at DESC LIMIT 1'
            )
            ->fetch();
        if (is_array($row)) {
            $latest = [
                'title'      => $row['title'],
                'page_url'   => $row['page_url'],
                'updated_at' => self::iso($row['updated_at'] ?? null),
            ];
        }

        return ['ok' => true, 'tables' => $counts, 'latest_scrape' => $latest];
    }

    /**
     * Counts per scrape status (used by the library stats endpoint).
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $rows = $this->pdo
            ->query('SELECT status, COUNT(*) AS c FROM ' . Database::SCRAPES_TABLE . ' GROUP BY status')
            ->fetchAll();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Flatten episodes, sources and downloads into deduplicated link rows.
     *
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    public static function linkRows(string $pageUrl, $title, array $result): array
    {
        $rows = [];
        $add = static function (array $row) use (&$rows): void {
            $stream = $row['stream_url'] ?? null;
            if ($stream === null || $stream === '') {
                return;
            }
            $key = (string) ($row['episode_label'] ?? '') . "\0" . $stream;
            if (!isset($rows[$key])) {
                $rows[$key] = $row;
            }
        };

        $now = self::now();
        foreach ((array) ($result['episodes'] ?? []) as $episode) {
            if (!is_array($episode)) {
                continue;
            }
            $label = (string) ($episode['label'] ?? '');
            foreach ((array) ($episode['sources'] ?? []) as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $add([
                    'page_url'       => $pageUrl,
                    'title'          => $title !== null ? (string) $title : null,
                    'episode_label'  => $label,
                    'season'         => self::asInt($episode['season'] ?? null),
                    'episode_number' => self::asInt($episode['number'] ?? null),
                    'provider'       => self::strOrNull($source['provider'] ?? null),
                    'quality'        => self::strOrNull($source['quality'] ?? $source['quality_label'] ?? null),
                    'audio'          => self::strOrNull($source['audio'] ?? null),
                    'kind'           => self::strOrNull($source['type'] ?? null),
                    'codec'          => self::strOrNull($source['codec'] ?? null),
                    'size_bytes'     => self::asInt($source['size_bytes'] ?? null),
                    'content_type'   => self::strOrNull($source['content_type'] ?? null),
                    'stream_url'     => (string) $source['stream_url'],
                    'download_url'   => self::strOrNull($source['download_url'] ?? null),
                    'is_best'        => !empty($source['is_best']) ? 1 : 0,
                    'updated_at'     => $now,
                ]);
            }
        }

        foreach ((array) ($result['downloads'] ?? []) as $download) {
            if (!is_array($download)) {
                continue;
            }
            $url = self::strOrNull($download['url'] ?? null);
            if ($url === null) {
                continue;
            }
            $add([
                'page_url'       => $pageUrl,
                'title'          => $title !== null ? (string) $title : null,
                'episode_label'  => (string) ($download['episode'] ?? ''),
                'season'         => null,
                'episode_number' => null,
                'provider'       => null,
                'quality'        => self::strOrNull($download['quality'] ?? null),
                'audio'          => self::strOrNull($download['audio'] ?? null),
                'kind'           => self::strOrNull($download['type'] ?? null),
                'codec'          => null,
                'size_bytes'     => self::asInt($download['size_bytes'] ?? null),
                'content_type'   => null,
                'stream_url'     => $url,
                'download_url'   => $url,
                'is_best'        => 0,
                'updated_at'     => $now,
            ]);
        }

        return array_values($rows);
    }

    /**
     * @param list<string> $pageUrls
     */
    private function deletePages(array $pageUrls): void
    {
        if ($pageUrls === []) {
            return;
        }
        $marks = implode(',', array_fill(0, count($pageUrls), '?'));
        $links = $this->pdo->prepare(
            'DELETE FROM ' . Database::LINKS_TABLE . " WHERE page_url IN ({$marks})"
        );
        $links->execute($pageUrls);
        $scrapes = $this->pdo->prepare(
            'DELETE FROM ' . Database::SCRAPES_TABLE . " WHERE page_url IN ({$marks})"
        );
        $scrapes->execute($pageUrls);
    }

    /**
     * @param list<array<string, mixed>> $links
     */
    private function replaceLinks(string $pageUrl, array $links): void
    {
        $delete = $this->pdo->prepare(
            'DELETE FROM ' . Database::LINKS_TABLE . ' WHERE page_url = ?'
        );
        $delete->execute([$pageUrl]);

        if ($links === []) {
            return;
        }

        $sql = sprintf(
            'INSERT INTO %s
                (page_url, title, episode_label, season, episode_number,
                 provider, quality, audio, kind, codec, size_bytes,
                 content_type, stream_url, download_url, is_best, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            Database::LINKS_TABLE
        );
        foreach (array_chunk($links, Database::LINK_BATCH) as $chunk) {
            $this->executemany($sql, array_map(static fn (array $link): array => [
                $link['page_url'],
                $link['title'],
                $link['episode_label'],
                $link['season'],
                $link['episode_number'],
                $link['provider'],
                $link['quality'],
                $link['audio'],
                $link['kind'],
                $link['codec'],
                $link['size_bytes'],
                $link['content_type'],
                $link['stream_url'],
                $link['download_url'],
                $link['is_best'],
                $link['updated_at'],
            ], $chunk));
        }
    }

    /**
     * @param list<array<int, mixed>> $rows
     */
    private function executemany(string $sql, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $statement = $this->pdo->prepare($sql);
        foreach ($rows as $row) {
            $statement->execute(array_values($row));
        }
    }

    /**
     * Split a query into at most four LIKE-safe tokens.
     *
     * @return list<string>
     */
    private static function tokens(string $query): array
    {
        $tokens = [];
        foreach (preg_split('/\s+/u', $query) ?: [] as $part) {
            $token = trim(str_replace([',', '(', ')', '*', '\\', '%', '_'], ' ', $part));
            if ($token !== '') {
                $tokens[] = $token;
            }
            if (count($tokens) === 4) {
                break;
            }
        }
        return $tokens;
    }

    /** Slug segment of a detail page URL. */
    public static function slugFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $trimmed = rtrim($path, '/');
        $segment = substr($trimmed, (int) strrpos($trimmed, '/') + 1);
        return $segment === '' ? $trimmed : $segment;
    }

    private static function json(array $value): ?string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return $encoded === false ? null : $encoded;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Parse an ISO-8601 or SQL timestamp into UTC. */
    private static function parseDate($value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromFormat('U.u', $value->format('U.u'))
                ?->setTimezone(new \DateTimeZone('UTC'));
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $text = trim($value);
        try {
            $parsed = new \DateTimeImmutable($text, new \DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            return null;
        }
        return $parsed->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function iso($value): ?string
    {
        $parsed = self::parseDate($value);
        return $parsed?->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function asInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }

    private static function strOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
