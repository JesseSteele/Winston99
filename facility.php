<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'text', 'facility', 'notify', 'form'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->is('superintendent')) {
    $app->redirect('');
}
$fid = (int) ($_GET['f'] ?? $_POST['f'] ?? 0);
$creating = $fid < 1;
$f = $creating ? null : $app->facility->find($fid);
if (!$creating && !$f) {
    $app->redirect('facilities.php');
}
$msg = '';
$form = new Form();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check()) {
    if (isset($_POST['create_facility'])) {
        $form->grab($_POST, 'name', 'code');
        $form->put('name', clean_title($form->get('name'), 120));
        $form->put('code', clean_title($form->get('code'), 16));
        if ($form->get('name') === '') {
            $form->fail('name', 'Name is required.');
        } else {
            $id = $app->facility->create($form->get('name'), $form->get('code'));
            $app->notify->send($app->auth->id(), 'new_facility', 'Facility created', 'facilities.php');
            $app->view->setFlash('Facility created.');
            $app->redirect('facility.php?f=' . $id);
        }
    } elseif (!$creating && isset($_POST['save_facility'])) {
        $form->grab($_POST, 'name', 'code', 'status');
        $form->put('name', clean_title($form->get('name'), 120));
        $form->put('code', clean_title($form->get('code'), 16));
        if ($form->get('name') === '') {
            $form->fail('name', 'Name is required.');
        } else {
            $app->facility->rename($fid, $form->get('name'), $form->get('code'));
            $app->facility->setStatus($fid, $form->get('status') === 'closed' ? 'closed' : 'open');
            $f = $app->facility->find($fid);
            $msg = 'Saved.';
            $form = new Form();
        }
    } elseif (!$creating && isset($_POST['enter'])) {
        $_SESSION['facility_id'] = $fid;
        $msg = 'Working inside this facility.';
    }
}
$app->view->start($creating ? 'New facility' : 'Facility', 'facilities', 'super');
echo '<h2 class="lt">' . ($creating ? 'New facility' : 'Edit facility') . '</h2>';
if ($msg) {
    echo '<p class="sans noticegreen">' . h($msg) . '</p>';
}
if (!$form->bad() && $f) {
    $form->put('name', (string) ($f['name'] ?? ''));
    $form->put('code', (string) ($f['code'] ?? ''));
    $form->put('status', (string) ($f['status'] ?? 'open'));
}
echo '<form method="post">' . $app->csrf->field();
if (!$creating) {
    echo '<input type="hidden" name="f" value="' . $fid . '">';
}
echo '<p class="field sans"><label for="name">Name</label>' . $form->input('name', 'text', 'required') . '</p>';
echo '<p class="field sans"><label for="code">Code</label>' . $form->input('code') . '</p>';
if ($creating) {
    echo '<p><input type="submit" name="create_facility" class="lt_button" value="Create"></p>';
} else {
    echo '<p class="field sans"><label for="status">Status</label>';
    echo $form->select('status', ['open' => 'open', 'closed' => 'closed'], '', 'formselect small') . '</p>';
    echo '<p><input type="submit" name="save_facility" class="lt_button" value="Save"></p>';
    echo '<p><button type="submit" name="enter" class="set_gray" value="1">Work in this facility</button></p>';
}
echo '</form>';
$app->view->end();
