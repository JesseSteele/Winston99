<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'user', 'passkey', 'oauth'];
require dirname(__DIR__) . '/lib/boot.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $app->json(['ok' => false, 'error' => 'POST only'], 405);
}
$u = $app->auth->requireUser();
if (!$app->csrf->check()) {
    $app->json(['ok' => false, 'error' => 'csrf'], 400);
}
$p = (string) ($_POST['unlink_oauth'] ?? '');
if (!in_array($p, ['google', 'github'], true)) {
    $app->json(['ok' => false, 'error' => 'Unknown login.'], 400);
}
$id = $app->auth->id();
$pks = $app->passkey->list($id);
$oauths = $app->oauth->list($id);
if (!$app->user->passwordLoginOn($u) && $pks === [] && count($oauths) < 2) {
    $app->json(['ok' => false, 'error' => 'Keep at least one way in.'], 400);
}
try {
    $app->oauth->unlink($id, $p);
} catch (Throwable $e) {
    $app->json(['ok' => false, 'cls' => 'error', 'error' => 'No database connection; changes not saved!']);
}
$app->json(['ok' => true, 'provider' => $p, 'linked' => false]);
