<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

/**
 * Decodes the ``#mlbdInlinePlayerData`` element embedded in every detail page.
 *
 * The body is base64 + XOR against the LCG stream seeded by ``data-s`` and
 * advanced ``data-r`` rounds before the first byte.
 */
final class PayloadDecoder
{
    private const PAYLOAD_RE = '#<script\b[^>]*\bid="mlbdInlinePlayerData"[^>]*>(.*?)</script>#is';
    private const SEED_RE = '/\bdata-s="([0-9a-fA-F]+)"/';
    private const ROUNDS_RE = '/\bdata-r="(\d+)"/';

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $markup): ?array
    {
        if (!preg_match(self::PAYLOAD_RE, $markup, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $body = $match[1][0] ?? '';
        $start = $match[0][1] ?? 0;

        // Search the opening tag first, then fall back to a window around it.
        $tagEnd = strpos($markup, '>', $start);
        $openingTag = $tagEnd === false
            ? substr($markup, max(0, $start - 500), 1000)
            : substr($markup, max(0, $start - 500), ($tagEnd - max(0, $start - 500)) + 1);

        $seed = '0';
        $rounds = 0;
        if (preg_match(self::SEED_RE, $openingTag, $seedMatch)) {
            $seed = $seedMatch[1];
        }
        if (preg_match(self::ROUNDS_RE, $openingTag, $roundsMatch)) {
            $rounds = (int) $roundsMatch[1];
        }

        $decoded = Lcg::decodeXorBase64(trim($body), $seed, $rounds);
        if ($decoded === null || $decoded === '') {
            return null;
        }

        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return null;
        }
        // The payload must be a JSON object, not a top-level array.
        if ($data !== [] && array_is_list($data)) {
            return null;
        }

        return $data;
    }
}
