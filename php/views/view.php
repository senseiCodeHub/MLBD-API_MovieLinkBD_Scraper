<?php
/**
 * Live scrape detail.
 *
 * @var string $url
 * @var array<string, mixed>|null $result
 * @var string|null $error
 * @var string|null $type
 * @var bool $cached
 * @var bool $saved
 * @var bool $probe
 * @var string $source
 */
$result = is_array($result ?? null) ? $result : [];
$episodes = (array) ($result['episodes'] ?? []);
$flatSources = (array) ($result['sources'] ?? []);
$downloads = (array) ($result['downloads'] ?? []);
$pageUrl = (string) ($result['page_url'] ?? $url);
$title = (string) ($result['title'] ?? 'Untitled');

$errorCopy = match ($type) {
    'CloudflareBlocked' => [
        'MovieLinkBD challenged this request',
        'The site is serving a Cloudflare interstitial to this server. Scraping resumes from a host it does not challenge, or open the stored copy in the library.',
    ],
    'NoStreamFound' => [
        'No stream in the payload',
        'The page loaded but carried no playable sources. It may be a placeholder, a trailer page, or the player data moved.',
    ],
    default => [
        'Could not scrape this title',
        (string) $error,
    ],
};
?>
<nav class="crumbs mono" aria-label="Breadcrumb">
    <a href="<?= e(page_url('/')) ?>">search</a>
    <span aria-hidden="true">/</span>
    <span class="faint"><?= e($title) ?></span>
</nav>

<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert">
        <p class="alert-title"><?= e($errorCopy[0]) ?></p>
        <p><?= e($errorCopy[1]) ?></p>
        <p class="alert-actions">
            <a class="btn" href="<?= e(page_url('/saved')) ?>">Open the library</a>
            <a class="btn btn-quiet" href="<?= e(page_url('/')) ?>">Back to search</a>
        </p>
    </div>
<?php endif; ?>

