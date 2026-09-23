<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'text', 'writ', 'block', 'user', 'notify'];
require __DIR__ . '/lib/boot.php';
$u = $app->auth->requireUser();
$uid = $app->auth->id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_writ']) && $app->csrf->check()) {
    $id = $app->writ->create([
        'writer_id' => $uid,
        'facility_id' => $app->auth->facilityId(),
        'title' => '',
        'work' => '',
    ]);
    $app->redirect('writ.php?w=' . $id);
}

$wid = (int) ($_GET['w'] ?? $_POST['writ_id'] ?? 0);
$w = $wid ? $app->writ->find($wid) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $w && $app->csrf->check() && isset($_POST['submit_draft'])) {
    $app->writ->saveDraft($wid, $uid, [
        'title' => writ_title($_POST['title'] ?? ''),
        'work' => writ_work($_POST['work'] ?? '', $wid),
        'block_id' => (int) ($_POST['block'] ?? 0),
        'notes' => clean_body($_POST['notes'] ?? ''),
        'draft' => clean_body($_POST['draft'] ?? ''),
        'draft_wordcount' => wordcount($_POST['draft'] ?? ''),
        'writing_time' => (int) ($_POST['writing_time'] ?? 0),
    ]);
    $app->writ->submitDraft($wid, $uid);
    $app->notify->toEditorOf($uid, 'new_writ', 'Writ submitted: ' . writ_title($_POST['title'] ?? ''), 'review.php?w=' . $wid);
    $app->notify->toObserversOf($uid, 'new_writ', 'Writ submitted: ' . writ_title($_POST['title'] ?? ''), 'writ.php?w=' . $wid);
    $app->redirect('writ.php?w=' . $wid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $w && $app->csrf->check() && isset($_POST['submit_correction'])) {
    $app->writ->saveCorrection($wid, $uid, [
        'notes' => clean_body($_POST['notes'] ?? ''),
        'correction' => clean_body($_POST['correction'] ?? ''),
        'correction_wordcount' => wordcount($_POST['correction'] ?? ''),
    ]);
    $app->writ->submitCorrection($wid, $uid);
    $app->notify->toEditorOf($uid, 'edited_writ', 'Correction submitted', 'review.php?w=' . $wid);
    $app->redirect('writ.php?w=' . $wid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $w && $app->csrf->check() && isset($_POST['new_comment'])) {
    $body = clean_body($_POST['comment_body'] ?? '');
    if ($body !== '' && $app->auth->atLeast('observer')) {
        $app->writ->addComment($wid, $uid, $body);
    }
    $app->redirect('writ.php?w=' . $wid);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $w && $app->csrf->check() && isset($_POST['save_comment'])) {
    $app->writ->saveComment((int) ($_POST['comment_id'] ?? 0), $uid, clean_body($_POST['comment_body'] ?? ''));
    $app->redirect('writ.php?w=' . $wid);
}

if ($wid && $w && (int) $w['writer_id'] === $uid) {
    $app->writ->markViewed($wid, $uid);
}

$sess = (string) ($_SESSION['pw_dash'] ?? '');
$active = match ($sess) {
    'observer' => 'owrits',
    'editor' => 'ewrits',
    'my' => 'dash',
    'admin' => 'blocks',
    'super' => 'facilities',
    default => 'writs',
};
$app->view->start('Writ', $active, 'auto');
if ($sess !== 'observer' && $sess !== 'editor' && $sess !== 'admin' && $sess !== 'super' && !$app->auth->is('observer')) {
    echo '<p>' . post_button('New writ +', 'Start writing something new', 'writ.php', 'new_writ', (string) $uid, 'newNoteButton', $app->csrf->token()) . '</p>';
}

if (!$w) {
    echo '<p class="sans">Open a writ from your list, or start a new one.</p>';
    $app->view->end();
    exit;
}
if ((int) $w['writer_id'] !== $uid && !$app->auth->atLeast('observer')) {
    $app->redirect('');
}
$owner = (int) $w['writer_id'] === $uid;
$canComment = !$owner && $app->auth->atLeast('observer') && ($sess === 'observer' || $app->auth->is('observer'));

if ($w['kind'] === 'test' && $owner) {
    $app->redirect('take-test.php?w=' . $wid);
}

if ($owner && $w['draft_status'] === 'submitted') {
    echo '<p class="sans noticegreen">Submitted and waiting for review.</p>';
    echo writ_times($w);
    echo comments_markup($app->writ->comments($wid), $wid, $canComment, $uid, $app->csrf->token());
    $app->view->end();
    exit;
}
if ($owner && $w['edits_status'] === 'submitted') {
    echo '<p class="sans noticegreen">Correction submitted and waiting to be scored.</p>';
    echo writ_times($w);
    echo comments_markup($app->writ->comments($wid), $wid, $canComment, $uid, $app->csrf->token());
    $app->view->end();
    exit;
}
if ($w['draft_status'] === 'reviewed' && $w['edits_status'] === 'scored') {
    echo '<h3 class="lt">' . h($w['work']) . ' — ' . h($w['title']) . '</h3>';
    echo '<h4 class="lt">Score: ' . h((string) $w['score']) . '<small class="dk">/' . h((string) $w['outof']) . '</small></h4>';
    echo writ_times($w);
    echo '<h4 class="review">Scoring</h4><section class="writcontent remarks">' . nl_text($w['scoring']) . '</section>';
    echo '<h4 class="review">Draft</h4><section class="writcontent draft">' . nl_text($w['draft']) . '</section>';
    echo '<h4 class="review">Editor revision</h4><section class="writcontent revision">' . nl_text($w['edits']) . '</section>';
    echo '<h4 class="review">Correction</h4><section class="writcontent correction">' . nl_text($w['correction']) . '</section>';
    echo '<p class="sans">Notes</p><section class="writcontent notes">' . nl_text($w['notes']) . '</section>';
    echo comments_markup($app->writ->comments($wid), $wid, $canComment, $uid, $app->csrf->token());
    echo '<p>' . history_button($app->writ->hasHistory($w), 'history.php?w=' . $wid) . '</p>';
    $app->view->end();
    exit;
}

$reviewed = $w['draft_status'] === 'reviewed';
$redraft = $w['draft_status'] === 'redraft';
$blocks = [];
if ($u['type'] === 'writer') {
    $thisEditor = (int) ($u['editor_id'] ?? 0);
    $blocks = $thisEditor ? $app->block->forEditor($thisEditor, true) : [];
}

if (!$owner) {
    echo '<h3 class="lt">' . h($w['work']) . ' — ' . h($w['title']) . '</h3>';
    echo writ_times($w);
    if ($w['instructions']) {
        echo '<h4 class="review">Instructions</h4><section class="writcontent remarks">' . nl_text($w['instructions']) . '</section>';
    }
    echo '<h4 class="review">Draft</h4><section class="writcontent draft">' . nl_text($w['draft']) . '</section>';
    if ($w['edits']) {
        echo '<h4 class="review">Editor revision</h4><section class="writcontent revision">' . nl_text($w['edits']) . '</section>';
    }
    if ($w['correction']) {
        echo '<h4 class="review">Correction</h4><section class="writcontent correction">' . nl_text($w['correction']) . '</section>';
    }
    echo '<p class="sans">Notes</p><section class="writcontent notes">' . nl_text($w['notes']) . '</section>';
    echo comments_markup($app->writ->comments($wid), $wid, $canComment, $uid, $app->csrf->token());
    echo '<p>' . history_button($app->writ->hasHistory($w), 'history.php?w=' . $wid) . '</p>';
    $app->view->end();
    exit;
}

echo '<form id="editform" method="post" onsubmit="offNavWarn();">' . $app->csrf->field();
echo '<input type="hidden" name="writ_id" value="' . (int) $wid . '">';
echo '<input type="hidden" name="user_form" value="' . (int) $uid . '">';
echo '<p class="sans"><label>Block: <select class="formselect small" name="block" id="block" onchange="onNavWarn()">';
echo '<option value="0">Main</option>';
foreach ($blocks as $b) {
    $sel = ((int) $w['block_id'] === (int) $b['id']) ? ' selected' : '';
    echo '<option value="' . (int) $b['id'] . '"' . $sel . '>' . h($app->block->named($b)) . '</option>';
}
echo '</select></label></p>';
$workShow = (string) $w['work'];
if ($workShow === '' || $workShow === 'task-' . $wid) {
    $workShow = '';
}
echo '<p class="sans">Work<br><input name="work" id="work" class="readBox" maxlength="122" value="' . h($workShow) . '" placeholder="task-' . (int) $wid . '" onchange="onNavWarn()"></p>';
$titleShow = ((string) $w['title'] === 'Untitled' || (string) $w['title'] === '') ? '' : (string) $w['title'];
echo '<p class="sans">Title<br><input name="title" id="title" class="writingBox" maxlength="122" value="' . h($titleShow) . '" placeholder="Untitled" onchange="onNavWarn()"></p>';
if ($w['instructions']) {
    echo '<h4 class="review">Instructions</h4><section class="writcontent remarks">' . nl_text($w['instructions']) . '</section>';
}
if ($redraft && $w['edit_notes']) {
    echo '<h4 class="review">Redraft remarks</h4><section class="writcontent remarks">' . nl_text($w['edit_notes']) . '</section>';
}
echo '<p class="save-row">' . history_button($app->writ->hasHistory($w), 'history.php?w=' . $wid) . '</p>';
echo writ_times($w);
echo '<p class="save-row"><button type="button" class="lt_button" title="Save (Ctrl + S)" onclick="pwAjaxForm(\'editform\',\'ajax/save-writ.php\',\'ajax_changes\');offNavWarn();">Save</button> ';
echo '<span id="wordCount" class="wordCounter"></span> <span id="ajax_changes"></span></p>';

if ($reviewed) {
    echo '<h4 class="review">Editor revision</h4><section class="writcontent revision">' . nl_text($w['edits']) . '</section>';
    echo '<h4 class="review">Remarks</h4><section class="writcontent remarks">' . nl_text($w['edit_notes']) . '</section>';
    echo '<p class="sans">Your correction</p>';
    echo '<textarea name="correction" id="writingArea" class="writingBox" rows="12" cols="82" spellcheck="false" onchange="onNavWarn()">' . h($w['correction']) . '</textarea>';
    echo '<input type="hidden" name="correction_wordcount" id="wordCountInput" value="0">';
    echo '<p>' . confirm_writ('submit_correction', 'Submit final correction', 'Confirm') . '</p>';
} else {
    echo '<textarea name="draft" id="writingArea" class="writingBox" rows="12" cols="82" spellcheck="false" autocapitalize="none" onchange="onNavWarn()" placeholder="Draft contents...">' . h($w['draft']) . '</textarea>';
    echo '<input type="hidden" name="draft_wordcount" id="wordCountInput" value="0">';
    echo '<input type="hidden" name="save_draft" value="1">';
    echo '<p>' . confirm_writ('submit_draft', 'Submit draft', 'Confirm') . '</p>';
}
echo '<p class="sans">Notes<br><textarea name="notes" rows="4" cols="82" onchange="onNavWarn()">' . h($w['notes']) . '</textarea></p>';
echo '</form>';
echo comments_markup($app->writ->comments($wid), $wid, false, $uid, $app->csrf->token());
echo '<script>pwWord("writingArea","wordCount","wordCountInput");pwNoPaste("writingArea");pwBindSave("editform","ajax/save-writ.php","ajax_changes");</script>';
$app->view->end();
