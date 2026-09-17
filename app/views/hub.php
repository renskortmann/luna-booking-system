<?php
/**
 * The hub: the page signing in lands on, and what the brand link goes back to.
 *
 * @var \Macrolab\Actor                                            $actor
 * @var list<array{href: string, label: string, blurb: string}>    $destinations
 */
?>
<section class="card">
    <h1>Macrolab</h1>

    <p class="muted">
        Signed in as <?= e($actor->label()) ?>. Choose where you are headed.
    </p>

    <div class="tiles">
        <?php foreach ($destinations as $destination): ?>
            <a class="tile" href="<?= e(path($destination['href'])) ?>">
                <h2><?= e($destination['label']) ?></h2>
                <p><?= e($destination['blurb']) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</section>
