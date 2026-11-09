<?php
/**
 * Saved (stored) title detail.
 *
 * @var string $url
 * @var array<string, mixed>|null $detail
 * @var string|null $error
 * @var string|null $type
 * @var bool $cached
 * @var bool $saved
 * @var bool $probe
 * @var bool $mysql
 */
$detail = is_array($detail ?? null) ? $detail : [];
$payload = is_array($detail['payload'] ?? null) ? $detail['payload'] : [];
$episodes = (array) ($detail['episodes'] ?? []);
$downloads = (array) ($detail['downloads'] ?? []);
$links = (array) ($detail['links'] ?? []);
$title = (string) ($detail['title'] ?? 'Untitled');
$status = (string) ($detail['status'] ?? 'saved');
$pageUrl = (string) ($detail['page_url'] ?? $url);
$statusChip = match ($status) {
    'saved'  => 'chip-ok',
    'failed' => 'chip-error',
    'empty'  => 'chip-warn',
    default  => 'chip-quiet',
};
?>
<nav class="crumbs mono" aria-label="Breadcrumb">
    <a href="<?= e(page_url('/saved')) ?>">library</a>
    <span aria-hidden="true">/</span>
    <span class="faint"><?= e($title) ?></span>
</nav>

<?php if ($error !== null): ?>
    <div class="alert alert-error" role="alert">
        <p class="alert-title">Nothing to show</p>
        <p><?= e($error) ?></p>
        <p class="alert-actions"><a class="btn" href="<?= e(page_url('/saved')) ?>">Back to the library</a></p>
    </div>
<?php else: ?>
    <article class="feature">
        <div class="feature-poster">
            <?php if (!empty($payload['poster'])): ?>
                <img src="<?= e((string) $payload['poster']) ?>" alt="" referrerpolicy="no-referrer" data-soft>
            <?php else: ?>
                <span class="poster-letter big" aria-hidden="true"><?= e(mb_substr($title, 0, 1)) ?></span>
            <?php endif; ?>
            <span class="feature-shadow" aria-hidden="true"></span>
        </div>

        <div class="feature-body">
            <p class="eyebrow">
                <?= e(strtoupper((string) ($payload['category'] ?? 'stored print'))) ?>
                <?php if (!empty($payload['adult'])): ?> · 18+<?php endif; ?>
            </p>
            <h1 class="display feature-title"><?= e($title) ?></h1>

            <p class="feature-tags">
                <span class="chip <?= $statusChip ?>"><?= e($status === 'empty' ? 'no streams' : $status) ?></span>
                <span class="chip chip-quiet"><?= count($episodes) ?> episode<?= count($episodes) === 1 ? '' : 's' ?></span>
                <span class="chip chip-quiet"><?= count($links) ?> link<?= count($links) === 1 ? '' : 's' ?></span>
                <?php if (!empty($payload['completed'])): ?><span class="chip chip-ok">Completed</span><?php endif; ?>
            </p>

            <dl class="meta-table mono">
                <div><dt>scraped</dt><dd><?= e((string) ($detail['scraped_at'] ?? '—')) ?></dd></div>
                <div><dt>updated</dt><dd title="<?= e(stamp_utc((string) ($detail['updated_at'] ?? ''))) ?>"><?= e(time_ago((string) ($detail['updated_at'] ?? ''))) ?></dd></div>
                <div><dt>slug</dt><dd><?= e((string) ($detail['slug'] ?? '—')) ?></dd></div>
                <div><dt>page</dt><dd><a href="<?= e($pageUrl) ?>" target="_blank" rel="noopener nofollow">movielinkbd ↗</a></dd></div>
            </dl>

            <?php if (!empty($detail['error'])): ?>
                <p class="meta-error mono">last error · <?= e((string) $detail['error']) ?></p>
            <?php endif; ?>

            <div class="feature-actions">
                <a class="btn btn-go" href="<?= e(page_url('/view', ['url' => $pageUrl ?: $url])) ?>" data-scrape>Re-scrape live</a>
                <a class="btn btn-quiet" href="<?= e(page_url('/saved')) ?>">Back to library</a>
            </div>
        </div>
    </article>

    <?php if ($episodes === [] && $downloads === []): ?>
        <div class="empty">
            <p class="empty-mark" aria-hidden="true">▤</p>
            <h3>No stored episodes.</h3>
            <p>This snapshot carries no playable sources. Re-scrape the title to refresh the print.</p>
        </div>
    <?php endif; ?>

    <?php if ($episodes !== []): ?>
        <section class="section" aria-labelledby="stored-episodes">
            <header class="section-head">
                <h2 id="stored-episodes" class="section-title"><span class="mono faint">print</span> Episodes</h2>
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
                            <p class="reel-empty">No sources stored for this episode.</p>
                        <?php else: ?>
                            <ul class="src-list">
                                <?php foreach ($sources as $src): ?>
                                    <?php if (!is_array($src)) { continue; } ?>
                                    <li class="src-row">
                                        <div class="src-main">
                                            <span class="src-provider"><?= e((string) ($src['provider'] ?? $src['name'] ?? 'Source')) ?></span>
                                            <span class="src-tags mono">
                                                <?php if (!empty($src['quality']) || !empty($src['quality_label'])): ?>
                                                    <span><?= e((string) ($src['quality'] ?? $src['quality_label'])) ?></span>
                                                <?php endif; ?>
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

    <?php if ($downloads !== []): ?>
        <section class="section" aria-labelledby="stored-downloads">
            <header class="section-head">
                <h2 id="stored-downloads" class="section-title"><span class="mono faint">print</span> Downloads</h2>
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
<?php endif; ?>
