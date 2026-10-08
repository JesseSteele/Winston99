<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'text', 'note', 'block', 'user', 'writ', 'notify'];
require __DIR__ . '/lib/boot.php';
$u = $app->auth->requireUser();
$uid = $app->auth->id();
$isEditor = $app->auth->atLeast('editor');
$assignWanted = isset($_GET['assign']) || isset($_POST['new_assignment']) || !empty($_POST['as_assignment']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['new_note']) || isset($_POST['new_assignment'])) && $app->csrf->check()) {
    $id = $app->note->create([
        'type' => $isEditor ? 'memo' : 'note',
        'writer_id' => $isEditor ? null : $uid,
        'editor_id' => $isEditor ? $uid : null,
        'body' => '',
    ]);
    $go = 'note.php?n=' . $id;
    if (isset($_POST['new_assignment']) || $assignWanted) {
        $go .= '&assign=1';
    }
    $app->redirect($go);
}

$nid = (int) ($_GET['n'] ?? $_GET['v'] ?? $_POST['note_id'] ?? 0);
$n = $nid ? $app->note->find($nid) : null;
if ($n && (int) $n['writer_id'] === $uid) {
    $app->note->markRead($nid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['copy_memo']) && $n && $isEditor && $app->csrf->check()) {
    $mid = $app->note->copyNoteToMemo($nid, $uid);
    $app->redirect('note.php?n=' . $mid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $n && $isEditor && $app->csrf->check() && isset($_POST['make_assignment'])) {
    $title = writ_title($_POST['title'] ?? '');
    $work = clean_title($_POST['work'] ?? '');
    $blockId = (int) ($_POST['editor_set_block'] ?? 0);
    $writerId = (int) ($_POST['editor_set_writer_id'] ?? 0);
    $app->note->saveBody($nid, clean_body($_POST['body'] ?? $n['body'] ?? ''), [
        'type' => ($_POST['type'] ?? '') === 'task' ? 'task' : 'memo',
        'editor_set_writer_id' => $writerId,
        'editor_set_block' => $blockId,
    ]);
    $n = $app->note->find($nid);
    $ids = [];
    if ($writerId > 0) {
        $ids[] = $writerId;
    } elseif ($blockId > 0) {
        foreach ($app->user->writersForEditor($uid) as $wr) {
            $blocks = json_arr($wr['blocks_json']);
            if (in_array($blockId, array_map('intval', $blocks), true)) {
                $ids[] = (int) $wr['id'];
            }
        }
    }
    $made = 0;
    foreach ($ids as $wid) {
        $writId = $app->writ->create([
            'writer_id' => $wid,
            'facility_id' => $app->auth->facilityId(),
            'block_id' => $blockId,
            'kind' => 'assignment',
            'memo_id' => $nid,
            'instructions' => $n['body'] ?? '',
            'title' => $title,
            'work' => $work !== '' ? $work : 'task-pending',
        ]);
        if ($work === '') {
            $app->writ->saveDraft($writId, $wid, [
                'title' => $title,
                'work' => writ_work('', $writId),
                'block_id' => $blockId,
                'notes' => '',
                'draft' => '',
                'draft_wordcount' => 0,
                'writing_time' => 0,
            ]);
        }
        $app->notify->send($wid, 'new_assignment', $title, 'writ.php?w=' . $writId, 'New assignment from your editor.');
        $made++;
    }
    $app->view->setFlash($made . ' assignment(s) created.');
    $app->redirect('assignments.php');
}

$dash = $isEditor ? 'editor' : 'writer';
$active = $isEditor ? 'memos' : 'notes';
$sess = (string) ($_SESSION['pw_dash'] ?? '');
if ($sess === 'observer') {
    $dash = 'observer';
    $active = 'memos';
} elseif ($sess === 'my') {
    $dash = 'my';
} elseif ($sess === 'admin') {
    $dash = 'admin';
    $active = 'blocks';
} elseif ($sess === 'super') {
    $dash = 'super';
}
$app->view->start($isEditor ? 'Memo' : 'Note', $active, $dash);
if ($isEditor) {
    echo '<p>' . post_button('New memo +', 'Create', 'note.php', 'new_note', '1', 'newNoteButton', $app->csrf->token()) . '</p>';
} else {
    echo '<p>' . post_button('New note +', 'Create', 'note.php', 'new_note', '1', 'newNoteButton', $app->csrf->token()) . '</p>';
}
if (!$n) {
    echo '<p class="sans">Open one from Memos, or create new.</p>';
    $app->view->end();
    exit;
}
$canEdit = ((int) $n['writer_id'] === $uid) || ((int) $n['editor_id'] === $uid) || $app->auth->atLeast('admin');
if (!$canEdit && $n['type'] !== 'memo') {
    $app->redirect('');
}

if ($n['type'] === 'note' && $isEditor) {
    echo '<p>' . post_button('Copy into a memo', 'Make a memo from this note', 'note.php?n=' . $nid, 'copy_memo', '1', 'set_gray', $app->csrf->token()) . '</p>';
}

$blocks = [];
$writers = [];
if ($isEditor) {
    $eid = $app->auth->is('editor') ? $uid : 0;
    $blocks = $eid ? $app->block->forEditor($eid, true) : $app->block->forFacility($app->auth->facilityId(), true);
    $writers = $eid ? $app->user->writersForEditor($eid) : $app->user->listByFacility($app->auth->facilityId(), 'writer');
}
$blockOpts = [];
foreach ($blocks as $b) {
    $blockOpts[(int) $b['id']] = $app->block->named($b);
}
$writerOpts = [];
foreach ($writers as $wr) {
    $writerOpts[(int) $wr['id']] = $wr['name'] . ' (' . $wr['username'] . ')';
}

$checked = $assignWanted ? ' checked' : '';
echo '<form id="editform" method="post" data-browser-kind="note" data-browser-id="' . (int) $nid . '">' . $app->csrf->field();
echo '<input type="hidden" name="note_id" value="' . $nid . '">';
echo '<input type="hidden" name="user_id" value="' . $uid . '">';
if ($isEditor && $canEdit) {
    echo '<p class="field sans"><label for="type">Type</label>';
    echo form_select('type', ['memo' => 'Memo', 'task' => 'Task'], $n['type'] === 'task' ? 'task' : 'memo', '', 'formselect small') . '</p>';
    echo '<p class="field sans"><label for="editor_set_block">Block</label>';
    echo form_select('editor_set_block', $blockOpts, (int) $n['editor_set_block'], 'Main', 'formselect') . '</p>';
    echo '<p class="field sans"><label for="editor_set_writer_id">Writer <small class="dk">(overrides Block)</small></label>';
    echo form_select('editor_set_writer_id', $writerOpts, (int) $n['editor_set_writer_id'], 'None — use the block', 'formselect') . '</p>';
    echo '<p class="field sans"><label><input type="checkbox" name="as_assignment" value="1" id="as_assignment"' . $checked . '> Make this an assignment</label></p>';
    echo '<div id="assign-fields">';
    echo '<p class="field sans"><label for="work">Work</label><input name="work" id="work" maxlength="122" placeholder="task-"></p>';
    echo '<p class="field sans"><label for="title">Title</label><input name="title" id="title" maxlength="122" placeholder="Untitled"></p>';
    echo '</div>';
}
if ($canEdit) {
    echo '<p class="save-row"><button type="button" class="lt_button small" title="Save (Ctrl + S)" onclick="pwAjaxForm(\'editform\',\'ajax/save-note.php\',\'ajax_changes\');offNavWarn();">Save</button> ';
    echo '<span id="ajax_changes"></span></p>';
    if ($isEditor) {
        echo '<p class="save-row"><button type="submit" name="make_assignment" class="lt_button" value="1">Save and assign</button></p>';
    }
}
echo '<p><textarea name="body" id="writingArea" class="writingBox" rows="16" cols="82" onchange="onNavWarn()"' . ($canEdit ? '' : ' readonly') . '>' . h($n['body']) . '</textarea></p>';
echo '</form>';
if ($canEdit) {
    echo '<script>pwBindSave("editform","ajax/save-note.php","ajax_changes");</script>';
}
$app->view->end();
