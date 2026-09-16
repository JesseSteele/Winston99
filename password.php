<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'user', 'notify', 'passkey', 'oauth', 'audit', 'form'];
require __DIR__ . '/lib/boot.php';
$u = $app->auth->requireUser();
$msg = '';
$uid = $app->auth->id();
$pks = $app->passkey->list($uid);
$oauths = $app->oauth->list($uid);
$canDisable = $pks !== [] && $oauths !== [];
$noPass = !$app->user->passwordLoginOn($u);
$form = new Form();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check()) {
    if (isset($_POST['pw_login_toggle']) && $canDisable) {
        if (isset($_POST['disable_password'])) {
            $app->user->setPassLogin($uid, false);
            $u = $app->user->find($uid) ?? $u;
            $noPass = true;
            $msg = 'Password login is off.';
            $app->audit->record($uid, 'password_off', 'self');
        } elseif (!empty($u['pass'])) {
            $app->user->setPassLogin($uid, true);
            $u = $app->user->find($uid) ?? $u;
            $noPass = false;
            $msg = 'Password login is on.';
            $app->audit->record($uid, 'password_on', 'self');
        }
    } elseif (isset($_POST['disable_password']) && $canDisable) {
        $app->user->setPassLogin($uid, false);
        $u = $app->user->find($uid) ?? $u;
        $noPass = true;
        $msg = 'Password login is off.';
        $app->audit->record($uid, 'password_off', 'self');
    } else {
        $keys = $noPass ? ['pass1', 'pass2'] : ['current', 'pass1', 'pass2'];
        $form->grab($_POST, ...$keys);
        $p1 = $form->get('pass1');
        $p2 = $form->get('pass2');
        if (!$noPass && !password_verify($form->get('current'), (string) $u['pass'])) {
            $form->fail('current', 'Current password is incorrect.');
        }
        if (strlen($p1) < 8 || $p1 !== $p2) {
            $form->fail('pass1', 'New passwords must match and be at least 8 characters.');
            $form->fail('pass2', 'New passwords must match and be at least 8 characters.');
        }
        if ($form->ok()) {
            $app->user->setPassword($uid, $p1);
            $msg = 'Password changed.';
            $noPass = false;
            $u = $app->user->find($uid) ?? $u;
            $app->audit->record($uid, 'password', 'self');
            if ($u['editor_id']) {
                $app->notify->send((int) $u['editor_id'], 'password_change', $u['username'] . ' changed their password', '');
            }
            $form = new Form();
        }
    }
}

$app->view->start('Password', 'locker', 'my');
echo '<h2 class="lt">Password</h2>';
if ($msg) {
    echo '<p class="sans noticegreen">' . h($msg) . '</p>';
}
if ($canDisable) {
    echo '<form method="post" id="nopwform" class="sans">' . $app->csrf->field();
    echo '<input type="hidden" name="pw_login_toggle" value="1">';
    echo '<p><label><input type="checkbox" name="disable_password" id="disable_password" value="1"'
        . ($noPass ? ' checked' : '') . '> Disable password login</label></p>';
    echo '</form>';
}
$off = $noPass && $canDisable ? ' disabled' : '';
echo '<form method="post" id="pwform" class="pw-pass-fields' . ($noPass && $canDisable ? ' pw-off' : '') . '">' . $app->csrf->field();
if (!$noPass) {
    echo '<p class="sans">Current<br>' . $form->input('current', 'password', 'required' . $off) . '</p>';
}
echo '<p class="sans">New<br>' . $form->input('pass1', 'password', 'required' . $off) . '</p>';
echo '<p class="sans">Confirm<br>' . $form->input('pass2', 'password', 'required' . $off) . '</p>';
echo '<p><input type="submit" class="lt_button" value="' . ($noPass ? 'Set password' : 'Change password') . '"'
    . $off . '></p></form>';
echo '<script>
(function(){
  var cb = document.getElementById("disable_password");
  var form = document.getElementById("pwform");
  var cut = document.getElementById("nopwform");
  if (!cb || !form) return;
  function apply() {
    var on = cb.checked;
    form.classList.toggle("pw-off", on);
    Array.prototype.forEach.call(form.querySelectorAll("input"), function (i) {
      if (i.type === "hidden") return;
      i.disabled = on;
    });
  }
  apply();
  cb.addEventListener("change", function () {
    apply();
    if (cut) cut.submit();
  });
})();
</script>';
$app->view->end();
