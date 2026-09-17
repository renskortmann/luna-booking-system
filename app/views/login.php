<?php
/**
 * @var string      $netid
 * @var string|null $error
 * @var bool        $localOpen
 * @var bool        $ssoOpen
 */

use Macrolab\Csrf;
?>
<section class="card narrow">
    <h1>Sign in</h1>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if ($ssoOpen): ?>
        <p>
            <a class="button primary block" href="<?= e(path('/auth/saml/login')) ?>">
                Sign in with your TU Delft netID
            </a>
        </p>
        <?php if ($localOpen): ?>
            <p class="divider">or use a password</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($localOpen): ?>
        <form method="post" action="<?= e(path('/login')) ?>" autocomplete="on">
            <?= Csrf::field() ?>

            <label for="netid">netID</label>
            <input id="netid" name="netid" type="text" value="<?= e($netid) ?>"
                   autocapitalize="none" autocorrect="off" spellcheck="false"
                   autocomplete="username" required autofocus>

            <label for="password">Password</label>
            <input id="password" name="password" type="password"
                   autocomplete="current-password" required>

            <button type="submit" class="primary">Sign in</button>
        </form>

        <p class="muted small">
            No password yet, or forgotten it? The lab administrator can send you a
            new sign-in link.
        </p>
    <?php endif; ?>

    <?php if (!$localOpen && !$ssoOpen): ?>
        <p class="alert">
            No sign-in method is enabled. The lab administrator needs to set one
            in the administration pages.
        </p>
    <?php endif; ?>
</section>
