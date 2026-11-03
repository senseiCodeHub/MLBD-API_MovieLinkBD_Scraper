<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

use Mlbd\Http\HttpClient;

/**
 * Whole-catalogue search for MovieLinkBD.
 *
 * The public ``/search?q=`` page only returns 25 results, so this class uses
 * the site's own search-suggest index: a manifest lists the current snapshot
 * version and 1,296 shards (``[a-z0-9]{2}``) that hold compact title rows.
 *
 * Each shard row is ``[title, slug, poster, type, year, adult]``. A title can
 * appear in several shards, so rows are de-duplicated by ``type:slug``. Shards
 * are capped at 240 rows; capped shards are re-fetched from the live fallback
 * endpoint to recover truncated rows. The merged index is cached on disk.
 */
final class Catalog
{
    private const MANIFEST_PATH = '/search-suggest/manifest';
    private const SHARD_ROOT = '/assets/search-suggest';
    private const LIVE_PATH = '/api/search-suggest-live.php';

    private const VALID_TYPES = ['movie', 'series', 'drama', 'anime', 'download18plus'];
    private const CATEGORY_TYPES = [
        'movie'  => ['movie'],
        'series' => ['series'],
        'drama'  => ['drama'],
        'anime'  => ['anime'],
        'adult'  => ['download18plus'],
    ];

    private const CHALLENGE_MARKERS = ['just a moment', 'challenge-platform', 'cf-chl'];

    private HttpClient $http;
    private string $baseUrl;
    private int $cacheTtl;
    private int $manifestTtl;
    private int $httpTimeout;
    private int $concurrency;
    private int $shardCap;

    /** @var array<string, mixed>|null */
    private static ?array $state = null;

    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    private bool $challengeDetected = false;

    public function __construct(?HttpClient $http = null)
    {
        $this->baseUrl = rtrim((string) config('site.base_url', 'https://movielinkbd.tv'), '/');
        $this->cacheTtl = (int) config('catalog.cache_ttl', 21600);
        $this->manifestTtl = (int) config('catalog.manifest_ttl', 1800);
        $this->httpTimeout = (int) config('catalog.http_timeout', 30);
        $this->concurrency = (int) config('catalog.concurrency', 32);
        $this->shardCap = (int) config('catalog.shard_cap', 240);
        $this->http = $http ?? new HttpClient($this->httpTimeout, 10);
    }

    /** Load (and cache) the catalogue index; return the number of titles. */
    public function ensure(bool $force = false): int
    {
        return (int) $this->loadState($force)['count'];
    }

    /**
     * Return the catalogue version and its raw ``[title, slug, ...]`` rows.
     *
     * @return array{0:?string,1:list<array>}
     */
    public function rows(): array
    {
        $state = $this->loadState(false);
        return [$state['version'], $state['rows']];
    }

    /**
     * Return every catalogue entry, optionally filtered by category.
     *
     * @return list<array<string, mixed>>
     */
    public function items(string $category = 'all'): array
    {
        [$version, $rows] = $this->rows();
        $allowed = self::CATEGORY_TYPES[$category] ?? null;

        $items = [];
        foreach ($rows as $row) {
            if ($allowed !== null && !in_array($row[3] ?? null, $allowed, true)) {
                continue;
            }
            $items[] = $this->toItem($row);
        }
        return $items;
    }

