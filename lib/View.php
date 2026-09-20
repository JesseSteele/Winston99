<?php
declare(strict_types=1);

final class View
{
    private App $app;
    private bool $started = false;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function start(string $pageTitle, string $active = '', string $dash = 'auto'): void
    {
        $this->started = true;
        $title = $pageTitle . ' :: ' . $this->app->title();
        $u = $this->app->auth->user();
        $dash = $this->resolveDash($u, $active, $dash);
        echo '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">' . "\n";
        echo '<html xmlns="http://www.w3.org/1999/xhtml"><head>';
        echo '<link rel="shortcut icon" type="image/png" href="favicon.png"/>';
        echo '<meta name="robots" content="noindex">';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
        $cssV = winston99_asset_v(__DIR__ . '/../css/styles.css');
        $jsV = winston99_asset_v(__DIR__ . '/../js/winston99.js');
        echo '<link rel="stylesheet" href="css/styles.css?v=' . h($cssV) . '" type="text/css" />';
        $theme = winston99_theme_id($u);
        $themeFile = __DIR__ . '/../css/' . $theme . '.css';
        if (is_file($themeFile)) {
            echo '<link rel="stylesheet" id="winston99-theme-css" href="css/' . h($theme) . '.css?v=' . h(winston99_asset_v($themeFile)) . '" type="text/css" />';
        }
        echo '<script src="js/winston99.js?v=' . h($jsV) . '"></script>';
        echo '<meta http-equiv="Cache-Control" content="no-cache" />';
        echo '<meta http-equiv="Pragma" content="no-cache" />';
        echo '<meta http-equiv="Expires" content="0" />';
        echo '<title>' . h($title) . '</title></head><body><div id="wrap">';
        if ($u) {
            $this->topNav($u, $active, $dash);
        }
        echo '<div id="header" class="header"></div><div class="page"><div class="content">';
        if ($u && $active !== 'login') {
            $this->dashNav($u, $active, $pageTitle, $dash);
        }
        if (!empty($_SESSION['act_message'])) {
            echo $_SESSION['act_message'];
            unset($_SESSION['act_message']);
        }
    }

    public function end(): void
    {
        echo '<p><br clear="all" /></p></div>';
        echo '<div class="footer"><p class="dk sans">';
        echo '<a class="dk" href="Terms.htm">Terms & Conditions</a> | ';
        echo '<a class="dk" href="Privacy.htm">Privacy</a> | ';
        echo '<a class="dk" href="https://github.com/JesseSteele/Winston99">OpenSource project from GitHub</a> | ';
        echo '<a class="dk" href="https://winston.software">winston.software</a> &nbsp; - &nbsp; &copy; Winston, ';
        echo '<a class="dk" href="https://www.gnu.org/licenses/gpl-3.0.en.html">GPLv3</a></p></div>';
        echo '</div></div></body></html>';
    }

    public function setFlash(string $msg, bool $ok = true): void
    {
        $_SESSION['act_message'] = $this->notice($msg, $ok);
    }

    private function resolveDash(?array $u, string $active, string $dash): string
    {
        $allowed = ['my', 'writer', 'editor', 'observer', 'admin', 'super'];
        if ($dash !== 'auto' && in_array($dash, $allowed, true)) {
            $_SESSION['pw_dash'] = $dash;
            return $dash;
        }
        $sess = (string) ($_SESSION['pw_dash'] ?? '');
        if (in_array($sess, $allowed, true)) {
            return $sess;
        }
        $type = (string) ($u['type'] ?? '');
        if (in_array($active, ['super', 'facilities', 'admins'], true)) {
            $dash = 'super';
        } elseif (in_array($active, ['admin', 'editors', 'observers', 'writers'], true)) {
            $dash = 'admin';
        } elseif (in_array($active, ['observer', 'owrits'], true) || $type === 'observer') {
            $dash = 'observer';
        } elseif (in_array($active, ['editor', 'ewrits', 'tests', 'assign', 'myblocks', 'archives', 'roll'], true)) {
            $dash = 'editor';
        } elseif ($active === 'dash' || $active === 'locker') {
            $dash = 'my';
        } elseif (in_array($active, ['writs', 'notes', 'memos'], true)) {
            $dash = $type === 'observer' ? 'observer' : 'writer';
        } elseif ($active === 'blocks') {
            $dash = $type === 'writer' ? 'writer' : 'admin';
        } else {
            $dash = 'my';
        }
        $_SESSION['pw_dash'] = $dash;
        return $dash;
    }

