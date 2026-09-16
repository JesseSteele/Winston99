<?php
declare(strict_types=1);
$import = ['auth', 'csrf', 'view', 'html', 'totp', 'passkey', 'user', 'oauth', 'audit', 'form'];
require __DIR__ . '/lib/boot.php';
$u = $app->auth->requireUser();
$totpForm = new Form();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app->csrf->check()) {
    if (isset($_POST['totp_start'])) {
        $_SESSION['totp_pending'] = $app->totp->secret();
    } elseif (isset($_POST['totp_confirm']) && !empty($_SESSION['totp_pending'])) {
        $totpForm->grab($_POST, 'code');
        if ($app->totp->verify($_SESSION['totp_pending'], $totpForm->get('code'))) {
            $app->user->setTotp($app->auth->id(), $_SESSION['totp_pending'], true);
            unset($_SESSION['totp_pending']);
            $u = $app->user->find($app->auth->id());
            $app->audit->record($app->auth->id(), 'totp_on', 'self');
            $totpForm = new Form();
        } else {
            $totpForm->fail('code', 'That code did not match.');
        }
    } elseif (isset($_POST['totp_off'])) {
        $app->user->setTotp($app->auth->id(), null, false);
        $u = $app->user->find($app->auth->id());
        $app->audit->record($app->auth->id(), 'totp_off', 'self');
    } elseif (isset($_POST['id'], $_POST['spki'])) {
        $app->passkey->register($app->auth->id(), (string) $_POST['id'], (string) $_POST['spki'], (string) ($_POST['name'] ?? 'Passkey'));
        $app->redirect('security.php');
    } elseif (isset($_POST['rename_pk'])) {
        $app->passkey->rename((int) $_POST['rename_pk'], $app->auth->id(), (string) ($_POST['pk_name'] ?? ''));
    } elseif (isset($_POST['del_pk'])) {
        $pkId = (int) $_POST['del_pk'];
        $pks = $app->passkey->list($app->auth->id());
        $oauths = $app->oauth->list($app->auth->id());
        if (!$app->user->passwordLoginOn($u) && count($pks) < 2 && $oauths === []) {
            // keep at least one way in
        } else {
            $app->passkey->delete($pkId, $app->auth->id());
        }
    } elseif (isset($_POST['unlink_oauth'])) {
        $pks = $app->passkey->list($app->auth->id());
        $oauths = $app->oauth->list($app->auth->id());
        if (!$app->user->passwordLoginOn($u) && $pks === [] && count($oauths) < 2) {
            // keep at least one way in
        } else {
            $app->oauth->unlink($app->auth->id(), (string) $_POST['unlink_oauth']);
        }
    } elseif (isset($_POST['pw_login_toggle'])) {
        $pks = $app->passkey->list($app->auth->id());
        $oauths = $app->oauth->list($app->auth->id());
        if ($pks !== [] && $oauths !== []) {
            $id = $app->auth->id();
            if (isset($_POST['disable_password'])) {
                $app->user->setPassLogin($id, false);
                $app->audit->record($id, 'password_off', 'self');
            } elseif (!empty($u['pass'])) {
                $app->user->setPassLogin($id, true);
                $app->audit->record($id, 'password_on', 'self');
            }
            $u = $app->user->find($id) ?? $u;
        }
    } elseif (isset($_POST['disable_password'])) {
        $pks = $app->passkey->list($app->auth->id());
        $oauths = $app->oauth->list($app->auth->id());
        if ($pks !== [] && $oauths !== []) {
            $app->user->setPassLogin($app->auth->id(), false);
            $u = $app->user->find($app->auth->id()) ?? $u;
            $app->audit->record($app->auth->id(), 'password_off', 'self');
        }
    }
}

$app->view->start('Security', 'locker', 'my');
if (!empty($_SESSION['oauth_err'])) {
    echo '<p class="sans noticered">' . h((string) $_SESSION['oauth_err']) . '</p>';
    unset($_SESSION['oauth_err']);
}
echo '<h2 class="lt">Authenticator (TOTP)</h2>';
if (!empty($u['totp_enabled'])) {
    echo '<p class="sans noticegreen">Authenticator is on.</p>';
    echo '<form method="post">' . $app->csrf->field() . '<input type="submit" name="totp_off" class="set_gray" value="Turn off"></form>';
} elseif (!empty($_SESSION['totp_pending'])) {
    $secret = $_SESSION['totp_pending'];
    $uri = $app->totp->uri($secret, $u['username'], $app->title());
    echo '<p class="sans">Scan this with your authenticator app, or type the secret below.</p>';
    echo '<div class="totp-qr" id="totp-qr" data-otpauth="' . h($uri) . '"></div>';
    echo '<p class="sans totp-secret"><code>' . h($secret) . '</code></p>';
    echo '<form method="post">' . $app->csrf->field();
    echo '<p class="field sans totp-code-row"><label for="totp_code">Code</label>';
    echo '<span class="totp-code-line">' . $totpForm->input('code', 'text', 'id="totp_code" inputmode="numeric" autocomplete="one-time-code" required') . ' ';
    echo '<input type="submit" name="totp_confirm" class="lt_button" value="Confirm"></span></p></form>';
} else {
    echo '<form method="post">' . $app->csrf->field() . '<input type="submit" name="totp_start" class="lt_button" value="Set up authenticator"></form>';
}

