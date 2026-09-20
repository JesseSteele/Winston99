<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'text', 'passkey', 'totp', 'oauth'];
require __DIR__ . '/lib/boot.php';

if ($app->auth->user()) {
    $app->redirect('');
}

$ip = client_ip();
$err = '';
$needTotp = !empty($_SESSION['pending_2fa']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['passkey'])) {
    $u = $app->passkey->assert(
        (string) $_POST['id'],
        (string) $_POST['clientData'],
        (string) $_POST['authData'],
        (string) $_POST['sig']
    );
    if ($u && $u['status'] === 'active') {
        $r = $app->auth->afterFactor($u, 'passkey');
        if ($r === 'ok') {
            $app->redirect('');
        }
        if ($r === 'totp') {
            $needTotp = true;
        }
    } else {
        $err = 'Passkey failed.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_code'])) {
    if (!$app->csrf->check()) {
        $err = 'Bad request.';
        $needTotp = true;
    } elseif ($app->auth->finishTotp((string) $_POST['totp_code'], isset($_POST['remember_machine']))) {
        $app->redirect('');
    } else {
        $err = 'That code did not match.';
        $needTotp = true;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    if (!$app->csrf->check()) {
        $err = 'Bad request.';
    } else {
        $r = $app->auth->login((string) $_POST['username'], (string) ($_POST['pass'] ?? ''), $ip);
        if ($r === 'ok') {
            $app->redirect('');
        } elseif ($r === 'totp') {
            $needTotp = true;
        } elseif ($r === 'blocked') {
            $err = 'Too many failed logins from this IP. Try later.';
        } elseif ($r === 'inactive') {
            $err = 'This account is not active.';
        } else {
            $err = 'The username and password do not match those on file.';
        }
    }
}

$app->view->start('Log In', 'login');
if (!empty($_SESSION['oauth_err'])) {
    echo '<p class="sans noticered">' . h((string) $_SESSION['oauth_err']) . '</p>';
    unset($_SESSION['oauth_err']);
}
if (!empty($_SESSION['logout'])) {
    echo '<p class="sans">You are now logged out. Bye!</p>';
    unset($_SESSION['logout']);
}
echo '<table style="clear:both;float:left;display:block;position:relative;width:auto;" class="plain"><tbody><tr>';
echo '<td><span class="sans dk"><a href="88">Typing practice: 88 Word Hanon</a></span></td>';
echo '<td><span class="sans dk"><a href="https://github.com/JesseSteele/Winston99">GitHub Source</a></span></td>';
echo '</tr></tbody></table>';
echo '<h1 style="clear:both;display:block;">' . h($app->title()) . '</h1>';
echo '<p class="dk sans"><b>Typing and Editing for Learners and Teachers</b>, <a href="https://winston.software"><small><i>powered by Winston 99</i></small></a></p>';
if ($err) {
    echo '<p class="sans noticered">' . h($err) . '</p>';
}
if ($needTotp) {
    echo '<h3 class="lt">Authenticator</h3>';
    echo '<form method="post">' . $app->csrf->field();
    echo '<p class="field sans"><label for="totp_code">Code</label>';
    echo '<input name="totp_code" id="totp_code" inputmode="numeric" autocomplete="one-time-code" required></p>';
    if ($app->auth->pendingCanRemember()) {
        echo '<p class="sans"><label><input type="checkbox" name="remember_machine" value="1"> Remember this machine for 30 days</label></p>';
    }
    echo '<p><input type="submit" class="set_gray" value="Verify"></p></form>';
} else {
    echo '<h3 class="lt">Login Options</h3>';
    echo '<form method="post" action="login.php">' . $app->csrf->field();
    echo '<p class="sans">Username<br><input name="username" required autocomplete="username"></p>';
    echo '<p class="sans">Password<br><input type="password" name="pass" required autocomplete="current-password"></p>';
    echo '<p><input type="submit" class="set_gray" value="Log in"> ';
    echo '<a class="dk sans" href="forgot.php">Forgot password?</a></p></form>';
    echo '<p class="sans dk login-or">OR</p>';
    echo '<div class="login-id">';
    echo id_login_button('passkey', 'Passkey', '', 'id="pkbtn"');
    foreach ($app->oauth->providers() as $p => $lab) {
        echo id_login_button($p, $lab, 'oauth.php?p=' . rawurlencode($p));
    }
    echo '</div>';
    echo '<script src="js/pw99.js"></script><script>document.getElementById("pkbtn").onclick=function(){pwPasskeyLogin("passkey-options.php");};</script>';
}
$app->view->end();
