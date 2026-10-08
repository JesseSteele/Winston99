<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'writ', 'note', 'user', 'block', 'writlist', 'notify'];
require __DIR__ . '/lib/boot.php';

if (!$app->auth->user()) {
    $app->redirect('login.php');
}
$u = $app->auth->user();
$uid = $app->auth->id();
$type = $u['type'];
$app->view->start('My Dash for ' . $u['name'], 'dash', 'my');
echo '<p class="sans dk">Open work, new notes, and notices — the rest lives on your other dashes.</p>';

$sort = preg_replace('/[^a-z]/', '', (string) ($_GET['s'] ?? 'activity')) ?: 'activity';
if (!in_array($sort, ['activity', 'creation', 'work', 'title', 'status'], true)) {
    $sort = 'activity';
}

try {
    if ($type === 'superintendent') {
        $pins = $app->note->pinnedFor($uid, 25);
        dash_notes_table($app, $pins, false);
        $app->view->end();
        exit;
    }

    if ($type === 'observer') {
        observer_my_dash($app, $u, $uid, $sort);
        $app->view->end();
        exit;
    }

    $isEditor = $app->auth->atLeast('editor');
    if ($isEditor) {
        echo '<p class="dash-actions">' . post_button('New memo +', 'Write a memo', 'note.php', 'new_note', '1', 'newNoteButton', $app->csrf->token()) . '</p>';
        echo '<p class="dash-actions">' . post_button('New test +', 'Compose a test', 'test.php', 'new_test', '1', 'newNoteButton', $app->csrf->token()) . '</p>';
    } else {
        echo '<p class="dash-actions">' . post_button('New note +', 'Start a new note', 'note.php', 'new_note', (string) $uid, 'newNoteButton', $app->csrf->token()) . '</p>';
        echo '<p class="dash-actions">' . post_button('New writ +', 'Start writing something new', 'writ.php', 'new_writ', (string) $uid, 'set_gray', $app->csrf->token()) . '</p>';
    }

    $app->writlist->dashSortBar('index.php');

    $pins = $app->note->pinnedFor($uid, 25);
    if ($pins) {
        echo '<h3 class="lt">Pinned notes</h3>';
        dash_notes_table($app, $pins, true);
        if (count($pins) >= 25) {
            echo '<p class="see-all">' . button('See all', 'Notes', $isEditor ? 'memos-editor.php' : 'notes.php', 'editNoteButton') . '</p>';
        }
    }

    if (!$isEditor) {
        $memos = $app->note->unopenedMemos($uid, $app->user->blocksOf($u), 25);
        if ($memos) {
            echo '<h3 class="lt">Unopened memos</h3>';
            dash_memo_table($app, $memos);
            echo '<p class="see-all">' . button('See all', 'Memos', 'memos.php', 'editNoteButton') . '</p>';
        }
    }

    if ($isEditor) {
        $eid = $app->auth->is('editor') ? $uid : 0;
        $where = ["w.review_status = 'current'", "(w.draft_status = 'submitted' OR w.edits_status = 'submitted')"];
        $params = [];
        if ($eid) {
            $col = $app->db->columnExists('users', 'editor_id') ? 'editor_id' : 'editor';
            $where[] = "u.{$col} = ?";
            $params[] = $eid;
        } elseif ($app->auth->facilityId()) {
            $where[] = 'w.facility_id = ?';
            $params[] = $app->auth->facilityId();
        }
        [$rows, $more] = $app->writ->dashList($where, $params, $sort, 25);
        echo '<h3 class="lt">Needs editor action</h3>';
        dash_writ_table($app, $rows, 'editor');
        if ($more) {
            echo '<p class="see-all">' . button('See all', 'Editor writs', 'writs-editor.php', 'editNoteButton') . '</p>';
        }
    } else {
        $where = [
            'w.writer_id = ?',
            "w.term_status = 'current'",
            "(w.draft_status IN ('saved','redraft','reviewed') OR w.edits_status = 'scored')",
        ];
        [$rows, $more] = $app->writ->dashList($where, [$uid], $sort, 25);
        echo '<h3 class="lt">Open writs</h3>';
        dash_writ_table($app, $rows, 'writer');
        if ($more) {
            echo '<p class="see-all">' . button('See all', 'All writs', 'writs.php', 'editNoteButton') . '</p>';
        }
    }

    dash_notices($app, $uid);
} catch (Throwable $e) {
    echo '<p class="sans noticered">Dashboard failed: ' . h($e->getMessage()) . '</p>';
}
$app->view->end();

