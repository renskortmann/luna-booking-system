<?php
/**
 * @var list<array<string, mixed>> $machines
 * @var int                  $machineCount
 * @var list<\Macrolab\Booking>  $upcoming
 * @var int                  $userCount
 * @var int                  $suspended
 * @var int                  $noPassword
 * @var string               $authMode
 * @var int                  $recoveryLeft
 * @var string               $passwordAlgo
 */

use Macrolab\Clock;
?>
<section class="card">
    <h1>Administration</h1>

    <nav class="admin-nav">
        <a href="<?= e(path('/admin/users')) ?>">Who may sign in</a>
        <a href="<?= e(path('/admin/machines')) ?>">Machines</a>
        <a href="<?= e(path('/admin/bookings')) ?>">All bookings</a>
        <a href="<?= e(path('/admin/projects')) ?>">Projects</a>
        <a href="<?= e(path('/admin/time')) ?>">Time overview</a>
        <a href="<?= e(path('/admin/settings')) ?>">Rules</a>
        <a href="<?= e(path('/admin/audit')) ?>">Audit log</a>
        <a href="<?= e(path('/admin/system')) ?>">System</a>
    </nav>
</section>

<section class="card">
    <h2>At a glance</h2>

    <dl class="facts">
        <dt>Machines in use</dt>
        <dd>
            <?= e($machineCount) ?>
            <span class="muted">
                - <?= e(implode(', ', array_column($machines, 'name'))) ?>
            </span>
        </dd>
        <dt>People on the allowlist</dt>
        <dd>
            <?= e($userCount) ?>
            <?php if ($suspended > 0): ?>
                <span class="muted">(<?= e($suspended) ?> suspended)</span>
            <?php endif; ?>
            <?php if ($noPassword > 0): ?>
                <span class="muted">- <?= e($noPassword) ?> have not set a password yet</span>
            <?php endif; ?>
        </dd>
        <dt>Sign-in mode</dt>
        <dd>
            <?= e($authMode) ?>
            <?php if ($authMode === 'local'): ?>
                <span class="muted">- netID and password, managed here</span>
            <?php endif; ?>
        </dd>
        <dt>Password hashing</dt><dd><?= e($passwordAlgo) ?></dd>
        <dt>Your recovery codes left</dt>
        <dd>
            <?= e($recoveryLeft) ?>
            <?php if ($recoveryLeft <= 2): ?>
                <span class="alert-inline">running low</span>
            <?php endif; ?>
        </dd>
    </dl>
</section>

<section class="card">
    <h2>Today and tomorrow</h2>

    <?php if ($upcoming === []): ?>
        <p class="muted">Nothing booked.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr><th>Date</th><th>Time</th><th>Machine</th><th>Who</th><th>Purpose</th></tr>
            </thead>
            <tbody>
            <?php foreach ($upcoming as $booking): ?>
                <tr>
                    <td><?= e(Clock::local($booking->startsAt, 'D j M')) ?></td>
                    <td><?= e(Clock::local($booking->startsAt, 'H:i')) ?>-<?= e(Clock::local($booking->endsAt, 'H:i')) ?></td>
                    <td><?= e($booking->resourceName ?? '-') ?></td>
                    <td><?= e($booking->ownerLabel()) ?> <span class="muted">(<?= e($booking->ownerNetid) ?>)</span></td>
                    <td><?= e($booking->purpose ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
