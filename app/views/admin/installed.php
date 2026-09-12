<?php
/**
 * @var string       $secret
 * @var string       $qr
 * @var list<string> $codes
 * @var list<string> $migrated
 */
?>
<section class="card">
    <h1>Administrator created</h1>

    <p class="alert">
        Everything on this page is shown once. Do not leave it until you have
        scanned the code and stored the recovery codes somewhere safe.
    </p>

    <?php if ($migrated !== []): ?>
        <p class="muted small">Database schema loaded: <?= e(implode(', ', $migrated)) ?>.</p>
    <?php endif; ?>

    <h2>1. Add the account to your authenticator app</h2>

    <div class="qr"><?= $qr /* generated SVG, not user input */ ?></div>

    <p>
        If you cannot scan it, enter this key by hand:
        <code class="secret"><?= e($secret) ?></code>
    </p>

    <h2>2. Store your recovery codes</h2>

    <p>
        Each code works once, and gets you in when your authenticator is
        unavailable. Print them, or put them in a password manager - not in the
        same place as your password.
    </p>

    <ul class="codes">
        <?php foreach ($codes as $code): ?>
            <li><code><?= e($code) ?></code></li>
        <?php endforeach; ?>
    </ul>

    <p><a class="button primary" href="<?= e(path('/admin/login')) ?>">Sign in</a></p>
</section>
