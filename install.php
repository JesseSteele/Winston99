<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
define('WINSTON99_INSTALLING', true);
$import = ['html', 'text'];
try {
    require __DIR__ . '/lib/boot.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "install.php could not boot:\n" . $e->getMessage() . "\n\n" . $e->getTraceAsString();
    exit;
}

$errors = [];
$notice = '';
$hasConfig = is_file(__DIR__ . '/config.php');
$needsDb = ($app->db === null);
$allowSuper = $hasConfig && !empty($app->config['allow_create_super']);
$alreadyInstalled = $hasConfig && !$needsDb && !$allowSuper;
if ($needsDb && !empty($app->bootError) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $errors[] = 'Database is not connected. Fill it in below. ' . $app->bootError;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
    $db_name = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['db_name'] ?? ''));
    $db_user = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['db_user'] ?? ''));
    $db_pass = (string) ($_POST['db_pass'] ?? '');
    $db_host = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
    if ($db_host === 'localhost') {
        $db_host = '127.0.0.1';
    }
    $db_port = (int) ($_POST['db_port'] ?? 3306);
    $host = trim((string) ($_POST['host'] ?? ''), '/');
    $host = preg_replace('#^https?://#i', '', $host) ?? $host;
    $site = trim((string) ($_POST['site_title'] ?? 'Winston 99'));
    $mail_from = trim((string) ($_POST['mail_from'] ?? ''));
    $username = (string) ($_POST['username'] ?? '');
    $email = (string) ($_POST['email'] ?? '');
    $name = trim((string) ($_POST['name'] ?? ''));
    $pass1 = (string) ($_POST['pass1'] ?? '');
    $pass2 = (string) ($_POST['pass2'] ?? '');
    $probe = $app->db;

    if ($needsDb) {
        if ($db_name === '' || $db_user === '') {
            $errors[] = 'Database name and user are required.';
        }
        if ($host === '' && empty($app->config['host'])) {
            $errors[] = 'Host is required (no https://).';
        }
        try {
            $probe = Db::connect([
                'host' => $db_host, 'port' => $db_port, 'name' => $db_name,
                'user' => $db_user, 'pass' => $db_pass, 'charset' => 'utf8mb4',
            ]);
        } catch (Throwable $e) {
            $errors[] = 'Could not connect: ' . $e->getMessage() . ' — use 127.0.0.1 not localhost.';
            $probe = null;
        }
    }

    if (!preg_match('/^[A-Za-z0-9]{4,32}$/', $username)) {
        $errors[] = 'Username: 4–32 letters or digits.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email required.';
    }
    if (strlen($pass1) < 8 || $pass1 !== $pass2) {
        $errors[] = 'Password must match and be at least 8 characters.';
    }
    if ($name === '') {
        $errors[] = 'Name required.';
    }

    if (!$errors && $needsDb) {
        $c = is_array($app->config) && $hasConfig ? $app->config : [
            'configured' => true,
            'allow_create_super' => false,
            'host' => '',
            'site_title' => 'Winston 99',
            'db' => [],
            'mail' => [
                'transport' => 'mail',
                'from' => '',
                'from_name' => 'Winston 99',
                'smtp_host' => '127.0.0.1',
                'smtp_port' => 587,
                'smtp_user' => '',
                'smtp_pass' => '',
                'smtp_secure' => 'tls',
            ],
            'github' => 'https://github.com/JesseSteele/Winston99.git',
            'stream' => 'main',
            'oauth' => [
                'google' => ['id' => '', 'secret' => ''],
                'github' => ['id' => '', 'secret' => ''],
            ],
        ];
        if ($host !== '') {
            $c['host'] = $host;
        }
        if ($site !== '') {
            $c['site_title'] = $site;
        }
        $c['configured'] = true;
        $c['db'] = [
            'host' => $db_host,
            'port' => $db_port,
            'name' => $db_name,
            'user' => $db_user,
            'pass' => $db_pass,
            'charset' => 'utf8mb4',
        ];
        $from = $mail_from !== '' ? $mail_from : (string) ($c['mail']['from'] ?? '');
        if ($from === '') {
            $from = 'noreply@' . preg_replace('#/.*$#', '', (string) $c['host']);
        }
        $c['mail']['from'] = $from;
        $c['mail']['from_name'] = $c['site_title'];
        $c['stream'] = 'main';
        $c['oauth'] = [
            'google' => [
                'id' => trim((string) ($_POST['oauth_google_id'] ?? '')),
                'secret' => trim((string) ($_POST['oauth_google_secret'] ?? '')),
            ],
            'github' => [
                'id' => trim((string) ($_POST['oauth_github_id'] ?? '')),
                'secret' => trim((string) ($_POST['oauth_github_secret'] ?? '')),
            ],
        ];
        $dump = "<?php\nreturn " . var_export($c, true) . ";\n";
        if (file_put_contents(__DIR__ . '/config.php', $dump) === false) {
            $errors[] = 'Could not write config.php — check folder permissions.';
        } else {
            @chmod(__DIR__ . '/config.php', 0640);
            $app->config = $c;
            $app->db = $probe;
            $needsDb = false;
        }
    }

    if (!$errors && $alreadyInstalled) {
        $errors[] = 'Already installed. Set allow_create_super in config.php for walk-in Superintendent recovery.';
    }

    if (!$errors && $app->db) {
        try {
            $app->need('user');
            require_once __DIR__ . '/sql/migrate.php';
            winston99_migrate($app);
            if ($app->user->findByUsername($username) || $app->user->findByEmail($email)) {
                $errors[] = 'That username or email already exists.';
            } else {
                $id = $app->user->create([
                    'type' => 'superintendent',
                    'facility_id' => null,
                    'username' => $username,
                    'email' => $email,
                    'name' => $name,
                    'pass' => password_hash($pass1, PASSWORD_DEFAULT),
                    'notify_prefs' => json_enc(['inapp' => [], 'email' => []]),
                ]);
                $notice = 'Superintendent created (id ' . $id . '). Log in. Then create a Facility and an Administrator.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Install query failed: ' . $e->getMessage();
        }
    }
}

