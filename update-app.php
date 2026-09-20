<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'update'];
require __DIR__ . '/lib/boot.php';
$app->auth->requireUser();
if (!$app->auth->is('superintendent')) {
    $app->redirect('');
}
$log = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check()) {
    $log = $app->update->run(isset($_POST['pull']));
}
$app->view->start('Update', 'locker', 'super');
echo '<h2 class="lt">Update</h2>';
echo '<p class="sans dk">Pulls GitHub master (config file is never overwritten) then runs SQL migrations — including the one-time lift of a legacy dump.</p>';
echo '<p class="sans">CLI: <code>php bin/update.php</code> or <code>bash bin/update</code></p>';
echo '<form method="post">' . $app->csrf->field();
echo '<p><label><input type="checkbox" name="pull" value="1" checked> git pull / clone</label></p>';
echo '<p><input type="submit" class="lt_button" value="Run update"></p></form>';
foreach ($log as $line) {
    echo '<pre class="sans">' . h($line) . '</pre>';
}
$app->view->end();
