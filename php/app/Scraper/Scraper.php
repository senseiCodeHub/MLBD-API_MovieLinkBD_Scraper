<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

use Mlbd\Http\HttpClient;

/**
 * Main orchestration: scrape($pageUrl) -> ScrapeResult array.
 *
 * MovieLinkBD serves the whole player payload for a detail page in an inline
 * ``<script id="mlbdInlinePlayerData">`` element. The body is XOR-encoded JSON
 * and contains every episode and every source (stream URL + download URL +
 * quality + audio) for the title. That is the primary extraction path.
 *
 * If the payload is missing (Cloudflare interstitial, new page type) the
 * scraper falls back to reading ``<video>``/``<source>`` tags and the page's
 * ``data-dl`` download buttons directly from the HTML. Unlike the original
 * Playwright fallback this needs no headless browser, which makes it work on
 * shared PHP hosting such as Webuzo.
 *
 * The ad-gated "UNLOCK & WATCH NOW" button is never clicked and no ad iframe is
 * followed; only the site's own player data is read.
 */
final class Scraper
{
    private const PROBE_CONCURRENCY = 8;

    private const CLOUDFLARE_TITLE_KEYWORDS = ['Just a moment', 'Attention Required'];
    private const CLOUDFLARE_MARKERS = ['Just a moment', 'Attention Required', 'cf-challenge'];

    private const AD_HOSTS = [
        'doubleclick.net',
        'googlesyndication.com',
        'google-analytics.com',
        'googletagmanager.com',
        'jaya9bangla.com',
        'dramalinkbd.tv',
    ];

    private HttpClient $http;
    private string $userAgent;
    private int $pageTimeout;
    private int $probeTimeout;
    private int $probeConcurrency;

    public function __construct(?HttpClient $http = null)
    {
        $this->userAgent = (string) config(
            'scraper.user_agent',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
        );
        $this->pageTimeout = (int) config('scraper.page_timeout', 30);
        $this->probeTimeout = (int) config('scraper.probe_timeout', 8);
        $this->probeConcurrency = (int) config('scraper.probe_concurrency', self::PROBE_CONCURRENCY);
        $this->http = $http ?? new HttpClient($this->pageTimeout, 10);
    }

