<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'text', 'note', 'writ', 'user', 'block', 'notify', 'form'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->atLeast('editor')) {
    $app->redirect('');
}
$uid = $app->auth->id();
$mid = (int) ($_GET['m'] ?? $_POST['m'] ?? 0);
$form = new Form();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_assignment']) && $app->csrf->check()) {
    $id = $app->note->create([
        'type' => 'memo',
        'editor_id' => $uid,
        'body' => '',
    ]);
    $app->redirect('note.php?n=' . $id . '&assign=1');
}

$memo = $mid ? $app->note->find($mid) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check() && isset($_POST['create_assignment'])) {
    $form->grab($_POST, 'm', 'work', 'title', 'block', 'writer_id');
    $mid = (int) $form->get('m');
    $memo = $mid ? $app->note->find($mid) : null;
    if (!$memo) {
        $form->fail('m', 'Choose a memo.');
    }
    if ($form->ok()) {
        $title = writ_title($form->get('title'));
        $work = clean_title($form->get('work'));
        $blockId = (int) $form->get('block');
        $writerId = (int) $form->get('writer_id');
        $ids = [];
        if ($writerId > 0) {
            $ids[] = $writerId;
        } elseif ($blockId > 0) {
            $eid = $app->auth->is('editor') ? $uid : 0;
            $pool = $eid ? $app->user->writersForEditor($eid) : $app->user->listByFacility($app->auth->facilityId(), 'writer');
            foreach ($pool as $wr) {
                $blocks = json_arr($wr['blocks_json']);
                if (in_array($blockId, array_map('intval', $blocks), true)) {
                    $ids[] = (int) $wr['id'];
                }
            }
        }
        foreach ($ids as $wid) {
            $writId = $app->writ->create([
                'writer_id' => $wid,
                'facility_id' => $app->auth->facilityId(),
                'block_id' => $blockId,
                'kind' => 'assignment',
                'memo_id' => $mid,
                'instructions' => $memo['body'],
                'title' => $title,
                'work' => $work !== '' ? $work : 'task-pending',
            ]);
            if ($work === '') {
                $app->db->run('UPDATE writs SET work = ? WHERE id = ?', ['task-' . $writId, $writId]);
            }
            $app->notify->send($wid, 'new_assignment', $title, 'writ.php?w=' . $writId, 'New assignment from your editor.');
        }
        $app->view->setFlash(count($ids) . ' assignment(s) created.');
        $app->redirect('assignments.php');
    }
}

$eid = $app->auth->is('editor') ? $uid : 0;
$memos = $app->note->labeledForEditor($eid ?: $uid);
$blocks = $eid ? $app->block->forEditor($eid, true) : $app->block->forFacility($app->auth->facilityId(), true);
$writers = $eid ? $app->user->writersForEditor($eid) : $app->user->listByFacility($app->auth->facilityId(), 'writer');
$blockOpts = [];
foreach ($blocks as $b) {
    $blockOpts[(int) $b['id']] = $app->block->named($b);
}
$writerOpts = [];
foreach ($writers as $wr) {
    $writerOpts[(int) $wr['id']] = $wr['name'] . ' (' . $wr['username'] . ')';
}

$app->view->start('New assignment', 'assign', 'editor');
echo '<h2 class="lt">New assignment</h2>';
echo '<p class="sans dk">An assignment is a writ with a memo attached as instructions. Same form as a new memo, with “make this an assignment” already on.</p>';
echo '<p>' . post_button('New assignment +', 'Compose a memo and assign it', 'assignment.php', 'new_assignment', '1', 'newNoteButton', $app->csrf->token()) . '</p>';
if ($form->get('m') === '' && $mid) {
    $form->put('m', (string) $mid);
}
echo '<form method="post">' . $app->csrf->field();
echo '<p class="field sans"><label for="m">Memo</label>' . $form->select('m', $memos, 'Choose a memo…') . '</p>';
if ($memo) {
    echo '<section class="writcontent remarks">' . nl_text($memo['body']) . '</section>';
}
echo '<p class="field sans"><label for="work">Work</label>' . $form->input('work', 'text', 'maxlength="122" placeholder="task-"') . '</p>';
echo '<p class="field sans"><label for="title">Title</label>' . $form->input('title', 'text', 'maxlength="122" placeholder="Untitled"') . '</p>';
echo '<p class="field sans"><label for="block">Block</label>' . $form->select('block', $blockOpts, 'Main') . '</p>';
echo '<p class="field sans"><label for="writer_id">Writer <small class="dk">(overrides Block)</small></label>';
echo $form->select('writer_id', $writerOpts, 'All writers in the block') . '</p>';
echo '<p><input type="submit" name="create_assignment" class="lt_button" value="Create assignment"></p></form>';
$app->view->end();
