<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'text', 'user', 'mail', 'notify', 'facility', 'form'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->atLeast('supervisor')) {
    $app->redirect('editor.php');
}

$allowed = ['writer', 'observer', 'editor'];
if ($app->auth->is('superintendent')) {
    $allowed[] = 'admin';
}
$want = (string) ($_POST['type'] ?? $_GET['type'] ?? 'writer');
$kind = in_array($want, $allowed, true) ? $want : 'writer';
$fidIn = (int) ($_POST['facility_id'] ?? $_GET['facility_id'] ?? 0);

$form = new Form();
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check()) {
    $form->grab($_POST, 'type', 'username', 'email', 'name', 'pass1', 'pass2', 'facility_id');
    $type = in_array($form->get('type'), $allowed, true) ? $form->get('type') : 'writer';
    $form->put('type', $type);
    $form->put('name', clean_title($form->get('name'), 80));
    $un = $form->get('username');
    $em = $form->get('email');
    $p1 = $form->get('pass1');
    $p2 = $form->get('pass2');
    $fid = $type === 'admin' ? ((int) $form->get('facility_id') ?: null) : $app->auth->facilityId();
    if ($type === 'admin' && $fid) {
        $ff = $app->facility->find((int) $fid);
        if (!$ff) {
            $fid = null;
        }
    }
    if ($form->get('name') === '') {
        $form->fail('name', 'Name is required.');
    }
    if (!preg_match('/^[A-Za-z0-9]{4,32}$/', $un)) {
        $form->fail('username', 'Username: 4–32 letters or digits.');
    }
    if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
        $form->fail('email', 'Valid email required.');
    }
    if (strlen($p1) < 8 || $p1 !== $p2) {
        $form->fail('pass1', 'Passwords must match, 8+ characters.');
        $form->fail('pass2', 'Passwords must match, 8+ characters.');
    }
    if ($form->ok()) {
        if ($app->user->findByUsername($un)) {
            $form->fail('username', 'Username already registered.');
        }
        if ($app->user->findByEmail($em)) {
            $form->fail('email', 'Email already registered.');
        }
    }
    if ($form->ok()) {
        $app->user->create([
            'type' => $type,
            'facility_id' => $fid,
            'username' => $un,
            'email' => $em,
            'name' => $form->get('name'),
            'pass' => password_hash($p1, PASSWORD_DEFAULT),
            'editor_id' => ($type === 'writer' && $app->auth->is('editor')) ? $app->auth->id() : null,
        ]);
        $note = match ($type) {
            'observer' => 'new_observer',
            'admin' => 'new_admin',
            'writer' => 'new_writer',
            default => '',
        };
        $list = match ($type) {
            'observer' => 'observers.php',
            'editor' => 'editors.php',
            'admin' => 'administrators.php',
            default => 'enrollment.php',
        };
        if ($note !== '') {
            $app->notify->send($app->auth->id(), $note, $form->get('name') . ' registered', $list);
        }
        if ($app->mail->enabled()) {
            $app->mail->send($em, 'Welcome to ' . $app->title(), "Your username is {$un}. Sign in at " . $app->url('login.php') . "\n");
        }
        $msg = ucfirst($type) . ' created.';
        $kind = $type;
        $fidIn = (int) ($fid ?? 0);
        $form = new Form();
    } else {
        $kind = $type;
        $fidIn = (int) $form->get('facility_id');
    }
}

[$dash, $active] = match ($kind) {
    'observer' => ['admin', 'observers'],
    'editor' => ['admin', 'editors'],
    'admin' => ['super', 'admins'],
    default => ['admin', 'writers'],
};
$app->view->start('Register', $active, $dash);
echo '<h2 class="lt">Register</h2>';
if ($msg) {
    echo '<p class="sans noticegreen">' . h($msg) . '</p>';
}
echo '<form method="post">' . $app->csrf->field();
$typeOpts = [];
foreach ($allowed as $t) {
    $typeOpts[$t] = ucfirst($t);
}
if ($form->get('type') === '') {
    $form->put('type', $kind);
}
echo '<p class="sans">Type<br>' . $form->select('type', $typeOpts) . '</p>';
if ($kind === 'admin' && $app->auth->is('superintendent')) {
    $fopts = [];
    foreach ($app->facility->all() as $f) {
        $fopts[(int) $f['id']] = $f['name'];
    }
    if ($form->get('facility_id') === '') {
        $form->put('facility_id', (string) $fidIn);
    }
    echo '<p class="sans">Facility<br>' . $form->select('facility_id', $fopts, 'None') . '</p>';
}
echo '<p class="sans">Name<br>' . $form->input('name', 'text', 'required') . '</p>';
echo '<p class="sans">Username<br>' . $form->input('username', 'text', 'required') . '</p>';
echo '<p class="sans">Email<br>' . $form->input('email', 'email', 'required') . '</p>';
echo '<p class="sans">Password<br>' . $form->input('pass1', 'password', 'required') . '</p>';
echo '<p class="sans">Confirm<br>' . $form->input('pass2', 'password', 'required') . '</p>';
echo '<p><input type="submit" class="lt_button" value="Register"></p></form>';
$app->view->end();
