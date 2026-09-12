<?php
/**
 * @var list<\Luna\Booking>        $bookings
 * @var list<array<string, mixed>> $users
 * @var string|null                $error
 */

use Luna\Clock;
use Luna\Csrf;
?>
<section class="card">
    <h1>All bookings</h1>

    <p class="muted small">
        As administrator you may create, change and delete any booking, and the
        booking rules do not restrict you. Two bookings still cannot overlap.
        Every change here is recorded in the <a href="<?= e(path('/admin/audit')) ?>">audit log</a>.
    </p>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>Create a booking for someone</h2>

    <form method="post" action="<?= e(path('/admin/bookings')) ?>" class="row">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">

        <label for="owner_netid">netID</label>
        <input id="owner_netid" name="owner_netid" list="netids" required
               autocapitalize="none" spellcheck="false">
        <datalist id="netids">
            <?php foreach ($users as $user): ?>
                <option value="<?= e($user['netid']) ?>"><?= e($user['display_name'] ?? $user['netid']) ?></option>
            <?php endforeach; ?>
        </datalist>

        <label for="start">Start</label>
        <input id="start" name="start" type="datetime-local" required>

        <label for="end">End</label>
        <input id="end" name="end" type="datetime-local" required>

        <label for="purpose">Purpose</label>
        <input id="purpose" name="purpose" type="text" maxlength="255">

        <button type="submit" class="primary">Create</button>
    </form>
</section>

<section class="card">
    <h2>Bookings</h2>

    <?php if ($bookings === []): ?>
        <p class="muted">No bookings yet.</p>
    <?php else: ?>
        <table class="wide">
            <thead>
            <tr><th>When</th><th>Who</th><th>Purpose</th><th>Status</th><th>Change</th></tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $booking): ?>
                <tr class="<?= $booking->isConfirmed() ? '' : 'row-cancelled' ?>">
                    <td>
                        <?= e(Clock::local($booking->startsAt, 'D j M Y')) ?><br>
                        <?= e(Clock::local($booking->startsAt, 'H:i')) ?>-<?= e(Clock::local($booking->endsAt, 'H:i')) ?>
                    </td>
                    <td>
                        <?= e($booking->ownerLabel()) ?><br>
                        <code class="small"><?= e($booking->ownerNetid) ?></code>
                    </td>
                    <td><?= e($booking->purpose ?? '-') ?></td>
                    <td>
                        <?= e($booking->status) ?>
                        <?php if ($booking->createdByAdmin): ?>
                            <br><span class="muted small">created by admin</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <form method="post" action="<?= e(path('/admin/bookings')) ?>" class="inline-edit">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="booking_id" value="<?= e($booking->id) ?>">

                            <input type="datetime-local" name="start" aria-label="New start"
                                   value="<?= e(Clock::local($booking->startsAt, 'Y-m-d\TH:i')) ?>">
                            <input type="datetime-local" name="end" aria-label="New end"
                                   value="<?= e(Clock::local($booking->endsAt, 'Y-m-d\TH:i')) ?>">
                            <input type="text" name="purpose" aria-label="Purpose" maxlength="255"
                                   value="<?= e($booking->purpose) ?>">

                            <button type="submit" name="action" value="update" class="link">Save</button>
                            <?php if ($booking->isConfirmed()): ?>
                                <button type="submit" name="action" value="cancel" class="link">Cancel</button>
                            <?php endif; ?>
                            <button type="submit" name="action" value="delete" class="link danger"
                                    data-confirm="Delete this booking outright? The audit log keeps a record.">
                                Delete
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
