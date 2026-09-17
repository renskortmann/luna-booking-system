<?php
/**
 * An employee's own timesheet for one month.
 *
 * @var \Macrolab\User                                   $user
 * @var list<\Macrolab\Project>                          $projects
 * @var list<\Macrolab\TimeEntry>                        $entries
 * @var \DateTimeImmutable                               $month
 * @var string                                           $prevMonth
 * @var string                                           $nextMonth
 * @var int                                              $totalMinutes
 * @var list<array{project: string, minutes: int}>       $byProject
 * @var \Macrolab\TimeRuleSet                            $rules
 * @var \DateTimeImmutable                               $today
 * @var string|null                                      $error
 */

use Macrolab\Csrf;
use Macrolab\TimeRules;

$maxDate = $today->modify('+' . $rules->maxFutureDays . ' days')->format('Y-m-d');
$minDate = $today->modify('-' . $rules->maxBackdateDays . ' days')->format('Y-m-d');
?>
<section class="card">
    <h1>Time registration</h1>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if ($projects === []): ?>
        <p class="muted">
            There are no projects to log time against yet. Ask the
            administrator to add one.
        </p>
    <?php else: ?>
        <h2>Log some time</h2>

        <form method="post" action="<?= e(path('/time')) ?>" class="row">
            <?= Csrf::field() ?>

            <label for="worked_on">Day</label>
            <input id="worked_on" name="worked_on" type="date" required data-echo
                   value="<?= e($today->format('Y-m-d')) ?>"
                   min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>">

            <label for="project_id">Project</label>
            <select id="project_id" name="project_id" required>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= e($project->id) ?>"><?= e($project->label()) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="hours">Hours</label>
            <input id="hours" name="hours" type="text" required inputmode="decimal"
                   placeholder="3.5" aria-describedby="hours-help">

            <label for="note">Note <span class="muted">(optional)</span></label>
            <input id="note" name="note" type="text" maxlength="<?= e(TimeRules::NOTE_MAX) ?>"
                   placeholder="what you worked on">

            <button type="submit" class="primary">Log it</button>
        </form>

        <p class="muted small" id="hours-help">
            Hours can be written as <code>3.5</code>, <code>3,5</code>,
            <code>3:30</code> or <code>3h30</code>. The longest single entry is
            <?= e(TimeRules::formatHours($rules->maxMinutesPerEntry)) ?>, and the
            most you can log on one day is
            <?= e(TimeRules::formatHours($rules->maxMinutesPerDay)) ?>.
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= e($month->format('F Y')) ?></h2>

    <p class="pager">
        <a href="<?= e(path('/time?month=' . $prevMonth)) ?>">&larr; <?= e($prevMonth) ?></a>
        <a href="<?= e(path('/time?month=' . $nextMonth)) ?>"><?= e($nextMonth) ?> &rarr;</a>
    </p>

    <?php if ($entries === []): ?>
        <p class="muted">Nothing logged this month.</p>
    <?php else: ?>
        <table class="wide">
            <thead>
            <tr><th>Day</th><th>Project</th><th class="num">Hours</th><th>Note</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= e($entry->workedOnLabel()) ?></td>
                    <td><?= e($entry->projectLabel()) ?></td>
                    <td class="num"><?= e($entry->hoursLabel()) ?></td>
                    <td><?= $entry->note === null ? '<span class="muted">-</span>' : e($entry->note) ?></td>
                    <td class="actions">
                        <a href="<?= e(path('/time/' . $entry->id)) ?>">Change</a>
                        <form method="post" action="<?= e(path('/time/' . $entry->id . '/delete')) ?>" class="inline"
                              data-confirm="Remove the <?= e($entry->hoursLabel()) ?> logged on <?= e($entry->workedOnLabel()) ?>?">
                            <?= Csrf::field() ?>
                            <button type="submit" class="link danger">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="2">Total</th>
                <th class="num"><?= e(TimeRules::formatHours($totalMinutes)) ?></th>
                <th colspan="2"></th>
            </tr>
            </tfoot>
        </table>

        <h3>By project</h3>
        <dl class="facts">
            <?php foreach ($byProject as $row): ?>
                <dt><?= e($row['project']) ?></dt>
                <dd><?= e(TimeRules::formatHours($row['minutes'])) ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
</section>
