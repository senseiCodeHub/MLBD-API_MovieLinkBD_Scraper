<?php
/**
 * Search page.
 *
 * @var string $query
 * @var string $category
 * @var string $scope
 * @var int $page
 * @var list<array<string, mixed>> $results
 * @var int $total
 * @var string|null $error
 * @var string|null $note
 * @var bool $searched
 * @var array<string, mixed>|null $catalog
 */
$categories = [
    'all'    => 'All',
    'movie'  => 'Movies',
    'series' => 'Series',
    'drama'  => 'Drama',
    'anime'  => 'Anime',
    'adult'  => '18+',
];
$scopes = [
    'catalog' => 'Whole catalogue',
    'site'    => 'Site search',
    'db'      => 'MySQL mirror',
];
$pages = (int) ceil($total / 24);
$hasIndex = is_array($catalog) && !empty($catalog['loaded']);
$count = (int) ($catalog['count'] ?? 0);
?>

<section class="masthead">
    <p class="eyebrow">MovieLinkBD · print room</p>
    <h1 class="display">
        Every episode,<br>
        every <em>server</em>, one index.
    </h1>
    <p class="lede">
        Search <?= $hasIndex ? number_format($count) . ' catalogued titles' : 'the whole catalogue' ?>
        and open a title to read each quality, audio track, stream and download the site serves —
        then keep a copy in MySQL so it survives a blocked afternoon.
    </p>

    <?php if (is_array($freshness ?? null) && !empty($freshness['last_at'])): ?>
        <p class="masthead-stamp mono">
            library updated
            <span title="<?= e(stamp_utc((string) $freshness['last_at'])) ?>"><?= e(time_ago((string) $freshness['last_at'])) ?></span>
            · <?= number_format((int) ($freshness['updated_24h'] ?? 0)) ?> titles refreshed in 24h
        </p>
    <?php endif; ?>
</section>

