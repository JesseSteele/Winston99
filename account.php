<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'user', 'notify', 'passkey', 'oauth', 'audit', 'form'];
require __DIR__ . '/lib/boot.php';
$me = $app->auth->requireUser();
if (!$app->auth->atLeast('supervisor')) {
    $app->redirect('');
}
$id = (int) ($_GET['u'] ?? $_POST['u'] ?? 0);
$w = $id ? $app->user->find($id) : null;
if (!$w || !$app->auth->canManageAccount($w)) {
    $app->redirect('enrollment.php');
}

$contact = new Form();
$passForm = new Form();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check()) {
    if (isset($_POST['save_contact'])) {
        $contact->grab($_POST, 'name', 'email');
        $name = $contact->get('name');
        $email = strtolower($contact->get('email'));
        $contact->put('email', $email);
        if ($name === '') {
            $contact->fail('name', 'Name is required.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $contact->fail('email', 'Enter a valid email address.');
        }
        if ($contact->ok()) {
            try {
                $app->user->saveContact($id, $name, $email);
                $app->audit->record($id, 'contact', 'name/email');
                $app->view->setFlash('Saved.');
                $app->redirect('account.php?u=' . $id);
            } catch (InvalidArgumentException $e) {
                $contact->fail('email', $e->getMessage());
            }
        }
    } elseif (isset($_POST['set_password'])) {
        $passForm->grab($_POST, 'pass1', 'pass2');
        $p1 = $passForm->get('pass1');
        $p2 = $passForm->get('pass2');
        if (strlen($p1) < 8 || $p1 !== $p2) {
            $passForm->fail('pass1', 'New passwords must match and be at least 8 characters.');
            $passForm->fail('pass2', 'New passwords must match and be at least 8 characters.');
        } else {
            $app->user->setPassword($id, $p1);
            $app->audit->record($id, 'password', 'set by staff');
            $app->view->setFlash('Password set. Authenticator still applies if it is on.');
            $app->redirect('account.php?u=' . $id);
        }
    } elseif (isset($_POST['totp_off'])) {
        $app->user->setTotp($id, null, false);
        $app->audit->record($id, 'totp_off', 'removed by staff');
        $app->view->setFlash('Authenticator removed.', false);
        $app->redirect('account.php?u=' . $id);
    } elseif (isset($_POST['save_notify'])) {
        $keys = Notify::keysFor($w['type']);
        $in = [];
        $em = [];
        foreach ($keys as $k) {
            $in[$k] = !empty($_POST['inapp'][$k]);
            $em[$k] = !empty($_POST['email'][$k]);
        }
        $app->user->savePrefs($id, ['inapp' => $in, 'email' => $em]);
        $app->audit->record($id, 'notify', 'prefs');
        $app->view->setFlash('Notification settings saved.');
        $app->redirect('account.php?u=' . $id);
    }
}

$w = $app->user->find($id) ?? $w;
if (!$contact->bad()) {
    $contact->put('name', (string) $w['name']);
    $contact->put('email', (string) $w['email']);
}
$type = (string) $w['type'];
[$dash, $active] = match ($type) {
    'admin', 'superintendent' => ['super', 'admins'],
    'observer' => ['admin', 'observers'],
    'editor' => ['admin', 'editors'],
    default => ['admin', 'writers'],
};
$app->view->start($w['name'], $active, $dash);
echo '<h2 class="lt">Edit account · ' . h($w['name']) . '</h2>';
echo '<p class="sans dk">' . h($w['username']) . ' · ' . h($type) . '</p>';

echo '<form method="post" class="sans">' . $app->csrf->field();
echo '<input type="hidden" name="u" value="' . $id . '">';
echo '<input type="hidden" name="save_contact" value="1">';
echo '<p class="field">Name<br>' . $contact->input('name', 'text', 'maxlength="80" required', 'readBox') . '</p>';
echo '<p class="field">Email<br>' . $contact->input('email', 'email', 'maxlength="120" required', 'readBox') . '</p>';
echo '<p><input type="submit" class="lt_button" value="Save"></p></form>';

echo '<h3 class="lt">Password</h3>';
echo '<p class="sans dk">Sets a new password for this account. Authenticator, if on, still blocks login until it is removed.</p>';
echo '<form method="post" class="sans">' . $app->csrf->field();
echo '<input type="hidden" name="u" value="' . $id . '">';
echo '<p class="field">New<br>' . $passForm->input('pass1', 'password', 'required') . '</p>';
echo '<p class="field">Confirm<br>' . $passForm->input('pass2', 'password', 'required') . '</p>';
echo '<p><input type="submit" name="set_password" class="lt_button" value="Set password"></p></form>';

echo '<h3 class="lt">Authenticator</h3>';
if (!empty($w['totp_enabled'])) {
    echo '<p class="sans">Authenticator is on.</p>';
    echo '<form method="post">' . $app->csrf->field();
    echo '<input type="hidden" name="u" value="' . $id . '">';
    echo confirm_submit('totp_off', 'Remove authenticator', 'Confirm remove', '1', 'set_gray', 'ln_button');
    echo '</form>';
} else {
    echo '<p class="sans dk">No authenticator.</p>';
}

$pks = $app->passkey->list($id);
echo '<h3 class="lt">Passkeys</h3>';
if (!$pks) {
    echo '<p class="sans dk">None. Staff cannot add or remove passkeys.</p>';
} else {
    echo '<p class="sans dk">Visible only. Staff cannot remove passkeys.</p><ul class="sans">';
    foreach ($pks as $pk) {
        echo '<li>' . h((string) ($pk['name'] ?? 'Passkey')) . ' <small class="dk">' . h((string) ($pk['created_at'] ?? '')) . '</small></li>';
    }
    echo '</ul>';
}

$oauths = $app->oauth->list($id);
echo '<h3 class="lt">Linked logins</h3>';
if (!$oauths) {
    echo '<p class="sans dk">None. Staff cannot connect or disconnect these.</p>';
} else {
    echo '<p class="sans dk">Visible only. Staff cannot disconnect these.</p><ul class="sans">';
    foreach ($oauths as $row) {
        echo '<li>' . h((string) $row['provider']) . ' · ' . h((string) ($row['email'] ?? '')) . '</li>';
    }
    echo '</ul>';
}

$keys = Notify::keysFor($type);
$labels = Notify::catalog();
$prefs = $app->user->prefs($w);
echo '<h3 class="lt">Notification settings</h3>';
echo '<form method="post">' . $app->csrf->field();
echo '<input type="hidden" name="u" value="' . $id . '">';
echo '<table class="list"><tr><th>Event</th><th>In-app</th><th>Email</th></tr>';
foreach ($keys as $k) {
    echo '<tr><td>' . h($labels[$k] ?? $k) . '</td>';
    echo '<td><input type="checkbox" name="inapp[' . h($k) . ']" value="1"' . (!empty($prefs['inapp'][$k]) ? ' checked' : '') . '></td>';
    echo '<td><input type="checkbox" name="email[' . h($k) . ']" value="1"' . (!empty($prefs['email'][$k]) ? ' checked' : '') . '></td></tr>';
}
echo '</table><p><input type="submit" name="save_notify" class="lt_button" value="Save notifications"></p></form>';

if ($app->auth->is('superintendent')) {
    echo '<h3 class="lt">Account log</h3>';
    $log = $app->audit->forUser($id);
    if (!$log) {
        echo empty_list();
    } else {
        echo '<table class="list sans lt"><tr><th>When</th><th>Who</th><th>Action</th><th>Detail</th><th>IP</th></tr>';
        $cc = 'lr';
        foreach ($log as $row) {
            $who = '—';
            $aid = (int) ($row['actor_id'] ?? 0);
            if ($aid === $id) {
                $who = 'Self';
            } elseif ((string) ($row['actor_name'] ?? '') !== '') {
                $who = (string) $row['actor_name'];
            }
            echo '<tr class="' . $cc . '"><td>' . h((string) $row['created_at']) . '</td>';
            echo '<td>' . h($who) . '</td>';
            echo '<td>' . h(Audit::label((string) $row['action'])) . '</td>';
            echo '<td>' . h((string) ($row['detail'] ?? '')) . '</td>';
            echo '<td>' . h((string) ($row['ip'] ?? '')) . '</td></tr>';
            $cc = $cc === 'lr' ? 'dr' : 'lr';
        }
        echo '</table>';
    }
}
$app->view->end();
