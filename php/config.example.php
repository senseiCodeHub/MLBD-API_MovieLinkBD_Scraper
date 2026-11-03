<?php
/**
 * MLBD Scraper — PHP configuration.
 *
 * Copy this file to `config.php` and edit the database credentials.
 * `config.php` is git-ignored and protected by .htaccess.
 *
 * Credentials are optional here: `app/bootstrap.php` already loads the shared
 * `.env` (../.env or ./.env), so an existing MYSQL_* block works unchanged and
 * these values are only the fallback.
 *
 * Every value can also be supplied through an environment variable, which is
 * useful on Webuzo/cPanel where you may prefer to keep secrets out of the code.
 */

return [
    // MovieLinkBD origin. Change only if the site moves to another domain.
    'site' => [
        'base_url' => 'https://movielinkbd.tv',
    ],

    // Core scrape settings (mirrors the Python scraper defaults).
    'scraper' => [
        'page_timeout'      => 30,  // seconds for the detail-page request
        'probe_timeout'     => 8,   // seconds per media HEAD/GET probe
        'probe_concurrency' => 8,   // parallel media probes
        'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        // Optional path to a cacert.pem bundle when the host has no CA store
        // (common with Windows/XAMPP). Leave empty on Webuzo/cPanel.
        'ca_bundle'         => getenv('MLBD_CA_BUNDLE') ?: '',
    ],

    // Whole-catalogue index (the site's search-suggest shards).
    'catalog' => [
        'cache_ttl'       => 21600, // 6 hours
        'manifest_ttl'    => 1800,  // 30 minutes
        'http_timeout'    => 30,
        'concurrency'     => 32,
        'shard_cap'       => 240,
    ],

    // How long a stored scrape is served when the live site fails.
    'scrape_cache_seconds' => 21600,

    // One-click library sync.
    'sync' => [
        'default_concurrency'      => 3,
        'max_concurrency'          => 12,
        'max_consecutive_failures' => 15,
        'titles_per_step'          => 1,
        // Scheduled refresh: a title counts as fresh for this many hours, and
        // each cron invocation runs for at most this many minutes.
        'refresh_hours'            => 6,
        'cron_minutes'             => 25,
    ],

    // Writable storage for the catalogue cache and sync state.
    'storage_dir' => __DIR__ . '/storage',

    // MySQL — the library/mirror store. Leave the password empty to run the
    // scraper and search without a database; only the Library tab needs it.
    'db' => [
        'host'     => getenv('MYSQL_HOST') ?: 'localhost',
        'port'     => (int) (getenv('MYSQL_PORT') ?: 3306),
        'user'     => getenv('MYSQL_USER') ?: 'root',
        'password' => getenv('MYSQL_PASSWORD') ?: '',
        // Use the EXACT name from the hosting panel. Webuzo/cPanel prefix every
        // database with the account name, e.g. "lnfbojpx_mlbd" — writing to a
        // bare "mlbd" creates (or uses) a different, empty database.
        'database' => getenv('MYSQL_DATABASE') ?: 'lnfbojpx_mlbd',
        'charset'  => 'utf8mb4',
    ],

    // Show detailed errors in the API response. Keep false in production.
    'debug' => false,
];
