<?php
declare(strict_types=1);

final class Audit
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function record(int $userId, string $action, string $detail = '', ?int $actorId = null): void
    {
        if ($userId < 1 || !$this->app->db || !$this->app->db->tableExists('account_log')) {
            return;
        }
        $actor = $actorId;
        $actorName = '';
        if ($actor === null && $this->app->auth && $this->app->auth->id() > 0) {
            $actor = $this->app->auth->id();
        }
        if ($actor && $this->app->auth && $this->app->auth->id() === $actor && $this->app->auth->user()) {
            $u = $this->app->auth->user();
            $actorName = (string) $u['name'] . ' (' . (string) $u['type'] . ')';
        } elseif ($actor) {
            $this->app->need('user');
            $u = $this->app->user->find($actor);
            if ($u) {
                $actorName = (string) $u['name'] . ' (' . (string) $u['type'] . ')';
            }
        }
        $this->app->db->run(
            'INSERT INTO account_log (user_id, actor_id, actor_name, action, detail, ip) VALUES (?,?,?,?,?,?)',
            [$userId, $actor ?: null, $actorName, $action, $detail, client_ip()]
        );
    }

    public function lastLogin(int $userId): ?array
    {
        if (!$this->app->db || !$this->app->db->tableExists('account_log')) {
            return null;
        }
        return $this->app->db->one(
            "SELECT * FROM account_log WHERE user_id = ? AND action = 'login' ORDER BY id DESC LIMIT 1",
            [$userId]
        );
    }

    public function forUser(int $userId, int $limit = 80): array
    {
        if (!$this->app->db || !$this->app->db->tableExists('account_log')) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        return $this->app->db->all(
            "SELECT * FROM account_log WHERE user_id = ? ORDER BY id DESC LIMIT {$limit}",
            [$userId]
        );
    }

    public static function label(string $action): string
    {
        return match ($action) {
            'login' => 'Login',
            'login_fail' => 'Failed login',
            'password' => 'Password',
            'password_off' => 'Password login off',
            'password_on' => 'Password login on',
            'totp_on' => 'Authenticator on',
            'totp_off' => 'Authenticator removed',
            'contact' => 'Name or email',
            'notify' => 'Notification settings',
            'status' => 'Status',
            default => $action,
        };
    }
}
