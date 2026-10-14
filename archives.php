<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'writ', 'writlist', 'user'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
$dash = (string) ($_SESSION['pw_dash'] ?? '');
if ($dash === 'observer' || ($dash !== 'writer' && $app->auth->is('observer'))) {
    $ids = $app->user->observeeIds($app->auth->user());
    $app->view->start('Archives', 'archives', 'observer');
    echo '<h2 class="lt">Archives</h2>';
    $app->writlist->renderObserver('archives.php', $ids, 'archived');
    $app->view->end();
    return;
}
$app->view->start('Archives', 'archives', 'writer');
echo '<h2 class="lt">Archives</h2>';
$app->writlist->renderWriter('archives.php', 'archived');
$app->view->end();
