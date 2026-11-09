<?php
/**
 * Not-found page.
 *
 * @var string $path
 */
?>
<section class="empty tall">
    <p class="empty-mark mono" aria-hidden="true">404</p>
    <h1 class="display small-display">This reel is missing.</h1>
    <p class="mono faint"><?= e($path) ?></p>
    <p><a class="btn btn-go" href="<?= e(page_url('/')) ?>">Back to search</a></p>
</section>
