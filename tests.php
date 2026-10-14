<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'test', 'writlist', 'user'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
$dash = (string) ($_SESSION['pw_dash'] ?? '');
if ($dash === 'observer' || ($dash !== 'editor' && $dash !== 'writer' && $app->auth->is('observer') && !$app->auth->atLeast('editor'))) {
    $ids = $app->user->observeeIds($app->auth->user());
    $app->view->start('Tests', 'tests', 'observer');
    echo '<h2 class="lt">Tests</h2>';
    $app->writlist->renderObserver('tests.php', $ids, 'current', 'test');
    $app->view->end();
    return;
}
if ($dash === 'writer' || !$app->auth->atLeast('editor')) {
    $app->view->start('Tests', 'tests', 'writer');
    echo '<h2 class="lt">Tests</h2>';
    $app->writlist->renderWriter('tests.php', 'current', 'test');
    $app->view->end();
    return;
}
$app->view->start('Tests', 'tests', 'editor');
echo '<h2 class="lt">Tests</h2>';
echo '<p>' . post_button('New test +', 'Compose a test', 'test.php', 'new_test', '1', 'newNoteButton', $app->csrf->token()) . '</p>';
$app->writlist->renderEditorTests('tests.php');
$app->view->end();