<form class="finder" method="get" action="index.php" role="search" data-finder>
    <input type="hidden" name="r" value="/">
    <div class="finder-field">
        <label class="sr-only" for="q">Title to search</label>
        <svg class="finder-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="11" cy="11" r="6.5"></circle>
            <path d="M16 16l4.5 4.5"></path>
        </svg>
        <input
            id="q"
            type="search"
            name="q"
            value="<?= e($query) ?>"
            placeholder="A movie, series, drama or anime…"
            autocomplete="off"
            spellcheck="false"
            autofocus
        >
        <button class="btn btn-go" type="submit">
            Search <span aria-hidden="true">→</span>
        </button>
    </div>

    <div class="finder-controls">
        <fieldset class="segmented">
            <legend class="sr-only">Category</legend>
            <?php foreach ($categories as $value => $label): ?>
                <label>
                    <input type="radio" name="category" value="<?= e($value) ?>" <?= $category === $value ? 'checked' : '' ?>>
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <label class="scope">
            <span class="scope-label mono">source</span>
            <select name="scope">
                <?php foreach ($scopes as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $scope === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php if ($hasIndex): ?>
            <p class="index-state mono">
                <span class="lamp lamp-quiet" aria-hidden="true"></span>
                <?= number_format($count) ?> titles indexed
                <span class="faint">· <?= e((string) ($catalog['version'] ?? '—')) ?></span>
            </p>
        <?php endif; ?>
    </div>
</form>

<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert">
        <p class="alert-title">Search failed</p>
        <p><?= e($error) ?></p>
    </div>
<?php endif; ?>

<?php if ($note !== null): ?>
    <div class="alert alert-note">
        <p><?= e($note) ?></p>
    </div>
<?php endif; ?>

<div class="skeleton-grid" data-skeleton hidden aria-hidden="true">
    <?php for ($i = 0; $i < 8; $i++): ?>
        <div class="skeleton-card">
            <span class="skeleton-poster"></span>
            <span class="skeleton-line"></span>
            <span class="skeleton-line short"></span>
        </div>
    <?php endfor; ?>
</div>

<?php if ($searched && $error === null): ?>
    <section class="results" aria-labelledby="results-title">
        <header class="section-head">
            <h2 id="results-title" class="section-title">
                <?php if ($query !== ''): ?>
                    <span class="mono faint">results for</span> <?= e($query) ?>
                <?php else: ?>
                    Browsing the catalogue
                <?php endif; ?>
            </h2>
            <p class="section-meta mono">
                <?= number_format($total) ?> title<?= $total === 1 ? '' : 's' ?><?php if ($page > 1): ?> · page <?= (int) $page ?><?php endif; ?>
            </p>
        </header>

        <?php if ($results === []): ?>
            <div class="empty">
                <p class="empty-mark" aria-hidden="true">∅</p>
                <h3>Nothing matched.</h3>
                <p>Try a shorter query, another category, or switch the source to the site's own search.</p>
            </div>
        <?php else: ?>
            <ul class="grid">
                <?php foreach ($results as $item): ?>
                    <li>
                        <a class="card" href="<?= e(page_url('/view', ['url' => $item['url'] ?? ''])) ?>" data-scrape>
                            <span class="poster">
                                <?php if (!empty($item['poster'])): ?>
                                    <img src="<?= e((string) $item['poster']) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-soft>
                                <?php else: ?>
                                    <span class="poster-letter" aria-hidden="true"><?= e(mb_substr((string) ($item['title'] ?? '?'), 0, 1)) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($item['quality'])): ?>
                                    <span class="poster-tag"><?= e((string) $item['quality']) ?></span>
                                <?php endif; ?>
                                <span class="poster-glow" aria-hidden="true"></span>
                            </span>
                            <span class="card-title"><?= e((string) ($item['title'] ?? 'Untitled')) ?></span>
                            <span class="card-meta mono">
                                <span><?= e(strtoupper((string) ($item['category'] ?? 'title'))) ?></span>
                                <?php if (!empty($item['year'])): ?><span><?= e((string) $item['year']) ?></span><?php endif; ?>
                                <?php if (!empty($item['language'])): ?><span><?= e((string) $item['language']) ?></span><?php endif; ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($pages > 1): ?>
                <nav class="pager" aria-label="Pagination">
                    <?php if ($page > 1): ?>
                        <a class="btn" href="<?= e(page_url('/', ['q' => $query, 'category' => $category, 'scope' => $scope, 'page' => $page - 1])) ?>">← Previous</a>
                    <?php else: ?>
                        <span class="btn is-disabled">← Previous</span>
                    <?php endif; ?>
                    <span class="mono faint">page <?= (int) $page ?> / <?= (int) $pages ?></span>
                    <?php if ($page < $pages): ?>
                        <a class="btn" href="<?= e(page_url('/', ['q' => $query, 'category' => $category, 'scope' => $scope, 'page' => $page + 1])) ?>">Next →</a>
                    <?php else: ?>
                        <span class="btn is-disabled">Next →</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (!$searched): ?>
    <section class="primers" aria-labelledby="primer-title">
        <h2 id="primer-title" class="section-title small-title">How the room works</h2>
        <ol class="primer-list">
            <li>
                <span class="primer-index mono">01</span>
                <h3>Look it up</h3>
                <p>One query hits the whole-catalogue index, the site's own search, or the MySQL mirror — pick the source that is reachable from this host.</p>
            </li>
            <li>
                <span class="primer-index mono">02</span>
                <h3>Read the print</h3>
                <p>Opening a title decodes MovieLinkBD's inline player payload: every episode, server, quality, audio track, stream and download in one view.</p>
            </li>
            <li>
                <span class="primer-index mono">03</span>
                <h3>Keep a copy</h3>
                <p>Each scrape lands in MySQL, so the library still opens when the site blocks this host or goes dark. Re-scrapes update the row instead of duplicating it.</p>
            </li>
        </ol>
    </section>
<?php endif; ?>
