<?php
/**
 * @var \Macrolab\TimeEntry     $entry
 * @var list<\Macrolab\Project> $projects
 * @var \Macrolab\TimeRuleSet   $rules
 * @var string|null             $error
 */

use Macrolab\Csrf;
use Macrolab\TimeRules;
?>
<section class="card narrow">
    <h1>Change a time entry</h1>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e(path('/time/' . $entry->id)) ?>">
        <?= Csrf::field() ?>

        <label for="worked_on">Day</label>
        <input id="worked_on" name="worked_on" type="date" required data-echo
               value="<?= e($entry->workedOnDate()) ?>">

        <label for="project_id">Project</label>
        <select id="project_id" name="project_id" required>
            <?php foreach ($projects as $project): ?>
                <option value="<?= e($project->id) ?>"
                    <?= $project->id === $entry->projectId ? 'selected' : '' ?>>
                    <?= e($project->label()) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="hours">Hours</label>
        <input id="hours" name="hours" type="text" required inputmode="decimal"
               value="<?= e($entry->hoursLabel()) ?>">

        <label for="note">Note <span class="muted">(optional)</span></label>
        <input id="note" name="note" type="text" maxlength="<?= e(TimeRules::NOTE_MAX) ?>"
               value="<?= e($entry->note ?? '') ?>">

        <div class="actions">
            <button type="submit" class="primary">Save</button>
            <a href="<?= e(path('/time?month=' . $entry->workedOn->format('Y-m'))) ?>">Cancel</a>
        </div>
    </form>
</section>