function observer_my_dash(App $app, array $u, int $uid, string $sort): void
{
    $ids = $app->user->observeeIds($u);
    $app->writlist->dashSortBar('index.php');
    if (!$ids) {
        echo empty_list();
        dash_notices($app, $uid);
        return;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $where = [
        "w.writer_id IN ({$in})",
        "w.term_status = 'current'",
        "(w.draft_status IN ('saved','redraft','reviewed','submitted') OR w.edits_status IN ('submitted','scored'))",
    ];
    [$rows, $more] = $app->writ->dashList($where, $ids, $sort, 25);
    echo '<h3 class="lt">Observed activity</h3>';
    dash_writ_table($app, $rows, 'observer');
    if ($more) {
        echo '<p class="see-all">' . button('See all', 'Observed writs', 'writs-observer.php', 'editNoteButton') . '</p>';
    }
    dash_notices($app, $uid);
}

function dash_notes_table(App $app, array $pins, bool $unpin): void
{
    if (!$pins) {
        echo empty_list();
        return;
    }
    $cc = 'lr';
    echo '<table class="list lt notes sans">';
    foreach ($pins as $n) {
        $nid = (int) $n['id'];
        echo '<tr class="' . $cc . '">';
        echo '<td><a class="listed_note" href="note.php?n=' . $nid . '">' . h(note_heading($n['body'] ?? '')) . '</a></td>';
        echo '<td><i class="listed_note">' . h((string) $n['save_date']) . '</i></td><td>';
        echo get_switch('Read', 'Read this note', 'note.php', 'n', (string) $nid, 'act_blue editNoteButton');
        if ($unpin) {
            echo ' ' . post_button('Unpin', 'Unpin from Dashboard', 'note-act.php', 'undash', (string) $nid, 'editNoteButton', $app->csrf->token());
        }
        echo '</td></tr>';
        $cc = $cc === 'lr' ? 'dr' : 'lr';
    }
    echo '</table>';
}

function dash_memo_table(App $app, array $memos): void
{
    $cc = 'lr';
    echo '<table class="list lt notes sans"><tbody>';
    foreach ($memos as $n) {
        $nid = (int) $n['id'];
        echo '<tr class="' . $cc . '"><td><a class="listed_note" href="note.php?n=' . $nid . '">' . h(note_heading($n['body'] ?? '')) . '</a></td>';
        echo '<td><i class="listed_note">' . h((string) $n['save_date']) . '</i></td><td>';
        echo get_switch('Read', 'Read', 'note.php', 'n', (string) $nid, 'act_blue editNoteButton');
        echo '</td></tr>';
        $cc = $cc === 'lr' ? 'dr' : 'lr';
    }
    echo '</tbody></table>';
}

function dash_writ_table(App $app, array $rows, string $mode): void
{
    if (!$rows) {
        echo empty_list();
        return;
    }
    echo browser_save_mount('writ');
    echo '<table class="list writ lt sans"><tbody><tr><th></th><th>Work</th><th>Title</th><th>Status</th>';
    if ($mode !== 'writer') {
        echo '<th>Writer</th>';
    }
    echo '</tr>';
    $cc = 'lr';
    foreach ($rows as $w) {
        $id = (int) $w['id'];
        echo '<tr class="' . $cc . '"><td>';
        if ($mode === 'editor') {
            echo get_switch('Review', 'Open', 'review.php', 'w', (string) $id, 'set_writ_orange');
        } elseif ($mode === 'observer') {
            echo get_switch('Open', 'Read', 'writ.php', 'w', (string) $id, 'set_writ_blue');
        } else {
            echo get_switch('Open', 'Open', 'writ.php', 'w', (string) $id, 'set_writ_orange');
        }
        echo '</td><td>' . h((string) $w['work']) . '</td><td>' . h((string) $w['title']) . '</td>';
        echo '<td>' . h((string) $w['draft_status']) . '</td>';
        if ($mode !== 'writer') {
            echo '<td>' . h((string) ($w['writer_name'] ?? '')) . '</td>';
        }
        echo '</tr>';
        $cc = $cc === 'lr' ? 'dr' : 'lr';
    }
    echo '</tbody></table>';
}

function dash_notices(App $app, int $uid): void
{
    $rows = $app->notify->list($uid);
    echo '<h3 class="lt">Notifications</h3>';
    if (!$rows) {
        echo empty_list();
        return;
    }
    $i = 0;
    foreach ($rows as $n) {
        if ($i >= 25) {
            echo '<p class="see-all">' . button('See all', 'Notifications', 'notifications.php', 'editNoteButton') . '</p>';
            break;
        }
        echo '<p class="sans"><b>' . h($n['title']) . '</b> <small class="dk">' . h((string) $n['created_at']) . '</small>';
        if ($n['link']) {
            echo ' ' . button('View', 'Open', $n['link'], 'editNoteButton');
        }
        echo '</p>';
        $i++;
    }
}
