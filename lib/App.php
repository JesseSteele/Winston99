<?php
declare(strict_types=1);

/**
 * App is the one object every page talks to.
 * Modules hang off it only after $import asks for them.
 */
final class App
{
    public string $root;
    public array $config;
    public ?Db $db;
    public ?string $bootError = null;

    public $auth = null;
    public $csrf = null;
    public $mail = null;
    public $view = null;
    public $user = null;
    public $facility = null;
    public $block = null;
    public $note = null;
    public $writ = null;
    public $test = null;
    public $notify = null;
    public $update = null;
    public $totp = null;
    public $passkey = null;
    public $clickathon = null;
    public $oauth = null;
    public $writlist = null;
    public $audit = null;

    /** @var array<string,string> */
    private const CATALOG = [
        'html'      => 'functions/html.php',
        'text'      => 'functions/text.php',
        'auth'      => 'lib/Auth.php',
        'csrf'      => 'lib/Csrf.php',
        'mail'      => 'lib/Mailer.php',
        'view'      => 'lib/View.php',
        'user'      => 'lib/UserRepo.php',
        'facility'  => 'lib/FacilityRepo.php',
        'block'     => 'lib/BlockRepo.php',
        'note'      => 'lib/NoteRepo.php',
        'writ'      => 'lib/WritRepo.php',
        'test'      => 'lib/TestRepo.php',
        'notify'    => 'lib/Notify.php',
        'update'    => 'lib/Update.php',
        'totp'      => 'lib/Totp.php',
        'passkey'   => 'lib/WebAuthn.php',
        'clickathon'=> 'lib/Clickathon.php',
        'oauth'     => 'lib/OAuth.php',
        'writlist'  => 'lib/WritList.php',
        'audit'     => 'lib/Audit.php',
        'form'      => 'lib/Form.php',
    ];

    /** @var array<string,true> */
    private array $loaded = [];

    public function __construct(string $root, array $config, ?Db $db)
    {
        $this->root = $root;
        $this->config = $config;
        $this->db = $db;
    }

    /** Load only the modules this page named. */
    public function load(array $names): void
    {
        foreach ($names as $name) {
            $this->need((string) $name);
        }
    }

    public function need(string $name): void
    {
        if (isset($this->loaded[$name])) {
            return;
        }
        if (!isset(self::CATALOG[$name])) {
            throw new RuntimeException('Unknown import: ' . $name);
        }
        $path = $this->root . '/' . self::CATALOG[$name];
        require_once $path;
        $this->loaded[$name] = true;
        $this->attach($name);
    }

    private function attach(string $name): void
    {
        switch ($name) {
            case 'auth':
                $this->need('user');
                $this->need('clickathon');
                $this->auth = new Auth($this);
                $this->auth->start();
                break;
            case 'csrf':
                $this->csrf = new Csrf();
                break;
            case 'mail':
                $this->mail = new Mailer($this->config['mail'] ?? ['transport' => 'off'], $this->origin());
                break;
            case 'view':
                $this->need('html');
                $this->need('auth');
                $this->need('notify');
                $this->view = new View($this);
                break;
            case 'user':
                $this->user = new UserRepo($this);
                break;
            case 'facility':
                $this->facility = new FacilityRepo($this);
                break;
            case 'block':
                $this->block = new BlockRepo($this);
                break;
            case 'note':
                $this->note = new NoteRepo($this);
                break;
            case 'writ':
                $this->writ = new WritRepo($this);
                break;
            case 'test':
                $this->need('text');
                require_once $this->root . '/lib/TestParser.php';
                $this->test = new TestRepo($this);
                break;
            case 'notify':
                $this->need('mail');
                $this->need('user');
                $this->notify = new Notify($this);
                break;
            case 'update':
                $this->update = new Update($this);
                break;
            case 'totp':
                $this->totp = new Totp();
                break;
            case 'passkey':
                $this->passkey = new WebAuthn($this);
                break;
            case 'clickathon':
                $this->clickathon = new Clickathon($this);
                break;
            case 'oauth':
                $this->need('auth');
                $this->need('user');
                $this->oauth = new OAuth($this);
                break;
            case 'audit':
                $this->audit = new Audit($this);
                break;
            case 'form':
                $this->need('html');
                break;
            case 'writlist':
                $this->need('html');
                $this->need('csrf');
                $this->need('writ');
                $this->need('user');
                $this->need('note');
                $this->need('block');
                $this->writlist = new WritList($this);
                break;
            case 'html':
            case 'text':
                break;
        }
    }

    public function title(): string
    {
        return (string) ($this->config['site_title'] ?? 'PinkWrite 99');
    }

    /** Host setting is scheme-less. Always https. */
    public function origin(): string
    {
        $host = trim((string) ($this->config['host'] ?? ''), '/');
        if ($host === '') {
            return '';
        }
        return 'https://' . $host;
    }

    public function url(string $path = ''): string
    {
        $base = rtrim($this->origin(), '/');
        $path = ltrim($path, '/');
        return $path === '' ? $base : $base . '/' . $path;
    }

    public function isAjax(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_POST['ajax']) && $_POST['ajax'] === '1');
    }

    public function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function redirect(string $path): void
    {
        if (preg_match('#^https?://#i', $path)) {
            header('Location: ' . $path);
        } else {
            header('Location: ' . $this->url($path));
        }
        exit;
    }
}
