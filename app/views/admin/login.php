<?php
/**
 * @var string      $username
 * @var string|null $error
 */

use Macrolab\Csrf;
?>
<section class="card narrow">
    <h1>Administrator sign-in</h1>

    <p class="muted small">
        This sign-in does not use TU Delft SSO, so the lab keeps access even
        when SSO is unavailable. Two steps: password, then a one-time code.
    </p>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(path('/admin/login')) ?>">
        <?= Csrf::field() ?>

        <label for="username">Username</label>
        <input id="username" name="username" type="text" value="<?= e($username) ?>"
               autocapitalize="none" spellcheck="false" autocomplete="username"
               required autofocus>

        <label for="password">Password</label>
        <input id="password" name="password" type="password"
               autocomplete="current-password" required>

        <button type="submit" class="primary">Continue</button>
    </form>
</section>