    /**
     * Search every title in the catalogue, optionally filtered by category.
     *
     * @return array<string, mixed>
     */
    public function search(string $query = '', string $category = 'all', int $limit = 24, int $offset = 0): array
    {
        $state = $this->loadState(false);
        $normalized = self::normalize($query);
        $tokens = $normalized === '' ? [] : explode(' ', $normalized);
        $allowed = self::CATEGORY_TYPES[$category] ?? null;

        $scored = [];
        foreach ($state['rows'] as $row) {
            if ($allowed !== null && !in_array($row[3] ?? null, $allowed, true)) {
                continue;
            }
            $score = self::score((string) ($row[0] ?? ''), $normalized, $tokens);
            if ($score <= 0) {
                continue;
            }
            $scored[] = [
                'score' => $score,
                'year'  => self::yearValue($row[4] ?? null),
                'title' => mb_strtolower((string) ($row[0] ?? '')),
                'row'   => $row,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($a['year'] !== $b['year']) {
                return $b['year'] <=> $a['year'];
            }
            return strcmp($a['title'], $b['title']);
        });

        $total = count($scored);
        $start = max(0, $offset);
        $page = array_slice($scored, $start, max(0, $limit));

        return [
            'query'         => $query,
            'category'      => $category,
            'results'       => array_map(fn (array $item) => $this->toItem($item['row']), $page),
            'total'         => $total,
            'limit'         => $limit,
            'offset'        => $start,
            'source'        => 'catalog',
            'catalog_count' => (int) $state['count'],
            'searched_at'   => now_iso(),
        ];
    }

