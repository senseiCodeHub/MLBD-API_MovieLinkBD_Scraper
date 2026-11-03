<?php
declare(strict_types=1);

namespace Mlbd\Store;

use Mlbd\Scraper\ScraperError;
use PDO;
use PDOException;

/**
 * Shared PDO connection for the ``mlbd_*`` library tables.
 *
 * Mirrors the Python ``scraper.store`` module: the database and its tables are
 * created on first use, so a fresh Webuzo/cPanel database only needs the
 * credentials in ``config.php``.
 */
final class Database
{
    public const TITLES_TABLE = 'mlbd_titles';
    public const SCRAPES_TABLE = 'mlbd_scrapes';
    public const LINKS_TABLE = 'mlbd_links';

    public const TITLE_BATCH = 400;
    public const LINK_BATCH = 200;

    private static ?PDO $pdo = null;

    /** True when a host and database name are configured. */
    public static function configured(): bool
    {
        $host = trim((string) config('db.host', ''));
        $name = trim((string) config('db.database', ''));
        return $host !== '' && $name !== '';
    }

    /** True when MySQL storage may be written to (same condition as Python). */
    public static function writable(): bool
    {
        return self::configured();
    }

    /**
     * Return the lazy shared connection, creating the database and schema.
     *
     * @throws ScraperError
     */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        if (!self::configured()) {
            throw new ScraperError(
                'MySQL is not configured: set db.host and db.database in config.php'
            );
        }

        $host = (string) config('db.host', 'localhost');
        $port = (int) config('db.port', 3306);
        $user = (string) config('db.user', 'root');
        $password = (string) config('db.password', '');
        $name = (string) config('db.database', 'mlbd');
        $charset = (string) config('db.charset', 'utf8mb4');

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 10,
        ];

        // Connect without a database first so CREATE DATABASE can run.
        try {
            $admin = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
                $user,
                $password,
                $options
            );
            $admin->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                str_replace('`', '', $name)
            ));
            $admin = null;
        } catch (PDOException $exception) {
            // Creating the database needs privileges some shared hosts do not
            // grant; the database may already exist, so try the real one next.
            error_log('MLBD: could not create database: ' . $exception->getMessage());
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset),
                $user,
                $password,
                $options
            );
        } catch (PDOException $exception) {
            throw new ScraperError(
                'Could not connect to MySQL: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        self::ensureSchema($pdo);
        self::$pdo = $pdo;
        return $pdo;
    }

    /**
     * Try a trivial query without creating anything.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function testConnection(): array
    {
        try {
            self::pdo()->query('SELECT 1')->fetchColumn();
            return ['ok' => true];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /** Create the three tables when they are missing. */
    private static function ensureSchema(PDO $pdo): void
    {
        $titles = self::TITLES_TABLE;
        $scrapes = self::SCRAPES_TABLE;
        $links = self::LINKS_TABLE;

        $statements = [
            "CREATE TABLE IF NOT EXISTS {$titles} (
                slug            VARCHAR(255) PRIMARY KEY,
                title           VARCHAR(255) NOT NULL,
                url             TEXT,
                poster          TEXT,
                kind            VARCHAR(64),
                category        VARCHAR(64),
                year            VARCHAR(16),
                adult           TINYINT(1) DEFAULT 0,
                quality         VARCHAR(32),
                language        VARCHAR(32),
                rating          VARCHAR(32),
                source_version  VARCHAR(64),
                updated_at      DATETIME(6),
                INDEX idx_titles_category (category),
                INDEX idx_titles_title (title(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$scrapes} (
                page_url       VARCHAR(512) PRIMARY KEY,
                requested_url  VARCHAR(512),
                slug           VARCHAR(255),
                title          VARCHAR(255),
                poster         TEXT,
                content_type   VARCHAR(64),
                category       VARCHAR(64),
                adult          TINYINT(1) DEFAULT 0,
                completed      TINYINT(1),
                episode_count  INT DEFAULT 0,
                source_count   INT DEFAULT 0,
                download_count INT DEFAULT 0,
                status         VARCHAR(16) DEFAULT 'saved',
                error          TEXT,
                payload        JSON,
                scraped_at     DATETIME(6),
                updated_at     DATETIME(6),
                INDEX idx_scrapes_updated (updated_at),
                INDEX idx_scrapes_slug (slug),
                INDEX idx_scrapes_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS {$links} (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                page_url       VARCHAR(512),
                title          VARCHAR(255),
                episode_label  VARCHAR(128),
                season         INT,
                episode_number INT,
                provider       VARCHAR(128),
                quality        VARCHAR(32),
                audio          VARCHAR(64),
                kind           VARCHAR(32),
                codec          VARCHAR(32),
                size_bytes     BIGINT,
                content_type   VARCHAR(64),
                stream_url     TEXT,
                download_url   TEXT,
                is_best        TINYINT(1) DEFAULT 0,
                updated_at     DATETIME(6),
                INDEX idx_links_page (page_url),
                INDEX idx_links_stream (stream_url(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $exception) {
                // A restricted user cannot create tables; only fail when the
                // tables really are absent.
                $table = preg_match('/EXISTS `?(\w+)`?/', $sql, $match) ? $match[1] : '';
                if ($table === '' || !self::tableExists($pdo, $table)) {
                    throw $exception;
                }
            }
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $statement = $pdo->prepare('SHOW TABLES LIKE ?');
            $statement->execute([$table]);
            return (bool) $statement->fetchColumn();
        } catch (PDOException $exception) {
            return false;
        }
    }

    /** Forget the connection (used by the CLI and tests). */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
