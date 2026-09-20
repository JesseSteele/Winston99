<?php
declare(strict_types=1);

/** SysAdmin mail. transport: off | mail | smtp. */
final class Mailer
{
    private array $cfg;
    private string $origin;

    public function __construct(array $cfg, string $origin)
    {
        $this->cfg = $cfg;
        $this->origin = $origin;
    }

    public function enabled(): bool
    {
        $t = $this->cfg['transport'] ?? 'off';
        return $t === 'mail' || $t === 'smtp';
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if (!$this->enabled() || $to === '') {
            return false;
        }
        $from = (string) ($this->cfg['from'] ?? 'noreply@localhost');
        $fromName = (string) ($this->cfg['from_name'] ?? 'Winston 99');
        $transport = $this->cfg['transport'] ?? 'mail';
        if ($transport === 'smtp') {
            return $this->smtp($to, $from, $fromName, $subject, $body);
        }
        $headers = 'From: ' . $fromName . ' <' . $from . ">\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        return @mail($to, $subject, $body, $headers);
    }

    private function smtp(string $to, string $from, string $fromName, string $subject, string $body): bool
    {
        $host = (string) ($this->cfg['smtp_host'] ?? '127.0.0.1');
        $port = (int) ($this->cfg['smtp_port'] ?? 587);
        $secure = (string) ($this->cfg['smtp_secure'] ?? 'tls');
        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15);
        if (!$fp) {
            return false;
        }
        $this->expect($fp, '220');
        $this->cmd($fp, 'EHLO winston99');
        $this->expect($fp, '250');
        if ($secure === 'tls') {
            $this->cmd($fp, 'STARTTLS');
            $this->expect($fp, '220');
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->cmd($fp, 'EHLO winston99');
            $this->expect($fp, '250');
        }
        $user = (string) ($this->cfg['smtp_user'] ?? '');
        $pass = (string) ($this->cfg['smtp_pass'] ?? '');
        if ($user !== '') {
            $this->cmd($fp, 'AUTH LOGIN');
            $this->expect($fp, '334');
            $this->cmd($fp, base64_encode($user));
            $this->expect($fp, '334');
            $this->cmd($fp, base64_encode($pass));
            $this->expect($fp, '235');
        }
        $this->cmd($fp, 'MAIL FROM:<' . $from . '>');
        $this->expect($fp, '250');
        $this->cmd($fp, 'RCPT TO:<' . $to . '>');
        $this->expect($fp, '250');
        $this->cmd($fp, 'DATA');
        $this->expect($fp, '354');
        $msg = 'From: ' . $fromName . ' <' . $from . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $body . "\r\n.";
        $this->cmd($fp, $msg);
        $this->expect($fp, '250');
        $this->cmd($fp, 'QUIT');
        fclose($fp);
        return true;
    }

    private function cmd($fp, string $line): void
    {
        fwrite($fp, $line . "\r\n");
    }

    private function expect($fp, string $code): void
    {
        $line = fgets($fp, 512);
        if ($line === false || strpos($line, $code) !== 0) {
            throw new RuntimeException('SMTP: ' . trim((string) $line));
        }
        while ($line !== false && isset($line[3]) && $line[3] === '-') {
            $line = fgets($fp, 512);
        }
    }
}
