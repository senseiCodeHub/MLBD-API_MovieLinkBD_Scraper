<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

/**
 * Reads the redesigned MovieLinkBD player (``Player v2``).
 *
 * Since late 2026 a detail page no longer embeds ``#mlbdInlinePlayerData``.
 * Instead it renders ``<iframe id="mlbdHdFrame" src="/p/<token>">`` and that
 * embed page carries a plain JSON object:
 *
 *     <script>window.__PD2 = {"master":"https://watch.movielink.tv/m/…",
 *         "episodes":[{"master":…,"mp4":…,"audio":[…],"subs":[…]}], …};</script>
 *
 * ``master`` is an HLS playlist served from ``watch.movielink.tv`` (disguised
 * as ``image/jpeg`` byte-range segments), ``mp4`` is the direct file on the
 * site's worker CDN. Both are real stream URLs; the player script itself
 * branches on ``/m/`` and hands the URL to hls.js.
 */
final class PlayerDecoder
{
    private const IFRAME_ID_RE = '/<iframe\b[^>]*\bid=["\']mlbdHdFrame["\'][^>]*>/i';
    private const SRC_RE = '/\b(?:data-)?src=["\']([^"\']+)["\']/i';
    private const PD2_RE = '/window\.__PD2\s*=\s*/';

    /**
     * Decode ``window.__PD2`` from a detail page or an embed page.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $markup): ?array
    {
        $json = self::extractObject($markup);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return null;
        }

        return $data;
    }

    /**
     * Resolve the player iframe URL on a detail page.
     */
    public static function iframeUrl(string $markup, string $baseUrl): ?string
    {
        if (preg_match(self::IFRAME_ID_RE, $markup, $tag)) {
            if (preg_match(self::SRC_RE, $tag[0], $src)) {
                $url = html_entity_decode(trim($src[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($url !== '') {
                    return url_join($baseUrl, $url);
                }
            }
        }

        // Markup without the id still uses the same `/p/<token>` embed path.
        if (preg_match_all('/<iframe\b[^>]*>/i', $markup, $tags)) {
            foreach ($tags[0] as $iframe) {
                if (!preg_match(self::SRC_RE, $iframe, $src)) {
                    continue;
                }
                $url = html_entity_decode(trim($src[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $path = (string) parse_url($url, PHP_URL_PATH);
                if (str_starts_with($path, '/p/')) {
                    return url_join($baseUrl, $url);
                }
            }
        }

        return null;
    }

    /**
     * True when a decoded payload uses the new player shape.
     *
     * @param array<string, mixed> $payload
     */
    public static function isPlayerPayload(array $payload): bool
    {
        if (isset($payload['episodes']) && is_array($payload['episodes'])) {
            foreach ($payload['episodes'] as $episode) {
                if (is_array($episode) && (isset($episode['master']) || isset($episode['mp4']))) {
                    return true;
                }
            }
        }

        return isset($payload['master']) || isset($payload['mp4']);
    }

    /**
     * Extract the balanced JSON object that follows ``window.__PD2 =``.
     */
    private static function extractObject(string $markup): ?string
    {
        if (!preg_match(self::PD2_RE, $markup, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $offset = (int) ($match[0][1] ?? 0) + strlen($match[0][0]);
        $start = strpos($markup, '{', $offset);
        if ($start === false) {
            return null;
        }

        $length = strlen($markup);
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $markup[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                $depth++;
                continue;
            }
            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($markup, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
