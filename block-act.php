<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'block'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->atLeast('supervisor')) {
    $app->redirect('editor.php');
}

$back = winston99_blocks_return();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$app->csrf->check()) {
    $app->view->setFlash('That block action did not go through. Try again.', false);
    $app->redirect($back);
}

$op = (string) ($_POST['blocksubmit'] ?? '');
$ids = [];
foreach ($_POST as $k => $v) {
    if (str_starts_with((string) $k, 'bulk_') && filter_var($v, FILTER_VALIDATE_INT)) {
        $ids[] = (int) $v;
    }
}
if ($ids === []) {
    $app->view->setFlash('Select at least one block.', false);
    $app->redirect($back);
}

$fid = $app->auth->facilityId();
$ok = 0;
foreach ($ids as $id) {
    $b = $app->block->find($id);
    if (!$b) {
        continue;
    }
    if ($fid && (int) ($b['facility_id'] ?? 0) !== $fid && !$app->auth->is('superintendent')) {
        continue;
    }
    if ($op === 'open') {
        $app->block->setStatus($id, 'open');
        $ok++;
    } elseif ($op === 'close') {
        $app->block->setStatus($id, 'closed');
        $ok++;
    } elseif ($op === 'delete') {
        $app->block->delete($id);
        $ok++;
    }
}

if ($op === 'open') {
    $app->view->setFlash($ok === 1 ? 'Block opened.' : $ok . ' blocks opened.');
} elseif ($op === 'close') {
    $app->view->setFlash($ok === 1 ? 'Block closed.' : $ok . ' blocks closed.');
} elseif ($op === 'delete') {
    $app->view->setFlash($ok === 1 ? 'Block deleted.' : $ok . ' blocks deleted.', false);
} else {
    $app->view->setFlash('Nothing was changed. Confirm the action if asked.', false);
}
$app->redirect($back);
