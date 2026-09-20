<?php
declare(strict_types=1);

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Empty-table copy: no table, no sort row — just this. */
function empty_list(): string
{
    return '<p class="lt sans"><b>Nothing yet</b></p>';
}

/** Cache-bust query for a static file: content hash, so a stale sheet cannot survive a CSS edit. */
function winston99_asset_v(string $abs): string
{
    $h = is_file($abs) ? md5_file($abs) : false;
    return is_string($h) && $h !== '' ? substr($h, 0, 10) : (string) time();
}

/** Theme id => display name from css/theme-*.css `@theme Name` comments. */
function winston99_themes(): array
{
    $dir = dirname(__DIR__) . '/css';
    $found = [];
    foreach (glob($dir . '/theme-*.css') ?: [] as $f) {
        $id = basename($f, '.css');
        $name = $id;
        $raw = (string) file_get_contents($f);
        if (preg_match('/@theme\s+([^\r\n*]+)/', $raw, $m)) {
            $name = trim($m[1]);
        }
        $found[$id] = $name;
    }
    $out = [];
    foreach (['theme-dusk-desk', 'theme-twilight-write', 'theme-city-night', 'theme-cream-write', 'theme-pink-dust', 'theme-99'] as $id) {
        if (isset($found[$id])) {
            $out[$id] = $found[$id];
            unset($found[$id]);
        }
    }
    foreach ($found as $id => $name) {
        $out[$id] = $name;
    }
    return $out;
}

function winston99_theme_id(?array $user): string
{
    $want = '';
    if ($user) {
        $p = json_arr($user['notify_prefs'] ?? []);
        $want = (string) ($p['theme'] ?? '');
    }
    if ($want === '' && !empty($_COOKIE['winston99_theme'])) {
        $want = (string) $_COOKIE['winston99_theme'];
    }
    $want = preg_replace('/[^a-z0-9\-]/', '', $want) ?? '';
    $dir = dirname(__DIR__) . '/css';
    $def = 'theme-dusk-desk';
    if ($want !== '' && str_starts_with($want, 'theme-') && is_file($dir . '/' . $want . '.css')) {
        return $want;
    }
    if (is_file($dir . '/' . $def . '.css')) {
        return $def;
    }
    $ids = array_keys(winston99_themes());
    return (string) ($ids[0] ?? $def);
}

/** Open/closed blocks list the editor came from. */
function winston99_blocks_return(?string $raw = null): string
{
    $allow = ['blocks-closed.php', 'blocks-editor.php'];
    foreach ([$raw, $_POST['return'] ?? '', $_GET['return'] ?? '', $_SERVER['HTTP_REFERER'] ?? ''] as $c) {
        if (!is_string($c) || $c === '') {
            continue;
        }
        $base = basename((string) (parse_url($c, PHP_URL_PATH) ?: $c));
        if (in_array($base, $allow, true)) {
            return $base;
        }
    }
    return 'blocks-editor.php';
}

/** Active/dormant people list the staff came from. */
function winston99_people_return(string $type, ?string $raw = null): string
{
    $allow = match ($type) {
        'observer' => ['observers.php', 'observers-dormant.php'],
        'writer' => ['enrollment.php', 'writers-dormant.php'],
        'admin' => ['administrators.php', 'admins-dormant.php'],
        default => ['editors.php', 'editors-dormant.php'],
    };
    foreach ([$raw, $_POST['return'] ?? '', $_GET['return'] ?? '', $_SERVER['HTTP_REFERER'] ?? ''] as $c) {
        if (!is_string($c) || $c === '') {
            continue;
        }
        $base = basename((string) (parse_url($c, PHP_URL_PATH) ?: $c));
        if (in_array($base, $allow, true)) {
            return $base;
        }
    }
    return $allow[0];
}

/** Active/dormant editors list the admin came from. */
function winston99_editors_return(?string $raw = null): string
{
    return winston99_people_return('editor', $raw);
}

/** Open/closed facilities list the superintendent came from. */
function winston99_facilities_return(?string $raw = null): string
{
    $allow = ['facilities.php', 'facilities-closed.php'];
    foreach ([$raw, $_POST['return'] ?? '', $_GET['return'] ?? '', $_SERVER['HTTP_REFERER'] ?? ''] as $c) {
        if (!is_string($c) || $c === '') {
            continue;
        }
        $base = basename((string) (parse_url($c, PHP_URL_PATH) ?: $c));
        if (in_array($base, $allow, true)) {
            return $base;
        }
    }
    return 'facilities.php';
}