<?php if ($result !== []): ?>
    <article class="feature">
        <div class="feature-poster">
            <?php if (!empty($result['poster'])): ?>
                <img src="<?= e((string) $result['poster']) ?>" alt="" referrerpolicy="no-referrer" data-soft>
            <?php else: ?>
                <span class="poster-letter big" aria-hidden="true"><?= e(mb_substr($title, 0, 1)) ?></span>
            <?php endif; ?>
            <span class="feature-shadow" aria-hidden="true"></span>
        </div>

        <div class="feature-body">
            <p class="eyebrow">
                <?= e(strtoupper((string) ($result['category'] ?? 'title'))) ?>
                <?php if (!empty($result['adult'])): ?> · 18+<?php endif; ?>
            </p>
            <h1 class="display feature-title"><?= e($title) ?></h1>

            <p class="feature-tags">
                <?php if (!empty($result['completed'])): ?><span class="chip chip-ok">Completed</span><?php endif; ?>
                <span class="chip chip-quiet"><?= count($episodes) ?> episode<?= count($episodes) === 1 ? '' : 's' ?></span>
                <span class="chip chip-quiet"><?= count($downloads) ?> download<?= count($downloads) === 1 ? '' : 's' ?></span>
                <?php if ($saved): ?><span class="chip chip-ok">Stored</span><?php endif; ?>
                <?php if ($cached): ?>
                    <span class="chip chip-warn">Stored copy · <?= e(format_seconds($result['cache_age_seconds'] ?? 0)) ?> old</span>
                <?php endif; ?>
            </p>

            <dl class="meta-table mono">
                <div><dt>scraped</dt><dd><?= e((string) ($result['scraped_at'] ?? 'just now')) ?></dd></div>
                <div><dt>source</dt><dd><?= e($cached ? 'mysql snapshot' : 'live page') ?></dd></div>
                <div><dt>page</dt><dd><a href="<?= e($pageUrl) ?>" target="_blank" rel="noopener nofollow">movielinkbd ↗</a></dd></div>
            </dl>

            <div class="feature-actions">
                <a class="btn btn-go" href="<?= e(page_url('/view', ['url' => $url, 'probe' => $probe ? '0' : null])) ?>" data-scrape>
                    <?= $probe ? 'Re-scrape · fast' : 'Re-scrape · with probes' ?>
                </a>
                <a class="btn btn-quiet" href="<?= e(page_url('/saved')) ?>">Library</a>
            </div>
        </div>
    </article>

    <?php if ($episodes !== []): ?>
        <section class="section" aria-labelledby="episodes-title">
            <header class="section-head">
                <h2 id="episodes-title" class="section-title"><span class="mono faint">print</span> Episodes</h2>
                <p class="section-meta mono"><?= count($episodes) ?> reel<?= count($episodes) === 1 ? '' : 's' ?></p>
            </header>

            <div class="reels">
                <?php foreach ($episodes as $index => $episode): ?>
                    <?php $sources = (array) ($episode['sources'] ?? []); ?>
                    <details class="reel" <?= $index === 0 ? 'open' : '' ?>>
                        <summary class="reel-summary">
                            <span class="reel-index mono"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                            <span class="reel-label"><?= e((string) ($episode['label'] ?? 'Episode ' . ($index + 1))) ?></span>
                            <span class="reel-count mono"><?= count($sources) ?> src</span>
                            <span class="reel-caret" aria-hidden="true"></span>
                        </summary>
                        <?php if ($sources === []): ?>
                            <p class="reel-empty">No sources listed for this episode.</p>
                        <?php else: ?>
                            <ul class="src-list">
                                <?php foreach ($sources as $src): ?>
                                    <?php if (!is_array($src)) { continue; } ?>
                                    <li class="src-row">
                                        <div class="src-main">
                                            <span class="src-provider"><?= e((string) ($src['provider'] ?? $src['server_name'] ?? 'Source')) ?></span>
                                            <span class="src-tags mono">
                                                <?php if (!empty($src['quality'])): ?><span><?= e((string) $src['quality']) ?></span><?php endif; ?>
                                                <?php if (!empty($src['audio'])): ?><span><?= e((string) $src['audio']) ?></span><?php endif; ?>
                                                <?php if (!empty($src['type'])): ?><span><?= e(strtoupper((string) $src['type'])) ?></span><?php endif; ?>
                                                <?php if (!empty($src['codec'])): ?><span><?= e((string) $src['codec']) ?></span><?php endif; ?>
                                                <?php if (!empty($src['size_bytes'])): ?><span><?= e(format_bytes($src['size_bytes'])) ?></span><?php endif; ?>
                                                <?php if (!empty($src['is_best'])): ?><span class="src-best">best</span><?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="src-links">
                                            <?php if (!empty($src['stream_url'])): ?>
                                                <a class="btn btn-small" href="<?= e((string) $src['stream_url']) ?>" target="_blank" rel="noopener nofollow">Stream ↗</a>
                                            <?php endif; ?>
                                            <?php if (!empty($src['download_url'])): ?>
                                                <a class="btn btn-small btn-quiet" href="<?= e((string) $src['download_url']) ?>" target="_blank" rel="noopener nofollow">Download ↓</a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($episodes === [] && $flatSources !== []): ?>
        <section class="section" aria-labelledby="sources-title">
            <header class="section-head">
                <h2 id="sources-title" class="section-title"><span class="mono faint">print</span> Sources</h2>
            </header>
            <ul class="src-list">
                <?php foreach ($flatSources as $src): ?>
                    <?php if (!is_array($src)) { continue; } ?>
                    <li class="src-row">
                        <div class="src-main">
                            <span class="src-provider"><?= e((string) ($src['provider'] ?? $src['server_name'] ?? 'Source')) ?></span>
                            <span class="src-tags mono">
                                <?php if (!empty($src['quality'])): ?><span><?= e((string) $src['quality']) ?></span><?php endif; ?>
                                <?php if (!empty($src['size_bytes'])): ?><span><?= e(format_bytes($src['size_bytes'])) ?></span><?php endif; ?>
                            </span>
                        </div>
                        <div class="src-links">
                            <?php if (!empty($src['stream_url'])): ?>
                                <a class="btn btn-small" href="<?= e((string) $src['stream_url']) ?>" target="_blank" rel="noopener nofollow">Stream ↗</a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($downloads !== []): ?>
        <section class="section" aria-labelledby="downloads-title">
            <header class="section-head">
                <h2 id="downloads-title" class="section-title"><span class="mono faint">print</span> Downloads</h2>
            </header>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Label</th>
                            <th scope="col">Episode</th>
                            <th scope="col">Quality</th>
                            <th scope="col">Size</th>
                            <th scope="col" class="right"><span class="sr-only">Link</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($downloads as $download): ?>
                            <?php if (!is_array($download)) { continue; } ?>
                            <tr>
                                <td><?= e((string) ($download['label'] ?? 'Download')) ?></td>
                                <td><?= e((string) ($download['episode'] ?? '—')) ?></td>
                                <td><?= e((string) ($download['quality'] ?? '—')) ?></td>
                                <td><?= e(format_bytes($download['size_bytes'] ?? null)) ?></td>
                                <td class="right">
                                    <?php if (!empty($download['url'])): ?>
                                        <a class="btn btn-small" href="<?= e((string) $download['url']) ?>" target="_blank" rel="noopener nofollow">Download ↓</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <details class="section raw">
        <summary class="mono">Raw payload</summary>
        <pre><code><?= e(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') ?></code></pre>
    </details>
<?php endif; ?>
