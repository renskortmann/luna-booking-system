<?php
/**
 * The administrator's read-only view of what everyone has logged.
 *
 * Read-only on purpose: there is no approval step, and nobody edits somebody
 * else's timesheet. See Macrolab\TimeEntryPolicy.
 *
 * @var \Macrolab\TimeFilter                       $filter
 * @var list<\Macrolab\TimeEntry>                  $entries
 * @var array{entries: int, minutes: int}          $totals
 * @var list<array{project: string, minutes: int}> $byProject
 * @var list<array<string, mixed>>                 $people
 * @var list<\Macrolab\Project>                    $projects
 */

use Macrolab\TimeRules;
?>
<section class="card">
    <h1>Time overview</h1>

    <form method="get" action="<?= e(path('/admin/time')) ?>" class="row">
        <label for="from">From</label>
        <input id="from" name="from" type="date" data-echo value="<?= e($filter->from->format('Y-m-d')) ?>">

        <label for="to">To</label>
        <input id="to" name="to" type="date" data-echo value="<?= e($filter->to->format('Y-m-d')) ?>">

        <label for="user">Person</label>
        <select id="user" name="user">
            <option value="">everyone</option>
            <?php foreach ($people as $person): ?>
                <option value="<?= e($person['id']) ?>"
                    <?= (int) $person['id'] === $filter->userId ? 'selected' : '' ?>>
                    <?= e($person['netid']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="project">Project</label>
        <select id="project" name="project">
            <option value="">all projects</option>
            <?php foreach ($projects as $project): ?>
                <option value="<?= e($project->id) ?>"
                    <?= $project->id === $filter->projectId ? 'selected' : '' ?>>
                    <?= e($project->label()) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="primary">Show</button>
    </form>

    <dl class="facts">
        <dt>Entries</dt><dd><?= e($totals['entries']) ?></dd>
        <dt>Total hours</dt><dd><?= e(TimeRules::formatHours($totals['minutes'])) ?></dd>
    </dl>

    <p>
        <a href="<?= e(path('/admin/time.csv?' . $filter->queryString())) ?>">Export these rows as CSV</a>
    </p>

    <p class="muted small">
        The export covers exactly what the filter above shows. Excel opens it
        through <em>Data &rarr; From Text/CSV</em> if a double-click does not
        split the columns.
    </p>
</section>

<?php if ($byProject !== []): ?>
    <section class="card">
        <h2>By project</h2>
        <dl class="facts">
            <?php foreach ($byProject as $row): ?>
                <dt><?= e($row['project']) ?></dt>
                <dd><?= e(TimeRules::formatHours($row['minutes'])) ?></dd>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Entries</h2>

    <?php if ($entries === []): ?>
        <p class="muted">Nothing logged in that range.</p>
    <?php else: ?>
        <table class="wide">
            <thead>
            <tr><th>Day</th><th>Who</th><th>Project</th><th class="num">Hours</th><th>Note</th></tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= e($entry->workedOnLabel('j M Y')) ?></td>
                    <td>
                        <?= e($entry->ownerLabel()) ?>
                        <span class="muted">(<?= e($entry->ownerNetid) ?>)</span>
                    </td>
                    <td><?= e($entry->projectLabel()) ?></td>
                    <td class="num"><?= e($entry->hoursLabel()) ?></td>
                    <td><?= $entry->note === null ? '<span class="muted">-</span>' : e($entry->note) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
