<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'text', 'block', 'form'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->atLeast('supervisor')) {
    $app->redirect('');
}
$bid = (int) ($_GET['b'] ?? $_POST['b'] ?? 0);
$back = winston99_blocks_return();
$b = $app->block->find($bid);
if (!$b) {
    $app->redirect($back);
}
$form = new Form();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check()) {
    $form->grab($_POST, 'name', 'code', 'status');
    $form->put('name', clean_title($form->get('name'), 120));
    $form->put('code', clean_title($form->get('code'), 10));
    $form->put('status', $form->get('status') === 'closed' ? 'closed' : 'open');
    if ($form->get('name') === '') {
        $form->fail('name', 'Name is required.');
    }
    if ($form->ok()) {
        $app->block->save($bid, [
            'name' => $form->get('name'),
            'code' => $form->get('code'),
            'status' => $form->get('status'),
        ]);
        $app->view->setFlash('Block saved.');
        $app->redirect($back);
    }
}
if (!$form->bad()) {
    $form->put('name', (string) $b['name']);
    $form->put('code', (string) $b['code']);
    $form->put('status', (string) $b['status']);
}
$app->view->start('Block', 'blocks', 'admin');
echo '<form method="post">' . $app->csrf->field();
echo '<input type="hidden" name="b" value="' . $bid . '">';
echo '<input type="hidden" name="return" value="' . h($back) . '">';
echo '<p class="sans">Name ' . $form->input('name') . ' Code ' . $form->input('code') . '</p>';
echo '<p class="sans">Status ' . $form->select('status', ['open' => 'open', 'closed' => 'closed'], '', 'formselect small') . '</p>';
echo '<p><input type="submit" class="lt_button" value="Save"></p></form>';
$app->view->end();
