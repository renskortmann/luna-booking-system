<?php
/**
 * The single page frame. Flash messages are rendered here and nowhere else.
 *
 * @var string $content
 * @var string $title
 */

use Macrolab\Auth;
use Macrolab\Config;
use Macrolab\Csrf;
use Macrolab\Session;

$user = Auth::user();
$isAdmin = Auth::isAdmin();
$flashes = Session::takeFlashes();
?>
<!doctype html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Booking') ?> &middot; <?= e(Config::string('app.name', 'Macrolab website')) ?></title>
    <link rel="stylesheet" href="<?= e(path('/assets/app.css')) ?>">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= e(path('/')) ?>"><?= e(Config::string('app.name', 'Macrolab website')) ?></a>

    <nav>
        <?php if ($isAdmin): ?>
            <span class="who">Administrator</span>
            <a href="<?= e(path('/')) ?>">Calendar</a>
            <a href="<?= e(path('/admin')) ?>">Admin</a>
            <form method="post" action="<?= e(path('/admin/logout')) ?>" class="inline">
                <?= Csrf::field() ?>
                <button type="submit" class="link">Sign out</button>
            </form>
        <?php elseif ($user !== null): ?>
            <span class="who"><?= e($user->label()) ?></span>
            <a href="<?= e(path('/')) ?>">Calendar</a>
            <a href="<?= e(path('/account')) ?>">My account</a>
            <form method="post" action="<?= e(path('/logout')) ?>" class="inline">
                <?= Csrf::field() ?>
                <button type="submit" class="link">Sign out</button>
            </form>
        <?php endif; ?>
    </nav>
</header>

<main>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<footer>
    <p>
        Macrolab website.
        Times are shown in <?= e(Config::string('app.display_timezone', 'Europe/Amsterdam')) ?>.
    </p>
</footer>

<script src="<?= e(path('/assets/app.js')) ?>"></script>
</body>
</html>
