<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'text', 'writ', 'test', 'notify'];
require __DIR__ . '/lib/boot.php';
$u = $app->auth->requireUser();
$wid = (int) ($_GET['w'] ?? $_POST['writ_id'] ?? 0);
$w = $app->writ->find($wid);
if (!$w || (int) $w['writer_id'] !== $app->auth->id() || $w['kind'] !== 'test') {
    $app->redirect('');
}
$t = $app->test->find((int) $w['test_id']);
$items = json_arr($t['parsed'] ?? '[]');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check() && isset($_POST['submit_test']) && $w['draft_status'] !== 'submitted') {
    $answers = $_POST['q'] ?? [];
    $g = $app->test->parser->grade($items, $answers);
    $outof = max(1, (int) $g['auto_possible']);
    $app->writ->saveTestAnswers($wid, $app->auth->id(), $answers, (int) $g['auto_got'], $outof);
    $app->notify->toEditorOf($app->auth->id(), 'new_writ', 'Test submitted: ' . $t['title'], 'review.php?w=' . $wid);
    $app->redirect('take-test.php?w=' . $wid);
}

$app->view->start('Test', 'writs');
echo '<h2 class="lt">' . h($t['title'] ?? 'Test') . '</h2>';
if ($w['draft_status'] === 'submitted') {
    echo '<p class="sans noticegreen">Submitted. Auto-score ' . (int) $w['test_auto_score'] . '/' . (int) $w['outof'] . ' (short answers graded by your editor).</p>';
    $app->view->end();
    exit;
}
echo '<form method="post">' . $app->csrf->field();
echo '<input type="hidden" name="writ_id" value="' . $wid . '">';
foreach ($items as $it) {
    if ($it['kind'] === 'I') {
        echo '<h3 class="lt">' . h($it['text']) . '</h3>';
        continue;
    }
    $n = (int) $it['n'];
    echo '<p class="sans"><b>' . $n . '.</b> ';
    if ($it['kind'] === 'TF') {
        echo h($it['statement'] ?: $it['q']) . '</p>';
        echo '<p><label><input type="radio" name="q[' . $n . ']" value="T"> True</label> ';
        echo '<label><input type="radio" name="q[' . $n . ']" value="F"> False</label></p>';
    } elseif ($it['kind'] === 'MC') {
        echo h($it['q']) . '</p>';
        $multi = $app->test->parser->multiChoice($it);
        foreach ($it['choices'] as $i => $c) {
            $nm = $multi ? 'q[' . $n . '][]' : 'q[' . $n . ']';
            $type = $multi ? 'checkbox' : 'radio';
            echo '<p><label><input type="' . $type . '" name="' . $nm . '" value="' . $i . '"> ' . h($c['text']) . '</label></p>';
        }
    } elseif ($it['kind'] === 'FI') {
        echo h(preg_replace('/___.+?___/u', '______', $it['q'])) . '</p>';
        echo '<p><input name="q[' . $n . ']" class="writingBox"></p>';
    } else {
        echo h($it['q']) . '</p>';
        if ($it['wr']) {
            echo '<p class="dk sans">Word range: ' . h($it['wr']) . '</p>';
        }
        echo '<p><textarea name="q[' . $n . ']" rows="6" cols="70" class="writingBox"></textarea></p>';
    }
}
echo '<p>' . confirm_writ('submit_test', 'Submit test', 'Confirm') . '</p></form>';
$app->view->end();
