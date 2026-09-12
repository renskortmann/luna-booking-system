<?php
/**
 * @var int    $status
 * @var string $message
 */
?>
<section class="card narrow">
    <h1><?= e($status) ?></h1>
    <p><?= e($message) ?></p>
    <p><a href="<?= e(path('/')) ?>">Back to the calendar</a></p>
</section>