function winston99_set_theme_cookie(string $id): void
{
    setcookie('winston99_theme', $id, [
        'expires' => time() + 86400 * 400,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['winston99_theme'] = $id;
}

/** Original geometric marks (passkey, G) plus nominative GitHub silhouette for login buttons. */
function brand_icon(string $which): string
{
    $svg = match ($which) {
        'passkey' => '<svg class="id-svg" viewBox="0 0 24 24" aria-hidden="true"><circle cx="8.2" cy="12" r="3.6" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="8.2" cy="12" r="1.1" fill="currentColor"/><path fill="currentColor" d="M11.6 11.15h9.2v1.7h-2.05V16h-1.9v-3.15h-1.4V16h-1.9v-3.15h-1.95z"/></svg>',
        'google' => '<svg class="id-svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12.2 11.15v2.55h4.08c-.2 1.05-1.18 2.92-4.08 2.92-2.46 0-4.47-2.04-4.47-4.55s2.01-4.55 4.47-4.55c1.4 0 2.34.6 2.88 1.14l1.97-1.91C15.7 5.4 14.12 4.7 12.2 4.7 8.22 4.7 5 7.9 5 11.92S8.22 19.15 12.2 19.15c3.96 0 6.55-2.78 6.55-6.7 0-.45-.05-.78-.12-1.12H12.2z"/></svg>',
        'github' => '<svg class="id-svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2C6.48 2 2 6.58 2 12.26c0 4.52 2.87 8.35 6.84 9.71.5.1.68-.22.68-.49 0-.24-.01-.87-.01-1.71-2.78.62-3.37-1.37-3.37-1.37-.45-1.18-1.11-1.5-1.11-1.5-.91-.64.07-.63.07-.63 1 .07 1.53 1.06 1.53 1.06.9 1.57 2.36 1.11 2.94.85.09-.66.35-1.11.63-1.37-2.22-.26-4.56-1.14-4.56-5.07 0-1.12.39-2.03 1.03-2.75-.1-.26-.45-1.3.1-2.7 0 0 .84-.27 2.75 1.05A9.3 9.3 0 0 1 12 6.84c.85 0 1.71.12 2.51.34 1.9-1.32 2.74-1.05 2.74-1.05.55 1.4.21 2.44.1 2.7.64.72 1.03 1.63 1.03 2.75 0 3.94-2.34 4.8-4.58 5.06.36.32.68.94.68 1.9 0 1.37-.01 2.47-.01 2.8 0 .27.18.59.69.49A10.05 10.05 0 0 0 22 12.26C22 6.58 17.52 2 12 2z"/></svg>',
        'check' => '<svg class="id-svg id-check-svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" d="M5 12.5 10 17.5 19 6.5"/></svg>',
        default => '',
    };
    return '<span class="id-ico">' . $svg . '</span>';
}

function id_login_button(string $kind, string $label, string $href = '', string $attrs = ''): string
{
    $inner = brand_icon($kind) . '<span class="id-lab">' . h($label) . '</span>';
    if ($href !== '') {
        return '<a class="id-btn" href="' . h($href) . '"' . ($attrs !== '' ? ' ' . $attrs : '') . '>' . $inner . '</a>';
    }
    return '<button type="button" class="id-btn"' . ($attrs !== '' ? ' ' . $attrs : '') . '>' . $inner . '</button>';
}

/** Same shape as the original set_button: <a><button class> so CSS on button.* applies. */
function button(string $text, string $title, string $href, string $class = 'navButton'): string
{
    return '<a title="' . h($title) . '" href="' . h($href) . '"><button type="button" class="' . h($class) . '">' . h($text) . '</button></a>';
}

function post_button(string $text, string $title, string $action, string $name, string $value, string $class, string $csrf): string
{
    return '<form action="' . h($action) . '" method="post" style="display:inline">'
        . '<input type="hidden" name="_csrf" value="' . h($csrf) . '">'
        . '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">'
        . '<input type="submit" title="' . h($title) . '" value="' . h($text) . '" class="' . h($class) . '">'
        . '</form>';
}

function get_switch(string $text, string $title, string $action, string $name, string $value, string $class): string
{
    return '<form action="' . h($action) . '" method="get" style="display:inline">'
        . '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">'
        . '<input type="submit" title="' . h($title) . '" value="' . h($text) . '" class="' . h($class) . '">'
        . '</form>';
}

function dead_switch(string $text, string $title, string $class): string
{
    return '<form action="#" method="post" style="display:inline">'
        . '<input type="submit" title="' . h($title) . '" value="' . h($text) . '" class="' . h($class) . '" disabled="disabled">'
        . '</form>';
}

function history_button(bool $hasHistory, string $href): string
{
    if ($hasHistory) {
        return button('Show history', 'Open draft and redraft history', $href, 'lt_button');
    }
    return '<button type="button" class="act_disabled" disabled title="No redraft history">no history</button>';
}

/** First click reveals Cancel + Confirm over a page-dimming overlay. */
function confirm_submit(
    string $name,
    string $firstLabel,
    string $confirmLabel,
    ?string $value = null,
    string $goClass = 'dk_sub_button',
    string $yesClass = 'ln_button'
): string {
    $val = $value ?? $confirmLabel;
    $cancelClass = preg_match('/\bsmall\b/', $goClass) ? 'act_ltgray small' : 'dk_sub_button';
    return '<span class="pw-confirm-wrap">'
        . '<button type="button" class="' . h($goClass) . ' pw-confirm-go">' . h($firstLabel) . '</button>'
        . '<button type="button" class="' . h($cancelClass) . ' pw-confirm-cancel" hidden>Cancel</button>'
        . '<button type="submit" name="' . h($name) . '" value="' . h($val) . '" class="' . h($yesClass) . ' pw-confirm-yes" hidden disabled>' . h($confirmLabel) . '</button>'
        . '</span>';
}

/**
 * Named options; value posted is the array key (an id). $empty is the value=0 label.
 * @param array<int|string,string> $options
 */
function form_select(string $name, array $options, $selected = 0, string $empty = '', string $class = 'formselect', string $attrs = ''): string
{
    $html = '<select class="' . h($class) . '" name="' . h($name) . '" id="' . h($name) . '"';
    if ($attrs !== '') {
        $html .= ' ' . $attrs;
    }
    $html .= '>';
    if ($empty !== '') {
        $sel0 = ((string) $selected === '0' || $selected === '' || $selected === null) ? ' selected' : '';
        $html .= '<option value="0"' . $sel0 . '>' . h($empty) . '</option>';
    }
    foreach ($options as $val => $label) {
        $sel = ((string) $val === (string) $selected) ? ' selected' : '';
        $html .= '<option value="' . h((string) $val) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html . '</select>';
}

function comments_markup(array $comments, int $writId, bool $canEdit, int $uid, string $csrf): string
{
    $html = '<h4 class="review">Comments</h4>';
    if (!$comments && !$canEdit) {
        return $html . '<p class="sans dk">None yet.</p>';
    }
    foreach ($comments as $c) {
        $mine = $canEdit && (int) $c['observer_id'] === $uid;
        $html .= '<div class="writcontent remarks comment-block"><p class="sans dk"><b>'
            . h((string) ($c['observer_name'] ?? 'Observer')) . '</b> · '
            . h((string) ($c['save_date'] ?? '')) . '</p>';
        if ($mine) {
            $html .= '<form method="post" class="comment-form">' . '<input type="hidden" name="_csrf" value="' . h($csrf) . '">'
                . '<input type="hidden" name="comment_id" value="' . (int) $c['id'] . '">'
                . '<textarea name="comment_body" class="writingBox" rows="3" cols="82">' . h((string) $c['body']) . '</textarea>'
                . '<p class="save-row"><button type="submit" name="save_comment" class="lt_button small" value="1">Save comment</button></p></form>';
        } else {
            $html .= '<section>' . nl_text((string) $c['body']) . '</section>';
        }
        $html .= '</div>';
    }
    if ($canEdit) {
        $html .= '<form method="post" class="comment-form">' . '<input type="hidden" name="_csrf" value="' . h($csrf) . '">'
            . '<p class="sans">New comment</p>'
            . '<textarea name="comment_body" class="writingBox" rows="3" cols="82" placeholder="Observer comment…"></textarea>'
            . '<p class="save-row"><button type="submit" name="new_comment" class="lt_button small" value="1">Post comment</button></p></form>';
    }
    return $html;
}

function nl_text(?string $s): string
{
    return nl2br(h($s), false);
}

/** First line of a note body, like the original strtok(). */
function note_heading(?string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", (string) $body);
    $line = trim((string) strtok($body, "\n"));
    return $line !== '' ? $line : '(untitled)';
}
