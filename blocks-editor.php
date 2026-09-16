<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'text', 'block', 'user', 'notify', 'writlist', 'form'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->atLeast('supervisor')) {
    $app->redirect('editor.php');
}
$msg = '';
$fid = $app->auth->facilityId();
$editors = $app->user->listByFacility($fid, 'editor');
$form = new Form();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check() && isset($_POST['create'])) {
    $form->grab($_POST, 'name', 'code', 'editor_id');
    $form->put('name', clean_title($form->get('name'), 120));
    $form->put('code', clean_title($form->get('code'), 10));
    $eid = (int) $form->get('editor_id');
    if ($eid < 1 && $editors) {
        $eid = (int) $editors[0]['id'];
        $form->put('editor_id', (string) $eid);
    }
    if ($form->get('name') === '') {
        $form->fail('name', 'Name is required.');
    }
    if ($form->ok()) {
        $app->block->create([
            'facility_id' => $fid,
            'editor_id' => $eid,
            'name' => $form->get('name'),
            'code' => $form->get('code'),
        ]);
        $app->notify->send($app->auth->id(), 'new_block', 'Block created', 'blocks-editor.php');
        $msg = 'Block created.';
        $form = new Form();
    }
}
$app->view->start('Blocks', 'blocks', 'admin');
echo '<h2 class="lt">Blocks</h2>';
if ($msg) {
    echo '<p class="sans noticegreen">' . h($msg) . '</p>';
}
$edOpts = [];
foreach ($editors as $ed) {
    $edOpts[(int) $ed['id']] = $ed['name'];
}
if ($form->get('editor_id') === '' && $edOpts) {
    $form->put('editor_id', (string) array_key_first($edOpts));
}
echo '<form method="post">' . $app->csrf->field();
echo '<p class="sans">Name<br>' . $form->input('name', 'text', 'required') . '</p>';
echo '<p class="sans">Code<br>' . $form->input('code', 'text', 'size="8"') . '</p>';
echo '<p class="sans">Editor<br>' . $form->select('editor_id', $edOpts) . '</p>';
echo '<p><input type="submit" name="create" class="lt_button" value="Create block"></p></form>';
echo '<h3 class="lt" style="display:inline-block;margin-right:0.75em">Open blocks</h3>';
echo button('View closed blocks', 'Closed blocks', 'blocks-closed.php', 'editNoteButton');
$app->writlist->renderEditorBlocks('blocks-editor.php', 'admin');
$app->view->end();
