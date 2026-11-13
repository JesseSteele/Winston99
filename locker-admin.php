<?php
declare(strict_types=1);
$import = ['auth', 'view', 'html', 'user', 'csrf'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->atLeast('supervisor')) {
    $app->redirect('locker.php');
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check() && isset($_POST['keep_id'], $_POST['merge_id'])) {
    $keep = (int) $_POST['keep_id'];
    $merge = (int) $_POST['merge_id'];
    if ($keep > 0 && $merge > 0 && $keep !== $merge) {
        $keepU = $app->user->find($keep);
        $mergeU = $app->user->find($merge);
        if ($keepU && $mergeU) {
            $db = $app->db;
            // Conflict check: at most one of each auth method across both
            $oauthKeep = $db->all('SELECT provider FROM oauth_identities WHERE user_id = ?', [$keep]);
            $oauthMerge = $db->all('SELECT provider FROM oauth_identities WHERE user_id = ?', [$merge]);
            $pkKeep = $db->val('SELECT COUNT(*) FROM passkeys WHERE user_id = ?', [$keep]) ?? 0;
            $pkMerge = $db->val('SELECT COUNT(*) FROM passkeys WHERE user_id = ?', [$merge]) ?? 0;
            $totpKeep = !empty($keepU['totp_enabled']);
            $totpMerge = !empty($mergeU['totp_enabled']);
            $conflict = false;
            foreach (['google', 'github'] as $prov) {
                $k = false; $m = false;
                foreach ($oauthKeep as $r) if ($r['provider'] === $prov) $k = true;
                foreach ($oauthMerge as $r) if ($r['provider'] === $prov) $m = true;
                if ($k && $m) $conflict = true;
            }
            if ($pkKeep && $pkMerge) $conflict = true;
            if ($totpKeep && $totpMerge) $conflict = true;
            if ($conflict) {
                $msg = '<p class="sans noticered">Cannot merge: both accounts have the same login method (Google, GitHub, Passkey, or Authenticator).</p>';
            } else {
                // Move content and credentials
                $db->run('UPDATE notes SET writer_id = ? WHERE writer_id = ?', [$keep, $merge]);
                $db->run('UPDATE notes SET editor_id = ? WHERE editor_id = ?', [$keep, $merge]);
                $db->run('UPDATE writs SET writer_id = ? WHERE writer_id = ?', [$keep, $merge]);
                $db->run('UPDATE oauth_identities SET user_id = ? WHERE user_id = ?', [$keep, $merge]);
                $db->run('UPDATE passkeys SET user_id = ? WHERE user_id = ?', [$keep, $merge]);
                if ($totpMerge && !$totpKeep) {
                    $db->run('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?', [$mergeU['totp_secret'] ?? null, $keep]);
                }
                $db->run('DELETE FROM users WHERE id = ?', [$merge]);
                $msg = '<p class="sans noticegreen">Accounts merged. Content and unique credentials moved to the kept account.</p>';
            }
        } else {
            $msg = '<p class="sans noticered">Invalid accounts.</p>';
        }
    }
}

$users = $app->user->listByFacility($app->auth->facilityId());
$opts = [];
foreach ($users as $u) {
    $opts[$u['id']] = $u['username'] . ' (' . $u['name'] . ')';
}

$app->view->start('Admin Locker', 'locker', 'admin');
echo '<h2 class="lt">Admin Locker</h2>';
echo $msg;
echo '<p>' . button('Failed logins', 'Clickathon', 'login-fails.php', 'set_gray') . '</p>';
echo '<h3 class="lt">Merge accounts</h3>';
echo '<form method="post" id="mergeform">' . $app->csrf->field();
echo '<p class="sans">Keep as main account</p>';
echo form_select('keep_id', $opts, 0, 'Choose account…', 'formselect', 'id="keep_id"');
echo '<p class="sans" id="merge_label" hidden>Merge into main</p>';
echo '<div id="merge_wrap" hidden>';
echo form_select('merge_id', $opts, 0, 'Choose account…', 'formselect', 'id="merge_id"');
echo '</div>';
echo '<p><input type="submit" class="set_gray" value="Merge" id="merge_btn" disabled></p>';
echo '</form>';
echo '<script>
(function(){
  var keep = document.getElementById("keep_id");
  var mergeWrap = document.getElementById("merge_wrap");
  var merge = document.getElementById("merge_id");
  var label = document.getElementById("merge_label");
  var btn = document.getElementById("merge_btn");
  function update(){
    var k = keep.value;
    if (!k || k === "0") {
      mergeWrap.hidden = true;
      label.hidden = true;
      btn.disabled = true;
      return;
    }
    label.hidden = false;
    mergeWrap.hidden = false;
    for (var i = 0; i < merge.options.length; i++) {
      var o = merge.options[i];
      o.disabled = (o.value === k);
      if (o.disabled && o.selected) merge.selectedIndex = 0;
    }
    btn.disabled = !(merge.value && merge.value !== "0" && merge.value !== k);
  }
  keep.addEventListener("change", update);
  merge.addEventListener("change", update);
  update();
})();
</script>';
$app->view->end();