    private function topNav(array $u, string $active, string $dash): void
    {
        $on = fn (string $d) => $dash === $d ? 'activedash' : '';
        $type = $u['type'];
        echo '<div id="top_menu_nav"><div id="topnav"><ul class="topnav">';
        echo '<li><h1><a class="dklink" href="index.php">' . h($this->app->title()) . '</a></h1></li>';
        echo '<li class="user">' . button('My Dash', 'Home', 'index.php', 'navButton user ' . $on('my')) . '</li>';
        if ($type !== 'observer') {
            echo '<li class="user">' . button('Writer Dash', 'Writing workspace', 'writer-dash.php', 'navButton user ' . $on('writer')) . '</li>';
        }
        if (in_array($type, ['superintendent', 'admin', 'supervisor', 'editor', 'observer'], true)) {
            echo '<li class="user">' . button('Observer Dash', 'Observer', 'observer.php', 'navButton user ' . $on('observer')) . '</li>';
        }
        if (in_array($type, ['superintendent', 'admin', 'supervisor', 'editor'], true)) {
            echo '<li class="user">' . button('Editor Dash', 'Editor', 'editor.php', 'navButton user ' . $on('editor')) . '</li>';
        }
        if (in_array($type, ['superintendent', 'admin', 'supervisor'], true)) {
            echo '<li class="user">' . button('Admin Dash', 'Admin', 'admin.php', 'navButton user ' . $on('admin')) . '</li>';
        }
        if ($type === 'superintendent') {
            echo '<li class="user">' . button('Super Dash', 'Superintendent', 'super.php', 'navButton user ' . $on('super')) . '</li>';
        }
        echo '<li class="user">' . button('Logout', 'Exit from this login session', 'logout.php', 'navButton user') . '</li>';
        echo '</ul></div></div>';
    }

