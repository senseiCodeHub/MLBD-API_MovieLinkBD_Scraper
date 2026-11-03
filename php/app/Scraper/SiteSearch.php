<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

use Mlbd\Http\HttpClient;

/**
 * Search MovieLinkBD titles by name using the site's own search page.
 *
 * The public search page is server-rendered, so a plain HTTP request is enough.
 * It only returns about 25 results; use {@see Catalog} for whole-server search.
 */
final class SiteSearch
{
    private const SEARCH_PATH = '/search';
    private const TIMEOUT = 20;

    private const CARD_SPLIT = '/<div class="movie-card"/i';
    private const HREF = '/href="(\/(?:movie|series|anime|drama|southIndian|tvseries)\/[^"]+)"/';
    private const TITLE = '/class="title"[^>]*>(.*?)<\/a>/s';
    private const POSTER = '/data-src="([^"]+)"/';
    private const QUALITY = '/<span class="quality"[^>]*>([^<]*)<\/span>/';
    private const LANGUAGE = '/<span class="language"[^>]*>([^<]*)<\/span>/';
    private const TYPE = '/<span class="type[^"]*"[^>]*>([^<]*)<\/span>/';
    private const RATING = '/rating-badge">([^<]*)<\/span>/';
    private const RATING_PREFIX = '/^[^\d.]+/';

    private const CLOUDFLARE_MARKERS = ['Attention Required', 'Just a moment', 'cf-challenge'];

    private HttpClient $http;
    private string $baseUrl;

    public function __construct(?HttpClient $http = null)
    {
        $this->baseUrl = rtrim((string) config('site.base_url', 'https://movielinkbd.tv'), '/');
        $this->http = $http ?? new HttpClient(self::TIMEOUT, 10);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $markup, int $limit = 24): array
    {
        $items = [];
        $seen = [];

        $chunks = preg_split(self::CARD_SPLIT, $markup) ?: [];
        foreach (array_slice($chunks, 1) as $chunk) {
            if (!preg_match(self::HREF, $chunk, $hrefMatch)) {
                continue;
            }
            $title = $this->clean(self::TITLE, $chunk);
            if ($title === null) {
                continue;
            }

            $url = url_join($this->baseUrl, $hrefMatch[1]);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $rating = null;
            $rawRating = $this->clean(self::RATING, $chunk);
            if ($rawRating !== null) {
                $rating = trim(preg_replace(self::RATING_PREFIX, '', $rawRating) ?? '');
                $rating = $rating !== '' ? $rating : null;
            }

            $items[] = [
                'title'    => $title,
                'url'      => $url,
                'poster'   => $this->clean(self::POSTER, $chunk),
                'quality'  => $this->clean(self::QUALITY, $chunk),
                'language' => $this->clean(self::LANGUAGE, $chunk),
                'type'     => $this->clean(self::TYPE, $chunk),
                'rating'   => $rating,
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CloudflareBlocked
     * @throws ScraperError
     */
    public function search(string $query, int $limit = 24): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new ScraperError('Search query is empty');
        }

        $url = $this->baseUrl . self::SEARCH_PATH . '?q=' . rawurlencode($query);
        $response = $this->http->request($url, [
            'user-agent'      => (string) config('scraper.user_agent'),
            'accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'accept-language' => 'en-US,en;q=0.9',
        ], ['timeout' => self::TIMEOUT]);

        if (!$response['ok']) {
            throw new ScraperError('Search request failed: ' . ($response['error'] ?? 'unknown error'));
        }

        $head = strtolower(substr($response['body'], 0, 5000));
        if (in_array($response['status'], [403, 429, 503], true)) {
            throw new CloudflareBlocked($url);
        }
        foreach (self::CLOUDFLARE_MARKERS as $marker) {
            if (str_contains($head, strtolower($marker))) {
                throw new CloudflareBlocked($url);
            }
        }
        if ($response['status'] >= 400) {
            throw new ScraperError('Search request failed with HTTP ' . $response['status']);
        }

        return [
            'query'       => $query,
            'results'     => $this->parse($response['body'], $limit),
            'searched_at' => now_iso(),
        ];
    }

    private function clean(string $pattern, string $chunk): ?string
    {
        if (!preg_match($pattern, $chunk, $match)) {
            return null;
        }
        $value = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim($value);
        return $value !== '' ? $value : null;
    }
}
