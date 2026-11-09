<?php
declare(strict_types=1);

namespace Mlbd\Http;

use Mlbd\Library\SyncJob;
use Mlbd\Scraper\Catalog;
use Mlbd\Scraper\CloudflareBlocked;
use Mlbd\Scraper\Diff;
use Mlbd\Scraper\NoStreamFound as NoStreamFoundAlias;
use Mlbd\Scraper\Scraper;
use Mlbd\Scraper\ScraperError;
use Mlbd\Scraper\SiteSearch;
use Mlbd\Store\Database;
use Mlbd\Store\Library;

/**
 * JSON API dispatcher.
 *
 * Routes mirror the FastAPI backend one-for-one so existing clients (and the
 * Next.js UI) can point at the PHP deployment without changes.
 */
final class Api
{
    /** Category names accepted by the public API. */
    private const CATEGORIES = ['all', 'movie', 'series', 'drama', 'anime', 'adult'];

    private const TYPE_TO_CATEGORY = [
        'movie'          => 'movie',
        'series'         => 'series',
        'drama'          => 'drama',
        'anime'          => 'anime',
        'download18plus' => 'adult',
        'adult'          => 'adult',
    ];

    /**
     * Handle an API path when it matches a known route.
     *
     * @return bool true when the request was answered here
     */
    public static function handle(string $path, string $method): bool
    {
        $segments = explode('/', trim($path, '/'));
        $head = $segments[0] ?? '';

        switch ($head) {
            case 'health':
                json_response(['status' => 'ok']);
                return true;

            case 'scrape':
                if (($segments[1] ?? '') === 'save') {
                    self::scrape(true);
                    return true;
                }
                if (!isset($segments[1])) {
                    self::scrape(false);
                    return true;
                }
                return false;

            case 'compare':
                if (!isset($segments[1])) {
                    self::compare();
                    return true;
                }
                return false;

            case 'search':
                if (!isset($segments[1])) {
                    self::search();
                    return true;
                }
                return false;

            case 'catalog':
                $action = $segments[1] ?? '';
                if ($action === 'status' && !isset($segments[2])) {
                    json_response((new Catalog())->stats());
                    return true;
                }
                if ($action === 'sync' && !isset($segments[2])) {
                    self::catalogSync();
                    return true;
                }
                if ($action === 'refresh' && !isset($segments[2])) {
                    self::catalogRefresh();
                    return true;
                }
                return false;

            case 'db':
                if (($segments[1] ?? '') === 'status' && !isset($segments[2])) {
                    self::dbStatus();
                    return true;
                }
                return false;

            case 'library':
                return self::library($segments);
        }

        return false;
    }

    /** GET/POST /scrape and POST /scrape/save. */
    private static function scrape(bool $forceSave): void
    {
        $body = request_body();
        $url = trim((string) ($body['url'] ?? $_GET['url'] ?? ''));
        $probe = self::boolParam($body['probe'] ?? $_GET['probe'] ?? true);

        if ($url === '') {
            json_response([
                'ok'         => false,
                'data'       => null,
                'error'      => 'A detail page url is required',
                'error_type' => 'ScraperError',
            ]);
        }

        try {
            try {
                $payload = (new Scraper())->scrape($url, 30, $probe);
            } catch (\Throwable $exception) {
                $cached = self::cachedScrape($url, $exception->getMessage());
                if ($cached !== null) {
                    json_response(['ok' => true, 'data' => $cached, 'error' => null, 'error_type' => null]);
                }
                throw $exception;
            }

            $storage = self::storeScrape($payload, $url);
            $payload['saved_to_library'] = $storage['saved'];
            $payload['saved_links'] = $storage['links'];
            json_response(['ok' => true, 'data' => $payload, 'error' => null, 'error_type' => null]);
        } catch (\Throwable $exception) {
            self::fail($exception);
        }
    }

