<?php
/**
 * @var string|null                                                       $error
 * @var int                                                               $minimum
 * @var list<array{label: string, ok: bool, detail: string, fatal: bool}> $checks
 * @var list<string>                                                      $pending
 * @var list<string>                                                      $applied
 * @var string                                                            $token
 */

use Luna\Csrf;
?>
<section class="card">
    <h1>Install the booking system</h1>

    <p class="alert">
        This page loads the database schema and creates the one administrator
        account. It stops working as soon as that account exists. Do it now,
        before telling anyone the address of this site.
    </p>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>This server</h2>

    <table>
        <tbody>
        <?php foreach ($checks as $check): ?>
            <tr>
                <td class="nowrap"><?= e($check['label']) ?></td>
                <td class="nowrap">
                    <?php if ($check['ok']): ?>
                        ok
                    <?php elseif ($check['fatal']): ?>
                        <strong class="alert-inline">problem</strong>
                    <?php else: ?>
                        <span class="muted">note</span>
                    <?php endif; ?>
                </td>
                <td class="muted small"><?= e($check['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Database schema</h2>

    <?php if ($pending === []): ?>
        <p class="muted">
            Already loaded<?= $applied === [] ? '' : ' (' . e(implode(', ', $applied)) . ')' ?>.
        </p>
    <?php else: ?>
        <p>
            To be applied when you submit this form:
            <?= e(implode(', ', $pending)) ?>
        </p>
    <?php endif; ?>

    <h2>Administrator account</h2>

    <form method="post" action="<?= e(path('/install')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="install_token" value="<?= e($token) ?>">

        <label for="username">Username</label>
        <input id="username" name="username" type="text" value="admin"
               autocapitalize="none" spellcheck="false" required>

        <label for="password">Password</label>
        <input id="password" name="password" type="password"
               autocomplete="new-password" required minlength="<?= e($minimum) ?>">

        <button type="submit" class="primary">Install</button>
    </form>

    <p class="muted small">
        At least <?= e($minimum) ?> characters; a few unrelated words work well.
        You will then be shown a QR code for your authenticator app and a set of
        recovery codes - once only.
    </p>

    <p class="muted small">
        When you are finished, remove <code>install_token</code> from
        <code>app/config.php</code>. The page is already closed by the existence
        of the account, so this is belt and braces.
    </p>
</section>
