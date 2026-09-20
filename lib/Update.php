<?php
declare(strict_types=1);

final class Update
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function run(bool $pullGit = true): array
    {
        $log = [];
        $root = $this->app->root;
        if ($pullGit) {
            $log[] = $this->pull($root);
        }
        $log[] = $this->migrate();
        return $log;
    }

    private function pull(string $root): string
    {
        $repo = (string) ($this->app->config['github'] ?? 'https://github.com/JesseSteele/Winston99.git');
        $branch = $this->stream();
        if (is_dir($root . '/.git')) {
            $git = 'git -C ' . escapeshellarg($root);
            $out = [];
            $code = 0;
            exec($git . ' fetch origin 2>&1', $out, $code);
            $verify = [];
            $vcode = 0;
            exec($git . ' rev-parse --verify ' . escapeshellarg('origin/' . $branch) . ' 2>&1', $verify, $vcode);
            if ($vcode !== 0 && $branch === 'main') {
                $branch = 'master';
                $out[] = 'stream main is not on origin; using master';
            }
            $out2 = [];
            $code2 = 0;
            exec(
                $git . ' checkout -B ' . escapeshellarg($branch) . ' ' . escapeshellarg('origin/' . $branch)
                . ' 2>&1 && ' . $git . ' reset --hard ' . escapeshellarg('origin/' . $branch) . ' 2>&1',
                $out2,
                $code2
            );
            return 'git [' . $branch . ']: ' . implode("\n", array_merge($out, $out2)) . " (exit {$code2})";
        }
        $tmp = sys_get_temp_dir() . '/winston99.update.' . getmypid();
        $cmd = 'git clone --depth 1 --branch ' . escapeshellarg($branch) . ' '
            . escapeshellarg($repo) . ' ' . escapeshellarg($tmp) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code !== 0 && $branch === 'main') {
            $branch = 'master';
            $cmd = 'git clone --depth 1 --branch ' . escapeshellarg($branch) . ' '
                . escapeshellarg($repo) . ' ' . escapeshellarg($tmp) . ' 2>&1';
            $out = [];
            exec($cmd, $out, $code);
        }
        if ($code !== 0) {
            return 'clone failed: ' . implode("\n", $out);
        }
        $this->copyTree($tmp, $root);
        $this->rmDir($tmp);
        return 'cloned ' . $branch . ' over files (config.php kept)';
    }

    /** SysAdmin stream in config.php. github_branch is the old name. */
    private function stream(): string
    {
        $s = trim((string) ($this->app->config['stream'] ?? $this->app->config['github_branch'] ?? 'master'));
        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $s)) {
            return 'master';
        }
        return $s;
    }

    private function copyTree(string $from, string $to): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), strlen($from) + 1);
            if ($rel === 'config.php' || str_starts_with($rel, '.git')) {
                continue;
            }
            $dest = $to . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0755, true);
                }
            } else {
                copy($item->getPathname(), $dest);
                if (is_executable($item->getPathname())) {
                    @chmod($dest, 0755);
                }
            }
        }
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function migrate(): string
    {
        require_once $this->app->root . '/sql/migrate.php';
        return winston99_migrate($this->app);
    }
}
