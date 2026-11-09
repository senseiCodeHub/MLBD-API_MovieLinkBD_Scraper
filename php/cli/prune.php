<?php
declare(strict_types=1);

/**
 * Remove scraped rows whose title no longer exists in the site catalogue.
 *
 *   php cli/prune.php                 # preview: what would be removed
 *   php cli/prune.php --apply         # back up, then delete
 *   php cli/prune.php --apply --keep=5
 *
 * A backup of everything about to be deleted (scrape snapshots, their links and
 * the catalogue rows) is written to storage/backups/ as JSON before the delete
 * runs. Nothing is removed without a successful backup file.
 *
 * Useful because the site retires titles and because older versions of this
 * scraper stored some titles under the canonical ``mKs_…`` id, which the
 * catalogue never lists: those rows can never be refreshed again and only
 * inflate the library, they must be pruned by hand.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script runs from the command line only.\n";
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use Mlbd\Scraper\Catalog;
use Mlbd\Store\Database;

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$options = getopt('', ['apply', 'category::', 'keep::', 'quiet']);
$apply = isset($options['apply']);
$quiet = isset($options['quiet']);
$category = is_string($options['category'] ?? null) ? (string) $options['category'] : 'all';
$keep = isset($options['keep']) && is_numeric($options['keep']) ? max(1, (int) $options['keep']) : 10;

$logFile = storage_path('logs/sync.log');
$log = static function (string $line) use ($quiet, $logFile): void {
    $entry = '[' . gmdate('Y-m-d H:i:s') . '] prune: ' . $line;
    @file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
    if (!$quiet) {
        echo $entry . PHP_EOL;
    }
};

if (!Database::configured()) {
    $log('ERROR: MySQL is not configured.');
    exit(1);
}

// Same lock as the sync job: never delete rows while a scrape is writing.
$lock = fopen(storage_path('sync.lock'), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log('Another sync or prune is running; try again when it finishes.');
    exit(0);
}

try {
    $catalog = new Catalog();
    $catalog->ensure(true); // force a fresh catalogue, never a cached one
    $items = $catalog->items($category);

    $live = [];
    foreach ($items as $item) {
        $slug = trim((string) ($item['slug'] ?? ''));
        if ($slug !== '') {
            $live[$slug] = true;
        }
    }

    if ($live === []) {
        // Deleting everything because the catalogue failed to load is far worse
        // than leaving a few stale rows behind.
        $log('ERROR: the catalogue came back empty; refusing to prune. Nothing was deleted.');
        exit(1);
    }

    $log(sprintf('Catalogue has %s titles (category=%s).', number_format(count($live)), $category));

    $pdo = Database::pdo();

    $scrapes = $pdo->query(
        'SELECT page_url, slug, title, status, updated_at FROM ' . Database::SCRAPES_TABLE
    )->fetchAll(PDO::FETCH_ASSOC);

    $orphanPages = [];
    foreach ($scrapes as $row) {
        $slug = trim((string) ($row['slug'] ?? ''));
        if ($slug === '' || !isset($live[$slug])) {
            $orphanPages[] = (string) $row['page_url'];
        }
    }

    $orphanTitles = [];
    $titles = $pdo->query(
        'SELECT slug FROM ' . Database::TITLES_TABLE
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($titles as $slug) {
        $slug = trim((string) $slug);
        if ($slug === '' || !isset($live[$slug])) {
            $orphanTitles[] = $slug;
        }
    }

    $orphanPages = array_values(array_unique($orphanPages));
    $orphanTitles = array_values(array_unique($orphanTitles));

    if ($orphanPages === [] && $orphanTitles === []) {
        $log('Nothing to prune — every stored title is still in the catalogue.');
        exit(0);
    }

    $log(sprintf(
        '%s scrape row(s) and %s catalogue row(s) are no longer listed by the site.',
        number_format(count($orphanPages)),
        number_format(count($orphanTitles))
    ));

    if (!$apply) {
        $log('Preview only. Re-run with --apply to back up and delete.');
        exit(0);
    }

    // ---- backup -----------------------------------------------------------
    $backupDir = storage_path('backups');
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        $log('ERROR: cannot create ' . $backupDir . '; nothing was deleted.');
        exit(1);
    }

    $chunks = static fn (array $values, int $size): array => array_chunk($values, $size);
    $placeholders = static fn (int $count): string => implode(',', array_fill(0, $count, '?'));

    $backup = [
        'created_at' => gmdate('Y-m-d H:i:s'),
        'category'   => $category,
        'scrapes'    => [],
        'links'      => [],
        'titles'     => [],
    ];

    foreach ($chunks($orphanPages, Database::LINK_BATCH) as $chunk) {
        $statement = $pdo->prepare(
            'SELECT * FROM ' . Database::SCRAPES_TABLE . ' WHERE page_url IN (' . $placeholders(count($chunk)) . ')'
        );
        $statement->execute($chunk);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $backup['scrapes'][] = $row;
        }

        $statement = $pdo->prepare(
            'SELECT * FROM ' . Database::LINKS_TABLE . ' WHERE page_url IN (' . $placeholders(count($chunk)) . ')'
        );
        $statement->execute($chunk);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $backup['links'][] = $row;
        }
    }

    foreach ($chunks($orphanTitles, Database::TITLE_BATCH) as $chunk) {
        $statement = $pdo->prepare(
            'SELECT * FROM ' . Database::TITLES_TABLE . ' WHERE slug IN (' . $placeholders(count($chunk)) . ')'
        );
        $statement->execute($chunk);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $backup['titles'][] = $row;
        }
    }

    $backupPath = $backupDir . '/prune-' . gmdate('Ymd-His') . '.json';
    $json = json_encode($backup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($backupPath, $json) === false) {
        $log('ERROR: could not write the backup; nothing was deleted.');
        exit(1);
    }
    $log('Backup written to ' . $backupPath . ' (' . format_bytes(strlen($json)) . ').');

    // ---- delete -----------------------------------------------------------
    $deleteIn = static function (string $table, string $column, array $values) use ($pdo, $chunks, $placeholders): int {
        $deleted = 0;
        foreach ($chunks($values, Database::LINK_BATCH) as $chunk) {
            $statement = $pdo->prepare(
                'DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . $placeholders(count($chunk)) . ')'
            );
            $statement->execute($chunk);
            $deleted += $statement->rowCount();
        }
        return $deleted;
    };

    $removedLinks = $orphanPages === [] ? 0 : $deleteIn(Database::LINKS_TABLE, 'page_url', $orphanPages);
    $removedScrapes = $orphanPages === [] ? 0 : $deleteIn(Database::SCRAPES_TABLE, 'page_url', $orphanPages);
    $removedTitles = $orphanTitles === [] ? 0 : $deleteIn(Database::TITLES_TABLE, 'slug', $orphanTitles);

    $log(sprintf(
        'Pruned %s scrape(s), %s link(s) and %s catalogue row(s).',
        number_format($removedScrapes),
        number_format($removedLinks),
        number_format($removedTitles)
    ));

    // Keep the backup directory from growing without bound.
    $files = glob($backupDir . '/prune-*.json') ?: [];
    usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }

    exit(0);
} catch (\Throwable $exception) {
    $log('ERROR: ' . $exception->getMessage());
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
