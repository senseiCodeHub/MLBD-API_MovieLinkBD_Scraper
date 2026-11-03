<?php
declare(strict_types=1);

/**
 * Shared helpers: configuration, escaping, URLs and JSON responses.
 */

/**
 * Read a dotted configuration value, e.g. config('db.host').
 *
 * @param mixed $default
 * @return mixed
 */
function config(?string $key = null, $default = null)
{
    $config = $GLOBALS['MLBD_CONFIG'] ?? [];

    if ($key === null || $key === '') {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** HTML-escape a value for templates. */
function e($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute filesystem path inside the project. */
function base_path(string $path = ''): string
{
    $root = defined('MLBD_ROOT') ? MLBD_ROOT : dirname(__DIR__);
    return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}

/** Writable storage directory, created on demand. */
function storage_path(string $path = ''): string
{
    $base = (string) config('storage_dir', base_path('storage'));
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}

/** UTC timestamp in the ISO-8601 shape the Python API emitted. */
function now_iso(): string
{
    $now = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
    if ($now === false) {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
    return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
}

/**
 * Resolve a possibly-relative URL against a base (RFC 3986).
 */
function url_join(string $base, string $relative): string
{
    if ($relative === '') {
        return $base;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative)) {
        return $relative;
    }

    $baseParts = parse_url($base);
    if ($baseParts === false) {
        return $relative;
    }
    $scheme = $baseParts['scheme'] ?? 'https';
    $host = $baseParts['host'] ?? '';
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    $authority = $scheme . '://' . $host . $port;

    if (str_starts_with($relative, '//')) {
        return $scheme . ':' . $relative;
    }
    if (str_starts_with($relative, '?')) {
        $path = $baseParts['path'] ?? '/';
        return $authority . $path . $relative;
    }
    if (str_starts_with($relative, '#')) {
        $path = $baseParts['path'] ?? '/';
        $query = isset($baseParts['query']) ? '?' . $baseParts['query'] : '';
        return $authority . $path . $query . $relative;
    }

    if (str_starts_with($relative, '/')) {
        return $authority . $relative;
    }

    $basePath = $baseParts['path'] ?? '/';
    $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
    if ($directory === '') {
        $directory = '/';
    }
    $path = $directory . $relative;

    // Normalise "." and ".." segments.
    $segments = explode('/', $path);
    $out = [];
    foreach ($segments as $segment) {
        if ($segment === '.' || $segment === '') {
            continue;
        }
        if ($segment === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $segment;
    }
    return $authority . '/' . implode('/', $out);
}

/** Hostname of a URL, lower-cased, or an empty string. */
function url_host(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

/** Path of a URL, lower-cased, or an empty string. */
function url_path(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    return is_string($path) ? strtolower($path) : '';
}

/**
 * Emit a JSON response and stop.
 *
 * @param mixed $data
 */
function json_response($data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Accept');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Read the JSON request body (or form fields) as an array.
 *
 * @return array<string, mixed>
 */
function request_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $body = is_array($decoded) ? $decoded : [];
        return $body;
    }

    $body = $_POST;
    if ($body === []) {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw !== '') {
            parse_str($raw, $parsed);
            $body = is_array($parsed) ? $parsed : [];
        }
    }
    return $body;
}

/** True when the request is a JSON API call. */
function wants_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return str_contains($accept, 'application/json');
}

/**
 * Resolve the request path, with a ``?r=/path`` fallback for hosts that cannot
 * rewrite (the app also runs from ``index.php/search`` PATH_INFO).
 */
function request_path(): string
{
    if (isset($_GET['r']) && is_string($_GET['r']) && $_GET['r'] !== '') {
        return '/' . trim($_GET['r'], '/');
    }

    $pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($pathInfo !== '') {
        return '/' . trim($pathInfo, '/');
    }

    $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    $uri = preg_replace('#^/index\.php#', '', $uri) ?? $uri;

    return '/' . trim($uri, '/');
}

/** Clamp an integer between bounds. */
function clamp_int($value, int $min, int $max, int $default): int
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return $default;
    }
    return max($min, min($max, (int) $value));
}

/** Convert a value to a trimmed string or null. */
function str_or_null($value): ?string
{
    if ($value === null) {
        return null;
    }
    $text = trim((string) $value);
    return $text === '' ? null : $text;
}

/**
 * Build an in-app link. ``?r=/path`` also works where rewriting is unavailable.
 *
 * @param array<string, mixed> $params
 */
function page_url(string $path, array $params = []): string
{
    $params = array_filter(
        $params,
        static fn ($value): bool => $value !== null && $value !== ''
    );
    return '?r=' . rawurlencode($path) . ($params === [] ? '' : '&' . http_build_query($params));
}

/** Human-readable byte size. */
function format_bytes($bytes): string
{
    if (!is_numeric($bytes) || (float) $bytes <= 0) {
        return '—';
    }
    $value = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return rtrim(rtrim(number_format($value, $index === 0 ? 0 : 1), '0'), '.') . ' ' . $units[$index];
}

/** Human-readable elapsed seconds. */
function format_seconds($seconds): string
{
    if (!is_numeric($seconds) || $seconds <= 0) {
        return '—';
    }
    $seconds = (int) $seconds;
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
    }
    return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
}

/**
 * Parse a stored UTC timestamp (``Y-m-d H:i:s`` with optional microseconds).
 */
function parse_stamp(?string $timestamp): ?\DateTimeImmutable
{
    $timestamp = trim((string) $timestamp);
    if ($timestamp === '') {
        return null;
    }

    $stamp = substr($timestamp, 0, 19);
    // Stored stamps come in two shapes: "Y-m-d H:i:s" from MySQL and ISO-8601
    // ("Y-m-d\TH:i:s.u\Z") from the library rows exposed to the views.
    $utc = new \DateTimeZone('UTC');
    $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stamp, $utc)
        ?: \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', $stamp, $utc);
    return $date instanceof \DateTimeImmutable ? $date : null;
}

/**
 * "just now", "12m ago", "5h ago", "3d ago" — reads stored UTC timestamps.
 */
function time_ago(?string $timestamp): string
{
    $date = parse_stamp($timestamp);
    if ($date === null) {
        return '—';
    }

    $seconds = time() - $date->getTimestamp();
    if ($seconds < 0) {
        $seconds = 0;
    }
    if ($seconds < 60) {
        return 'just now';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . 'm ago';
    }
    if ($seconds < 86400) {
        return intdiv($seconds, 3600) . 'h ago';
    }
    if ($seconds < 86400 * 30) {
        return intdiv($seconds, 86400) . 'd ago';
    }
    return $date->format('Y-m-d');
}

/** Absolute UTC stamp for tooltips: ``2026-11-01 20:52 UTC``. */
function stamp_utc(?string $timestamp): string
{
    $date = parse_stamp($timestamp);
    return $date === null ? '—' : $date->format('Y-m-d H:i') . ' UTC';
}
