<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'note', 'writlist'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
$dash = (string) ($_SESSION['pw_dash'] ?? 'writer');
if (!in_array($dash, ['writer', 'observer', 'editor'], true)) {
    $dash = 'writer';
}
$app->view->start('Notes', 'notes', $dash);
echo '<br>';
echo button('Memos →', 'View all notes from your editor and blocks', 'memos.php', 'editNoteButton');
echo '<h2 class="lt">My Notes</h2>';
echo post_button('New note +', 'Start a new note', 'note.php', 'new_note', (string) $app->auth->id(), 'newNoteButton', $app->csrf->token());
echo '<br>';
$app->writlist->renderNotes('notes.php');
$app->view->end();
