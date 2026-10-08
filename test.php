<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'text', 'test', 'block', 'user', 'notify'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->atLeast('editor')) {
    $app->redirect('');
}
$uid = $app->auth->id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_test']) && $app->csrf->check()) {
    $r = $app->test->save($uid, null, 'Untitled test', "I: Read each question carefully and answer\n\n1) MC\nQ: What is the capital of Illinois?\n[x] Mexico\n[x] Chicago\n[v] Springfield\n[x] Detroit\n[x] Not listed\n", 0, $app->auth->facilityId());
    $app->redirect('test.php?t=' . (int) $r['id']);
}

$tid = (int) ($_GET['t'] ?? $_POST['test_id'] ?? 0);
$t = $tid ? $app->test->find($tid) : null;
$source = $t['source'] ?? "I: Read each question carefully and answer\n\n1) MC\nQ: What is the capital of Illinois?\n[x] Mexico\n[x] Chicago\n[v] Springfield\n[x] Detroit\n[x] Not listed\n\n2) FI\nQ: Puerto Rico is an American ___commonwealth |& unincorporated territory___.\n\n3) SA\nQ: In 50-100 words, explain why waxing cars is a good idea.\nWR: 50-100\n\nI: II. Section 2: True & False\n\n4) TF\nF: Pixels render in Red, Yellow, and Blue.\n\n5) TF\nT: SpaceX was founded by Elon Musk.\n";
$title = $t['title'] ?? 'Untitled test';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish']) && $tid && $app->csrf->check()) {
    $app->test->publish($tid, $uid);
    $blockId = (int) ($_POST['block'] ?? 0);
    $writers = $app->user->writersForEditor($uid);
    $ids = [];
    foreach ($writers as $wr) {
        if ($blockId) {
            $blocks = array_map('intval', json_arr($wr['blocks_json']));
            if (!in_array($blockId, $blocks, true)) {
                continue;
            }
        }
        $ids[] = (int) $wr['id'];
    }
    $writIds = $app->test->assignToWriters($tid, $ids, $app->auth->facilityId(), $blockId);
    foreach ($ids as $i => $wid) {
        $app->notify->send($wid, 'new_test', $title, 'take-test.php?w=' . ($writIds[$i] ?? ''), 'A test is ready.');
    }
    $app->view->setFlash('Published to ' . count($ids) . ' writer(s).');
    $app->redirect('tests.php');
}

$eid = $app->auth->is('editor') ? $uid : 0;
$blocks = $eid ? $app->block->forEditor($eid, true) : $app->block->forFacility($app->auth->facilityId(), true);
$blockOpts = [];
foreach ($blocks as $b) {
    $blockOpts[(int) $b['id']] = $app->block->named($b);
}

$app->view->start('Test', 'tests', 'editor');
echo '<h2 class="lt">Compose test</h2>';
echo '<p>' . post_button('New test +', 'Start another test', 'test.php', 'new_test', '1', 'newNoteButton', $app->csrf->token()) . '</p>';
echo '<p class="sans dk"><code>I:</code> heading · <code>1) MC|FI|SA|TF</code> · <code>[v]</code>/<code>[x]</code> · fill-in <code>___a || b___</code> (OR) or <code>___a |& b___</code> (AND/OR) · <code>T:</code>/<code>F:</code> is the key. Numbers resequence on save.</p>';
echo '<form id="testform" data-browser-kind="test" data-browser-id="' . (int) $tid . '">' . $app->csrf->field();
echo '<input type="hidden" name="test_id" value="' . (int) $tid . '">';
echo '<p class="field sans"><label for="title">Title</label><input name="title" id="title" value="' . h($title) . '"></p>';
echo '<p class="field sans"><label for="block">Block</label>';
echo form_select('block', $blockOpts, (int) ($t['block_id'] ?? 0), 'Main', 'formselect') . '</p>';
echo '<button type="button" class="lt_button" title="Save (Ctrl + S)" onclick="pwAjaxForm(\'testform\',\'ajax/save-test.php\',\'ajax_changes\');offNavWarn();">Save</button> ';
echo '<span id="ajax_changes"></span>';
echo '<p><textarea name="source" id="writingArea" class="writingBox" rows="22" cols="82" onchange="onNavWarn()">' . h($source) . '</textarea></p>';
echo '</form>';
if ($tid) {
    echo '<form method="post">' . $app->csrf->field();
    echo '<input type="hidden" name="test_id" value="' . $tid . '">';
    echo '<p class="field sans"><label for="pub_block">Publish to block</label>';
    echo form_select('block', $blockOpts, (int) ($t['block_id'] ?? 0), 'All my writers', 'formselect') . '</p>';
    echo '<p><input type="submit" name="publish" class="lt_button" value="Publish to writers"></p></form>';
}
echo '<script>pwBindSave("testform","ajax/save-test.php","ajax_changes");</script>';
$app->view->end();
