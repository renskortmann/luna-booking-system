<?php
/** @var string|null $error */

use Macrolab\Csrf;
?>
<section class="card narrow">
    <h1>One-time code</h1>

    <p>Enter the six-digit code from your authenticator app.</p>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(path('/admin/login/2fa')) ?>">
        <?= Csrf::field() ?>

        <label for="code">Code</label>
        <input id="code" name="code" type="text" inputmode="numeric"
               autocomplete="one-time-code" spellcheck="false"
               required autofocus>

        <button type="submit" class="primary">Sign in</button>
    </form>

    <p class="muted small">
        Lost your authenticator? Enter one of your recovery codes instead. Each
        works once.
    </p>
</section>
