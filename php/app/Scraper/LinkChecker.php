<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

/**
 * Cheap liveness probe for stored stream URLs.
 *
 * MovieLinkBD hands out opaque per-request tokens (``https://fsldownload.com/p/…``)
 * that expire, so a saved library slowly fills with dead links. Fetching every
 * detail page again costs ~1.9 s per title; asking the CDN for the first couple
 * of kilobytes of each stored link costs a fraction of that and tells us exactly
 * which titles actually need a re-scrape.
 *
 * Uses a ranged GET instead of HEAD because several of these CDNs answer 403 to
 * HEAD, and the write callback aborts the transfer after a few KB so a server
 * that ignores ``Range`` cannot stream a whole movie at us.
 */
final class LinkChecker
{
    private const SAMPLE_BYTES = 16384;

    private string $userAgent;

    private string $referer;

    private int $timeout;

    private ?string $caBundle;

    public function __construct(int $timeout = 12, ?string $referer = null)
    {
        $this->timeout = max(3, $timeout);
        $this->userAgent = (string) config(
            'scraper.user_agent',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
        );
        $this->referer = $referer
            ?? rtrim((string) config('site.base_url', 'https://movielinkbd.tv'), '/') . '/';

        $bundle = trim((string) config('scraper.ca_bundle', ''));
        $this->caBundle = $bundle !== '' && is_file($bundle) ? $bundle : null;
    }

    /**
     * @param list<string> $urls
     * @return array<string, array{ok: bool, status: int, error: ?string, unknown: bool, size: ?int, content_type: ?string}>
     */
    public function check(array $urls, int $concurrency = 8): array
    {
        $results = [];
        $unique = array_values(array_unique(array_filter($urls, static fn ($url): bool => is_string($url) && $url !== '')));

        foreach (array_chunk($unique, max(1, $concurrency)) as $chunk) {
            foreach ($this->runChunk($chunk) as $url => $result) {
                $results[$url] = $result;
            }
        }

        return $results;
    }

    /**
     * A 2xx/3xx response (or 416 for an unsatisfiable range) means the token is
     * still valid; everything else — 401/403/404/410, DNS failures, resets — is
     * treated as dead and worth re-scraping.
     *
     * The same request also carries the file's size and MIME type: a ranged
     * response reports the whole length as `Content-Range: bytes 0-2048/123456`,
     * so filling in `size_bytes` needs no second request per link.
     *
     * @param list<string> $urls
     * @return array<string, array{ok: bool, status: int, error: ?string, unknown: bool, size: ?int, content_type: ?string}>
     */
    private function runChunk(array $urls): array
    {
        $multi = curl_multi_init();
        $handles = [];

        // Per-URL response headers, captured by the header callback. A new
        // status line starts a new response, so a redirect chain leaves the
        // final response's headers behind, not the first hop's. (The blank line
        // that ends a header block must NOT reset: it arrives *after* the
        // headers we want, and clearing there loses the final response.)
        $headerStore = [];

        foreach ($urls as $url) {
            $handle = curl_init($url);
            if ($handle === false) {
                continue;
            }

            $headerStore[$url] = [];
            $options = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER         => false,
                CURLOPT_RANGE          => '0-2048',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_REFERER        => $this->referer,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING       => '',
                CURLOPT_WRITEFUNCTION  => static function ($ch, string $data): int {
                    return strlen($data) > self::SAMPLE_BYTES ? 0 : strlen($data);
                },
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headerStore, $url): int {
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        return strlen($line);
                    }
                    if (stripos($trimmed, 'HTTP/') === 0) {
                        $headerStore[$url] = [];
                        return strlen($line);
                    }
                    $parts = explode(':', $trimmed, 2);
                    if (count($parts) === 2) {
                        $headerStore[$url][strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ];

            if ($this->caBundle !== null) {
                $options[CURLOPT_CAINFO] = $this->caBundle;
            }

            curl_setopt_array($handle, $options);
            curl_multi_add_handle($multi, $handle);
            $handles[$url] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $url => $handle) {
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = trim((string) curl_error($handle));
            $headers = $headerStore[$url] ?? [];

            $results[$url] = [
                'ok'     => ($code >= 200 && $code < 400) || $code === 416,
                'status' => $code,
                'error'  => $error !== '' ? $error : null,
                // status 0 means the request itself failed (DNS, TLS, reset):
                // that says nothing about the token, so it must not trigger a
                // re-scrape of the whole library.
                'unknown' => $code === 0,
                'size'    => self::sizeFromHeaders($headers, $code),
                'content_type' => self::strOrNull($headers['content-type'] ?? null),
            ];

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);
        return $results;
    }

    /**
     * The whole file's length, from a ranged or plain response.
     *
     * A 206 answers `Content-Range: bytes 0-2048/1234567`, where the number
     * after the slash is the file. A plain 200 can only offer Content-Length —
     * on a 206 that header is just the range we asked for, so it is ignored
     * there rather than stored as a wrong size.
     *
     * @param array<string, string> $headers
     */
    private static function sizeFromHeaders(array $headers, int $status): ?int
    {
        $range = trim((string) ($headers['content-range'] ?? ''));
        if ($range !== '' && preg_match('#/\s*(\d+)\s*$#', $range, $match)) {
            $total = (int) $match[1];
            return $total > 0 ? $total : null;
        }

        if ($status === 206) {
            return null;
        }

        $length = trim((string) ($headers['content-length'] ?? ''));
        if ($length !== '' && ctype_digit($length) && (int) $length > 0) {
            return (int) $length;
        }

        return null;
    }

    private static function strOrNull(?string $value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