    /**
     * Scrape a MovieLinkBD detail page for every episode's stream + download.
     *
     * @return array<string, mixed>
     *
     * @throws CloudflareBlocked
     * @throws NoStreamFound
     * @throws ScraperError
     */
    public function scrape(string $pageUrl, int $timeout = 30, bool $probe = true): array
    {
        try {
            return $this->scrapePayload($pageUrl, $probe);
        } catch (CloudflareBlocked $exception) {
            // Retrying the same IP will only hit the challenge again.
            throw $exception;
        } catch (\Throwable $exception) {
            try {
                return $this->scrapeHtml($pageUrl, $probe);
            } catch (CloudflareBlocked $htmlException) {
                throw $htmlException;
            } catch (\Throwable $htmlException) {
                if ($exception instanceof ScraperError) {
                    throw $exception;
                }
                throw new ScraperError(
                    'Scrape failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Payload path
    // -----------------------------------------------------------------------

    /**
     * Fetch a detail page and decode its inline player payload.
     *
     * @return array{0:?array<string,mixed>,1:string}
     */
    private function fetchPayload(string $pageUrl): array
    {
        $response = $this->http->request($pageUrl, $this->browserHeaders(), [
            'timeout' => $this->pageTimeout,
            'follow'  => true,
        ]);

        if (!$response['ok']) {
            $message = (string) ($response['error'] ?? 'unknown error');
            if (stripos($message, 'certificate') !== false || stripos($message, 'SSL') !== false) {
                throw new ScraperError(
                    'Detail page request failed: ' . $message
                    . ' — this host has no CA store; point scraper.ca_bundle at a cacert.pem file.'
                );
            }
            throw new ScraperError('Detail page request failed: ' . $message);
        }

        $head = strtolower(substr($response['body'], 0, 6000));
        if (in_array($response['status'], [403, 429, 503], true)) {
            throw new CloudflareBlocked($pageUrl);
        }
        foreach (self::CLOUDFLARE_MARKERS as $marker) {
            if (str_contains($head, strtolower($marker))) {
                throw new CloudflareBlocked($pageUrl);
            }
        }
        if ($response['status'] >= 400) {
            throw new ScraperError('Detail page returned HTTP ' . $response['status']);
        }

        return [PayloadDecoder::decode($response['body']), $response['url']];
    }

    /**
     * @return array<string, mixed>
     */
    private function scrapePayload(string $pageUrl, bool $probe): array
    {
        [$payload, $finalUrl] = $this->fetchPayload($pageUrl);

        if ($payload === null) {
            throw new NoStreamFound($pageUrl, ['reason' => 'inline player payload not found']);
        }

        $streamUrls = [];
        $rawEpisodes = $payload['episodes'] ?? null;
        if (is_array($rawEpisodes)) {
            foreach ($rawEpisodes as $episode) {
                if (!is_array($episode)) {
                    continue;
                }
                $episodeSources = $episode['sources'] ?? [];
                if (!is_array($episodeSources)) {
                    continue;
                }
                foreach ($episodeSources as $source) {
                    if (is_array($source) && !empty($source['url'])) {
                        $streamUrls[] = url_join($finalUrl, (string) $source['url']);
                    }
                }
            }
        }

        $probes = $probe ? $this->probeMany($streamUrls, $finalUrl) : [];
        [$episodes, $sources, $downloads] = $this->buildEpisodes($payload, $probes, $finalUrl);

        if ($episodes === [] && $downloads === []) {
            throw new NoStreamFound($pageUrl, [
                'reason'       => 'payload contained no usable sources',
                'content_type' => $payload['content_type'] ?? null,
            ]);
        }

        $category = $this->categoryFromUrl($finalUrl, $payload);

        return $this->result([
            'page_url'     => $finalUrl,
            'title'        => str_or_null($payload['title'] ?? null),
            'poster'       => str_or_null($payload['poster'] ?? null),
            'content_type' => str_or_null($payload['content_type'] ?? null),
            'category'     => $category,
            'adult'        => (bool) ($payload['adult'] ?? false) || $category === 'adult',
            'completed'    => is_bool($payload['completed'] ?? null) ? $payload['completed'] : null,
            'episodes'     => $episodes,
            'sources'      => $sources,
            'downloads'    => $downloads,
        ]);
    }

    // -----------------------------------------------------------------------
    // HTML fallback (no browser required)
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function scrapeHtml(string $pageUrl, bool $probe): array
    {
        $response = $this->http->request($pageUrl, $this->browserHeaders(), [
            'timeout' => $this->pageTimeout,
            'follow'  => true,
        ]);

        if (!$response['ok']) {
            throw new ScraperError('Detail page request failed: ' . ($response['error'] ?? 'unknown error'));
        }

        $head = strtolower(substr($response['body'], 0, 6000));
        if (in_array($response['status'], [403, 429, 503], true)) {
            throw new CloudflareBlocked($pageUrl);
        }
        foreach (self::CLOUDFLARE_MARKERS as $marker) {
            if (str_contains($head, strtolower($marker))) {
                throw new CloudflareBlocked($pageUrl);
            }
        }
        if ($response['status'] >= 400) {
            throw new ScraperError('Detail page returned HTTP ' . $response['status']);
        }

        $markup = $response['body'];
        $finalUrl = $response['url'];

        $title = $this->pageTitle($markup);
        $poster = $this->metaContent($markup, 'og:image');
        $downloads = Downloads::parseFromHtml($markup, $finalUrl);
        $streamUrls = $this->streamsFromHtml($markup, $finalUrl);

        if ($streamUrls === [] && $downloads === []) {
            throw new NoStreamFound($pageUrl, [
                'reason'     => 'no player payload, video tag or download button found',
                'page_title' => $title,
            ]);
        }

        $probes = $probe ? $this->probeMany($streamUrls, $finalUrl) : [];
        $sources = [];

        foreach ($streamUrls as $streamUrl) {
            if (str_ends_with(url_path($streamUrl), '.m3u8')) {
                $sources[] = [
                    'server_name' => $this->serverName($streamUrl),
                    'type'        => 'hls',
                    'master_url'  => $streamUrl,
                    'variants'    => HlsParser::parse($streamUrl, [
                        'user-agent' => $this->userAgent,
                        'referer'    => $finalUrl,
                    ]),
                    'size_bytes'  => null,
                    'content_type'=> null,
                ];
                continue;
            }

            [$contentType, $sizeBytes] = $probes[$streamUrl] ?? [null, null];
            if (!$this->isVideoContentType($contentType)) {
                continue;
            }

            $streamType = $this->streamType($streamUrl, $contentType);
            $quality = 'auto';
            foreach ($downloads as $link) {
                if (!empty($link['size_bytes']) && !empty($sizeBytes)) {
                    $difference = abs($sizeBytes - $link['size_bytes']) / $link['size_bytes'];
                    if ($difference <= 0.15 && !empty($link['quality'])) {
                        $quality = (string) $link['quality'];
                        break;
                    }
                }
            }

            $sources[] = [
                'server_name' => $this->serverName($streamUrl),
                'type'        => $streamType,
                'master_url'  => $streamUrl,
                'variants'    => [[
                    'quality'   => $quality,
                    'bandwidth' => null,
                    'url'       => $streamUrl,
                ]],
                'size_bytes'   => $sizeBytes,
                'content_type' => $contentType,
            ];
        }

        $category = $this->categoryFromUrl($finalUrl);

        return $this->result([
            'page_url'     => $finalUrl,
            'title'        => $title,
            'poster'       => $poster,
            'content_type' => null,
            'category'     => $category,
            'adult'        => $category === 'adult',
            'completed'    => null,
            'episodes'     => [],
            'sources'      => $sources,
            'downloads'    => $downloads,
        ]);
    }

    // -----------------------------------------------------------------------
    // Payload extraction helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @param array<string, array{0:?string,1:?int}> $probes
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>}
     */
    private function buildEpisodes(array $payload, array $probes, string $baseUrl): array
    {
        $episodes = [];
        $flatSources = [];
        $downloads = [];
        $seenStreams = [];
        $seenDownloads = [];

        $rawEpisodes = $payload['episodes'] ?? null;
        if (!is_array($rawEpisodes)) {
            $rawEpisodes = [];
        }

        foreach ($rawEpisodes as $rawEpisode) {
            if (!is_array($rawEpisode)) {
                continue;
            }

            $label = $this->episodeLabel($rawEpisode);
            $episodeSources = [];

            $rawSources = $rawEpisode['sources'] ?? null;
            if (!is_array($rawSources)) {
                $rawSources = [];
            }

            foreach ($rawSources as $rawSource) {
                if (!is_array($rawSource)) {
                    continue;
                }

                $streamUrl = trim((string) ($rawSource['url'] ?? ''));
                if ($streamUrl === '') {
                    continue;
                }
                $streamUrl = url_join($baseUrl, $streamUrl);

                [$contentType, $sizeBytes] = $probes[$streamUrl] ?? [null, null];
                if (!$this->isVideoContentType($contentType)) {
                    continue;
                }

                $quality = $this->qualityText(
                    $rawSource['quality'] ?? null,
                    $rawSource['quality_label'] ?? null
                );
                $sourceType = $this->streamType(
                    $streamUrl,
                    $contentType,
                    isset($rawSource['type']) ? (string) $rawSource['type'] : null
                );

                $downloadUrl = trim((string) ($rawSource['download_url'] ?? ''));
                $downloadUrl = $downloadUrl !== '' ? url_join($baseUrl, $downloadUrl) : null;

                $provider = str_or_null($rawSource['provider'] ?? null);
                $audio = str_or_null($rawSource['audio'] ?? null);

                $languages = [];
                $rawLanguages = $rawSource['audio_languages'] ?? [];
                if (is_array($rawLanguages)) {
                    foreach ($rawLanguages as $language) {
                        $language = trim((string) $language);
                        if ($language !== '') {
                            $languages[] = $language;
                        }
                    }
                }

                $hevc = $rawSource['hevc'] ?? null;

                $episodeSources[] = [
                    'id'              => str_or_null($rawSource['id'] ?? null),
                    'provider'        => $provider,
                    'quality'         => $quality,
                    'quality_label'   => str_or_null($rawSource['quality_label'] ?? null),
                    'audio'           => $audio,
                    'audio_languages' => $languages,
                    'type'            => $sourceType,
                    'name'            => str_or_null($rawSource['name'] ?? null),
                    'codec'           => str_or_null($rawSource['codec'] ?? null),
                    'hevc'            => is_bool($hevc) ? $hevc : null,
                    'is_best'         => (bool) ($rawSource['is_best'] ?? false),
                    'stream_url'      => $streamUrl,
                    'download_url'    => $downloadUrl,
                    'size_bytes'      => $sizeBytes,
                    'content_type'    => $contentType,
                ];

                if (!isset($seenStreams[$streamUrl])) {
                    $seenStreams[$streamUrl] = true;
                    $flatSources[] = [
                        'server_name' => $this->serverName($streamUrl, $provider),
                        'type'        => $sourceType,
                        'master_url'  => $streamUrl,
                        'variants'    => [[
                            'quality'   => $quality ?? 'auto',
                            'bandwidth' => null,
                            'url'       => $streamUrl,
                        ]],
                        'size_bytes'   => $sizeBytes,
                        'content_type' => $contentType,
                    ];
                }

                if ($downloadUrl !== null) {
                    $downloadKey = $downloadUrl . "\0" . $label;
                    if (!isset($seenDownloads[$downloadKey])) {
                        $seenDownloads[$downloadKey] = true;
                        $sizeText = $this->formatSize($sizeBytes);
                        $parts = array_values(array_filter(
                            [$quality, $audio, $sizeText],
                            static fn ($part) => $part !== null && $part !== ''
                        ));
                        $downloads[] = [
                            'label'      => $parts === [] ? 'Download' : implode(' • ', $parts),
                            'quality'    => $quality,
                            'size'       => $sizeText,
                            'size_bytes' => $sizeBytes,
                            'episode'    => $label,
                            'url'        => $downloadUrl,
                            'type'       => $sourceType,
                            'audio'      => $audio,
                        ];
                    }
                }
            }

            $number = $rawEpisode['number'] ?? null;
            $season = $rawEpisode['season'] ?? null;
            $rangeStart = $rawEpisode['range_start'] ?? null;
            $rangeEnd = $rawEpisode['range_end'] ?? null;
            $rawId = $rawEpisode['id'] ?? null;

            $episodes[] = [
                'id'          => $rawId !== null && $rawId !== '' && $rawId !== false ? (string) $rawId : $label,
                'label'       => $label,
                'number'      => is_int($number) ? $number : null,
                'season'      => is_int($season) ? $season : null,
                'range_start' => is_int($rangeStart) ? $rangeStart : null,
                'range_end'   => is_int($rangeEnd) ? $rangeEnd : null,
                'kind'        => str_or_null($rawEpisode['kind'] ?? null),
                'sources'     => $episodeSources,
            ];
        }

        return [$episodes, $flatSources, $downloads];
    }

    /** Return a consistent, compact label such as ``S03E01`` or ``S03 Ep 45-48``. */
    private function episodeLabel(array $rawEpisode): string
    {
        $siteLabel = trim((string) ($rawEpisode['label'] ?? ''));
        $number = $rawEpisode['number'] ?? null;
        $season = $rawEpisode['season'] ?? null;
        $start = $rawEpisode['range_start'] ?? null;
        $end = $rawEpisode['range_end'] ?? null;
        $kind = strtolower(trim((string) ($rawEpisode['kind'] ?? '')));

        if ($kind === 'episode') {
            $hasSeason = is_int($season) && $season > 0;
            if (is_int($start) && is_int($end) && $start !== $end) {
                return $hasSeason
                    ? sprintf('S%02d Ep %d-%d', $season, $start, $end)
                    : sprintf('Ep %d-%d', $start, $end);
            }
            if (is_int($number) && $number > 0) {
                return $hasSeason
                    ? sprintf('S%02dE%02d', $season, $number)
                    : sprintf('E%02d', $number);
            }
        }

        return $siteLabel !== '' ? $siteLabel : 'Source';
    }

    private function qualityText($value, $label = null): ?string
    {
        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $number = (int) $value;
            return $number > 0 ? $number . 'p' : null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        return ctype_digit($text) ? $text . 'p' : $text;
    }

    private function streamType(string $url, ?string $contentType, ?string $hint = null): string
    {
        $hinted = strtolower(ltrim(trim((string) $hint), '.'));
        if (in_array($hinted, ['hls', 'mp4', 'mkv', 'webm'], true)) {
            return $hinted;
        }

        $ct = strtolower((string) $contentType);
        $path = url_path($url);
        if (str_contains($ct, 'mpegurl') || str_ends_with($path, '.m3u8')) {
            return 'hls';
        }
        if (str_contains($ct, 'matroska') || str_ends_with($path, '.mkv')) {
            return 'mkv';
        }
        if (str_contains($ct, 'webm') || str_ends_with($path, '.webm')) {
            return 'webm';
        }
        if (str_contains($ct, 'mp4') || str_ends_with($path, '.mp4')) {
            return 'mp4';
        }
        return 'auto';
    }

    private function serverName(string $url, ?string $provider = null): string
    {
        if ($provider !== null && trim($provider) !== '') {
            return trim($provider);
        }
        $host = url_host($url);
        if ($host === 'fsldownload.com' || str_ends_with($host, '.fsldownload.com')) {
            return 'MLBD CDN';
        }
        return $host !== '' ? $host : 'Source';
    }

    private function isVideoContentType(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '') {
            return true;
        }
        $ct = strtolower($contentType);
        return str_starts_with($ct, 'video/')
            || str_contains($ct, 'mpegurl')
            || str_contains($ct, 'dash+xml')
            || in_array($ct, ['application/octet-stream', 'binary/octet-stream'], true);
    }

    private function formatSize(?int $sizeBytes): ?string
    {
        if ($sizeBytes === null || $sizeBytes <= 0) {
            return null;
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $sizeBytes;
        $unit = 0;
        while ($value >= 1000 && $unit < count($units) - 1) {
            $value /= 1000.0;
            $unit++;
        }
        $rounded = $value >= 100 ? round($value) : round($value, 1);
        $text = rtrim(rtrim(number_format((float) $rounded, 1, '.', ''), '0'), '.');
        return $text . ' ' . $units[$unit];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function categoryFromUrl(string $pageUrl, ?array $payload = null): string
    {
        if ($payload !== null && !empty($payload['adult'])) {
            return 'adult';
        }

        $path = (string) parse_url($pageUrl, PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/', $path), static fn ($part) => $part !== ''));
        $segment = isset($parts[0]) ? strtolower($parts[0]) : '';

        $mapping = [
            'movie'         => 'movie',
            'movies'        => 'movie',
            'southindian'   => 'movie',
            'series'        => 'series',
            'tvseries'      => 'series',
            'drama'         => 'drama',
            'anime'         => 'anime',
            'adult'         => 'adult',
            'adult18plus'   => 'adult',
            'download18plus'=> 'adult',
        ];
        if (isset($mapping[$segment])) {
            return $mapping[$segment];
        }

        $contentType = strtolower((string) ($payload['content_type'] ?? ''));
        if (in_array($contentType, ['movie', 'series', 'drama', 'anime'], true)) {
            return $contentType;
        }
        return 'other';
    }

    // -----------------------------------------------------------------------
    // Media probing
    // -----------------------------------------------------------------------

    /**
     * @param list<string> $urls
     * @return array<string, array{0:?string,1:?int}>
     */
    private function probeMany(array $urls, string $referer): array
    {
        $unique = [];
        foreach ($urls as $url) {
            if (is_string($url) && $url !== '' && !isset($unique[$url])) {
                $unique[$url] = true;
            }
        }
        $unique = array_keys($unique);
        if ($unique === []) {
            return [];
        }

        $headers = [
            'user-agent' => $this->userAgent,
            'referer'    => $referer,
            'accept'     => '*/*',
        ];

        $probes = [];
        $needsGet = [];

        $this->http->multiGet(
            $unique,
            max(1, $this->probeConcurrency),
            static function (string $url, array $response) use (&$probes, &$needsGet): void {
                $contentType = $response['headers']['content-type'] ?? null;
                $length = $response['headers']['content-length'] ?? null;
                if (!$response['ok'] || $response['status'] >= 400 || !$contentType) {
                    $needsGet[$url] = [$contentType, $length];
                    return;
                }
                $probes[$url] = [$contentType, self::parseLength($length)];
            },
            $headers,
            ['timeout' => $this->probeTimeout, 'method' => 'HEAD']
        );

        if ($needsGet !== []) {
            $this->http->multiGet(
                array_keys($needsGet),
                max(1, $this->probeConcurrency),
                static function (string $url, array $response) use (&$probes, $needsGet): void {
                    [$contentType, $length] = $needsGet[$url];
                    $contentType = $contentType ?: ($response['headers']['content-type'] ?? null);

                    if (!$length) {
                        $range = (string) ($response['headers']['content-range'] ?? '');
                        if ($range !== '') {
                            $slash = strrpos($range, '/');
                            $length = $slash === false ? null : trim(substr($range, $slash + 1));
                        }
                        if (!$length) {
                            $length = $response['headers']['content-length'] ?? null;
                        }
                    }

                    $probes[$url] = [$contentType, self::parseLength($length)];
                },
                array_merge($headers, ['range' => 'bytes=0-0']),
                ['timeout' => $this->probeTimeout, 'method' => 'GET']
            );
        }

        return $probes;
    }

    private static function parseLength($length): ?int
    {
        if ($length === null || $length === '' || !is_numeric($length)) {
            return null;
        }
        $parsed = (int) $length;
        return $parsed > 0 ? $parsed : null;
    }

    // -----------------------------------------------------------------------
    // HTML helpers
    // -----------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function streamsFromHtml(string $markup, string $baseUrl): array
    {
        $urls = [];
        $seen = [];

        $add = static function (string $value) use (&$urls, &$seen, $baseUrl): void {
            $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($value === '' || str_starts_with($value, 'data:') || str_starts_with($value, 'blob:')) {
                return;
            }
            $url = url_join($baseUrl, $value);
            if (isset($seen[$url]) || Scraper::isAdUrl($url)) {
                return;
            }
            $seen[$url] = true;
            $urls[] = $url;
        };

        if (preg_match_all('/<(?:video|source)\b[^>]*>/i', $markup, $tags)) {
            foreach ($tags[0] as $tag) {
                foreach (['src', 'data-src'] as $attribute) {
                    if (preg_match('/\b' . $attribute . '="([^"]+)"/i', $tag, $match)) {
                        $add($match[1]);
                    }
                }
            }
        }

        // Direct media URLs embedded in player scripts.
        if (preg_match_all('#https?://[^\s"\'<>\\\\]+\.(?:m3u8|mpd|mp4|mkv|webm)(?:\?[^\s"\'<>\\\\]*)?#i', $markup, $matches)) {
            foreach ($matches[0] as $url) {
                $add($url);
            }
        }

        return $urls;
    }

    public static function isAdUrl(string $url): bool
    {
        $host = url_host($url);
        foreach (self::AD_HOSTS as $adHost) {
            if ($host === $adHost || str_ends_with($host, '.' . $adHost)) {
                return true;
            }
        }
        return false;
    }

    private function pageTitle(string $markup): ?string
    {
        $title = null;
        if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $markup, $match)) {
            $title = $this->textContent($match[1]);
        }
        if ($title === null || $title === '') {
            $title = $this->metaContent($markup, 'og:title');
        }
        if ($title === null || $title === '') {
            if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $markup, $match)) {
                $title = $this->textContent($match[1]);
            }
        }
        if ($title === null) {
            return null;
        }
        $title = str_replace(' Download | MovieLinkBD TV', '', $title);
        return trim($title) !== '' ? trim($title) : null;
    }

    private function metaContent(string $markup, string $property): ?string
    {
        if (!preg_match_all('/<meta\b[^>]*>/i', $markup, $tags)) {
            return null;
        }
        foreach ($tags[0] as $tag) {
            if (!preg_match('/\b(?:property|name)=["\']' . preg_quote($property, '/') . '["\']/i', $tag)) {
                continue;
            }
            if (preg_match('/\bcontent=["\']([^"\']*)["\']/i', $tag, $match)) {
                $value = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                return $value !== '' ? $value : null;
            }
        }
        return null;
    }

    private function textContent(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    // -----------------------------------------------------------------------
    // Shared
    // -----------------------------------------------------------------------

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return [
            'user-agent'                => $this->userAgent,
            'accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'accept-language'           => 'en-US,en;q=0.9',
            'sec-ch-ua'                 => '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
            'sec-ch-ua-mobile'          => '?0',
            'sec-ch-ua-platform'        => '"Windows"',
            'sec-fetch-dest'            => 'document',
            'sec-fetch-mode'            => 'navigate',
            'sec-fetch-site'            => 'none',
            'sec-fetch-user'            => '?1',
            'upgrade-insecure-requests' => '1',
        ];
    }

    /**
     * Attach the common trailing fields to a result.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function result(array $fields): array
    {
        return [
            'page_url'     => $fields['page_url'],
            'title'        => $fields['title'],
            'poster'       => $fields['poster'],
            'content_type' => $fields['content_type'],
            'category'     => $fields['category'],
            'adult'        => $fields['adult'],
            'completed'    => $fields['completed'],
            'episodes'     => $fields['episodes'],
            'sources'      => $fields['sources'],
            'downloads'    => $fields['downloads'],
            'scraped_at'   => now_iso(),
        ];
    }
}
