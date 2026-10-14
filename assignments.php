<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'writ', 'user', 'writlist'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
$dash = (string) ($_SESSION['pw_dash'] ?? '');
if ($dash === 'observer' || ($dash !== 'editor' && $dash !== 'writer' && $app->auth->is('observer') && !$app->auth->atLeast('editor'))) {
    $ids = $app->user->observeeIds($app->auth->user());
    $app->view->start('Assignments', 'assign', 'observer');
    echo '<h2 class="lt">Assignments</h2>';
    $app->writlist->renderObserver('assignments.php', $ids, 'current', 'assignment');
    $app->view->end();
    return;
}
if ($dash === 'writer' || !$app->auth->atLeast('editor')) {
    $app->view->start('Assignments', 'assign', 'writer');
    echo '<h2 class="lt">Assignments</h2>';
    $app->writlist->renderWriter('assignments.php', 'current', 'assignment');
    $app->view->end();
    return;
}
$app->view->start('Assignments', 'assign', 'editor');
echo '<h2 class="lt">Assignments</h2>';
echo '<p>' . post_button('New assignment +', 'Memo plus a writ for the writer or block', 'assignment.php', 'new_assignment', '1', 'newNoteButton', $app->csrf->token()) . '</p>';
$app->writlist->renderEditorAssignments('assignments.php');
$app->view->end();
