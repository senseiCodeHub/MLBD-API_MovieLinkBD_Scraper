<?php
declare(strict_types=1);

/**
 * Application bootstrap: configuration, autoloading, storage and error handling.
 */

define('MLBD_ROOT', dirname(__DIR__));

if (!file_exists(MLBD_ROOT . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "MLBD Scraper is not configured yet.\n\n";
    echo "Copy config.example.php to config.php and fill in the MySQL credentials.\n";
    exit;
}

/**
 * Pick up MySQL credentials from the project's .env.
 *
 * The Python app already keeps them there, so the PHP port reads the same file
 * instead of duplicating secrets in config.php. Real environment variables
 * always win, and a missing file is fine (config.php has its own defaults).
 */
(static function (): void {
    $candidates = [
        MLBD_ROOT . '/.env',          // ships with php/ — the one to upload
        dirname(MLBD_ROOT) . '/.env', // repo root, shared with the Python app
    ];

    foreach ($candidates as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || preg_match('/[^A-Za-z0-9_]/', $key) === 1) {
                continue;
            }
            if (getenv($key) !== false) {
                continue; // never shadow a real environment variable
            }

            $value = trim($value);
            $length = strlen($value);
            if ($length > 1) {
                $first = $value[0];
                $last = $value[$length - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }

        break; // the first .env found wins
    }
})();

/** @var array<string, mixed> $config */
$config = require MLBD_ROOT . '/config.php';
$GLOBALS['MLBD_CONFIG'] = $config;

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Long catalogue crawls and one-title scrape steps need more than the default
// 30 seconds. Shared hosts may cap this; the sync job is chunked so it still
// works when they do.
@set_time_limit(300);

require __DIR__ . '/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Mlbd\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    // Several exception types share one file, which the class-per-file rule
    // below cannot infer from the name.
    $classmap = [
        'Mlbd\\Scraper\\ScraperError'    => __DIR__ . '/Scraper/Exceptions.php',
        'Mlbd\\Scraper\\CloudflareBlocked' => __DIR__ . '/Scraper/Exceptions.php',
        'Mlbd\\Scraper\\NoStreamFound'   => __DIR__ . '/Scraper/Exceptions.php',
    ];
    if (isset($classmap[$class])) {
        require $classmap[$class];
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Ensure the writable directories exist.
foreach (['', 'cache', 'logs'] as $dir) {
    $path = storage_path($dir);
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

if (!is_writable(storage_path())) {
    // The API still works for scraping; only caching and library sync need it.
    error_log('MLBD: storage directory is not writable: ' . storage_path());
}

// Never leak stack traces to visitors unless debug is explicitly enabled.
$debug = (bool) config('debug', false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(static function (\Throwable $exception) use ($debug): void {
    error_log('MLBD uncaught: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok'         => false,
        'data'       => null,
        'error'      => $debug ? $exception->getMessage() : 'Internal server error',
        'error_type' => 'InternalError',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
});
