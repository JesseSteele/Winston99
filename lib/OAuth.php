<?php
declare(strict_types=1);

/**
 * Google and GitHub OAuth. SysAdmin puts client id/secret in config.
 * After social login, Authenticator / passkey still apply if the account has them.
 */
final class OAuth
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function enabled(string $p): bool
    {
        $c = $this->app->config['oauth'][$p] ?? [];
        return !empty($c['id']) && !empty($c['secret']);
    }

    /** @return array<string,string> provider => label, only those with keys */
    public function providers(): array
    {
        $all = ['google' => 'Google', 'github' => 'GitHub'];
        $out = [];
        foreach ($all as $p => $lab) {
            if ($this->enabled($p)) {
                $out[$p] = $lab;
            }
        }
        return $out;
    }

    public function start(string $provider, bool $link): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;
        $_SESSION['oauth_provider'] = $provider;
        $_SESSION['oauth_link'] = $link ? 1 : 0;
        $cb = $this->app->url('oauth.php');
        if ($provider === 'google') {
            $q = http_build_query([
                'client_id' => $this->cfg('google', 'id'),
                'redirect_uri' => $cb,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
                'access_type' => 'online',
            ]);
            return 'https://accounts.google.com/o/oauth2/v2/auth?' . $q;
        }
        if ($provider === 'github') {
            $q = http_build_query([
                'client_id' => $this->cfg('github', 'id'),
                'redirect_uri' => $cb,
                'scope' => 'user:email',
                'state' => $state,
            ]);
            return 'https://github.com/login/oauth/authorize?' . $q;
        }
        throw new RuntimeException('unknown provider');
    }

    /** @return array{ok:bool,need?:string,error?:string} */
    public function finish(string $code, string $state): array
    {
        if ($state === '' || $state !== ($_SESSION['oauth_state'] ?? '')) {
            return ['ok' => false, 'error' => 'State mismatch. Try again.'];
        }
        $p = (string) ($_SESSION['oauth_provider'] ?? '');
        $link = !empty($_SESSION['oauth_link']);
        unset($_SESSION['oauth_state'], $_SESSION['oauth_provider'], $_SESSION['oauth_link']);
        $profile = $this->profile($p, $code);
        if (!$profile) {
            return ['ok' => false, 'error' => 'Could not read that account.'];
        }
        $db = $this->app->db;
        $row = $db->one('SELECT * FROM oauth_identities WHERE provider = ? AND subject = ?', [$p, $profile['sub']]);
        if ($link) {
            $uid = $this->app->auth->id();
            if ($uid < 1) {
                return ['ok' => false, 'error' => 'Sign in first to link.'];
            }
            if ($row && (int) $row['user_id'] !== $uid) {
                return ['ok' => false, 'error' => 'That login is already linked to another account.'];
            }
            if (!$row) {
                $db->run(
                    'INSERT INTO oauth_identities (user_id, provider, subject, email) VALUES (?,?,?,?)',
                    [$uid, $p, $profile['sub'], $profile['email']]
                );
            }
            return ['ok' => true, 'link' => true];
        }
        $u = null;
        if ($row) {
            $u = $this->app->user->find((int) $row['user_id']);
        } elseif ($profile['email'] !== '') {
            $u = $this->app->user->findByEmail($profile['email']);
            if ($u) {
                $db->run(
                    'INSERT INTO oauth_identities (user_id, provider, subject, email) VALUES (?,?,?,?)',
                    [$u['id'], $p, $profile['sub'], $profile['email']]
                );
            }
        }
        if (!$u) {
            $u = $this->provision($profile);
            $db->run(
                'INSERT INTO oauth_identities (user_id, provider, subject, email) VALUES (?,?,?,?)',
                [$u['id'], $p, $profile['sub'], $profile['email']]
            );
        }
        if (($u['status'] ?? '') !== 'active') {
            return ['ok' => false, 'error' => 'This account is not active.'];
        }
        $r = $this->app->auth->afterFactor($u, 'oauth');
        if ($r === 'totp') {
            return ['ok' => true, 'need' => 'totp'];
        }
        if ($r !== 'ok') {
            return ['ok' => false, 'error' => 'This account is not active.'];
        }
        return ['ok' => true];
    }

    public function list(int $uid): array
    {
        return $this->app->db->all('SELECT provider, email, created_at FROM oauth_identities WHERE user_id = ?', [$uid]);
    }

    public function unlink(int $uid, string $p): void
    {
        $this->app->db->run('DELETE FROM oauth_identities WHERE user_id = ? AND provider = ?', [$uid, $p]);
    }

    private function cfg(string $p, string $k): string
    {
        return (string) ($this->app->config['oauth'][$p][$k] ?? '');
    }

    /** @return array{sub:string,email:string,name:string}|null */
    private function profile(string $p, string $code): ?array
    {
        $cb = $this->app->url('oauth.php');
        if ($p === 'google') {
            $tok = $this->postForm('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => $this->cfg('google', 'id'),
                'client_secret' => $this->cfg('google', 'secret'),
                'redirect_uri' => $cb,
                'grant_type' => 'authorization_code',
            ]);
            $access = $tok['access_token'] ?? '';
            if ($access === '') {
                return null;
            }
            $info = $this->getJson('https://openidconnect.googleapis.com/v1/userinfo', $access);
            return [
                'sub' => (string) ($info['sub'] ?? ''),
                'email' => (string) ($info['email'] ?? ''),
                'name' => (string) ($info['name'] ?? $info['email'] ?? 'Writer'),
            ];
        }
        if ($p === 'github') {
            $tok = $this->postForm('https://github.com/login/oauth/access_token', [
                'code' => $code,
                'client_id' => $this->cfg('github', 'id'),
                'client_secret' => $this->cfg('github', 'secret'),
                'redirect_uri' => $cb,
            ], true);
            $access = $tok['access_token'] ?? '';
            if ($access === '') {
                return null;
            }
            $info = $this->getJson('https://api.github.com/user', $access);
            $email = (string) ($info['email'] ?? '');
            if ($email === '') {
                $emails = $this->getJson('https://api.github.com/user/emails', $access);
                if (is_array($emails)) {
                    foreach ($emails as $e) {
                        if (!empty($e['primary']) && !empty($e['email'])) {
                            $email = (string) $e['email'];
                            break;
                        }
                    }
                }
            }
            $sub = (string) ($info['id'] ?? '');
            return [
                'sub' => $sub,
                'email' => $email,
                'name' => (string) ($info['name'] ?? $info['login'] ?? 'Writer'),
            ];
        }
        return null;
    }

    private function provision(array $profile): array
    {
        $base = preg_replace('/[^A-Za-z0-9]/', '', strtok($profile['email'] ?: $profile['name'], '@')) ?: 'user';
        $base = substr($base, 0, 20);
        if (strlen($base) < 4) {
            $base .= 'user';
        }
        $uname = $base;
        $n = 0;
        while ($this->app->user->findByUsername($uname)) {
            $n++;
            $uname = substr($base, 0, 24) . $n;
        }
        $email = $profile['email'] !== '' ? $profile['email'] : $uname . '@oauth.local';
        $fid = $this->app->db->val('SELECT id FROM facilities ORDER BY id LIMIT 1');
        $id = $this->app->user->create([
            'type' => 'writer',
            'facility_id' => $fid,
            'username' => $uname,
            'email' => $email,
            'name' => $profile['name'] ?: $uname,
            'pass' => null,
            'status' => 'active',
        ]);
        return $this->app->user->find($id);
    }

    private function postForm(string $url, array $fields, bool $acceptJson = false): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
        parse_str($raw, $out);
        return is_array($out) ? $out : [];
    }

    private function getJson(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'User-Agent: Winston-99',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $j = json_decode((string) $raw, true);
        return is_array($j) ? $j : [];
    }
}
