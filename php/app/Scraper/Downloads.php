<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

/**
 * Parses the download section of a MovieLinkBD detail page.
 *
 * The site renders download buttons as
 * ``<a href="#" data-dl="..." data-ds="..." data-dr="...">`` and decodes the
 * real ``/file/<token>`` URL in the browser with a small XOR routine. The same
 * routine is implemented here so downloads work without JavaScript.
 */
final class Downloads
{
    private const SIZE_UNITS = [
        'B'  => 1,
        'KB' => 1000,
        'MB' => 1000000,
        'GB' => 1000000000,
        'TB' => 1000000000000,
    ];

    private const ANCHOR = '/<a\b(?P<attrs>[^>]*\bdata-dl="[^"]+"[^>]*)>(?P<label>.*?)<\/a>/is';
    private const DATA_DL = '/\bdata-dl="([^"]+)"/';
    private const DATA_DS = '/\bdata-ds="([^"]*)"/';
    private const DATA_DR = '/\bdata-dr="(\d+)"/';
    private const QUALITY = '/\b(\d{3,4}p)\b/i';
    private const SIZE = '/\b(\d+(?:\.\d+)?)\s*(TB|GB|MB|KB|B)\b/i';
    // PCRE spells a code point `\x{fe0f}`; the Python original's `\ufe0f` does
    // not compile here, and a failed preg_replace returns null — which used to
    // blank every download label before its quality and size were read off it.
    private const DOWNLOAD_WORD = '/^\s*(?:⬇\x{fe0f}?\s*)?download\b[\s:•\-–—\[\]()]*/iu';

    /** Normalise a download button label into "480p • 676 MB" form. */
    public static function cleanLabel(string $raw): string
    {
        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = trim($text);
        $text = preg_replace(self::DOWNLOAD_WORD, '', $text) ?? '';
        $text = str_replace('•', '•', $text);
        $text = trim($text, " •-–—[]()");
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /** Parse "676 MB" into bytes. */
    public static function parseSize(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!preg_match(self::SIZE, $value, $match)) {
            return null;
        }
        $amount = (float) $match[1];
        $unit = strtoupper($match[2]);
        return (int) round($amount * (self::SIZE_UNITS[$unit] ?? 1));
    }

    /**
     * Decode the ``data-dl`` payload exactly like the page script does.
     */
    public static function decodeUrl(string $blob, string $seed, int $rounds): string
    {
        return Lcg::decodeXorBase64($blob, $seed, $rounds) ?? '';
    }

    /**
     * Turn a decoded href plus its button label into a DownloadLink array.
     *
     * @return array<string, mixed>|null
     */
    public static function build(string $url, string $rawLabel, ?string $episode = null): ?array
    {
        if ($url === '') {
            return null;
        }

        $label = self::cleanLabel($rawLabel);
        $quality = null;
        $size = null;
        $sizeBytes = null;

        if (preg_match(self::QUALITY, $label, $qualityMatch)) {
            $quality = strtolower($qualityMatch[1]);
        }
        if (preg_match(self::SIZE, $label, $sizeMatch)) {
            $size = trim(preg_replace('/\s+/u', ' ', $sizeMatch[0]) ?? '');
            $sizeBytes = self::parseSize($sizeMatch[0]);
        }

        return [
            'label'      => $label !== '' ? $label : 'Download',
            'quality'    => $quality,
            'size'       => $size,
            'size_bytes' => $sizeBytes,
            'episode'    => str_or_null($episode),
            'url'        => $url,
            'type'       => null,
            'audio'      => null,
        ];
    }

    /**
     * Fallback parser for raw HTML that still carries ``data-dl`` blobs.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseFromHtml(string $markup, string $baseUrl): array
    {
        $downloads = [];
        $seen = [];

        if (!preg_match_all(self::ANCHOR, $markup, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $attrs = $match['attrs'] ?? '';
            if (!preg_match(self::DATA_DL, $attrs, $blobMatch)) {
                continue;
            }

            $seed = '';
            $rounds = 0;
            if (preg_match(self::DATA_DS, $attrs, $seedMatch)) {
                $seed = $seedMatch[1];
            }
            if (preg_match(self::DATA_DR, $attrs, $roundsMatch)) {
                $rounds = (int) $roundsMatch[1];
            }

            $decoded = self::decodeUrl($blobMatch[1], $seed, $rounds);
            if ($decoded === '') {
                continue;
            }

            $url = url_join($baseUrl, $decoded);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $link = self::build($url, $match['label'] ?? '');
            if ($link !== null) {
                $downloads[] = $link;
            }
        }

        return $downloads;
    }
}
