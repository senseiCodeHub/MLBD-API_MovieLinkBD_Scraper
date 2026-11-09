<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

use Mlbd\Http\HttpClient;

/**
 * Per-title badges (language / type / adult) read from MovieLinkBD's listings.
 *
 * The search-suggest shards the catalogue is built from only carry
 * ``[title, slug, poster, type, year, adult]`` — no language — yet language is
 * what every "Bangla", "Bangla Dubbed" or "Hindi Dubbed" shelf is answered
 * from. The listing pages do carry it: each card wears a
 * ``<span class="language">`` badge ("Bangla", "Bangla Dubbed",
 * ``Dual [Hindi–Tamil]`` …), a ``<span class="type">`` badge ("Movie",
 * "Series", "Drama", "Anime") and a ``data-18`` flag.
 *
 * Five listings cover the whole catalogue, 30 cards per page:
 *
 *     /type/movies  /type/series  /drama  /anime  /adult18plus
 *
 * Card hrefs are opaque per-deployment handles that cannot be joined to the
 * stored ``slug``, so the **title** is the join key: cards and catalogue rows
 * describe the same works with the same text. Titles are normalised with
 * {@see Catalog::normalize}, the function the catalogue's own scoring uses, so
 * both sides agree on case, punctuation and accents.
 *
 * The index is cached on disk for a few hours like the catalogue index, and the
 * crawl is polite (eight concurrent requests) because it walks every page of
 * every listing.
 */
final class Taxonomy
{
    /** kind of the card => listing path; each shows every title of that kind. */
    private const ROOTS = [
        'movie'          => '/type/movies',
        'series'         => '/type/series',
        'drama'          => '/drama',
        'anime'          => '/anime',
        'download18plus' => '/adult18plus',
    ];

    private const CACHE_FILE = 'taxonomy.json';
    private const CACHE_TTL = 21600;
    private const PAGE_BATCH = 40;
    private const CONCURRENCY = 8;

    private const CARD_SPLIT = '/class="movie-card"/';
    private const HREF = '/href="\/(?P<kind>movie|series|drama|anime|download18plus)\/[^"]+"/';
    private const TITLE = '/<a[^>]*class="title"[^>]*>(?P<t>.*?)<\/a>/s';
    private const LANGUAGE = '/<span class="language"[^>]*>(?P<v>[^<]*)<\/span>/';
    private const TYPE = '/<span class="type[^"]*"[^>]*>(?P<v>[^<]*)<\/span>/';
    private const ADULT = '/data-18="(?P<v>\d+)"/';
    private const PAGES = '/data-pages="(?P<n>\d+)"/';

    /** Type badge wording => stored kind. */
    private const TYPE_TO_KIND = [
        'movie'  => 'movie',
        'film'   => 'movie',
        'series' => 'series',
        'drama'  => 'drama',
        'anime'  => 'anime',
    ];

    private const CHALLENGE_MARKERS = [
        'just a moment',
        'attention required',
        'challenge-platform',
        'cf-chl',
    ];

    private HttpClient $http;
    private string $baseUrl;

    /** @var array<string, array{title:string,language:?string,kind:string,type:?string,adult:bool}>|null */
    private ?array $byTitle = null;

    private float $fetchedAt = 0.0;

    public function __construct(?HttpClient $http = null)
    {
        $this->baseUrl = rtrim((string) config('site.base_url', 'https://movielinkbd.tv'), '/');
        $this->http = $http ?? new HttpClient(30, 10);
    }

    /** Load (and cache) the badge index; return the number of titles in it. */
    public function ensure(bool $force = false): int
    {
        $this->load($force);
        return count($this->byTitle ?? []);
    }

    /** How many titles the loaded index knows. */
    public function count(): int
    {
        return count($this->byTitle ?? []);
    }

    /** When the loaded index was built (unix seconds). */
    public function fetchedAt(): float
    {
        return $this->fetchedAt;
    }

    /**
     * The badges recorded for [title], or null when the listing does not
     * mention it.
     *
     * @return array{title:string,language:?string,kind:string,type:?string,adult:bool}|null
     */
    public function badgesFor(string $title): ?array
    {
        if ($this->byTitle === null) {
            $this->load(false);
        }
        $key = Catalog::normalize($title);
        return $key === '' ? null : ($this->byTitle[$key] ?? null);
    }