    /** POST /compare — live scrape compared with the stored snapshot. */
    private static function compare(): void
    {
        $body = request_body();
        $url = trim((string) ($body['url'] ?? $_GET['url'] ?? ''));
        $probe = self::boolParam($body['probe'] ?? $_GET['probe'] ?? true);

        if ($url === '') {
            json_response([
                'ok'         => false,
                'data'       => null,
                'error'      => 'A detail page url is required',
                'error_type' => 'ScraperError',
            ]);
        }

        try {
            $live = (new Scraper())->scrape($url, 30, $probe);
        } catch (\Throwable $exception) {
            self::fail($exception);
            return;
        }

        $stored = null;
        if (Database::writable()) {
            try {
                $detail = (new Library())->getScrapeDetail($url);
                if ($detail !== null) {
                    $stored = is_array($detail['payload'] ?? null) ? $detail['payload'] : [];
                }
            } catch (\Throwable $exception) {
                error_log('MLBD: could not read stored scrape: ' . $exception->getMessage());
            }
        }

        json_response([
            'ok'         => true,
            'data'       => [
                'page_url' => $url,
                'stored'   => $stored,
                'live'     => $live,
                'diff'     => Diff::compare($stored, $live, $url),
            ],
            'error'      => null,
            'error_type' => null,
        ]);
    }

