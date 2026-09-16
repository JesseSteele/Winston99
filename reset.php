<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'user', 'form'];
require __DIR__ . '/lib/boot.php';

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$err = '';
$ok = '';
$form = new Form();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check()) {
    $form->grab($_POST, 'pass1', 'pass2');
    $p1 = $form->get('pass1');
    $p2 = $form->get('pass2');
    if (strlen($p1) < 8 || $p1 !== $p2) {
        $form->fail('pass1', 'Passwords must match and be at least 8 characters.');
        $form->fail('pass2', 'Passwords must match and be at least 8 characters.');
    }
    if ($form->ok()) {
        $u = $app->user->consumeReset($token);
        if (!$u) {
            $err = 'That link is invalid or expired.';
            $form = new Form();
        } elseif (!$app->auth->canEmailReset($u)) {
            $err = 'This account cannot reset by email.';
            $form = new Form();
        } else {
            $app->user->setPassword((int) $u['id'], $p1);
            $ok = 'Password changed. You can log in.';
            $form = new Form();
        }
    }
}

$app->view->start('Reset password', 'login');
echo '<h2 class="lt">Reset password</h2>';
if ($err) {
    echo '<p class="sans noticered">' . h($err) . '</p>';
}
if ($ok) {
    echo '<p class="sans noticegreen">' . h($ok) . ' <a href="login.php">Login</a></p>';
} else {
    echo '<form method="post">' . $app->csrf->field();
    echo '<input type="hidden" name="t" value="' . h($token) . '">';
    echo '<p class="sans">New password<br>' . $form->input('pass1', 'password', 'required') . '</p>';
    echo '<p class="sans">Confirm<br>' . $form->input('pass2', 'password', 'required') . '</p>';
    echo '<p><input type="submit" class="lt_button" value="Set password"></p></form>';
}
$app->view->end();
