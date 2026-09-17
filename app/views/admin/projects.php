<?php
/**
 * @var list<\Macrolab\Project> $projects
 * @var string|null             $error
 */

use Macrolab\Csrf;
use Macrolab\TimeRules;
?>
<section class="card">
    <h1>Projects</h1>

    <p class="muted small">
        Every time entry belongs to one project. This list has nothing to do
        with the machines: time is logged against work, not against equipment.
    </p>

    <?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>Add a project</h2>

    <form method="post" action="<?= e(path('/admin/projects')) ?>" class="row">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="add">

        <label for="name">Name</label>
        <input id="name" name="name" type="text" required maxlength="128" placeholder="Beam alignment">

        <label for="code">Code <span class="muted">(optional)</span></label>
        <input id="code" name="code" type="text" maxlength="32" placeholder="BA-12">

        <label for="description">Description <span class="muted">(optional)</span></label>
        <input id="description" name="description" type="text" placeholder="what this project covers">

        <button type="submit" class="primary">Add</button>
    </form>
</section>

<section class="card">
    <h2>On the list</h2>

    <?php if ($projects === []): ?>
        <p class="muted">
            No projects yet. Nobody can log time until there is at least one.
        </p>
    <?php else: ?>
        <table class="wide">
            <thead>
            <tr>
                <th>Name</th><th>Code</th><th>Status</th>
                <th class="num">Entries</th><th class="num">Hours</th><th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($projects as $project): ?>
                <tr class="<?= $project->isActive ? '' : 'row-suspended' ?>">
                    <td>
                        <form method="post" action="<?= e(path('/admin/projects')) ?>" class="inline-edit">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="project_id" value="<?= e($project->id) ?>">
                            <input type="text" name="name" aria-label="Name" required maxlength="128"
                                   value="<?= e($project->name) ?>">
                            <input type="text" name="description" aria-label="Description"
                                   value="<?= e($project->description ?? '') ?>">
                            <input type="hidden" name="code" value="<?= e($project->code ?? '') ?>">
                            <button type="submit" name="action" value="update" class="link">Save</button>
                        </form>
                    </td>
                    <td><?= $project->code === null ? '<span class="muted">-</span>' : e($project->code) ?></td>
                    <td><?= $project->isActive ? 'in use' : 'retired' ?></td>
                    <td class="num"><?= e($project->entryCount) ?></td>
                    <td class="num"><?= e(TimeRules::formatHours($project->totalMinutes)) ?></td>
                    <td class="actions">
                        <form method="post" action="<?= e(path('/admin/projects')) ?>" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="project_id" value="<?= e($project->id) ?>">
                            <?php if ($project->isActive): ?>
                                <button type="submit" name="action" value="retire" class="link"
                                        data-confirm="Retire <?= e($project->name) ?>? The hours already logged stay on record, but nobody can log new time against it.">
                                    Retire
                                </button>
                            <?php else: ?>
                                <button type="submit" name="action" value="reactivate" class="link">Put back in use</button>
                            <?php endif; ?>
                        </form>

                        <?php if ($project->entryCount === 0): ?>
                            <form method="post" action="<?= e(path('/admin/projects')) ?>" class="inline"
                                  data-confirm="Remove <?= e($project->name) ?> completely?">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="project_id" value="<?= e($project->id) ?>">
                                <button type="submit" name="action" value="delete" class="link danger">Remove</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="muted small">
            A project with time on record cannot be removed - retire it instead,
            so those hours keep the project they were booked to.
        </p>
    <?php endif; ?>
</section>
