<?php
/**
 * One month of an employee's entries, as a list. Rendered inside the time
 * registration page and on its own by /api/time/month, which the day sheet
 * calls after each save so this list never disagrees with the grid above it.
 *
 * @var list<\Macrolab\Time\TimeEntry>             $entries
 * @var \DateTimeImmutable                         $month
 * @var string                                     $day        Y-m-d on the sheet above
 * @var string                                     $prevMonth
 * @var string                                     $nextMonth
 * @var int                                        $totalMinutes
 * @var list<array{project: string, minutes: int}> $byProject
 */

use Macrolab\Csrf;
use Macrolab\Time\TimeRules;
?>
<section class="card" id="time-month">
    <h2><?= e($month->format('F Y')) ?></h2>

    <p class="pager">
        <a href="<?= e(path('/time?day=' . $day . '&month=' . $prevMonth)) ?>">&larr; <?= e($prevMonth) ?></a>
        <a href="<?= e(path('/time?day=' . $day . '&month=' . $nextMonth)) ?>"><?= e($nextMonth) ?> &rarr;</a>
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
