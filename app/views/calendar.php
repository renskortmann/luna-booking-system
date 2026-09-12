<?php
/**
 * The calendar page. All behaviour lives in assets/app.js; the configuration
 * it needs is handed over in a data attribute, because the content security
 * policy allows no inline script.
 *
 * @var array<string, mixed>  $resource
 * @var \Luna\Actor           $actor
 * @var \Luna\RuleSet         $rules
 * @var string                $csrf
 * @var array<string, mixed>  $clientRules
 */

use Luna\BookingRules;
?>
<section class="card">
    <div class="calendar-head">
        <div>
            <h1><?= e($resource['name']) ?></h1>
            <?php if (!empty($resource['description'])): ?>
                <p class="muted"><?= e($resource['description']) ?></p>
            <?php endif; ?>
        </div>

        <ul class="legend">
            <li><span class="swatch own"></span> Your bookings</li>
            <li><span class="swatch other"></span> Someone else</li>
        </ul>
    </div>

    <p class="muted small rules-summary">
        <?php if ($actor->isAdmin): ?>
            You are signed in as the administrator: the booking rules below do not
            apply to you, but two bookings still cannot overlap.
        <?php else: ?>
            Bookable <?= e(BookingRules::humanDays($rules->openDays)) ?>,
            <?= e($rules->openTime) ?>-<?= e($rules->closeTime) ?>,
            in blocks of <?= e($rules->slotMinutes) ?> minutes,
            up to <?= e(BookingRules::humanDuration($rules->maxMinutes)) ?> at a time,
            <?= e($rules->maxAdvanceDays) ?> days ahead,
            <?= $rules->maxActivePerUser > 0
                ? 'at most ' . e($rules->maxActivePerUser) . ' upcoming booking(s) each'
                : 'with no limit on how many you may hold' ?>.
        <?php endif; ?>
    </p>

    <div id="calendar"
         data-feed="<?= e(path('/api/bookings')) ?>"
         data-csrf="<?= e($csrf) ?>"
         data-config="<?= e(json_encode($clientRules, JSON_THROW_ON_ERROR)) ?>"></div>
</section>

<script src="<?= e(path('/assets/vendor/fullcalendar-6.1.15.global.min.js')) ?>"></script>

<dialog id="booking-dialog">
    <form id="booking-form" method="dialog">
        <h2 id="booking-dialog-title">Book the machine</h2>

        <p id="booking-dialog-error" class="alert" hidden role="alert"></p>

        <p id="booking-dialog-owner" class="muted small" hidden></p>

        <label for="booking-start">Start</label>
        <input id="booking-start" name="start" type="datetime-local" required>

        <label for="booking-end">End</label>
        <input id="booking-end" name="end" type="datetime-local" required>

        <label for="booking-purpose">Purpose <span class="muted">(optional, only you and the administrator see it)</span></label>
        <input id="booking-purpose" name="purpose" type="text" maxlength="255">

        <?php if ($actor->isAdmin): ?>
            <label for="booking-owner">Book on behalf of (netID)</label>
            <input id="booking-owner" name="owner_netid" type="text"
                   autocapitalize="none" spellcheck="false"
                   placeholder="netID of the person this booking is for">
        <?php endif; ?>

        <menu>
            <button type="button" value="cancel" id="booking-close">Close</button>
            <button type="button" value="delete" id="booking-delete" class="danger" hidden>Cancel this booking</button>
            <button type="button" value="save" id="booking-save" class="primary">Save</button>
        </menu>
    </form>
</dialog>
