<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'text', 'writ'];
require dirname(__DIR__) . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->atLeast('editor')) {
    $app->json(['ok' => false, 'error' => 'forbidden'], 403);
}
if (!$app->csrf->check()) {
    $app->json(['ok' => false, 'error' => 'csrf'], 400);
}
$wid = (int) ($_POST['writ_id'] ?? 0);
$w = $app->writ->find($wid);
if (!$w) {
    $app->json(['ok' => false, 'error' => 'not found'], 404);
}
$score = $_POST['score'] ?? '';
$peek = $app->writ->isPeek($w);
if ($peek) {
    $fields = [
        'block_id' => (int) $w['block_id'],
        'title' => (string) $w['title'],
        'work' => (string) $w['work'],
        'notes' => (string) $w['notes'],
        'edits' => (string) $w['edits'],
        'edits_wordcount' => (int) $w['edits_wordcount'],
        'edit_notes' => clean_body($_POST['edit_notes'] ?? ''),
        'scoring' => (string) $w['scoring'],
        'score' => $w['score'],
        'outof' => (int) ($w['outof'] ?: 100),
    ];
    if (!$app->writ->saveEdits($wid, $fields)) {
        $item = [
            'kind' => 'writ',
            'id' => $wid,
            'title' => (string) $w['title'],
            'href' => 'review.php?w=' . $wid,
            'warning' => "Can't connect to the database; saved in this browser.",
            'fields' => ['edit_notes' => $fields['edit_notes']],
        ];
        browser_save_remember($item);
        $app->json(['ok' => false, 'browser' => true] + $item);
    }
    $app->json(['ok' => true, 'msg' => 'Editor notes saved.', 'forget' => ['kind' => 'writ', 'id' => $wid]]);
}
$fields = [
    'block_id' => (int) ($_POST['block'] ?? $w['block_id']),
    'title' => writ_title($_POST['title'] ?? $w['title'] ?? ''),
    'work' => writ_work($_POST['work'] ?? $w['work'] ?? '', $wid),
    'notes' => clean_body($_POST['notes'] ?? $w['notes']),
    'edits' => clean_body($_POST['edits'] ?? ''),
    'edits_wordcount' => wordcount($_POST['edits'] ?? ''),
    'edit_notes' => clean_body($_POST['edit_notes'] ?? ''),
    'scoring' => clean_body($_POST['scoring'] ?? ''),
    'score' => $score === '' ? null : (int) $score,
    'outof' => (int) ($_POST['outof'] ?? 100),
];
if (!$app->writ->saveEdits($wid, $fields)) {
    $item = [
        'kind' => 'writ',
        'id' => $wid,
        'title' => $fields['title'],
        'href' => 'review.php?w=' . $wid,
        'warning' => "Can't connect to the database; saved in this browser.",
        'fields' => $fields,
    ];
    browser_save_remember($item);
    $app->json(['ok' => false, 'browser' => true] + $item);
}
$app->json(['ok' => true, 'msg' => 'Editor revision saved, not finalized.', 'forget' => ['kind' => 'writ', 'id' => $wid]]);
