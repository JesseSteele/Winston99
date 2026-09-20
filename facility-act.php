<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'facility'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->is('superintendent')) {
    $app->redirect('');
}

$back = winston99_facilities_return();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$app->csrf->check()) {
    $app->view->setFlash('That facility action did not go through. Try again.', false);
    $app->redirect($back);
}

$op = (string) ($_POST['facilitysubmit'] ?? '');
$ids = [];
foreach ($_POST as $k => $v) {
    if (str_starts_with((string) $k, 'bulk_') && filter_var($v, FILTER_VALIDATE_INT)) {
        $ids[] = (int) $v;
    }
}
if ($ids === []) {
    $app->view->setFlash('Select at least one facility.', false);
    $app->redirect($back);
}

$ok = 0;
foreach ($ids as $id) {
    $f = $app->facility->find($id);
    if (!$f) {
        continue;
    }
    if ($op === 'open') {
        $app->facility->setStatus($id, 'open');
        $ok++;
    } elseif ($op === 'close') {
        $app->facility->setStatus($id, 'closed');
        $ok++;
    } elseif ($op === 'delete') {
        $app->facility->delete($id);
        $ok++;
    }
}

if ($op === 'open') {
    $app->view->setFlash($ok === 1 ? 'Facility opened.' : $ok . ' facilities opened.');
} elseif ($op === 'close') {
    $app->view->setFlash($ok === 1 ? 'Facility closed.' : $ok . ' facilities closed.');
} elseif ($op === 'delete') {
    $app->view->setFlash($ok === 1 ? 'Facility deleted.' : $ok . ' facilities deleted.', false);
} else {
    $app->view->setFlash('Nothing was changed. Confirm the action if asked.', false);
}
$app->redirect($back);
