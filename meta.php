<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'user', 'passkey', 'oauth', 'audit'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->atLeast('supervisor')) {
    $app->redirect('');
}
$id = (int) ($_GET['u'] ?? 0);
$w = $id ? $app->user->find($id) : null;
if (!$w || !$app->auth->canManageAccount($w)) {
    $app->redirect('enrollment.php');
}
$type = (string) $w['type'];
[$dash, $active] = match ($type) {
    'admin', 'superintendent' => ['super', 'admins'],
    'observer' => ['admin', 'observers'],
    'editor' => ['admin', 'editors'],
    default => ['admin', 'writers'],
};
$last = $app->audit->lastLogin($id);
$pks = $app->passkey->list($id);
$oauths = $app->oauth->list($id);
$app->view->start('Meta · ' . $w['name'], $active, $dash);
echo '<h2 class="lt">Meta · ' . h($w['name']) . '</h2>';
echo '<p class="sans dk">' . h($w['username']) . ' · ' . h($type) . ' · <a href="account.php?u=' . $id . '">Edit</a></p>';
echo '<p class="sans dk">Lookup only. Nothing on this page changes the account.</p>';

echo '<h3 class="lt">Last login</h3>';
if (!$last) {
    echo '<p class="sans dk">No login recorded.</p>';
} else {
    echo '<p class="sans">' . h((string) ($last['created_at'] ?? '')) . ' <small class="dk">' . h((string) ($last['ip'] ?? '')) . '</small></p>';
}

echo '<h3 class="lt">Authenticator</h3>';
echo !empty($w['totp_enabled'])
    ? '<p class="sans">On.</p>'
    : '<p class="sans dk">Off.</p>';

echo '<h3 class="lt">Linked logins</h3>';
if (!$oauths) {
    echo '<p class="sans dk">None.</p>';
} else {
    echo '<ul class="sans">';
    foreach ($oauths as $row) {
        echo '<li>' . h((string) $row['provider']) . ' · ' . h((string) ($row['email'] ?? '')) . '</li>';
    }
    echo '</ul>';
}

echo '<h3 class="lt">Passkeys</h3>';
if (!$pks) {
    echo '<p class="sans dk">None.</p>';
} else {
    echo '<ul class="sans">';
    foreach ($pks as $pk) {
        echo '<li>' . h((string) ($pk['name'] ?? 'Passkey')) . ' <small class="dk">' . h((string) ($pk['created_at'] ?? '')) . '</small></li>';
    }
    echo '</ul>';
}

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