    /**
     * Lightweight status information about the loaded index.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        if (self::$state !== null) {
            return [
                'loaded'      => true,
                'version'     => self::$state['version'],
                'count'       => self::$state['count'],
                'types'       => self::$state['types'],
                'age_seconds' => (int) (time() - self::$state['fetched_at']),
            ];
        }

        // Without a loaded state, report the newest cache file so the UI can
        // show the index badge without triggering a full crawl.
        $cache = $this->newestCacheHeader();
        if ($cache === null) {
            return ['loaded' => false];
        }
        return [
            'loaded'      => true,
            'version'     => $cache['version'] ?? null,
            'count'       => (int) ($cache['count'] ?? 0),
            'types'       => $cache['types'] ?? [],
            'age_seconds' => (int) (time() - (float) ($cache['fetched_at'] ?? 0)),
            'cached'      => true,
        ];
    }

    // -----------------------------------------------------------------------
    // State loading and caching
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function loadState(bool $force = false): array
    {
        $lock = fopen(storage_path('cache/catalog.lock'), 'c');
        if ($lock === false) {
            $manifest = $this->fetchManifest($force);
            return $this->buildState((string) $manifest['v'], $this->crawl($manifest));
        }

        try {
            flock($lock, LOCK_EX);
            $manifest = $this->fetchManifest($force);
            $version = (string) $manifest['v'];

            if (!$force && self::$state !== null && self::$state['version'] === $version) {
                if (time() - (float) self::$state['fetched_at'] < $this->cacheTtl) {
                    return self::$state;
                }
            }

            $cached = $this->loadCache($version);
            if ($cached !== null && !$force && time() - (float) $cached['fetched_at'] < $this->cacheTtl) {
                self::$state = $cached;
                return $cached;
            }

            try {
                $rows = $this->crawl($manifest);
            } catch (CloudflareBlocked $exception) {
                if ($cached !== null) {
                    self::$state = $cached;
                    return $cached;
                }
                throw $exception;
            } catch (\Throwable $exception) {
                if ($cached !== null) {
                    self::$state = $cached;
                    return $cached;
                }
                throw new ScraperError('Catalog crawl failed: ' . $exception->getMessage(), 0, $exception);
            }

            $state = $this->buildState($version, $rows);
            if ($state['count'] === 0) {
                if ($cached !== null) {
                    self::$state = $cached;
                    return $cached;
                }
                throw new ScraperError('Catalog crawl returned no titles');
            }

            self::$state = $state;
            $this->writeCache($state);
            return $state;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param list<array> $rows
     * @return array<string, mixed>
     */
    private function buildState(string $version, array $rows): array
    {
        $byKey = [];
        $types = [];
        foreach ($rows as $row) {
            $key = self::rowKey($row);
            if ($key === null) {
                continue;
            }
            $byKey[$key] = $row;
            $kind = (string) $row[3];
            $types[$kind] = ($types[$kind] ?? 0) + 1;
        }

        return [
            'version'    => $version,
            'fetched_at' => (float) time(),
            'rows'       => array_values($byKey),
            'by_key'     => $byKey,
            'types'      => $types,
            'count'      => count($byKey),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCache(string $version): ?array
    {
        $path = $this->cacheFile($version);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || ($payload['version'] ?? null) !== $version) {
            return null;
        }
        $rows = $payload['rows'] ?? null;
        if (!is_array($rows)) {
            return null;
        }

        $state = $this->buildState($version, $rows);
        $state['fetched_at'] = (float) ($payload['fetched_at'] ?? 0);
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeCache(array $state): void
    {
        $path = $this->cacheFile((string) $state['version']);
        $payload = [
            'version'    => $state['version'],
            'fetched_at' => $state['fetched_at'],
            'count'      => $state['count'],
            'types'      => $state['types'],
            'rows'       => $state['rows'],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function newestCacheHeader(): ?array
    {
        $files = glob(storage_path('cache/catalog-*.json')) ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, static fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        foreach ($files as $file) {
            $handle = @fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            // Read the head of the file: enough for everything before "rows".
            $head = fread($handle, 8192) ?: '';
            fclose($handle);

            $version = null;
            $fetchedAt = null;
            $count = null;
            $types = [];
            if (preg_match('/"version":"([^"]*)"/', $head, $match)) {
                $version = $match[1];
            }
            if (preg_match('/"fetched_at":([0-9.]+)/', $head, $match)) {
                $fetchedAt = (float) $match[1];
            }
            if (preg_match('/"count":(\d+)/', $head, $match)) {
                $count = (int) $match[1];
            }
            if (preg_match('/"types":(\{[^}]*\})/', $head, $match)) {
                $decoded = json_decode($match[1], true);
                if (is_array($decoded)) {
                    $types = $decoded;
                }
            }

            if ($version !== null) {
                return [
                    'version'    => $version,
                    'fetched_at' => $fetchedAt ?? 0,
                    'count'      => $count ?? 0,
                    'types'      => $types,
                ];
            }
        }
        return null;
    }

    private function cacheFile(string $version): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $version) ?: 'unknown';
        return storage_path('cache/catalog-' . $safe . '.json');
    }

    // -----------------------------------------------------------------------
    // Crawling
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetchManifest(bool $force = false): array
    {
        if (!$force && self::$manifest !== null && time() - (float) self::$manifest['fetched_at'] < $this->manifestTtl) {
            return self::$manifest['data'];
        }

        $cacheFile = storage_path('cache/manifest.json');
        if (!$force && is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['fetched_at'], $cached['data'])
                && time() - (float) $cached['fetched_at'] < $this->manifestTtl) {
                self::$manifest = $cached;
                return $cached['data'];
            }
        }

        $bucket = (int) floor(time() / 30000);
        $url = $this->baseUrl . self::MANIFEST_PATH . '?r=' . $bucket;
        $response = $this->http->request($url, $this->headers(), ['timeout' => $this->httpTimeout]);

        if ($this->looksLikeChallenge($response)) {
            throw new CloudflareBlocked(
                'MovieLinkBD is blocking this server\'s IP with a Cloudflare challenge, so the '
                . 'scraper cannot read the catalogue. Cloud and datacentre IPs are challenged; '
                . 'run the scraper from a residential connection.'
            );
        }
        if (!$response['ok'] || $response['status'] >= 400) {
            throw new ScraperError('Catalogue manifest request failed (HTTP ' . $response['status'] . ')');
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['v']) || empty($data['ready'])) {
            throw new ScraperError(
                'MovieLinkBD catalogue manifest is not ready (the site returned an unexpected response)'
            );
        }

