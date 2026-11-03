<?php
declare(strict_types=1);

namespace Mlbd\Scraper;

/**
 * The Linear Congruential Generator used by MovieLinkBD's inline player.
 *
 * state = (state * 1103515245 + 12345) mod 2^32
 *
 * PHP integers are 64-bit on every mainstream host, which is enough for the
 * multiplication. On a 32-bit build the arithmetic falls back to GMP.
 */
final class Lcg
{
    private const MULTIPLIER = 1103515245;
    private const INCREMENT = 12345;
    private const MASK = 0xFFFFFFFF;

    public static function next(int $state): int
    {
        if (PHP_INT_SIZE >= 8) {
            return ($state * self::MULTIPLIER + self::INCREMENT) & self::MASK;
        }

        if (function_exists('gmp_mul')) {
            $value = gmp_add(gmp_mul(gmp_init($state, 10), self::MULTIPLIER), self::INCREMENT);
            return gmp_intval(gmp_and($value, self::MASK));
        }

        // Last-resort 32-bit emulation using floats. Kept only so the scraper
        // does not crash on exotic hosts; Webuzo servers are 64-bit.
        return (int) fmod($state * self::MULTIPLIER + self::INCREMENT, 4294967296.0);
    }

    public static function advance(int $state, int $rounds): int
    {
        for ($i = 0; $i < $rounds; $i++) {
            $state = self::next($state);
        }
        return $state;
    }

    /**
     * Decode a base64 body XOR-ed with the LCG keystream.
     */
    public static function decodeXorBase64(string $blob, string $seedHex, int $rounds): ?string
    {
        $seedHex = trim($seedHex);
        $seed = $seedHex === '' ? 0 : (int) hexdec($seedHex);
        $state = $seed & self::MASK;
        $state = self::advance($state, max(0, $rounds));

        $raw = base64_decode($blob, false);
        if ($raw === false || $raw === '') {
            return null;
        }

        $length = strlen($raw);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $state = self::next($state);
            $out .= chr(ord($raw[$i]) ^ (($state >> 16) & 0xFF));
        }
        return $out;
    }
}
