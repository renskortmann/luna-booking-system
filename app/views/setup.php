<?php
/**
 * @var string      $netid
 * @var string      $purpose
 * @var string      $token
 * @var string|null $error
 * @var int         $minimum
 */

use Luna\Csrf;
?>
<section class="card narrow">
    <h1><?= $purpose === 'reset' ? 'Choose a new password' : 'Choose a password' ?></h1>

    <p>
        This link is for <strong><?= e($netid) ?></strong>. It works once.
    </p>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(path('/setup/' . $token)) ?>">
        <?= Csrf::field() ?>

        <label for="password">Password</label>
        <input id="password" name="password" type="password"
               autocomplete="new-password" required autofocus
               minlength="<?= e($minimum) ?>">

        <label for="password_confirm">Password again</label>
        <input id="password_confirm" name="password_confirm" type="password"
               autocomplete="new-password" required minlength="<?= e($minimum) ?>">

        <button type="submit" class="primary">Set my password</button>
    </form>

    <p class="muted small">
        At least <?= e($minimum) ?> characters. A few unrelated words make a
        password that is both strong and easy to remember; there are no rules
        about capitals or symbols.
    </p>
</section>
