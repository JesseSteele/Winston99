<?php
declare(strict_types=1);

/**
 * Apply pending SQL. Also lifts a legacy mysqli-era dump into this schema.
 * Safe to run many times. Called from install, bin/update, and Admin Locker.
 */
function winston99_migrate(App $app): string
{
    $db = $app->db;
    if (!$db) {
        return 'no database';
    }
    $notes = [];
    $sweep = winston99_sweep_legacy($app);
    if ($sweep !== '') {
        $notes[] = $sweep;
    }
    if (!$db->tableExists('schema_version')) {
        $notes[] = winston99_apply_schema_file($db, $app->root . '/sql/schema.sql');
    }
    if ($db->tableExists('users') && !$db->columnExists('users', 'facility_id')) {
        $notes[] = winston99_legacy_lift($db);
    }
    $promo = winston99_ensure_superintendent($db);
    if ($promo !== '') {
        $notes[] = $promo;
    }
    $cols = winston99_ensure_rewrite_columns($db);
    if ($cols !== '') {
        $notes[] = $cols;
    }
    $soft = winston99_soften_legacy_notnull($db);
    if ($soft !== '') {
        $notes[] = $soft;
    }
    $ver = (int) ($db->val('SELECT MAX(version) FROM schema_version') ?? 0);
    if ($ver < 1) {
        $db->run('INSERT INTO schema_version (version, note) VALUES (1, ?)', ['initial']);
        $notes[] = 'schema_version=1';
        $ver = 1;
    }
    if ($ver < 2) {
        if (!$db->tableExists('oauth_identities')) {
            $db->pdo()->exec("CREATE TABLE oauth_identities (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id BIGINT UNSIGNED NOT NULL,
              provider VARCHAR(16) NOT NULL,
              subject VARCHAR(191) NOT NULL,
              email VARCHAR(160) DEFAULT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY provider_subject (provider, subject),
              KEY user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $db->run('INSERT INTO schema_version (version, note) VALUES (2, ?)', ['oauth google apple github']);
        $notes[] = 'schema_version=2 oauth';
        $ver = 2;
    }
    if ($ver < 3) {
        if (!$db->tableExists('writ_comments')) {
            $db->pdo()->exec("CREATE TABLE writ_comments (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              writ_id BIGINT UNSIGNED NOT NULL,
              observer_id BIGINT UNSIGNED NOT NULL,
              body MEDIUMTEXT,
              save_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY writ_id (writ_id),
              KEY observer_id (observer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $db->run('INSERT INTO schema_version (version, note) VALUES (3, ?)', ['observer comments on writs']);
        $notes[] = 'schema_version=3 comments';
        $ver = 3;
    }
    if ($ver < 4) {
        if (!$db->tableExists('totp_devices')) {
            $db->pdo()->exec("CREATE TABLE totp_devices (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id BIGINT UNSIGNED NOT NULL,
              selector CHAR(16) NOT NULL,
              token_hash CHAR(64) NOT NULL,
              expires_at DATETIME NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              last_used_at DATETIME DEFAULT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY selector (selector),
              KEY user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $db->run('INSERT INTO schema_version (version, note) VALUES (4, ?)', ['remember this machine for totp']);
        $notes[] = 'schema_version=4 totp devices';
        $ver = 4;
    }
    if ($ver < 5) {
        if (!$db->tableExists('account_log')) {
            $db->pdo()->exec("CREATE TABLE account_log (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_id BIGINT UNSIGNED NOT NULL,
              actor_id BIGINT UNSIGNED DEFAULT NULL,
              actor_name VARCHAR(120) DEFAULT NULL,
              action VARCHAR(32) NOT NULL,
              detail VARCHAR(255) DEFAULT NULL,
              ip VARCHAR(45) DEFAULT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY user_id (user_id),
              KEY created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $db->run('INSERT INTO schema_version (version, note) VALUES (5, ?)', ['account action log']);
        $notes[] = 'schema_version=5 account log';
        $ver = 5;
    }
    if ($ver < 6) {
        if ($db->tableExists('users') && !$db->columnExists('users', 'last_seen')) {
            $db->pdo()->exec('ALTER TABLE users ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL');
        }
        if ($db->tableExists('account_log') && $db->columnExists('users', 'last_seen')) {
            $db->pdo()->exec("UPDATE users u SET last_seen = (
                SELECT MAX(al.created_at) FROM account_log al
                WHERE al.user_id = u.id AND al.action = 'login'
            ) WHERE last_seen IS NULL");
        }
        $db->run('INSERT INTO schema_version (version, note) VALUES (6, ?)', ['users last_seen']);
        $notes[] = 'schema_version=6 last_seen';
        $ver = 6;
    }
    if ($ver < 7) {
        if ($db->tableExists('users') && !$db->columnExists('users', 'pass_login')) {
            $db->pdo()->exec('ALTER TABLE users ADD COLUMN pass_login TINYINT(1) NOT NULL DEFAULT 1');
        }
        if ($db->columnExists('users', 'pass_login')) {
            $db->pdo()->exec("UPDATE users SET pass_login = 0 WHERE pass IS NULL OR pass = ''");
        }
        $db->run('INSERT INTO schema_version (version, note) VALUES (7, ?)', ['password login toggle']);
        $notes[] = 'schema_version=7 pass_login';
    }
    return $notes ? implode('; ', $notes) : 'schema current';
}

/** Drop old PinkWrite 99 filenames; move /etc/pw99; retitle config. Does not touch oauth keys. */
function winston99_sweep_legacy(App $app): string
{
    $notes = [];
    foreach (['bin/update', 'bin/update.php'] as $rel) {
        $p = $app->root . '/' . $rel;
        if (is_file($p)) {
            @chmod($p, 0755);
        }
    }
    foreach (['bin/pw99-update', 'js/pw99.js', 'contrib/pw99-install', 'dev/pw99-config.php'] as $rel) {
        $p = $app->root . '/' . $rel;
        if (is_file($p) && @unlink($p)) {
            $notes[] = 'removed ' . $rel;
        }
    }
    if (is_dir('/etc/pw99') && !is_dir('/etc/winston99') && @rename('/etc/pw99', '/etc/winston99')) {
        $notes[] = '/etc/winston99';
    }
    $cfgFile = $app->root . '/config.php';
    if (is_file($cfgFile) && is_writable($cfgFile)) {
        $c = include $cfgFile;
        if (is_array($c)) {
            $changed = false;
            if (($c['site_title'] ?? '') === 'PinkWrite 99') {
                $c['site_title'] = 'Winston 99';
                $changed = true;
            }
            $gh = (string) ($c['github'] ?? '');
            if ($gh !== '' && (str_contains($gh, 'PinkWrite/99') || str_contains($gh, 'pinkwrite/99'))) {
                $c['github'] = 'https://github.com/JesseSteele/Winston99.git';
                $changed = true;
            }
            if (($c['mail']['from_name'] ?? '') === 'PinkWrite 99') {
                $c['mail']['from_name'] = 'Winston 99';
                $changed = true;
            }
            if ($changed) {
                $out = "<?php\nreturn " . var_export($c, true) . ";\n";
                if (@file_put_contents($cfgFile, $out) !== false) {
                    $notes[] = 'config.php name/github';
                }
            }
        }
    }
    return implode('; ', $notes);
}

function winston99_apply_schema_file(Db $db, string $file): string
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        return 'schema.sql missing';
    }
    $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '' || stripos($stmt, 'SET ') === 0) {
            continue;
        }
        try {
            $db->pdo()->exec($stmt);
        } catch (PDOException $e) {
            // table-exists races are fine
        }
    }
    return 'applied schema.sql';
}

function winston99_legacy_lift(Db $db): string
{
    $pdo = $db->pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS facilities (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(120) NOT NULL,
      code VARCHAR(16) DEFAULT NULL,
      status ENUM(\'open\',\'closed\') NOT NULL DEFAULT \'open\',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $fid = $db->val('SELECT id FROM facilities ORDER BY id LIMIT 1');
    if (!$fid) {
        $db->run('INSERT INTO facilities (name, code) VALUES (?,?)', ['Home facility', 'HOME']);
        $fid = $db->lastId();
    }

    $adds = [
        'users' => [
            "ADD COLUMN facility_id BIGINT UNSIGNED DEFAULT NULL",
            "ADD COLUMN groups_json JSON NULL",
            "ADD COLUMN blocks_json JSON NULL",
            "ADD COLUMN observing_json JSON NULL",
            "ADD COLUMN editor_id BIGINT UNSIGNED DEFAULT NULL",
            "ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL",
            "ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0",
            "ADD COLUMN notify_prefs JSON NULL",
        ],
        'writs' => [
            "ADD COLUMN facility_id BIGINT UNSIGNED DEFAULT NULL",
            "ADD COLUMN block_id BIGINT UNSIGNED DEFAULT 0",
            "ADD COLUMN kind ENUM('writ','assignment','test') NOT NULL DEFAULT 'writ'",
            "ADD COLUMN memo_id BIGINT UNSIGNED DEFAULT NULL",
            "ADD COLUMN test_id BIGINT UNSIGNED DEFAULT NULL",
            "ADD COLUMN drafts JSON NULL",
            "ADD COLUMN redrafts JSON NULL",
            "ADD COLUMN writing_time INT UNSIGNED DEFAULT 0",
            "ADD COLUMN test_answers JSON DEFAULT NULL",
            "ADD COLUMN test_auto_score INT UNSIGNED DEFAULT NULL",
        ],
        'notes' => [
            "ADD COLUMN type ENUM('note','memo','task') NOT NULL DEFAULT 'note'",
            "ADD COLUMN status ENUM('live','draft','archived') NOT NULL DEFAULT 'live'",
            "ADD COLUMN seen_writer ENUM('new','read','archived') NOT NULL DEFAULT 'new'",
            "ADD COLUMN seen_observer ENUM('new','read','archived') NOT NULL DEFAULT 'new'",
            "ADD COLUMN writing_time INT UNSIGNED DEFAULT 0",
        ],
        'blocks' => [
            "ADD COLUMN facility_id BIGINT UNSIGNED DEFAULT NULL",
            "ADD COLUMN group_id BIGINT UNSIGNED DEFAULT 0",
        ],
    ];
    foreach ($adds as $table => $cols) {
        foreach ($cols as $frag) {
            if (preg_match('/ADD COLUMN (\w+)/', $frag, $m) && $db->columnExists($table, $m[1])) {
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE `{$table}` {$frag}");
            } catch (PDOException $e) {
                // already there
            }
        }
    }

    if ($db->columnExists('users', 'groups') && $db->columnExists('users', 'groups_json')) {
        try {
            $pdo->exec('UPDATE users SET groups_json = IFNULL(groups, \'[]\') WHERE groups_json IS NULL');
            $pdo->exec('UPDATE users SET blocks_json = IFNULL(blocks, \'[]\') WHERE blocks_json IS NULL');
            $pdo->exec('UPDATE users SET observing_json = IFNULL(observing, \'[]\') WHERE observing_json IS NULL');
        } catch (PDOException $e) {
        }
    }
    if ($db->columnExists('users', 'editor') && $db->columnExists('users', 'editor_id')) {
        try {
            $pdo->exec('UPDATE users SET editor_id = editor WHERE editor_id IS NULL');
        } catch (PDOException $e) {
        }
    }
    $pdo->exec('UPDATE users SET facility_id = ' . (int) $fid . ' WHERE facility_id IS NULL AND type != \'superintendent\'');
    $pdo->exec("UPDATE users SET notify_prefs = '{\"inapp\":{},\"email\":{}}' WHERE notify_prefs IS NULL");
    $pdo->exec("UPDATE users SET groups_json = '[]' WHERE groups_json IS NULL");
    $pdo->exec("UPDATE users SET blocks_json = '[]' WHERE blocks_json IS NULL");
    $pdo->exec("UPDATE users SET observing_json = '[]' WHERE observing_json IS NULL");

    if ($db->columnExists('writs', 'block') && $db->columnExists('writs', 'block_id')) {
        try {
            $pdo->exec('UPDATE writs SET block_id = `block` WHERE block_id = 0 OR block_id IS NULL');
        } catch (PDOException $e) {
        }
    }
    $pdo->exec("UPDATE writs SET drafts = JSON_ARRAY() WHERE drafts IS NULL OR drafts = 'null'");
    $pdo->exec("UPDATE writs SET redrafts = JSON_ARRAY() WHERE redrafts IS NULL OR redrafts = 'null'");
    $pdo->exec('UPDATE writs SET facility_id = ' . (int) $fid . ' WHERE facility_id IS NULL');
    if ($db->columnExists('writs', 'type') && $db->columnExists('writs', 'kind')) {
        try {
            $pdo->exec("UPDATE writs SET kind = IF(`type`='test','test', IF(`type`='task','assignment','writ')) WHERE kind = 'writ'");
        } catch (PDOException $e) {
        }
    }
    $pdo->exec('UPDATE blocks SET facility_id = ' . (int) $fid . ' WHERE facility_id IS NULL');

    try {
        $pdo->exec("ALTER TABLE users MODIFY type ENUM('writer','observer','editor','supervisor','admin','superintendent') NOT NULL");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE writs MODIFY draft_status ENUM('saved','submitted','reviewed','redraft') NOT NULL DEFAULT 'saved'");
    } catch (PDOException $e) {
    }

    winston99_apply_schema_file($db, dirname(__DIR__) . '/sql/schema.sql');
    return 'legacy dump lifted into facility ' . $fid;
}

function winston99_ensure_superintendent(Db $db): string
{
    if (!$db->tableExists('users') || !$db->columnExists('users', 'type')) {
        return '';
    }
    $sid = $db->val("SELECT id FROM users WHERE type = 'superintendent' ORDER BY id LIMIT 1");
    if ($sid) {
        return '';
    }
    $aid = $db->val("SELECT id FROM users WHERE type = 'admin' ORDER BY id LIMIT 1");
    if (!$aid) {
        $aid = $db->val('SELECT id FROM users ORDER BY id LIMIT 1');
    }
    if (!$aid) {
        return '';
    }
    $db->run('UPDATE users SET type = ?, facility_id = NULL WHERE id = ?', ['superintendent', (int) $aid]);
    return 'promoted user ' . $aid . ' to superintendent';
}

function winston99_soften_legacy_notnull(Db $db): string
{
    $pdo = $db->pdo();
    $did = [];
    foreach (['groups', 'blocks', 'observing'] as $col) {
        if (!$db->columnExists('users', $col)) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE users MODIFY `{$col}` JSON NULL");
            $did[] = 'users.' . $col;
        } catch (PDOException $e) {
        }
    }
    if ($db->columnExists('notes', 'group')) {
        try {
            $pdo->exec('ALTER TABLE notes MODIFY `group` BIGINT UNSIGNED NULL DEFAULT 0');
            $did[] = 'notes.group';
        } catch (PDOException $e) {
        }
    }
    return $did ? 'legacy not-null ' . implode(',', $did) : '';
}

/** Columns the rewrite needs. Runs every update so a leftover mysqli dump cannot 500 the home page. */
function winston99_ensure_rewrite_columns(Db $db): string
{
    if (!$db->tableExists('users')) {
        return '';
    }
    $pdo = $db->pdo();
    $did = [];
    $adds = [
        'users' => [
            'facility_id' => "ADD COLUMN facility_id BIGINT UNSIGNED DEFAULT NULL",
            'groups_json' => "ADD COLUMN groups_json JSON NULL",
            'blocks_json' => "ADD COLUMN blocks_json JSON NULL",
            'observing_json' => "ADD COLUMN observing_json JSON NULL",
            'editor_id' => "ADD COLUMN editor_id BIGINT UNSIGNED DEFAULT NULL",
            'totp_secret' => "ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL",
            'totp_enabled' => "ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0",
            'notify_prefs' => "ADD COLUMN notify_prefs JSON NULL",
        ],
        'writs' => [
            'facility_id' => "ADD COLUMN facility_id BIGINT UNSIGNED DEFAULT NULL",
            'block_id' => "ADD COLUMN block_id BIGINT UNSIGNED DEFAULT 0",
            'kind' => "ADD COLUMN kind ENUM('writ','assignment','test') NOT NULL DEFAULT 'writ'",
            'memo_id' => "ADD COLUMN memo_id BIGINT UNSIGNED DEFAULT NULL",
            'test_id' => "ADD COLUMN test_id BIGINT UNSIGNED DEFAULT NULL",
            'drafts' => "ADD COLUMN drafts JSON NULL",
            'redrafts' => "ADD COLUMN redrafts JSON NULL",
            'writing_time' => "ADD COLUMN writing_time INT UNSIGNED DEFAULT 0",
            'test_answers' => "ADD COLUMN test_answers JSON DEFAULT NULL",
            'test_auto_score' => "ADD COLUMN test_auto_score INT UNSIGNED DEFAULT NULL",
        ],
        'notes' => [
            'type' => "ADD COLUMN type ENUM('note','memo','task') NOT NULL DEFAULT 'note'",
            'status' => "ADD COLUMN status ENUM('live','draft','archived') NOT NULL DEFAULT 'live'",
            'seen_writer' => "ADD COLUMN seen_writer ENUM('new','read','archived') NOT NULL DEFAULT 'new'",
            'seen_observer' => "ADD COLUMN seen_observer ENUM('new','read','archived') NOT NULL DEFAULT 'new'",
            'writing_time' => "ADD COLUMN writing_time INT UNSIGNED DEFAULT 0",
        ],
        'blocks' => [
            'facility_id' => "ADD COLUMN facility_id BIGINT UNSIGNED DEFAULT NULL",
            'group_id' => "ADD COLUMN group_id BIGINT UNSIGNED DEFAULT 0",
        ],
    ];
    foreach ($adds as $table => $cols) {
        if (!$db->tableExists($table)) {
            continue;
        }
        foreach ($cols as $name => $frag) {
            if ($db->columnExists($table, $name)) {
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE `{$table}` {$frag}");
                $did[] = $table . '.' . $name;
            } catch (PDOException $e) {
            }
        }
    }
    if ($db->columnExists('writs', 'block') && $db->columnExists('writs', 'block_id')) {
        try {
            $pdo->exec('UPDATE writs SET block_id = `block` WHERE (block_id = 0 OR block_id IS NULL) AND `block` > 0');
        } catch (PDOException $e) {
        }
    }
    if ($db->columnExists('users', 'editor') && $db->columnExists('users', 'editor_id')) {
        try {
            $pdo->exec('UPDATE users SET editor_id = editor WHERE editor_id IS NULL');
        } catch (PDOException $e) {
        }
    }
    if ($db->columnExists('users', 'blocks') && $db->columnExists('users', 'blocks_json')) {
        try {
            $pdo->exec("UPDATE users SET blocks_json = IFNULL(blocks, '[]') WHERE blocks_json IS NULL");
        } catch (PDOException $e) {
        }
    }
    if ($db->tableExists('writs')) {
        try {
            $pdo->exec("UPDATE writs SET drafts = JSON_ARRAY() WHERE drafts IS NULL OR drafts = 'null'");
            $pdo->exec("UPDATE writs SET redrafts = JSON_ARRAY() WHERE redrafts IS NULL OR redrafts = 'null'");
        } catch (PDOException $e) {
        }
    }
    return $did ? 'added ' . implode(',', $did) : '';
}

