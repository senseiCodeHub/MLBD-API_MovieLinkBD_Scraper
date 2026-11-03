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
     * @return array<string, array{ok: bool, status: int, error: ?string, unknown: bool}>
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
     * @param list<string> $urls
     * @return array<string, array{ok: bool, status: int, error: ?string, unknown: bool}>
     */
    private function runChunk(array $urls): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($urls as $url) {
            $handle = curl_init($url);
            if ($handle === false) {
                continue;
            }

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

            $results[$url] = [
                'ok'     => ($code >= 200 && $code < 400) || $code === 416,
                'status' => $code,
                'error'  => $error !== '' ? $error : null,
                // status 0 means the request itself failed (DNS, TLS, reset):
                // that says nothing about the token, so it must not trigger a
                // re-scrape of the whole library.
                'unknown' => $code === 0,
            ];

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);
        return $results;
    }
}