    private function dashNav(array $u, string $active, string $greeting, string $dash): void
    {
        $is = fn ($k) => $active === $k ? 'active' : '';
        $n = 0;
        try {
            $n = $this->app->notify->count((int) $u['id']);
        } catch (Throwable $e) {
            $n = 0;
        }
        $noteLabel = $n > 0 ? "Notifications ({$n})" : 'Notifications';
        if ($dash === 'super') {
            $lockerLabel = 'Super Locker';
            $lockerHref = 'locker-super.php';
        } elseif ($dash === 'admin') {
            $lockerLabel = 'Admin Locker';
            $lockerHref = 'locker-admin.php';
        } else {
            $lockerLabel = 'My Locker';
            $lockerHref = 'locker.php';
        }
        echo '<div class="dash_menu_nav"><div class="dashnav"><ul class="dashnav">';
        echo '<li class="lt sans">' . h($greeting) . '</li>';
        $label = match ($dash) {
            'editor' => 'Editor',
            'observer' => 'Observer',
            'super' => 'Super',
            'admin' => 'Admin',
            'writer' => 'Writer',
            default => 'Dash',
        };
        echo '<li class="user lt sans">' . h($u['name']) . ' (' . h($label) . ')</li>';
        if ($dash === 'super') {
            echo '<li class="user">' . button('Facilities', 'Schools', 'facilities.php', 'navDarkButton user ' . $is('facilities')) . '</li>';
            echo '<li class="user">' . button('Administrators', 'Manage administrators', 'administrators.php', 'navDarkButton user ' . $is('admins')) . '</li>';
        } elseif ($dash === 'admin') {
            echo '<li class="user">' . button('Writers', 'Enrollment and blocks', 'enrollment.php', 'navDarkButton user ' . $is('writers')) . '</li>';
            echo '<li class="user">' . button('Observers', 'Manage observers', 'observers.php', 'navDarkButton user ' . $is('observers')) . '</li>';
            echo '<li class="user">' . button('Editors', 'Manage editors', 'editors.php', 'navDarkButton user ' . $is('editors')) . '</li>';
            echo '<li class="user">' . button('Blocks', 'Manage blocks', 'blocks-editor.php', 'navDarkButton user ' . $is('blocks')) . '</li>';
        } elseif ($dash === 'observer') {
            echo '<li class="user">' . button('Writs', 'Writs', 'writs-observer.php', 'navDarkButton user ' . $is('owrits')) . '</li>';
            echo '<li class="user">' . button('Observees', 'View observed writers', 'observer.php', 'navDarkButton user ' . $is('observer')) . '</li>';
            echo '<li class="user">' . button('Memos', 'List memos', 'memos-observer.php', 'navDarkButton user ' . $is('memos')) . '</li>';
        } elseif ($dash === 'editor') {
            echo '<li class="user">' . button('Archives', 'Editor archives', 'archives-editor.php', 'navDarkButton user ' . $is('archives')) . '</li>';
            echo '<li class="user">' . button('Tests', 'Compose and list tests', 'tests.php', 'navDarkButton user ' . $is('tests')) . '</li>';
            echo '<li class="user">' . button('Assignments', 'Assigned writs', 'assignments.php', 'navDarkButton user ' . $is('assign')) . '</li>';
            echo '<li class="user">' . button('Writs', 'List writs', 'writs-editor.php', 'navDarkButton user ' . $is('ewrits')) . '</li>';
            echo '<li class="user">' . button('Roll', 'Writers by student', 'roll.php', 'navDarkButton user ' . $is('roll')) . '</li>';
            echo '<li class="user">' . button('My Blocks', 'Blocks you edit', 'my-blocks.php', 'navDarkButton user ' . $is('myblocks')) . '</li>';
            echo '<li class="user">' . button('Memos', 'List memos', 'memos-editor.php', 'navDarkButton user ' . $is('memos')) . '</li>';
        } elseif ($dash === 'writer') {
            echo '<li class="user">' . button('Archives', 'Archives', 'archives.php', 'navDarkButton user ' . $is('archives')) . '</li>';
            echo '<li class="user">' . button('Writs', 'View writs', 'writs.php', 'navDarkButton user ' . $is('writs')) . '</li>';
            echo '<li class="user">' . button('Blocks', 'View blocks', 'blocks.php', 'navDarkButton user ' . $is('blocks')) . '</li>';
            echo '<li class="user">' . button('Notes', 'View notes', 'notes.php', 'navDarkButton user ' . $is('notes')) . '</li>';
            echo '<li class="user">' . button('Memos', 'View memos & tasks', 'memos.php', 'navDarkButton user ' . $is('memos')) . '</li>';
        }
        echo '<li class="user">' . button($noteLabel, 'Notifications', 'notifications.php', 'navDarkButton user ' . $is('notify')) . '</li>';
        echo '<li class="user">' . button($lockerLabel, $lockerLabel, $lockerHref, 'navDarkButton user ' . $is('locker')) . '</li>';
        echo '</ul></div></div>';
    }

    public function flash(?string $html): void
    {
        if ($html) {
            echo $html;
        }
    }

    public function notice(string $msg, bool $ok = true): string
    {
        $c = $ok ? 'noticegreen' : 'noticered';
        return '<p class="sans ' . $c . '">' . h($msg) . '</p>';
    }
}
