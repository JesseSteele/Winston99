<?php
/**
 * Winston 99 boot.
 *
 * Each page declares $import = ['auth','view',...]; then requires this file.
 * Only those modules load. Boot itself loads config + PDO. Nothing else.
 */
declare(strict_types=1);

if (!function_exists('pw99_fail')) {
    function pw99_fail(Throwable $e): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Winston 99: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Winston 99</title></head><body>';
        echo '<pre style="white-space:pre-wrap;font:14px/1.4 monospace;max-width:70em">';
        echo "Winston 99 could not run this page.\n\n";
        echo htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), "\n\n";
        echo htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), "\n\n";
        echo htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "</pre></body></html>";
        exit(1);
    }
    set_exception_handler('pw99_fail');
}

if (!isset($import) || !is_array($import)) {
    $import = [];
}

$pw99Root = dirname(__DIR__);

require $pw99Root . '/lib/App.php';
require $pw99Root . '/lib/Db.php';
require $pw99Root . '/functions/text.php';

$configFile = $pw99Root . '/config.php';
$installing = (defined('PW99_INSTALLING') && PW99_INSTALLING === true);

$stub = static function () use ($pw99Root, $import): App {
    $app = new App($pw99Root, [
        'configured' => false,
        'host' => '',
        'site_title' => 'Winston 99',
        'db' => [],
        'mail' => ['transport' => 'off'],
    ], null);
    $app->load($import);
    return $app;
};

if (!is_file($configFile)) {
    if ($installing) {
        $app = $stub();
        return;
    }
    header('Location: install.php');
    exit;
}

try {
    $config = require $configFile;
} catch (Throwable $e) {
    if ($installing) {
        $app = $stub();
        $app->bootError = $e->getMessage();
        return;
    }
    pw99_fail($e);
}

if (!is_array($config)) {
    if ($installing) {
        $app = $stub();
        $app->bootError = 'config.php did not return an array.';
        return;
    }
    header('Location: install.php');
    exit;
}

if (empty($config['configured'])) {
    if (!$installing) {
        header('Location: install.php');
        exit;
    }
}

$db = null;
if (!empty($config['db']['name'])) {
    try {
        $db = Db::connect($config['db']);
    } catch (Throwable $e) {
        if ($installing) {
            $app = new App($pw99Root, $config, null);
            $app->bootError = $e->getMessage();
            $app->load($import);
            return;
        }
        $ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_POST['ajax']) && (string) $_POST['ajax'] === '1');
        error_log('Winston 99: database connection failed: ' . $e->getMessage());
        http_response_code(503);
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'retry' => true,
                'error' => 'Waiting for the database…',
            ]);
            exit;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo "Database connection failed. The SysAdmin needs to check config.php.\n";
        echo "Use 127.0.0.1 (TCP), not localhost (Unix socket).\n";
        echo $e->getMessage(), "\n";
        exit;
    }
}

$app = new App($pw99Root, $config, $db);
try {
    $app->load($import);
} catch (Throwable $e) {
    if ($installing) {
        $app->bootError = $e->getMessage();
        return;
    }
    pw99_fail($e);
}
