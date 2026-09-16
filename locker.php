<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'user', 'audit', 'form'];
require __DIR__ . '/lib/boot.php';
$u = $app->auth->requireUser();
$uid = $app->auth->id();
$form = new Form();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check() && isset($_POST['save_contact'])) {
    $form->grab($_POST, 'name', 'email');
    $name = $form->get('name');
    $email = strtolower($form->get('email'));
    $form->put('email', $email);
    if ($name === '') {
        $form->fail('name', 'Name is required.');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $form->fail('email', 'Enter a valid email address.');
    }
    if ($form->ok()) {
        try {
            $app->user->saveContact($uid, $name, $email);
            $app->audit->record($uid, 'contact', 'name/email');
            $app->view->setFlash('Saved.');
            $app->redirect('locker.php');
        } catch (InvalidArgumentException $e) {
            $form->fail('email', $e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check() && isset($_POST['save_theme'])) {
    $tid = preg_replace('/[^a-z0-9\-]/', '', (string) ($_POST['theme'] ?? '')) ?? '';
    if ($tid !== '' && str_starts_with($tid, 'theme-') && is_file(__DIR__ . '/css/' . $tid . '.css')) {
        $app->user->saveTheme($uid, $tid);
        pw99_set_theme_cookie($tid);
        $app->audit->record($uid, 'theme', $tid);
        $app->view->setFlash('Theme saved.');
        $app->redirect('locker.php');
    }
}

$u = $app->user->find($uid) ?? $u;
if (!$form->bad()) {
    $form->put('name', (string) $u['name']);
    $form->put('email', (string) $u['email']);
}
$app->view->start('My Locker', 'locker', 'my');
echo '<h2 class="lt">My Locker</h2>';
echo '<form method="post" class="sans">' . $app->csrf->field();
echo '<input type="hidden" name="save_contact" value="1">';
echo '<p class="field">Name<br>' . $form->input('name', 'text', 'maxlength="80" required', 'readBox') . '</p>';
echo '<p class="field">Email<br>' . $form->input('email', 'email', 'maxlength="120" required', 'readBox') . '</p>';
echo '<p><input type="submit" class="lt_button" value="Save"></p></form>';
echo '<h3 class="lt">Theme</h3>';
echo '<form method="post" class="sans" id="pw-theme-form" data-saved="' . h($curTheme = pw99_theme_id($u)) . '">';
echo $app->csrf->field();
echo '<input type="hidden" name="save_theme" value="1">';
foreach (pw99_themes() as $tid => $tname) {
    $ck = $curTheme === $tid ? ' checked' : '';
    echo '<p class="field"><label><input type="radio" name="theme" value="' . h($tid) . '"' . $ck . '> ' . h($tname) . '</label></p>';
}
echo '<p><input type="submit" id="pw-theme-keep" class="set_writ_disabled" value="Keep theme" disabled></p></form>';
echo '<p>' . button('Password', 'Change password', 'password.php', 'set_gray') . '</p>';
echo '<p>' . button('Security', '2FA and passkeys', 'security.php', 'set_gray') . '</p>';
echo '<p>' . button('Notification settings', 'In-app and email', 'notify-settings.php', 'set_gray') . '</p>';
$app->view->end();
