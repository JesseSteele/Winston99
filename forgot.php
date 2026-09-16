<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'mail', 'user', 'form'];
require __DIR__ . '/lib/boot.php';

$msg = '';
$form = new Form();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check()) {
    $form->grab($_POST, 'email');
    $email = $form->get('email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $form->fail('email', 'Valid email required.');
    }
    if ($form->ok()) {
        $u = $app->user->findByEmail($email);
        // Always the same message — no account enumeration.
        $msg = 'If that address can reset by email, a link is on the way.';
        if ($u && $app->auth->canEmailReset($u) && $app->mail->enabled()) {
            $token = $app->user->createReset((int) $u['id']);
            $link = $app->url('reset.php?t=' . urlencode($token));
            $app->mail->send(
                $u['email'],
                'Reset your ' . $app->title() . ' password',
                "Someone asked to reset the password for {$u['username']}.\n\n{$link}\n\nIf this was not you, ignore this mail. The link expires in one hour.\n"
            );
        } elseif ($u && !$app->auth->canEmailReset($u)) {
            $msg = 'Superintendent passwords are reset in person by the SysAdmin (install.php with allow_create_super).';
        }
        $form = new Form();
    }
}

$app->view->start('Forgot password', 'login');
echo '<h2 class="lt">Forgot password</h2>';
if ($msg) {
    echo '<p class="sans noticegreen">' . h($msg) . '</p>';
}
echo '<p class="sans dk">Administrators and below can reset by email. Superintendent requires the SysAdmin in the room.</p>';
echo '<form method="post">' . $app->csrf->field();
echo '<p class="sans">Email<br>' . $form->input('email', 'email', 'required') . '</p>';
echo '<p><input type="submit" class="lt_button" value="Send reset link"></p></form>';
echo '<p>' . button('Login', 'Login', 'login.php', 'set_gray') . '</p>';
$app->view->end();