    /** GET /search?q=&category=&limit=&offset=&scope=. */
    private static function search(): void
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $category = (string) ($_GET['category'] ?? 'all');
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'all';
        }
        $limit = clamp_int($_GET['limit'] ?? null, 1, 100, 24);
        $offset = clamp_int($_GET['offset'] ?? null, 0, PHP_INT_MAX, 0);
        $scope = (string) ($_GET['scope'] ?? 'catalog');

        try {
            if ($scope === 'db') {
                $data = self::searchFromDb($query, $category, $limit, $offset);
            } elseif ($scope === 'site') {
                $data = self::searchSite($query, $limit);
            } else {
                try {
                    $data = (new Catalog())->search($query, $category, $limit, $offset);
                } catch (CloudflareBlocked | ScraperError $exception) {
                    $data = self::searchFromDb(
                        $query,
                        $category,
                        $limit,
                        $offset,
                        $exception->getMessage()
                    );
                }
            }
            json_response(['ok' => true, 'data' => $data, 'error' => null, 'error_type' => null]);
        } catch (\Throwable $exception) {
            self::fail($exception);
        }
    }

    /** POST /catalog/sync — push the catalogue index into MySQL. */
    private static function catalogSync(): void
    {
        $force = self::boolParam($_GET['force'] ?? request_body()['force'] ?? false);

        try {
            if (!Database::configured()) {
                json_response([
                    'ok'         => false,
                    'data'       => null,
                    'error'      => 'MySQL is not configured: set db.host and db.database in config.php',
                    'error_type' => 'ScraperError',
                ], 503);
                return;
            }

            $catalog = new Catalog();
            $count = $catalog->ensure($force);
            [$version, $rows] = $catalog->rows();

            $payload = [];
            foreach ($rows as $row) {
                $title = (string) ($row[0] ?? '');
                $slug = trim((string) ($row[1] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $kind = (string) ($row[3] ?? '');
                $payload[] = [
                    'slug'     => $slug,
                    'title'    => trim($title),
                    'url'      => (string) config('site.base_url', 'https://movielinkbd.tv')
                        . '/' . $kind . '/' . rawurlencode($slug),
                    'poster'   => $row[2] ?? null,
                    'kind'     => $kind,
                    'category' => Library::CATEGORY_TO_STORED[$kind] ?? 'other',
                    'year'     => isset($row[4]) && $row[4] !== null ? (string) $row[4] : null,
                    'adult'    => !empty($row[5]) || $kind === 'download18plus',
                ];
            }

            $library = new Library();
            $pushed = $library->upsertTitles($payload, $version);
            json_response([
                'ok'         => true,
                'data'       => [
                    'local_titles' => $count,
                    'pushed'       => $pushed,
                    'version'      => $version,
                    'tables'       => $library->stats()['tables'],
                ],
                'error'      => null,
                'error_type' => null,
            ]);
        } catch (\Throwable $exception) {
            self::fail($exception, 503);
        }
    }

    /** POST /catalog/refresh — force a fresh crawl of the suggest shards. */
    private static function catalogRefresh(): void
    {
        try {
            $catalog = new Catalog();
            $count = $catalog->ensure(true);
            json_response(['ok' => true, 'data' => ['count' => $count] + $catalog->stats()]);
        } catch (\Throwable $exception) {
            self::fail($exception, 503);
        }
    }

    /** GET /db/status — MySQL connectivity and mirror size. */
    private static function dbStatus(): void
    {
        $config = [
            'host' => (string) config('db.host', ''),
            'db'   => (string) config('db.database', ''),
        ];
        if ($config['host'] === '' || $config['db'] === '') {
            json_response([
                'configured'     => false,
                'mysql'          => false,
                'mysql_writable' => false,
                'hint'           => 'Set db.host, db.user, db.password and db.database in config.php',
            ]);
            return;
        }

        $connection = Database::testConnection();
        if (!$connection['ok']) {
            json_response([
                'configured'     => true,
                'mysql'          => false,
                'mysql_writable' => false,
                'ok'             => false,
                'error'          => $connection['error'] ?? 'unknown error',
            ]);
            return;
        }

        try {
            $stats = (new Library())->stats();
        } catch (\Throwable $exception) {
            json_response([
                'configured'     => true,
                'mysql'          => true,
                'mysql_writable' => true,
                'ok'             => false,
                'error'          => $exception->getMessage(),
            ]);
            return;
        }

        json_response([
            'configured'     => true,
            'mysql'          => true,
            'mysql_writable' => true,
            'ok'             => true,
        ] + $stats);
    }

    /**
     * /library routes: stats, sync control, list and detail.
     *
     * @param list<string> $segments
     */
    private static function library(array $segments): bool
    {
        $action = $segments[1] ?? '';

        if ($action === 'stats' && !isset($segments[2])) {
            self::libraryStats();
            return true;
        }
        if ($action === 'sync') {
            $sub = $segments[2] ?? '';
            if ($sub === '') {
                self::syncStart();
                return true;
            }
            if ($sub === 'status') {
                json_response(self::ok(SyncJob::status()));
                return true;
            }
            if ($sub === 'step') {
                json_response(self::ok(SyncJob::step()));
                return true;
            }
            if ($sub === 'cancel') {
                json_response(self::ok(SyncJob::cancel()));
                return true;
            }
            return false;
        }

        // /library  → search saved titles
        if (!isset($segments[1]) || $segments[1] === '') {
            self::librarySearch();
            return true;
        }

        // /library/<page_url> → one saved title (the URL may be percent-encoded)
        $pageUrl = implode('/', array_slice($segments, 1));
        self::libraryTitle(rawurldecode($pageUrl));
        return true;
    }

    private static function libraryStats(): void
    {
        $sync = SyncJob::status();
        $empty = [
            'mysql'          => false,
            'mysql_writable' => false,
            'tables'         => ['titles' => 0, 'pages' => 0, 'links' => 0],
            'latest_scrape'  => [],
        ];

        if (!Database::configured()) {
            json_response(self::ok($empty + [
                'sync'      => $sync,
                'titles'    => ['total' => 0, 'saved' => 0, 'pending' => 0, 'failed' => 0, 'empty' => 0],
                'episodes'  => 0,
                'sources'   => 0,
                'downloads' => 0,
                'size_bytes'=> 0,
            ]));
            return;
        }

        try {
            $library = new Library();
            $stats = $library->stats();
            $statuses = $library->statusCounts();
        } catch (\Throwable $exception) {
            json_response(self::ok($empty + [
                'sync'      => $sync,
                'titles'    => ['total' => 0, 'saved' => 0, 'pending' => 0, 'failed' => 0, 'empty' => 0],
                'episodes'  => 0,
                'sources'   => 0,
                'downloads' => 0,
                'size_bytes'=> 0,
                'error'     => $exception->getMessage(),
            ]));
            return;
        }

        $tables = $stats['tables'];
        $totalTitles = (int) $tables['titles'];
        $saved = (int) ($statuses['saved'] ?? 0);
        $emptyCount = (int) ($statuses['empty'] ?? 0);
        $failed = (int) ($statuses['failed'] ?? 0);
        $links = (int) $tables['links'];

        json_response(self::ok([
            'mysql'          => true,
            'mysql_writable' => true,
            'sync'           => $sync,
            'tables'         => $tables,
            'latest_scrape'  => $stats['latest_scrape'],
            'titles'         => [
                'total'   => $totalTitles,
                'saved'   => $saved,
                'pending' => max(0, $totalTitles - $saved - $emptyCount - $failed),
                'failed'  => $failed,
                'empty'   => $emptyCount,
            ],
            'episodes'  => $links,
            'sources'   => $links,
            'downloads' => $links,
            'size_bytes'=> 0,
        ]));
    }

    private static function syncStart(): void
    {
        try {
            json_response(self::ok(SyncJob::start(request_body())));
        } catch (\Throwable $exception) {
            json_response([
                'ok'         => false,
                'data'       => null,
                'error'      => $exception->getMessage(),
                'error_type' => 'ScraperError',
            ], 409);
        }
    }

    private static function librarySearch(): void
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $category = (string) ($_GET['category'] ?? 'all');
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'all';
        }
        $status = (string) ($_GET['status'] ?? 'saved');
        if (!in_array($status, ['all', 'saved', 'pending', 'failed', 'empty'], true)) {
            $status = 'saved';
        }
        $limit = clamp_int($_GET['limit'] ?? null, 1, 100, 24);
        $offset = clamp_int($_GET['offset'] ?? null, 0, PHP_INT_MAX, 0);

        if (!Database::configured()) {
            json_response(self::ok([
                'query'    => $query,
                'category' => $category,
                'status'   => $status,
                'results'  => [],
                'total'    => 0,
                'limit'    => $limit,
                'offset'   => $offset,
            ]));
            return;
        }

        try {
            $data = (new Library())->searchScrapes($query, $category, $status, $limit, $offset);
            json_response(self::ok($data));
        } catch (\Throwable $exception) {
            json_response([
                'ok'         => false,
                'data'       => null,
                'error'      => $exception->getMessage(),
                'error_type' => 'ScraperError',
            ], 503);
        }
    }

    private static function libraryTitle(string $pageUrl): void
    {
        if (!Database::configured()) {
            json_response([
                'ok'         => false,
                'data'       => null,
                'error'      => 'MySQL is not configured',
                'error_type' => 'ScraperError',
            ], 503);
            return;
        }

        try {
            $data = (new Library())->getScrapeDetail($pageUrl);
        } catch (\Throwable $exception) {
            json_response([
                'ok'         => false,
                'data'       => null,
                'error'      => $exception->getMessage(),
                'error_type' => 'ScraperError',
            ], 503);
            return;
        }

        if ($data === null) {
            json_response([
                'ok'         => false,
                'data'       => null,
                'error'      => 'Title not found',
                'error_type' => 'NotFound',
            ], 404);
            return;
        }

        json_response(self::ok($data));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function searchFromDb(
        string $query,
        string $category,
        int $limit,
        int $offset,
        ?string $reason = null
    ): array {
        if (!Database::configured()) {
            throw new ScraperError(
                $reason
                ?: 'MySQL is not configured (set db.host and db.database), and the live catalogue is unreachable'
            );
        }

        [$items, $total] = (new Library())->searchTitles($query, $category, $limit, $offset);
        if ($reason !== null) {
            error_log('MLBD: live catalogue unavailable (' . $reason . '); served from MySQL');
        }

        return [
            'query'         => $query,
            'category'      => $category,
            'results'       => $items,
            'total'         => $total,
            'limit'         => $limit,
            'offset'        => $offset,
            'source'        => 'db',
            'catalog_count' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function searchSite(string $query, int $limit): array
    {
        if ($query === '') {
            throw new ScraperError('Search query is empty');
        }

        $site = new SiteSearch();
        $found = $site->search($query, $limit);

        $results = [];
        foreach ((array) ($found['results'] ?? []) as $item) {
            $kind = strtolower(trim((string) ($item['type'] ?? '')));
            if (!isset(self::TYPE_TO_CATEGORY[$kind])) {
                $parts = explode('/', rtrim((string) ($item['url'] ?? ''), '/'));
                $kind = strtolower($parts[count($parts) - 2] ?? '');
            }
            if (!isset(self::TYPE_TO_CATEGORY[$kind])) {
                $kind = 'movie';
            }

            $year = null;
            if (preg_match('/\((\d{4})\)/', (string) ($item['title'] ?? ''), $match)) {
                $year = $match[1];
            }
            $slug = basename(rtrim((string) ($item['url'] ?? ''), '/'));

            $results[] = [
                'title'    => $item['title'] ?? '',
                'url'      => $item['url'] ?? '',
                'slug'     => $slug,
                'poster'   => $item['poster'] ?? null,
                'type'     => $kind,
                'category' => self::TYPE_TO_CATEGORY[$kind],
                'year'     => $year,
                'adult'    => in_array($kind, ['download18plus', 'adult'], true),
                'quality'  => $item['quality'] ?? null,
                'language' => $item['language'] ?? null,
                'rating'   => $item['rating'] ?? null,
            ];
        }

        return [
            'query'         => $query,
            'category'      => 'all',
            'results'       => $results,
            'total'         => count($results),
            'limit'         => $limit,
            'offset'        => 0,
            'source'        => 'site',
            'catalog_count' => null,
            'searched_at'   => $found['searched_at'] ?? now_iso(),
        ];
    }

    /**
     * Persist a scrape; failures never break the response.
     *
     * @param array<string, mixed> $payload
     * @return array{saved:bool,links:int}
     */
    private static function storeScrape(array $payload, string $requestedUrl): array
    {
        if (!Database::writable()) {
            return ['saved' => false, 'links' => 0];
        }

        try {
            $saved = (new Library())->saveScrape($payload, $requestedUrl);
            return ['saved' => true, 'links' => (int) ($saved['links'] ?? 0)];
        } catch (\Throwable $exception) {
            error_log('MLBD: could not store scrape: ' . $exception->getMessage());
            return ['saved' => false, 'links' => 0];
        }
    }

    /**
     * Fresh stored snapshot for a URL, if any.
     *
     * @return array<string, mixed>|null
     */
    private static function cachedScrape(string $pageUrl, string $reason): ?array
    {
        if (!Database::configured()) {
            return null;
        }

        try {
            $cached = (new Library())->cachedScrape(
                $pageUrl,
                (int) config('scrape_cache_seconds', 21600)
            );
        } catch (\Throwable $exception) {
            error_log('MLBD: could not read cached scrape: ' . $exception->getMessage());
            return null;
        }

        if ($cached === null) {
            return null;
        }

        error_log('MLBD: live scrape failed (' . $reason . '); serving stored copy');
        [$payload, $age] = $cached;
        return $payload + ['cached' => true, 'cache_age_seconds' => $age];
    }

    /** @param mixed $data */
    private static function ok($data): array
    {
        return ['ok' => true, 'data' => $data, 'error' => null, 'error_type' => null];
    }

    /** Emit a failure in the API's envelope. */
    private static function fail(\Throwable $exception, int $status = 200): void
    {
        $type = match (true) {
            $exception instanceof CloudflareBlocked => 'CloudflareBlocked',
            $exception instanceof NoStreamFoundAlias => 'NoStreamFound',
            $exception instanceof ScraperError => 'ScraperError',
            default => 'InternalError',
        };

        json_response([
            'ok'         => false,
            'data'       => null,
            'error'      => $exception->getMessage(),
            'error_type' => $type,
        ], $status);
    }

    /** @param mixed $value */
    private static function boolParam($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $text = strtolower(trim((string) $value));
        return !in_array($text, ['0', 'false', 'no', 'off', ''], true);
    }
}