    /**
     * Copy the badges onto catalogue rows before they are stored.
     *
     * A row the listings do not mention is left exactly as it was. The 18+
     * section serves films and series from one path, so the card's own
     * "Movie"/"Series" badge replaces the uninformative ``download18plus``
     * kind there — that is what lets the API split the adult shelves.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function enrich(array $items): array
    {
        if ($this->byTitle === null) {
            return $items;
        }

        foreach ($items as $index => $item) {
            $badge = $this->badgesFor((string) ($item['title'] ?? ''));
            if ($badge === null) {
                continue;
            }

            if (!empty($badge['language'])) {
                $items[$index]['language'] = $badge['language'];
            }
            if (!empty($badge['adult'])) {
                $items[$index]['adult'] = true;
            }

            $current = (string) ($item['kind'] ?? $item['type'] ?? '');
            $type = (string) ($badge['type'] ?? '');
            if (($current === 'download18plus' || !empty($badge['adult'])) && $type !== '') {
                $items[$index]['kind'] = $type;
            }
        }

        return $items;
    }

    // -----------------------------------------------------------------------
    // Index loading
    // -----------------------------------------------------------------------

    private function load(bool $force): void
    {
        if (!$force && $this->byTitle !== null) {
            return;
        }

        $cached = $this->readCache();
        if (!$force && $cached !== null) {
            [$this->byTitle, $this->fetchedAt] = $cached;
            if (time() - $this->fetchedAt < self::CACHE_TTL) {
                return;
            }
        }

        try {
            [$entries, $pages] = $this->crawl();
        } catch (\Throwable $exception) {
            if ($cached !== null) {
                // A stale index still beats no badges at all.
                error_log('MLBD taxonomy refresh failed; using the cached index: ' . $exception->getMessage());
                [$this->byTitle, $this->fetchedAt] = $cached;
                return;
            }
            throw $exception;
        }

        if (count($entries) < 1000 && $cached !== null) {
            error_log('MLBD taxonomy crawl returned only ' . count($entries) . ' titles; keeping the cache');
            [$this->byTitle, $this->fetchedAt] = $cached;
            return;
        }

        $this->byTitle = $entries;
        $this->fetchedAt = (float) time();
        $this->writeCache($entries, $pages);
    }

    /**
     * @return array{0:?array<string, array{title:string,language:?string,kind:string,type:?string,adult:bool}>,1:float}
     */
    private function readCache(): ?array
    {
        $path = storage_path('cache/' . self::CACHE_FILE);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !is_array($payload['entries'] ?? null)) {
            return null;
        }
        $entries = [];
        foreach ($payload['entries'] as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                continue;
            }
            $entries[$key] = [
                'title'    => (string) ($entry['title'] ?? ''),
                'language' => str_or_null($entry['language'] ?? null),
                'kind'     => (string) ($entry['kind'] ?? ''),
                'type'     => str_or_null($entry['type'] ?? null),
                'adult'    => (bool) ($entry['adult'] ?? false),
            ];
        }
        return [$entries, (float) ($payload['fetched_at'] ?? 0)];
    }

    /**
     * @param array<string, array{title:string,language:?string,kind:string,type:?string,adult:bool}> $entries
     */
    private function writeCache(array $entries, int $pages): void
    {
        $path = storage_path('cache/' . self::CACHE_FILE);
        $json = json_encode(
            [
                'fetched_at' => $this->fetchedAt,
                'count'      => count($entries),
                'pages'      => $pages,
                'entries'    => $entries,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
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

    // -----------------------------------------------------------------------
    // Crawling
    // -----------------------------------------------------------------------

    /**
     * @return array{0:array<string, array{title:string,language:?string,kind:string,type:?string,adult:bool}>,1:int}
     */
    private function crawl(): array
    {
        /** @var array<string, array{title:string,language:?string,kind:string,type:?string,adult:bool}> $merged */
        $merged = [];
        $pages = 0;

        foreach (self::ROOTS as $kind => $path) {
            $base = $this->baseUrl . $path;
            $first = $this->http->request($base, $this->headers(), ['timeout' => 30]);
            if ($this->looksLikeChallenge($first)) {
                throw new CloudflareBlocked(
                    'MovieLinkBD is challenging this host (Cloudflare), so the language '
                    . 'badges cannot be read.'
                );
            }
            if (!($first['ok'] ?? false) || (int) ($first['status'] ?? 0) >= 400) {
                throw new ScraperError(
                    'Listing ' . $path . ' did not answer (HTTP ' . (int) ($first['status'] ?? 0) . ')'
                );
            }

            $cards = self::parseCards((string) ($first['body'] ?? ''));
            if ($cards === []) {
                throw new ScraperError('Listing ' . $path . ' carried no cards; the badge crawl cannot trust this response');
            }
            $this->merge($merged, $cards);
            $pages++;

            $pageCount = preg_match(self::PAGES, (string) ($first['body'] ?? ''), $match)
                ? (int) $match['n']
                : 1;

            for ($start = 2; $start <= $pageCount; $start += self::PAGE_BATCH) {
                $urls = [];
                for ($number = $start; $number < min($start + self::PAGE_BATCH, $pageCount + 1); $number++) {
                    $urls[] = $base . '/page/' . $number;
                }
                if ($urls === []) {
                    break;
                }

                $batched = 0;
                $this->http->multiGet(
                    $urls,
                    self::CONCURRENCY,
                    function (string $url, array $response) use (&$merged, &$batched): void {
                        if (!($response['ok'] ?? false)) {
                            return;
                        }
                        $cards = self::parseCards((string) ($response['body'] ?? ''));
                        if ($cards === []) {
                            return;
                        }
                        $this->merge($merged, $cards);
                        $batched++;
                    },
                    $this->headers(),
                    ['timeout' => 30]
                );
                $pages += $batched;
            }
        }

        return [$merged, $pages];
    }

    /**
     * Merge one page's cards into the index.
     *
     * The first card wins; a badge that is missing never erases one already
     * read, and the adult flag sticks as soon as any listing reports it.
     *
     * @param array<string, array{title:string,language:?string,kind:string,type:?string,adult:bool}> $merged
     * @param list<array{title:string,language:?string,kind:string,type:?string,adult:bool}>        $cards
     */
    private function merge(array &$merged, array $cards): void
    {
        foreach ($cards as $card) {
            $key = Catalog::normalize($card['title']);
            if ($key === '') {
                continue;
            }
            if (!isset($merged[$key])) {
                $merged[$key] = $card;
                continue;
            }
            if ($merged[$key]['language'] === null && $card['language'] !== null) {
                $merged[$key]['language'] = $card['language'];
            }
            if ($merged[$key]['type'] === null && $card['type'] !== null) {
                $merged[$key]['type'] = $card['type'];
            }
            $merged[$key]['adult'] = $merged[$key]['adult'] || $card['adult'];
        }
    }

    /**
     * Every card on one listing page: its title and its three badges.
     *
     * @return list<array{title:string,language:?string,kind:string,type:?string,adult:bool}>
     */
    public static function parseCards(string $markup): array
    {
        $out = [];
        if ($markup === '') {
            return $out;
        }

        $chunks = preg_split(self::CARD_SPLIT, $markup) ?: [];
        foreach (array_slice($chunks, 1) as $chunk) {
            if (!preg_match(self::HREF, $chunk, $href)) {
                continue;
            }
            if (!preg_match(self::TITLE, $chunk, $titleMatch)) {
                continue;
            }
            $title = self::decode($titleMatch['t']);
            if ($title === '') {
                continue;
            }

            $type = null;
            if (preg_match(self::TYPE, $chunk, $typeMatch)) {
                $badge = mb_strtolower(self::decode($typeMatch['v']));
                $type = self::TYPE_TO_KIND[$badge] ?? null;
            }

            $out[] = [
                'title'    => $title,
                'language' => preg_match(self::LANGUAGE, $chunk, $language) ? self::decode($language['v']) : null,
                'kind'     => (string) $href['kind'],
                'type'     => $type,
                'adult'    => preg_match(self::ADULT, $chunk, $adult) && $adult['v'] === '1',
            ];
        }

        return $out;
    }

    /** Strip tags, decode entities and normalise the dashes of a badge. */
    private static function decode(string $raw): string
    {
        $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/<[^>]+>/', '', $text);
        foreach (["\u{2013}", "\u{2014}", "\u{2212}"] as $dash) {
            $text = str_replace($dash, '-', $text);
        }
        $text = str_or_null($text);
        return $text === null ? '' : (string) preg_replace('/\s+/u', ' ', $text);
    }

    /** Browser-shaped headers: the site answers plain agents with a challenge. */
    private function headers(): array
    {
        return [
            'user-agent'      => (string) config('scraper.user_agent'),
            'accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'accept-language' => 'en-US,en;q=0.9',
            'referer'         => $this->baseUrl . '/',
        ];
    }

    /** @param array<string, mixed> $response */
    private function looksLikeChallenge(array $response): bool
    {
        if (in_array((int) ($response['status'] ?? 0), [403, 429, 503], true)) {
            return true;
        }
        $head = mb_strtolower(substr((string) ($response['body'] ?? ''), 0, 5000));
        foreach (self::CHALLENGE_MARKERS as $marker) {
            if (str_contains($head, $marker)) {
                return true;
            }
        }
        return false;
    }
}
