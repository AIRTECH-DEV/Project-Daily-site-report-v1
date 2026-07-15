<?php
/**
 * Shared chrome for every authenticated admin page: sidebar + topbar.
 *   Layout::head('Dashboard', 'dashboard');   // opens shell + <main>
 *   ... page body ...
 *   Layout::foot();                            // closes shell, loads JS
 * The second arg is the nav key used to highlight the active link.
 */
class Layout
{
    private static array $nav = [
        'dashboard'   => ['index.php',       'bi-speedometer2', 'Dashboard'],
        'submissions' => ['submissions.php', 'bi-card-list',    'Site Reports'],
        'projects'    => ['projects.php',    'bi-buildings',    'Projects'],
        'holds'       => ['holds.php',       'bi-pause-circle', 'On Hold'],
        'developers'  => ['developers.php',  'bi-diagram-3',    'Developers'],
        'pipeline'    => ['pipeline.php',    'bi-diagram-2',    'Pipeline Health'],
        'settings'    => ['settings.php',    'bi-gear',         'Settings'],
        'users'       => ['users.php',       'bi-people',       'Admin Users'],
    ];

    public static function head(string $title, string $active = ''): void
    {
        $u = Admin::user();
        $b = Admin::BASE;
        $A = Admin::ASSETS;
        echo '<!DOCTYPE html><html lang="en" data-bs-theme="light"><head>';
        echo '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>' . Admin::e($title) . ' — PMS Admin</title>';
        echo '<link rel="icon" type="image/png" href="' . $A . '/favicon.png">';
        echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">';
        echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">';
        echo '<link rel="stylesheet" href="' . $b . '/assets/admin.css?v=2">';
        echo '</head><body>';

        echo '<div class="admin-shell">';

        // Sidebar
        echo '<aside class="sidebar" id="sidebar">';
        echo '<div class="sidebar-brand"><img src="' . $A . '/logo.png" alt="VAPL"><div><div class="sb-title">PMS Admin</div><div class="sb-sub">Site Report Tracker</div></div></div>';
        echo '<nav class="sidebar-nav">';
        foreach (self::$nav as $key => [$href, $icon, $label]) {
            if ($key === 'users' && Admin::isViewer()) {
                continue;
            }
            $cls = $key === $active ? ' active' : '';
            echo '<a class="nav-link' . $cls . '" href="' . $b . '/' . $href . '"><i class="bi ' . $icon . '"></i><span>' . $label . '</span></a>';
        }
        echo '</nav>';
        echo '<div class="sidebar-foot"><a href="' . $b . '/../index.php" target="_blank"><i class="bi bi-box-arrow-up-right"></i><span>Open Report Form</span></a></div>';
        echo '</aside>';

        // Main
        echo '<div class="main-wrap">';
        echo '<header class="topbar">';
        echo '<button class="menu-toggle" id="menuToggle" aria-label="Toggle menu"><i class="bi bi-list"></i></button>';
        echo '<h1 class="page-title">' . Admin::e($title) . '</h1>';
        echo '<div class="topbar-right">';
        echo '<span class="clock" id="clock"></span>';
        echo '<div class="user-chip"><div class="avatar">' . Admin::e(strtoupper(substr($u['name'], 0, 1))) . '</div>';
        echo '<div class="u-meta"><div class="u-name">' . Admin::e($u['name']) . '</div><div class="u-role">' . Admin::e(ucfirst($u['role'])) . '</div></div></div>';
        echo '<a class="btn-logout" href="' . $b . '/logout.php" title="Sign out"><i class="bi bi-box-arrow-right"></i></a>';
        echo '</div></header>';
        echo '<main class="content">';
    }

    public static function foot(string $extraJs = ''): void
    {
        echo '</main></div></div>'; // content, main-wrap, admin-shell
        echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
        echo '<script>(function(){var t=document.getElementById("menuToggle"),s=document.getElementById("sidebar");'
           . 'if(t&&s)t.addEventListener("click",function(){s.classList.toggle("open");});'
           . 'var c=document.getElementById("clock");function tick(){if(c)c.textContent=new Date().toLocaleString("en-IN",{hour:"2-digit",minute:"2-digit",day:"2-digit",month:"short"});}tick();setInterval(tick,30000);})();</script>';
        echo $extraJs;
        echo '</body></html>';
    }

    /** Coloured pill for an overall_status / step status value. */
    public static function statusBadge(string $s): string
    {
        $map = [
            'done'            => 'ok',       'partial' => 'warn',    'failed' => 'bad',
            'processing'      => 'info',     'queued'  => 'muted',   'received' => 'muted',
            'awaiting_notify' => 'info',
            'running'         => 'info',     'skipped' => 'muted',   'pending' => 'warn',
            'hold'            => 'bad',
        ];
        $k = strtolower(trim($s));
        $tone = $map[$k] ?? 'muted';
        return '<span class="pill pill-' . $tone . '">' . Admin::e($s !== '' ? $s : '—') . '</span>';
    }
}