        self::$manifest = ['fetched_at' => (float) time(), 'data' => $data];
        @file_put_contents(
            $cacheFile,
            json_encode(self::$manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        return $data;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array>
     */
    private function crawl(array $manifest): array
    {
        $version = (string) $manifest['v'];
        $rows = [];
        $shardLengths = [];

        $keys = [];
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        for ($i = 0; $i < strlen($alphabet); $i++) {
            for ($j = 0; $j < strlen($alphabet); $j++) {
                $keys[] = $alphabet[$i] . $alphabet[$j];
            }
        }

        $urls = [];
        foreach ($keys as $key) {
            $urls[$key] = $this->baseUrl . self::SHARD_ROOT . '/v' . $version . '/' . $key . '.json';
        }

        $this->challengeDetected = false;
        $results = $this->fetchJsonBatch(array_values($urls));

        foreach ($urls as $key => $url) {
            $data = $results[$url] ?? null;
            $shardRows = is_array($data) ? $data : [];
            $shardLengths[$key] = count($shardRows);
            foreach ($shardRows as $row) {
                $rowKey = self::rowKey($row);
                if ($rowKey !== null && !isset($rows[$rowKey])) {
                    $rows[$rowKey] = $row;
                }
            }
        }

        if ($this->challengeDetected && $rows === []) {
            throw new CloudflareBlocked(
                'MovieLinkBD is blocking this server\'s IP with a Cloudflare challenge.'
            );
        }

        // Shards are capped, so query the live fallback for the truncated ones.
        $capped = [];
        foreach ($shardLengths as $key => $size) {
            if ($size >= $this->shardCap) {
                $capped[] = $key;
            }
        }
        if ($capped !== []) {
            $liveUrls = [];
            foreach ($capped as $key) {
                $liveUrls[] = $this->baseUrl . self::LIVE_PATH . '?k=' . $key;
            }
            foreach ($this->fetchJsonBatch($liveUrls) as $data) {
                if (!is_array($data)) {
                    continue;
                }
                foreach ($data as $row) {
                    $rowKey = self::rowKey($row);
                    if ($rowKey !== null && !isset($rows[$rowKey])) {
                        $rows[$rowKey] = $row;
                    }
                }
            }
        }

        // A few recently-added titles only appear in the popular feed.
        $popularUrl = $this->baseUrl . self::LIVE_PATH . '?popular=1';
        $popular = $this->fetchJsonBatch([$popularUrl])[$popularUrl] ?? null;
        if (is_array($popular)) {
            foreach ($popular as $row) {
                $rowKey = self::rowKey($row);
                if ($rowKey !== null && !isset($rows[$rowKey])) {
                    $rows[$rowKey] = $row;
                }
            }
        }

        return array_values($rows);
    }

    /**
     * Fetch a batch of JSON endpoints, retrying transient failures.
     *
     * @param list<string> $urls
     * @return array<string, mixed>
     */
    private function fetchJsonBatch(array $urls): array
    {
        $results = [];
        $pending = $urls;

        for ($attempt = 0; $attempt < 3 && $pending !== []; $attempt++) {
            if ($attempt > 0) {
                usleep(600000 * $attempt);
            }
            $retry = [];
            $this->http->multiGet(
                $pending,
                max(1, $this->concurrency),
                function (string $url, array $response) use (&$results, &$retry): void {
                    if ($this->looksLikeChallenge($response)) {
                        $this->challengeDetected = true;
                        return;
                    }
                    if ($response['status'] === 404) {
                        $results[$url] = [];
                        return;
                    }
                    if (!$response['ok'] || $response['status'] >= 400) {
                        $retry[] = $url;
                        return;
                    }
                    $data = json_decode($response['body'], true);
                    if ($data === null && trim($response['body']) !== 'null') {
                        $retry[] = $url;
                        return;
                    }
                    $results[$url] = $data;
                },
                $this->headers(),
                ['timeout' => $this->httpTimeout]
            );
            $pending = $retry;
        }

        foreach ($pending as $url) {
            $results[$url] = null;
        }
        return $results;
    }

    /**
     * @param array{ok:bool,status:int,headers:array<string,string>,body:string,url:string,error:?string} $response
     */
    private function looksLikeChallenge(array $response): bool
    {
        if (!empty($response['headers']['cf-mitigated'])) {
            return true;
        }
        if (!in_array($response['status'], [403, 429, 503], true)) {
            return false;
        }
        $head = strtolower(substr($response['body'], 0, 4000));
        foreach (self::CHALLENGE_MARKERS as $marker) {
            if (str_contains($head, $marker)) {
                return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Row helpers
    // -----------------------------------------------------------------------

    /**
     * @param mixed $row
     */
    private static function rowKey($row): ?string
    {
        if (!is_array($row) || !array_is_list($row) || count($row) < 6) {
            return null;
        }
        $title = $row[0] ?? null;
        $slug = $row[1] ?? null;
        $kind = $row[3] ?? null;
        if (!is_string($title) || trim($title) === '') {
            return null;
        }
        if (!is_string($slug) || trim($slug) === '') {
            return null;
        }
        if (!in_array($kind, self::VALID_TYPES, true)) {
            return null;
        }
        return $kind . ':' . trim($slug);
    }

    /**
     * @param array $row
     * @return array<string, mixed>
     */
    private function toItem(array $row): array
    {
        [$title, $slug, $poster, $kind, $year, $adult] = array_pad($row, 6, null);
        $category = $kind === 'download18plus' ? 'adult' : $kind;

        return [
            'title'    => trim((string) $title),
            'url'      => $this->baseUrl . '/' . $kind . '/' . rawurlencode(trim((string) $slug)),
            'slug'     => trim((string) $slug),
            'poster'   => $poster ?: null,
            'type'     => $kind,
            'category' => $category,
            'year'     => $year ? (string) $year : null,
            'adult'    => (bool) $adult || $kind === 'download18plus',
            'quality'  => null,
            'language' => null,
            'rating'   => null,
        ];
    }

    public static function normalize(string $value): string
    {
        $text = $value;
        if (class_exists('\Normalizer')) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KD);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private static function yearValue($year): int
    {
        if ($year === null || $year === '') {
            return 0;
        }
        return (int) substr((string) $year, 0, 4);
    }

    /**
     * @param list<string> $tokens
     */
    private static function score(string $title, string $query, array $tokens): int
    {
        if ($query === '') {
            return 1;
        }
        $titleNormalized = self::normalize($title);
        if ($titleNormalized === '') {
            return 0;
        }
        if ($titleNormalized === $query) {
            return 1000;
        }
        if (str_starts_with($titleNormalized, $query)) {
            return 800;
        }
        if (str_contains($titleNormalized, $query)) {
            return 600;
        }

        $titleTokens = explode(' ', $titleNormalized);

        if ($tokens !== []) {
            $allPrefix = true;
            foreach ($tokens as $part) {
                $found = false;
                foreach ($titleTokens as $token) {
                    if (str_starts_with($token, $part)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $allPrefix = false;
                    break;
                }
            }
            if ($allPrefix) {
                return 400;
            }

            $allContains = true;
            foreach ($tokens as $part) {
                $found = false;
                foreach ($titleTokens as $token) {
                    if (str_contains($token, $part)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $allContains = false;
                    break;
                }
            }
            if ($allContains) {
                return 300;
            }
        }

        return 0;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'user-agent'      => (string) config('scraper.user_agent'),
            'accept'          => 'application/json, text/plain, */*',
            'accept-language' => 'en-US,en;q=0.9',
            'referer'         => $this->baseUrl . '/',
            'sec-fetch-dest'  => 'empty',
            'sec-fetch-mode'  => 'cors',
            'sec-fetch-site'  => 'same-origin',
        ];
    }
}