echo '<h2 class="lt">Passkeys</h2>';
echo '<p class="sans dk">Works over https; platform (Apple, Google, etc) or hardware key (YubiKey, etc).</p>';
echo '<p><button type="button" class="lt_button" id="pkadd">Add a passkey</button></p>';
$pks = $app->passkey->list($app->auth->id());
if ($pks) {
    echo '<table class="id-link pk-list"><colgroup><col class="pk-col-name"><col class="pk-col-when"><col class="pk-col-rm"></colgroup><tbody>';
    foreach ($pks as $pk) {
        echo '<tr><td class="pk-name"><form method="post" class="pk-row">' . $app->csrf->field();
        echo '<input type="hidden" name="rename_pk" value="' . (int) $pk['id'] . '">';
        echo '<span class="pk-who"><span class="pk-label sans">' . h((string) $pk['name']) . '</span>';
        echo '<input type="text" class="pk-input" name="pk_name" value="' . h((string) $pk['name']) . '" maxlength="80" hidden aria-label="Passkey name"></span>';
        echo '<span class="pk-act"><button type="button" class="lt_button small pk-edit" onclick="pwPkEdit(this)">Edit</button>';
        echo '<input type="submit" class="lt_button small pk-save" value="Save" hidden></span>';
        echo '<button type="button" class="pk-cancel" onclick="pwPkCancel(this)" title="Cancel" aria-label="Cancel edit">×</button>';
        echo '</form></td>';
        echo '<td class="sans dk pk-when">' . h((string) $pk['created_at']) . '</td><td class="pk-rm">';
        echo post_button('Remove', 'Delete', 'security.php', 'del_pk', (string) $pk['id'], 'set_gray small', $app->csrf->token());
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}
$jsV = h(pw99_asset_v(__DIR__ . '/js/pw99.js'));
$qrV = h(pw99_asset_v(__DIR__ . '/js/qrcodegen.js'));
echo '<script src="js/qrcodegen.js?v=' . $qrV . '"></script>';
echo '<script src="js/pw99.js?v=' . $jsV . '"></script>';
echo '<script>document.getElementById("pkadd").onclick=function(){pwPasskeyRegister("passkey-create.php","security.php",' . json_encode($app->csrf->token()) . ');};';
if (!empty($_SESSION['totp_pending'])) {
    echo 'pwDrawTotpQr("totp-qr");';
}
echo '</script>';

$have = [];
foreach ($app->oauth->list($app->auth->id()) as $row) {
    $have[$row['provider']] = $row;
}
$linkRows = '';
foreach (['google' => 'Google', 'github' => 'GitHub'] as $p => $lab) {
    $on = isset($have[$p]);
    if (!$on && !$app->oauth->enabled($p)) {
        continue;
    }
    $linkRows .= '<tr><td class="id-who">' . brand_icon($p) . '<span class="id-lab">' . h($lab) . '</span></td>';
    $linkRows .= '<td class="id-mark">' . ($on ? brand_icon('check') : '&nbsp;') . '</td><td class="id-act">';
    if ($on) {
        $linkRows .= '<button type="button" class="set_gray" data-oauth="' . h($p) . '" data-act="disconnect" title="Stop using this login">Disconnect</button>';
    } else {
        $linkRows .= '<button type="button" class="lt_button" data-oauth="' . h($p) . '" data-act="connect" title="Link this login">Connect</button>';
    }
    $linkRows .= '</td></tr>';
}
if ($linkRows !== '') {
    echo '<h2 class="lt">Linked logins</h2>';
    echo '<table class="id-link oauth-list" id="oauth-list" data-csrf="' . h($app->csrf->token()) . '"><colgroup><col class="oauth-col-who"><col class="oauth-col-mark"><col class="oauth-col-act"></colgroup><tbody>' . $linkRows . '</tbody></table>';
    echo '<script>pwBindOauthLinks("#oauth-list");</script>';
}
$pksNow = $app->passkey->list($app->auth->id());
$oauthNow = $app->oauth->list($app->auth->id());
if ($pksNow !== [] && $oauthNow !== []) {
    $pwOff = !$app->user->passwordLoginOn($u);
    echo '<form method="post" id="nopwform" class="sans pw-login-toggle" action="ajax/save-pass-login.php" onsubmit="return false;">' . $app->csrf->field();
    echo '<label><input type="checkbox" name="disable_password" id="disable_password" value="1"'
        . ($pwOff ? ' checked' : '') . '> Disable password login</label>';
    echo '<span id="pw_login_saved"></span></form>';
    echo '<script>pwBindPassLogin("disable_password","pw_login_saved","ajax/save-pass-login.php");</script>';
}
$app->view->end();
