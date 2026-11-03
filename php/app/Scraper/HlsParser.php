<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

use Mlbd\Http\HttpClient;

/**
 * Parse an HLS master playlist into quality variants.
 *
 * Used by the HTML fallback path; the inline payload already carries the
 * quality of every source, so the primary path never needs a playlist request.
 */
final class HlsParser
{
    /**
     * @param array<string, string> $headers
     * @return list<array{quality:string,bandwidth:?int,url:string}>
     */
    public static function parse(string $url, array $headers = []): array
    {
        $client = new HttpClient(20);
        $response = $client->request($url, $headers, ['timeout' => 20]);
        if (!$response['ok'] || $response['status'] >= 400) {
            return [];
        }

        $text = $response['body'];
        if (stripos($text, '#EXT-X-STREAM-INF') === false) {
            return [['quality' => 'auto', 'bandwidth' => null, 'url' => $url]];
        }

        $variants = [];
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $pending = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (stripos($line, '#EXT-X-STREAM-INF:') === 0) {
                $attributes = substr($line, strlen('#EXT-X-STREAM-INF:'));
                $bandwidth = null;
                $height = null;

                if (preg_match('/BANDWIDTH=(\d+)/i', $attributes, $match)) {
                    $bandwidth = (int) $match[1];
                }
                if (preg_match('/RESOLUTION=(\d+)x(\d+)/i', $attributes, $match)) {
                    $height = (int) $match[2];
                }

                $pending = [
                    'quality'   => $height !== null && $height > 0 ? $height . 'p' : 'auto',
                    'bandwidth' => $bandwidth,
                ];
                continue;
            }
            if ($line[0] === '#') {
                continue;
            }
            if ($pending !== null) {
                $variants[] = [
                    'quality'   => $pending['quality'],
                    'bandwidth' => $pending['bandwidth'],
                    'url'       => url_join($url, $line),
                ];
                $pending = null;
            }
        }

        if ($variants === []) {
            $variants[] = ['quality' => 'auto', 'bandwidth' => null, 'url' => $url];
        }
        return $variants;
    }
}
