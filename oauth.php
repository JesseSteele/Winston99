<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'oauth', 'user'];
require __DIR__ . '/lib/boot.php';

$p = (string) ($_GET['p'] ?? $_POST['p'] ?? '');
$link = isset($_GET['link']) || isset($_POST['link']);
if (isset($_GET['popup']) || isset($_POST['popup'])) {
    $_SESSION['oauth_popup'] = 1;
}

$popupDone = static function (bool $ok, string $provider, string $error = '') : void {
    header('Content-Type: text/html; charset=utf-8');
    $payload = json_encode(
        ['ok' => $ok, 'provider' => $provider, 'error' => $error],
        JSON_UNESCAPED_UNICODE
    );
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Winston 99</title></head><body>';
    echo '<script>(function(){var d=' . $payload . ';';
    echo 'try{if(window.opener)window.opener.postMessage({pw99oauth:1,ok:!!d.ok,provider:d.provider,error:d.error||""},window.location.origin);}catch(e){}';
    echo 'window.close();})();</script>';
    echo '<p class="sans">You can close this window.</p></body></html>';
    exit;
};

if (isset($_GET['code'], $_GET['state'])) {
    $popup = !empty($_SESSION['oauth_popup']);
    $provider = (string) ($_SESSION['oauth_provider'] ?? '');
    $r = $app->oauth->finish((string) $_GET['code'], (string) $_GET['state']);
    unset($_SESSION['oauth_popup']);
    if ($popup) {
        $ok = !empty($r['ok']) && (($r['need'] ?? '') !== 'totp');
        $popupDone($ok, $provider, (string) ($r['error'] ?? ($ok ? '' : 'Sign-in failed.')));
    }
    if (!empty($r['need']) && $r['need'] === 'totp') {
        $app->redirect('login.php');
    }
    if (!empty($r['ok'])) {
        $app->redirect(!empty($r['link']) ? 'security.php' : '');
    }
    $_SESSION['oauth_err'] = $r['error'] ?? 'Sign-in failed.';
    $app->redirect($app->auth->user() ? 'security.php' : 'login.php');
}

if (in_array($p, ['google', 'github'], true) && $app->oauth->enabled($p)) {
    header('Location: ' . $app->oauth->start($p, $link));
    exit;
}

if (in_array($p, ['google', 'github'], true) && !$app->oauth->enabled($p)) {
    if (!empty($_SESSION['oauth_popup'])) {
        unset($_SESSION['oauth_popup']);
        $popupDone(false, $p, 'That sign-in is not set up on this site.');
    }
    $_SESSION['oauth_err'] = 'That sign-in is not set up on this site.';
    $app->redirect($link ? 'security.php' : 'login.php');
}

if (!empty($_SESSION['oauth_popup'])) {
    unset($_SESSION['oauth_popup']);
    $popupDone(false, $p, 'Sign-in failed.');
}
$app->redirect($link ? 'security.php' : 'login.php');
