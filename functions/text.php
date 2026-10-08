<?php
declare(strict_types=1);

function wordcount(?string $s): int
{
    $s = trim((string) $s);
    if ($s === '') {
        return 0;
    }
    $s = str_replace(["\u{2013}", "\u{2014}"], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return count(explode(' ', $s));
}

function clean_title(?string $s, int $max = 122): string
{
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $s)) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max);
    }
    return substr($s, 0, $max);
}

/** Blank title stores as Untitled. The form stays empty; this is the list/DB name. */
function writ_title(?string $s): string
{
    $t = clean_title($s);
    return $t !== '' ? $t : 'Untitled';
}

/** Blank work stores as task-{id} so the list always has a work name. */
function writ_work(?string $s, int $id): string
{
    $w = clean_title($s);
    return $w !== '' ? $w : 'task-' . $id;
}

function writ_when(?string $ts): string
{
    $ts = trim((string) $ts);
    if ($ts === '' || str_starts_with($ts, '0000') || str_starts_with($ts, '1970-01-01')) {
        return '';
    }
    return $ts;
}

/** Saved / submitted / edited times on a writ. Empty stamps are omitted. */
function writ_times(array $w): string
{
    $pairs = [
        'Opened' => 'draft_open_date',
        'Saved' => 'draft_save_date',
        'Submitted' => 'draft_submit_date',
        'Edited' => 'edits_date',
        'Viewed' => 'edits_viewed_date',
        'Correction saved' => 'corrected_save_date',
        'Correction submitted' => 'corrected_submit_date',
        'Scored' => 'scoring_date',
    ];
    $bits = [];
    foreach ($pairs as $label => $col) {
        $t = writ_when($w[$col] ?? null);
        if ($t !== '') {
            $bits[] = $label . ' ' . $t;
        }
    }
    if (!$bits) {
        return '';
    }
    return '<p class="sans dk writ-times">' . h(implode(' · ', $bits)) . '</p>';
}

function clean_body(?string $s): string
{
    $s = strip_tags((string) $s);
    $s = preg_replace("/[ \t]+/u", ' ', $s) ?? $s;
    $s = preg_replace("/\r\n?/", "\n", $s) ?? $s;
    $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
    return trim($s);
}

function json_arr($v): array
{
    if (is_array($v)) {
        return $v;
    }
    if ($v === null || $v === '' || $v === 'null') {
        return [];
    }
    $d = json_decode((string) $v, true);
    return is_array($d) ? $d : [];
}

function json_enc($v): string
{
    $j = json_encode($v, JSON_UNESCAPED_UNICODE);
    return $j === false ? '[]' : $j;
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '';
}

function normalize_answer(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = preg_replace("/[^\p{L}\p{N} ]+/u", '', $s) ?? $s;
    return $s;
}

function db_call(callable $fn): bool
{
    try {
        $fn();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @param array<string,mixed> $item */
function browser_save_remember(array $item): void
{
    $item['at'] = (int) ($item['at'] ?? time());
    $json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    if (!is_string($json) || $json === '') {
        return;
    }
    $cookie = strlen($json) <= 3500 ? $json : json_encode([
        'kind' => (string) ($item['kind'] ?? ''),
        'id' => $item['id'] ?? 0,
        'title' => (string) ($item['title'] ?? ''),
        'href' => (string) ($item['href'] ?? ''),
        'at' => $item['at'],
        'local' => 1,
    ], JSON_UNESCAPED_UNICODE);
    if (!is_string($cookie)) {
        return;
    }
    setcookie('winston99_bs', $cookie, [
        'expires' => time() + 86400 * 400,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['winston99_bs'] = $cookie;
}

/** @param array<string,mixed> $item */
function browser_save_script(array $item): string
{
    $json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    if (!is_string($json)) {
        return '';
    }
    return '<script>if (window.pwRememberBrowser) pwRememberBrowser(' . $json . ');</script>';
}

function browser_save_mount(string $kind): string
{
    $kind = htmlspecialchars($kind, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<div class="browser-save-mount" data-kind="' . $kind . '"></div>';
}

function msg_warning(string $text): string
{
    return '<p class="sans warning">' . h($text) . '</p>';
}

function msg_error(string $text): string
{
    return '<p class="sans error">' . h($text) . '</p>';
}

function msg_ok(string $text): string
{
    return '<p class="sans noticegreen noticehide">' . h($text) . '</p>';
}

