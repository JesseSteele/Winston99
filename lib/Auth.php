<?php
declare(strict_types=1);

final class Auth
{
    private App $app;
    private ?array $user = null;

    private const RANK = [
        'writer' => 1,
        'observer' => 2,
        'editor' => 3,
        'supervisor' => 4,
        'admin' => 5,
        'superintendent' => 6,
    ];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');
            session_start();
        }
        if (!empty($_SESSION['user_id']) && $this->app->db) {
            $u = $this->app->user->find((int) $_SESSION['user_id']);
            if ($u && $u['status'] === 'active') {
                $this->user = $u;
            } else {
                $this->clear();
            }
        }
    }

    public function user(): ?array
    {
        return $this->user;
    }

    public function id(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    public function type(): string
    {
        return (string) ($this->user['type'] ?? '');
    }

    public function is(string $type): bool
    {
        return $this->type() === $type;
    }

    public function atLeast(string $type): bool
    {
        $have = self::RANK[$this->type()] ?? 0;
        $need = self::RANK[$type] ?? 99;
        return $have >= $need;
    }

    public function facilityId(): ?int
    {
        if (!$this->user) {
            return null;
        }
        if ($this->is('superintendent') && !empty($_SESSION['facility_id'])) {
            return (int) $_SESSION['facility_id'];
        }
        $fid = $this->user['facility_id'] ?? null;
        return $fid === null ? null : (int) $fid;
    }

    public function requireUser(): array
    {
        if (!$this->user) {
            if ($this->app->isAjax()) {
                $this->app->json(['ok' => false, 'error' => 'auth'], 401);
            }
            $this->app->redirect('login.php');
        }
        return $this->user;
    }

    public function requireRole(string ...$types): array
    {
        $u = $this->requireUser();
        if ($this->is('superintendent')) {
            return $u;
        }
        foreach ($types as $t) {
            if ($this->is($t) || $this->atLeast($t) && in_array($t, ['supervisor', 'admin', 'editor'], true)) {
                // fallthrough handled below
            }
        }
        foreach ($types as $t) {
            if ($this->is($t)) {
                return $u;
            }
        }
        // Admin may use editor/observer/supervisor tools.
        if ($this->is('admin') || $this->is('supervisor')) {
            foreach ($types as $t) {
                if (in_array($t, ['editor', 'observer', 'supervisor', 'admin', 'writer'], true)) {
                    return $u;
                }
            }
        }
        if ($this->app->isAjax()) {
            $this->app->json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $this->app->redirect('');
        return $u;
    }

    public function login(string $username, string $pass, string $ip): string
    {
        if ($this->app->clickathon->blocked($ip) || $this->app->clickathon->tooManyTries()) {
            return 'blocked';
        }
        if (!preg_match('/^[A-Za-z0-9]{4,32}$/', $username)) {
            $this->app->clickathon->fail($ip, $username);
            return 'bad';
        }
        $u = $this->app->user->findByUsername($username);
        if (!$u || !$this->app->user->passwordLoginOn($u) || !password_verify($pass, (string) $u['pass'])) {
            $this->app->clickathon->fail($ip, $username);
            if ($u) {
                $this->logAccount((int) $u['id'], 'login_fail', 'password', 0);
            }
            return 'bad';
        }
        if ($u['status'] !== 'active') {
            return 'inactive';
        }
        $this->app->clickathon->clear();
        session_regenerate_id(true);
        return $this->afterFactor($u, 'password');
    }

    /** After password, passkey, or linked login: TOTP unless this machine is remembered (not for password). */
    public function afterFactor(array $u, string $via): string
    {
        if (($u['status'] ?? '') !== 'active') {
            return 'inactive';
        }
        session_regenerate_id(true);
        if (!empty($u['totp_enabled'])) {
            if ($via !== 'password' && $this->deviceTrusted((int) $u['id'])) {
                $this->establish($u);
                $this->logAccount((int) $u['id'], 'login', $via);
                return 'ok';
            }
            $_SESSION['pending_2fa'] = (int) $u['id'];
            $_SESSION['pending_2fa_via'] = $via;
            return 'totp';
        }
        $this->establish($u);
        $this->logAccount((int) $u['id'], 'login', $via);
        return 'ok';
    }

    public function totpVia(): string
    {
        return (string) ($_SESSION['pending_2fa_via'] ?? 'password');
    }

    /** Remember-this-machine is for accounts that already have a passkey or linked login. */
    public function pendingCanRemember(): bool
    {
        return $this->userCanRemember((int) ($_SESSION['pending_2fa'] ?? 0));
    }

    public function userCanRemember(int $userId): bool
    {
        if ($userId < 1 || !$this->app->db) {
            return false;
        }
        try {
            $this->app->need('passkey');
            if ($this->app->passkey->list($userId) !== []) {
                return true;
            }
        } catch (Throwable $e) {
        }
        try {
            $this->app->need('oauth');
            if ($this->app->oauth->list($userId) !== []) {
                return true;
            }
        } catch (Throwable $e) {
        }
        return false;
    }

    public function finishTotp(string $code, bool $remember = false): bool
    {
        $id = (int) ($_SESSION['pending_2fa'] ?? 0);
        if ($id < 1) {
            return false;
        }
        $u = $this->app->user->find($id);
        if (!$u || empty($u['totp_secret'])) {
            return false;
        }
        $this->app->need('totp');
        if (!$this->app->totp->verify($u['totp_secret'], $code)) {
            return false;
        }
        $via = $this->totpVia();
        unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_via']);
        $this->establish($u);
        $this->logAccount((int) $u['id'], 'login', $via . '+authenticator');
        if ($remember && $this->userCanRemember((int) $u['id'])) {
            $this->rememberDevice((int) $u['id']);
        }
        return true;
    }

    public function establish(array $u): void
    {
        $_SESSION['user_id'] = (int) $u['id'];
        $_SESSION['username'] = $u['username'];
        $_SESSION['type'] = $u['type'];
        $_SESSION['name'] = $u['name'];
        $this->user = $u;
        unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_via']);
        if ($this->app->db && $this->app->db->columnExists('users', 'last_seen')) {
            $this->app->db->run('UPDATE users SET last_seen = NOW() WHERE id = ?', [(int) $u['id']]);
        }
    }

    public function logout(): void
    {
        $this->clear();
        session_regenerate_id(true);
    }

    private function clear(): void
    {
        $this->user = null;
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['type'], $_SESSION['name'], $_SESSION['pending_2fa'], $_SESSION['pending_2fa_via'], $_SESSION['facility_id']);
    }

    public function canEmailReset(array $u): bool
    {
        return ($u['type'] ?? '') !== 'superintendent';
    }

    public function defaultNotifyPrefs(string $type): array
    {
        $all = Notify::keysFor($type);
        $inapp = [];
        $email = [];
        foreach ($all as $k) {
            $inapp[$k] = true;
            $email[$k] = false;
        }
        return ['inapp' => $inapp, 'email' => $email];
    }

    private const TRUST_COOKIE = 'winston99_trust';
    private const TRUST_DAYS = 30;

    public function deviceTrusted(int $userId): bool
    {
        if ($userId < 1 || !$this->app->db || !$this->app->db->tableExists('totp_devices')) {
            return false;
        }
        $raw = (string) ($_COOKIE[self::TRUST_COOKIE] ?? '');
        if (!preg_match('/^([a-f0-9]{16}):([a-f0-9]{64})$/', $raw, $m)) {
            return false;
        }
        $row = $this->app->db->one(
            'SELECT id, user_id, token_hash, expires_at FROM totp_devices WHERE selector = ?',
            [$m[1]]
        );
        if (!$row || (int) $row['user_id'] !== $userId) {
            return false;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            $this->app->db->run('DELETE FROM totp_devices WHERE id = ?', [(int) $row['id']]);
            return false;
        }
        if (!hash_equals((string) $row['token_hash'], hash('sha256', hex2bin($m[2]) ?: ''))) {
            return false;
        }
        $this->app->db->run('UPDATE totp_devices SET last_used_at = NOW() WHERE id = ?', [(int) $row['id']]);
        return true;
    }

    public function rememberDevice(int $userId): void
    {
        if ($userId < 1 || !$this->app->db || !$this->app->db->tableExists('totp_devices')) {
            return;
        }
        $this->app->db->run('DELETE FROM totp_devices WHERE user_id = ? AND expires_at < NOW()', [$userId]);
        $extra = $this->app->db->all(
            'SELECT id FROM totp_devices WHERE user_id = ? ORDER BY id DESC',
            [$userId]
        );
        if (count($extra) >= 10) {
            foreach (array_slice($extra, 9) as $old) {
                $this->app->db->run('DELETE FROM totp_devices WHERE id = ?', [(int) $old['id']]);
            }
        }
        $selector = bin2hex(random_bytes(8));
        $validator = random_bytes(32);
        $this->app->db->run(
            'INSERT INTO totp_devices (user_id, selector, token_hash, expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 30 DAY))',
            [$userId, $selector, hash('sha256', $validator)]
        );
        setcookie(self::TRUST_COOKIE, $selector . ':' . bin2hex($validator), [
            'expires' => time() + (self::TRUST_DAYS * 86400),
            'path' => $this->cookiePath(),
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function forgetDevices(int $userId): void
    {
        if ($userId < 1 || !$this->app->db || !$this->app->db->tableExists('totp_devices')) {
            return;
        }
        $this->app->db->run('DELETE FROM totp_devices WHERE user_id = ?', [$userId]);
    }

    public function canManageAccount(array $target): bool
    {
        if (!$this->user) {
            return false;
        }
        $tid = (int) ($target['id'] ?? 0);
        if ($tid < 1 || $tid === $this->id()) {
            return false;
        }
        if ($this->is('superintendent')) {
            return true;
        }
        if (!$this->atLeast('supervisor')) {
            return false;
        }
        $tr = self::RANK[$target['type'] ?? ''] ?? 0;
        if ($tr >= (self::RANK[$this->type()] ?? 0)) {
            return false;
        }
        $fid = $this->facilityId();
        if ($fid && (int) ($target['facility_id'] ?? 0) !== (int) $fid) {
            return false;
        }
        return true;
    }

    private function logAccount(int $userId, string $action, string $detail = '', ?int $actorId = null): void
    {
        try {
            $this->app->need('audit');
            $this->app->audit->record($userId, $action, $detail, $actorId);
        } catch (Throwable $e) {
        }
    }

    private function cookiePath(): string
    {
        $host = trim((string) ($this->app->config['host'] ?? ''), '/');
        $slash = strpos($host, '/');
        if ($slash === false) {
            return '/';
        }
        return '/' . substr($host, $slash + 1);
    }
}
