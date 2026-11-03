<?php
declare(strict_types=1);

namespace Mlbd\Http;

use Mlbd\Scraper\Catalog;
use Mlbd\Scraper\CloudflareBlocked;
use Mlbd\Scraper\NoStreamFound;
use Mlbd\Scraper\Scraper;
use Mlbd\Scraper\ScraperError;
use Mlbd\Scraper\SiteSearch;
use Mlbd\Store\Database;
use Mlbd\Store\Library;

/**
 * Server-rendered pages: catalogue search, live scrape detail and the saved
 * library (with the chunked sync controls).
 */
final class Web
{
    private const PAGE_SIZE = 24;

    private const CATEGORIES = ['all', 'movie', 'series', 'drama', 'anime', 'adult'];

    /** @var array<string, mixed> */
    private static array $shared = [];

    /** Handle a page route; returns false when the path is not a page. */
    public static function handle(string $path): bool
    {
        switch ($path) {
            case '':
            case '/':
                self::home();
                return true;

            case '/view':
                self::view();
                return true;

            case '/saved':
                self::saved();
                return true;

            case '/saved/title':
                self::savedTitle();
                return true;
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Pages
    // -----------------------------------------------------------------------

    private static function home(): void
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $category = self::category();
        $scope = (string) ($_GET['scope'] ?? 'catalog');
        if (!in_array($scope, ['catalog', 'site', 'db'], true)) {
            $scope = 'catalog';
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $data = [
            'query'    => $query,
            'category' => $category,
            'scope'    => $scope,
            'page'     => $page,
            'results'  => [],
            'total'    => 0,
            'error'    => null,
            'note'     => null,
            'searched' => false,
            'catalog'  => null,
            'freshness' => null,
        ];

        if (Database::configured()) {
            try {
                $data['freshness'] = (new Library())->freshness();
            } catch (\Throwable $exception) {
                $data['freshness'] = null;
            }
        }

        $shouldSearch = $query !== ''
            || $scope === 'db'
            || $category !== 'all'
            || $page > 1;

        if ($shouldSearch) {
            $data['searched'] = true;
            try {
                if ($scope === 'db') {
                    // ``+=`` keeps the empty defaults already in $data, which is
                    // why this page used to report "0 titles" for every query.
                    $mirror = self::dbSearch($query, $category, self::PAGE_SIZE, $offset);
                    $data['results'] = $mirror['results'];
                    $data['total'] = $mirror['total'];
                } elseif ($scope === 'site') {
                    $site = (new SiteSearch())->search($query, self::PAGE_SIZE);
                    $results = self::siteItems((array) ($site['results'] ?? []));
                    $data['results'] = $results;
                    $data['total'] = count($results);
                } else {
                    $hits = (new Catalog())->search($query, $category, self::PAGE_SIZE, $offset);
                    $data['results'] = (array) ($hits['results'] ?? []);
                    $data['total'] = (int) ($hits['total'] ?? 0);
                }
            } catch (\Throwable $exception) {
                $data['error'] = $exception->getMessage();
                if ($scope !== 'db' && Database::configured()) {
                    try {
                        $fallback = self::dbSearch($query, $category, self::PAGE_SIZE, $offset);
                        $data['results'] = $fallback['results'];
                        $data['total'] = $fallback['total'];
                        $data['note'] = 'Live catalogue unreachable — showing the saved MySQL mirror.';
                        $data['error'] = null;
                    } catch (\Throwable $inner) {
                        // Keep the original error.
                    }
                }
            }

            if ($data['error'] === null) {
                $data['catalog'] = self::safeCatalogStats();
            }
        } else {
            $data['catalog'] = self::safeCatalogStats();
        }

        self::render('home', $data, 'Search');
    }

    private static function view(): void
    {
        $url = trim((string) ($_GET['url'] ?? ''));
        if ($url === '') {
            http_response_code(404);
            self::render('notfound', ['path' => 'No detail URL'], 'Not found');
            return;
        }

        $data = [
            'url'     => $url,
            'result'  => null,
            'error'   => null,
            'type'    => null,
            'cached'  => false,
            'saved'   => false,
            'probe'   => !isset($_GET['probe']) || $_GET['probe'] !== '0',
            'source'  => 'live',
        ];

        try {
            $data['result'] = (new Scraper())->scrape($url, 30, $data['probe']);

            if (Database::writable()) {
                try {
                    (new Library())->saveScrape($data['result'], $url);
                    $data['saved'] = true;
                } catch (\Throwable $exception) {
                    error_log('MLBD: could not store scrape: ' . $exception->getMessage());
                }
            }
        } catch (\Throwable $exception) {
            $data['error'] = $exception->getMessage();
            $data['type'] = self::errorType($exception);

            if (Database::configured()) {
                try {
                    $cached = (new Library())->cachedScrape(
                        $url,
                        (int) config('scrape_cache_seconds', 21600)
                    );
                } catch (\Throwable $inner) {
                    $cached = null;
                }
                if ($cached !== null) {
                    $data['result'] = $cached[0] + ['cached' => true, 'cache_age_seconds' => $cached[1]];
                    $data['cached'] = true;
                    $data['saved'] = true;
                    $data['error'] = null;
                    $data['source'] = 'cache';
                }
            }
        }

        self::render('view', $data, $data['result']['title'] ?? 'Scrape');
    }

    private static function saved(): void
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $category = self::category();
        $status = (string) ($_GET['status'] ?? 'saved');
        if (!in_array($status, ['all', 'saved', 'pending', 'failed', 'empty'], true)) {
            $status = 'saved';
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $data = [
            'query'    => $query,
            'category' => $category,
            'status'   => $status,
            'page'     => $page,
            'results'  => [],
            'total'    => 0,
            'error'    => null,
            'mysql'    => Database::configured(),
            'stats'    => null,
            'sync'     => \Mlbd\Library\SyncJob::status(),
            'params'   => (array) (config('sync') ?? []),
            // What the scheduled job did lately, read from its own run log, so
            // the library page never parses the plain-text cron output.
            'cron'      => \Mlbd\Library\CronLog::summary(),
            'freshness' => null,
        ];

        if (Database::configured()) {
            try {
                $library = new Library();
                $rows = $library->searchScrapes($query, $category, $status, self::PAGE_SIZE, $offset);
                $data['results'] = (array) ($rows['results'] ?? []);
                $data['total'] = (int) ($rows['total'] ?? 0);
                $stats = $library->stats();
                $statuses = $library->statusCounts();
                $tables = $stats['tables'];
                $data['freshness'] = $library->freshness();
                $data['stats'] = [
                    'tables' => $tables,
                    'titles' => [
                        'total'   => (int) $tables['titles'],
                        'saved'   => (int) ($statuses['saved'] ?? 0),
                        'failed'  => (int) ($statuses['failed'] ?? 0),
                        'empty'   => (int) ($statuses['empty'] ?? 0),
                        'pending' => max(0, (int) $tables['titles']
                            - (int) ($statuses['saved'] ?? 0)
                            - (int) ($statuses['failed'] ?? 0)
                            - (int) ($statuses['empty'] ?? 0)),
                    ],
                ];
            } catch (\Throwable $exception) {
                $data['error'] = $exception->getMessage();
            }
        }

        self::render('saved', $data, 'Library');
    }

    private static function savedTitle(): void
    {
        $pageUrl = rawurldecode(trim((string) ($_GET['url'] ?? '')));
        $data = [
            'url'    => $pageUrl,
            'detail' => null,
            'error'  => null,
            'mysql'  => Database::configured(),
        ];

        if (!Database::configured()) {
            $data['error'] = 'MySQL is not configured, so there is no saved library yet.';
        } elseif ($pageUrl === '') {
            $data['error'] = 'No title URL was given.';
        } else {
            try {
                $detail = (new Library())->getScrapeDetail($pageUrl);
                if ($detail === null) {
                    $data['error'] = 'That title is not in the library yet.';
                } else {
                    $data['detail'] = $detail;
                }
            } catch (\Throwable $exception) {
                $data['error'] = $exception->getMessage();
            }
        }

        self::render('saved-title', $data, $data['detail']['title'] ?? 'Saved title');
    }

    // -----------------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------------

    /**
     * Render a view inside the layout.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $view, array $data = [], string $title = ''): void
    {
        self::$shared = [
            'title' => $title,
            'brand' => 'MLBD Scraper',
            'path'  => request_path(),
        ];

        // The side rail shows the sync state on every page; it is a small JSON
        // read, so computing it here keeps the rail honest during cron runs.
        self::$shared['syncMini'] = null;
        if (Database::configured()) {
            try {
                self::$shared['syncMini'] = \Mlbd\Library\SyncJob::status();
            } catch (\Throwable $exception) {
                self::$shared['syncMini'] = null;
            }
        }

        $file = base_path('views/' . $view . '.php');
        if (!is_file($file)) {
            http_response_code(500);
            echo 'Missing view: ' . e($view);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        $data = $data + self::$shared;
        extract($data, EXTR_SKIP);
        require base_path('views/layout.php');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array{results:list<array<string,mixed>>,total:int} */
    private static function dbSearch(string $query, string $category, int $limit, int $offset): array
    {
        if (!Database::configured()) {
            throw new ScraperError(
                'MySQL is not configured, so there is no saved mirror to search.'
            );
        }
        [$items, $total] = (new Library())->searchTitles($query, $category, $limit, $offset);
        return ['results' => $items, 'total' => $total];
    }

    /**
     * Map the site's search page rows to catalogue items.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function siteItems(array $items): array
    {
        $typeToCategory = [
            'movie'          => 'movie',
            'series'         => 'series',
            'drama'          => 'drama',
            'anime'          => 'anime',
            'download18plus' => 'adult',
            'adult'          => 'adult',
        ];

        $results = [];
        foreach ($items as $item) {
            $kind = strtolower(trim((string) ($item['type'] ?? '')));
            if (!isset($typeToCategory[$kind])) {
                $parts = explode('/', rtrim((string) ($item['url'] ?? ''), '/'));
                $kind = strtolower($parts[count($parts) - 2] ?? '');
            }
            if (!isset($typeToCategory[$kind])) {
                $kind = 'movie';
            }

            $year = null;
            if (preg_match('/\((\d{4})\)/', (string) ($item['title'] ?? ''), $match)) {
                $year = $match[1];
            }

            $results[] = [
                'title'    => $item['title'] ?? '',
                'url'      => $item['url'] ?? '',
                'slug'     => basename(rtrim((string) ($item['url'] ?? ''), '/')),
                'poster'   => $item['poster'] ?? null,
                'type'     => $kind,
                'category' => $typeToCategory[$kind],
                'year'     => $year,
                'adult'    => in_array($kind, ['download18plus', 'adult'], true),
                'quality'  => $item['quality'] ?? null,
                'language' => $item['language'] ?? null,
                'rating'   => $item['rating'] ?? null,
            ];
        }
        return $results;
    }

    /** @return array<string, mixed>|null */
    private static function safeCatalogStats(): ?array
    {
        try {
            return (new Catalog())->stats();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private static function category(): string
    {
        $category = (string) ($_GET['category'] ?? 'all');
        return in_array($category, self::CATEGORIES, true) ? $category : 'all';
    }

    private static function errorType(\Throwable $exception): string
    {
        return match (true) {
            $exception instanceof CloudflareBlocked => 'CloudflareBlocked',
            $exception instanceof NoStreamFound     => 'NoStreamFound',
            $exception instanceof ScraperError      => 'ScraperError',
            default                                 => 'Error',
        };
    }
}
