<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'text', 'writ', 'block', 'user', 'notify'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->atLeast('editor')) {
    $app->redirect('');
}
$wid = (int) ($_GET['w'] ?? $_POST['writ_id'] ?? 0);
$w = $wid ? $app->writ->find($wid) : null;
if (!$w) {
    $app->redirect('editor.php');
}

$fields = function () use ($w): array {
    $score = $_POST['score'] ?? '';
    return [
        'block_id' => (int) ($_POST['block'] ?? $w['block_id']),
        'title' => clean_title($_POST['title'] ?? $w['title']),
        'work' => clean_title($_POST['work'] ?? $w['work']),
        'notes' => clean_body($_POST['notes'] ?? $w['notes']),
        'edits' => clean_body($_POST['edits'] ?? $w['edits']),
        'edits_wordcount' => wordcount($_POST['edits'] ?? $w['edits'] ?? ''),
        'edit_notes' => clean_body($_POST['edit_notes'] ?? $w['edit_notes']),
        'scoring' => clean_body($_POST['scoring'] ?? $w['scoring']),
        'score' => $score === '' ? null : (int) $score,
        'outof' => (int) ($_POST['outof'] ?? ($w['outof'] ?: 100)),
    ];
};

$ds = (string) $w['draft_status'];
$es = (string) $w['edits_status'];
$peek = ($ds === 'saved' || $es === 'saved') && $ds !== 'submitted' && $es !== 'submitted';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check() && !$peek) {
    $f = $fields();
    $writerId = (int) $w['writer_id'];
    if (isset($_POST['submit_edits'])) {
        $app->writ->submitReview($wid, $f);
        $app->notify->send($writerId, 'edited_writ', 'Your writ was reviewed', 'writ.php?w=' . $wid);
        $app->notify->toObserversOf($writerId, 'edited_writ', 'Writ reviewed', 'writ.php?w=' . $wid);
        $app->redirect('editor.php');
    } elseif (isset($_POST['submit_redraft'])) {
        $app->writ->sendRedraft($wid, $f);
        $app->notify->send($writerId, 'redraft_writ', 'Redraft requested — start from the editor version', 'writ.php?w=' . $wid);
        $app->redirect('editor.php');
    } elseif (isset($_POST['submit_scoring'])) {
        $app->writ->score($wid, $f);
        $app->notify->send($writerId, 'scored_writ', 'Your writ was scored', 'writ.php?w=' . $wid);
        $app->notify->toObserversOf($writerId, 'scored_writ', 'Writ scored', 'writ.php?w=' . $wid);
        $app->redirect('editor.php');
    }
}

$writer = $app->user->find((int) $w['writer_id']);

$app->view->start('Review', 'ewrits', 'editor');
echo '<p class="save-row">' . history_button($app->writ->hasHistory($w), 'history.php?w=' . $wid) . '</p>';
echo '<p class="sans">Writer: ' . h($writer['name'] ?? '') . '</p>';
echo writ_times($w);
if ($w['kind'] === 'test') {
    echo '<p class="sans">This is a test. Auto-score: ' . h((string) $w['test_auto_score']) . '/' . h((string) $w['outof']) . '</p>';
}
echo '<form id="editsform" method="post" onsubmit="offNavWarn();">' . $app->csrf->field();
echo '<input type="hidden" name="writ_id" value="' . $wid . '">';
echo '<input type="hidden" name="reviewed_writer_id" value="' . (int) $w['writer_id'] . '">';

if ($peek) {
    echo '<p class="sans notice">Not submitted yet. You can leave editor notes, not a revision.</p>';
    echo '<h3 class="lt">' . h($w['work']) . ' — ' . h($w['title']) . '</h3>';
    echo '<h4 class="review">Writer draft</h4><section class="writcontent draft">' . nl_text($w['draft']) . '</section>';
    echo '<p class="sans">Word count: ' . (int) $w['draft_wordcount'] . '</p>';
    if ((string) $w['notes'] !== '') {
        echo '<h4 class="review">Writer notes</h4><section class="writcontent notes">' . nl_text($w['notes']) . '</section>';
    }
    echo '<input type="hidden" name="peek" value="1">';
    echo '<p class="sans">Editor notes<br><textarea name="edit_notes" rows="4" cols="82" onchange="onNavWarn()">' . h($w['edit_notes']) . '</textarea></p>';
    echo '<p class="save-row"><button type="button" class="lt_button" title="Save (Ctrl + S)" onclick="pwAjaxForm(\'editsform\',\'ajax/save-review.php\',\'ajax_changes\');offNavWarn();">Save</button> ';
    echo '<span id="ajax_changes"></span></p>';
    echo '</form>';
    echo '<script>pwBindSave("editsform","ajax/save-review.php","ajax_changes");</script>';
    $app->view->end();
    exit;
}

echo '<p class="sans">Work <input name="work" value="' . h($w['work']) . '"> Title <input name="title" value="' . h($w['title']) . '"></p>';
echo '<h4 class="review">Writer draft</h4><section class="writcontent draft">' . nl_text($w['draft']) . '</section>';
echo '<p class="sans">Word count: ' . (int) $w['draft_wordcount'] . '</p>';
echo '<p class="save-row"><button type="button" class="lt_button" title="Save (Ctrl + S)" onclick="pwAjaxForm(\'editsform\',\'ajax/save-review.php\',\'ajax_changes\');offNavWarn();">Save</button> ';
echo '<span id="ajax_changes"></span></p>';
echo '<p class="sans">Editor revision</p>';
echo '<textarea name="edits" id="writingArea" class="writingBox" rows="12" cols="82" onchange="onNavWarn()">' . h($w['edits'] ?: $w['draft']) . '</textarea>';
echo '<p class="sans">Edit notes<br><textarea name="edit_notes" rows="4" cols="82">' . h($w['edit_notes']) . '</textarea></p>';
echo '<p class="sans">Scoring remarks<br><textarea name="scoring" rows="3" cols="82">' . h($w['scoring']) . '</textarea></p>';
echo '<p class="sans">Score <input name="score" type="number" min="0" max="1000" value="' . h((string) $w['score']) . '"> / <input name="outof" type="number" value="' . h((string) ($w['outof'] ?: 100)) . '"></p>';
echo '<p class="pw-confirm-row">' . confirm_writ('submit_edits', 'Submit edits', 'Confirm submit edits');
echo confirm_writ('submit_redraft', 'Redraft', 'Confirm redraft');
echo confirm_writ('submit_scoring', 'Submit score', 'Confirm score') . '</p>';
echo '<p class="sans">Notes<br><textarea name="notes" rows="3" cols="82">' . h($w['notes']) . '</textarea></p>';
echo '<input type="hidden" name="save_edit" value="1">';
echo '</form>';
echo comments_markup($app->writ->comments($wid), $wid, false, $app->auth->id(), $app->csrf->token());
echo '<script>pwBindSave("editsform","ajax/save-review.php","ajax_changes");</script>';
$app->view->end();
