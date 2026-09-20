<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'user', 'audit'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();

$type = (string) ($_POST['type'] ?? 'editor');
if (!in_array($type, ['editor', 'observer', 'writer', 'admin'], true)) {
    $type = 'editor';
}
if ($type === 'admin' && !$app->auth->is('superintendent')) {
    $app->redirect('');
}
if (!$app->auth->atLeast('supervisor')) {
    $app->redirect('editor.php');
}

$back = winston99_people_return($type);
$noun = match ($type) {
    'observer' => 'observer',
    'writer' => 'writer',
    'admin' => 'administrator',
    default => 'editor',
};
$nouns = $noun . 's';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$app->csrf->check()) {
    $app->view->setFlash('That action did not go through. Try again.', false);
    $app->redirect($back);
}

$op = (string) ($_POST['staffsubmit'] ?? $_POST['editorsubmit'] ?? '');
$ids = [];
foreach ($_POST as $k => $v) {
    if (str_starts_with((string) $k, 'bulk_') && filter_var($v, FILTER_VALIDATE_INT)) {
        $ids[] = (int) $v;
    }
}
if ($ids === []) {
    $app->view->setFlash('Select at least one ' . $noun . '.', false);
    $app->redirect($back);
}

$ok = 0;
foreach ($ids as $id) {
    $u = $app->user->find($id);
    if (!$u || ($u['type'] ?? '') !== $type || !$app->auth->canManageAccount($u)) {
        continue;
    }
    if ($op === 'dormant') {
        $app->user->setStatus($id, 'dormant');
        $app->audit->record($id, 'status', 'dormant');
        $ok++;
    } elseif ($op === 'activate') {
        $app->user->setStatus($id, 'active');
        $app->audit->record($id, 'status', 'active');
        $ok++;
    } elseif ($op === 'delete') {
        $app->user->delete($id);
        $app->audit->record($id, 'delete', $type);
        $ok++;
    }
}

if ($op === 'dormant') {
    $app->view->setFlash($ok === 1 ? ucfirst($noun) . ' set dormant.' : $ok . ' ' . $nouns . ' set dormant.');
} elseif ($op === 'activate') {
    $app->view->setFlash($ok === 1 ? ucfirst($noun) . ' activated.' : $ok . ' ' . $nouns . ' activated.');
} elseif ($op === 'delete') {
    $app->view->setFlash($ok === 1 ? ucfirst($noun) . ' deleted.' : $ok . ' ' . $nouns . ' deleted.', false);
} else {
    $app->view->setFlash('Nothing was changed. Confirm the action if asked.', false);
}
$app->redirect($back);