if (!function_exists('h')) {
    function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Install Winston 99</title>
<link rel="stylesheet" href="css/styles.css?v=<?php echo h(function_exists('winston99_asset_v') ? winston99_asset_v(__DIR__ . '/css/styles.css') : (string) time()); ?>">
<link rel="stylesheet" href="css/theme-dusk-desk.css?v=<?php echo h(function_exists('winston99_asset_v') ? winston99_asset_v(__DIR__ . '/css/theme-dusk-desk.css') : (string) time()); ?>">
</head><body><div id="wrap"><div class="page"><div class="content">
<h1 class="sans">Install Winston 99</h1>
<p class="sans">SysAdmin only. Mail, host, and database live in <code>config.php</code>.</p>
<?php
foreach ($errors as $e) {
    echo '<p class="sans noticered">' . h($e) . '</p>';
}
if ($notice) {
    echo '<p class="sans noticegreen">' . h($notice) . ' <a href="login.php">Login</a></p>';
}
if ($alreadyInstalled && !$notice) {
    echo '<p class="sans">Installed. <a href="login.php">Login</a></p>';
    echo '<p class="sans">Walk-in Superintendent recovery: set <code>allow_create_super</code> to true in the config, reload this page, then set it false again.</p>';
} elseif (!$notice) {
    echo '<form method="post" class="sans">';
    echo '<input type="hidden" name="install" value="1">';
    if ($needsDb) {
        $dbcfg = $app->config['db'] ?? [];
        echo '<h3 class="sans">Database (TCP)</h3>';
        echo '<p>Host <input name="db_host" value="' . h((string) ($dbcfg['host'] ?? '127.0.0.1')) . '"> Port <input name="db_port" value="' . h((string) ($dbcfg['port'] ?? '3306')) . '" size="5"></p>';
        echo '<p>Name <input name="db_name" value="' . h((string) ($dbcfg['name'] ?? '')) . '" required> User <input name="db_user" value="' . h((string) ($dbcfg['user'] ?? '')) . '" required> Password <input type="password" name="db_pass"></p>';
        echo '<h3 class="sans">Public host</h3>';
        echo '<p>No http:// — examples: <code>write.pink</code>, <code>99.example.org</code>, <code>example.org/99</code>. Always served as https://</p>';
        echo '<p><input name="host" placeholder="write.pink/99" value="' . h((string) ($app->config['host'] ?? '')) . '" required></p>';
        echo '<p>Site title <input name="site_title" value="' . h((string) ($app->config['site_title'] ?? 'Winston 99')) . '"></p>';
        echo '<p>Mail from <input name="mail_from" placeholder="noreply@write.pink" value="' . h((string) ($app->config['mail']['from'] ?? '')) . '"> (SMTP later in config)</p>';
        echo '<h3 class="sans">Sign-in keys (optional)</h3>';
        echo '<p>Leave a pair blank to hide that button. Keys go in <code>config.php</code> only — not the database, and not editable in the app later. SysAdmin can add or change them in the config file. Callback for both: <code>https://</code><em>host</em><code>/oauth.php</code></p>';
        echo '<p>Google client ID<br><input name="oauth_google_id" autocomplete="off"></p>';
        echo '<p>Google client secret<br><input type="password" name="oauth_google_secret" autocomplete="off"></p>';
        echo '<p class="dk"><a href="https://console.cloud.google.com/apis/credentials">Get Google keys</a> · <a href="https://developers.google.com/identity/protocols/oauth2/web-server">instructions</a></p>';
        echo '<p>GitHub client ID<br><input name="oauth_github_id" autocomplete="off"></p>';
        echo '<p>GitHub client secret<br><input type="password" name="oauth_github_secret" autocomplete="off"></p>';
        echo '<p class="dk"><a href="https://github.com/settings/developers">Get GitHub keys</a> · <a href="https://docs.github.com/apps/oauth-apps/building-oauth-apps/creating-an-oauth-app">instructions</a></p>';
    }
    echo '<h3 class="sans">First Superintendent</h3>';
    echo '<p>Name <input name="name" required> Username <input name="username" required></p>';
    echo '<p>Email <input type="email" name="email" required></p>';
    echo '<p>Password <input type="password" name="pass1" required> Confirm <input type="password" name="pass2" required></p>';
    echo '<p><input type="submit" class="lt_button" value="Install"></p>';
    echo '</form>';
}
?>
</div>
<div class="footer">
<p class="dk sans"><a class="dk" href="Terms.htm">Terms & Conditions</a> | <a class="dk" href="Privacy.htm">Privacy</a> | <a class="dk" href="https://github.com/JesseSteele/Winston99">OpenSource project from GitHub</a> | <a class="dk" href="https://winston.software">winston.software</a> &nbsp; - &nbsp; &copy; Winston, <a class="dk" href="https://www.gnu.org/licenses/gpl-3.0.en.html">GPLv3</a></p>
</div>
</div></div></body></html>
