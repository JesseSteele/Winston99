<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'text', 'writ'];
require dirname(__DIR__) . '/lib/boot.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $app->json(['ok' => false, 'error' => 'POST only'], 405);
}
$u = $app->auth->requireUser();
if (!$app->csrf->check()) {
    $app->json(['ok' => false, 'error' => 'csrf'], 400);
}
$wid = (int) ($_POST['writ_id'] ?? 0);
$w = $app->writ->find($wid);
if (!$w || (int) $w['writer_id'] !== $app->auth->id()) {
    $app->json(['ok' => false, 'error' => 'not found'], 404);
}
$held = null;
if (!empty($_POST['save_draft']) || isset($_POST['draft'])) {
    $title = writ_title($_POST['title'] ?? '');
    $work = writ_work($_POST['work'] ?? '', $wid);
    $fields = [
        'title' => $title,
        'work' => $work,
        'block_id' => (int) ($_POST['block'] ?? 0),
        'notes' => clean_body($_POST['notes'] ?? ''),
        'draft' => clean_body($_POST['draft'] ?? ''),
        'draft_wordcount' => wordcount($_POST['draft'] ?? ''),
        'writing_time' => (int) ($_POST['writing_time'] ?? 0),
    ];
    if (!$app->writ->saveDraft($wid, $app->auth->id(), $fields)) {
        $held = [
            'kind' => 'writ',
            'id' => $wid,
            'title' => $title,
            'href' => 'writ.php?w=' . $wid,
            'warning' => "Can't connect to the database; saved in this browser.",
            'fields' => $fields,
        ];
    }
}
if ($held === null && isset($_POST['correction'])) {
    $fields = [
        'notes' => clean_body($_POST['notes'] ?? ''),
        'correction' => clean_body($_POST['correction'] ?? ''),
        'correction_wordcount' => wordcount($_POST['correction'] ?? ''),
    ];
    if (!$app->writ->saveCorrection($wid, $app->auth->id(), $fields)) {
        $held = [
            'kind' => 'writ',
            'id' => $wid,
            'title' => writ_title($_POST['title'] ?? $w['title'] ?? ''),
            'href' => 'writ.php?w=' . $wid,
            'warning' => "Can't connect to the database; saved in this browser.",
            'fields' => $fields,
        ];
    }
}
if ($held !== null) {
    browser_save_remember($held);
    $app->json(['ok' => false, 'browser' => true] + $held);
}
$app->json(['ok' => true, 'msg' => 'Saved', 'forget' => ['kind' => 'writ', 'id' => $wid], 'title' => writ_title($_POST['title'] ?? $w['title'] ?? ''), 'work' => writ_work($_POST['work'] ?? $w['work'] ?? '', $wid)]);
