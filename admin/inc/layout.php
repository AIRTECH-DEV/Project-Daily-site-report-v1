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
        'calendar'    => ['calendar.php',    'bi-calendar3',    'Calendar'],
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
        echo '<link rel="stylesheet" href="' . Admin::e(Admin::vendor('bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css')) . '">';
        echo '<link rel="stylesheet" href="' . Admin::e(Admin::vendor('bootstrap-icons.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css')) . '">';
        echo '<link rel="stylesheet" href="' . $b . '/assets/admin.css?v=4">';
        echo '</head><body>';

        echo '<div class="admin-shell">';
        echo '<div class="sidebar-backdrop" id="sbBackdrop"></div>';

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
        $init = Admin::e(strtoupper(substr($u['name'], 0, 1)));
        echo '<header class="topbar">';
        echo '<button class="menu-toggle" id="menuToggle" aria-label="Toggle menu"><i class="bi bi-list"></i></button>';
        echo '<form class="topbar-search" method="get" action="' . $b . '/submissions.php" role="search">';
        echo '<i class="bi bi-search"></i>';
        echo '<input type="text" name="q" placeholder="Search projects, reports…" value="' . Admin::e($_GET['q'] ?? '') . '" autocomplete="off">';
        echo '</form>';
        echo '<div class="topbar-right">';
        echo '<span class="clock" id="clock"></span>';
        echo '<div class="user-menu">';
        echo '<div class="user-chip" id="userChip">';
        echo '<div class="avatar">' . $init . '</div>';
        echo '<div class="u-meta"><div class="u-name">' . Admin::e($u['name']) . '</div><div class="u-role">' . Admin::e(ucfirst($u['role'])) . '</div></div>';
        echo '<i class="bi bi-chevron-down caret"></i></div>';
        echo '<div class="user-dd" id="userDd">';
        echo '<div class="dd-head"><div class="n">' . Admin::e($u['name']) . '</div><div class="r">@' . Admin::e($u['user']) . ' · ' . Admin::e(ucfirst($u['role'])) . '</div></div>';
        echo '<a href="' . $b . '/users.php"><i class="bi bi-people"></i> Admin Users</a>';
        echo '<a href="' . $b . '/settings.php"><i class="bi bi-gear"></i> Settings</a>';
        echo '<a class="danger" href="' . $b . '/logout.php"><i class="bi bi-box-arrow-right"></i> Sign out</a>';
        echo '</div></div>';
        echo '</div></header>';
        echo '<main class="content">';

        // Prompt to localize CDN assets (fixes icon-font blocked by some networks).
        if (!Admin::vendorReady() && !Admin::isViewer()) {
            echo '<div class="alert2 info" style="justify-content:space-between">'
               . '<span><i class="bi bi-cloud-arrow-down"></i> Icons &amp; styles load from the internet (CDN). '
               . 'If icons show as boxes, download them to this server for reliable, offline display.</span>'
               . '<a class="btn btn-primary btn-sm" href="' . $b . '/vendor_fetch.php" style="flex-shrink:0">Localize now</a></div>';
        }
    }

    public static function foot(string $extraJs = ''): void
    {
        echo '</main></div></div>'; // content, main-wrap, admin-shell
        echo '<script src="' . Admin::e(Admin::vendor('bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js')) . '"></script>';
        echo '<script src="' . Admin::e(Admin::vendor('chart.umd.min.js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js')) . '"></script>';
        echo '<script>(function(){'
           . 'var t=document.getElementById("menuToggle"),s=document.getElementById("sidebar"),bd=document.getElementById("sbBackdrop");'
           . 'function openSb(o){if(!s)return;s.classList.toggle("open",o);if(bd)bd.classList.toggle("show",o);}'
           . 'if(t)t.addEventListener("click",function(){openSb(!s.classList.contains("open"));});'
           . 'if(bd)bd.addEventListener("click",function(){openSb(false);});'
           . 'var chip=document.getElementById("userChip"),dd=document.getElementById("userDd");'
           . 'if(chip&&dd){chip.addEventListener("click",function(e){e.stopPropagation();dd.classList.toggle("open");});'
           . 'document.addEventListener("click",function(){dd.classList.remove("open");});}'
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

    /** Traffic-light pipeline health from overall_status: Green / Amber / Red. */
    public static function pipelinePill(string $overall): string
    {
        $k = strtolower(trim($overall));
        if ($k === 'done')            { return '<span class="pill pill-ok"><span class="dot"></span>Green</span>'; }
        if ($k === 'failed')          { return '<span class="pill pill-bad"><span class="dot"></span>Red</span>'; }
        return '<span class="pill pill-warn"><span class="dot"></span>Amber</span>';
    }
}
