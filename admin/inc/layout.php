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
        'notifications' => ['notifications.php', 'bi-bell',      'Notifications'],
        'developers'  => ['developers.php',  'bi-diagram-3',    'Developers'],
        'workforce'   => ['workforce.php',   'bi-people-fill',  'Workforce'],
        'pipeline'    => ['pipeline.php',    'bi-diagram-2',    'Pipeline Health'],
        'settings'    => ['settings.php',    'bi-gear',         'Settings'],
        'users'       => ['users.php',       'bi-people',       'Admin Users'],
    ];

    /**
     * Plain-language "what is this page" help, shown in a collapsible ℹ callout
     * at the top of every page. Written for non-technical staff.
     * Keys match the page's info key (usually same as the nav key).
     *   'intro' => one-line summary
     *   'does'  => bullet list of what you can do here
     *   'steps' => numbered example walk-through
     */
    private static array $info = [
        'dashboard' => [
            'intro' => 'This is your home screen. It shows the big picture of every site in one glance — how many projects are running, what got done today, and anything that needs attention.',
            'does'  => [
                'See total projects, reports received today, and pending or stuck sites at the top.',
                'Read the charts to spot trends — for example, how many site visits happened this week.',
                'Notice red or amber items that may need a phone call or follow-up.',
            ],
            'steps' => [
                'Open this page each morning to check the day\'s activity.',
                'If a number looks wrong (e.g. "0 reports today" at 5 PM), click <b>Site Reports</b> in the left menu to check details.',
                'Click any project name to jump straight into that project\'s full history.',
            ],
        ],
        'submissions' => [
            'intro' => 'Every report an engineer submits from the site form lands here — like an inbox of site visits. Newest reports sit on top.',
            'does'  => [
                'Search a project name or order id to find a specific report.',
                'See at a glance whether each report was processed (email sent, WhatsApp sent, PDF made).',
                'Open any row to read the full report with photos and notes.',
            ],
            'steps' => [
                'Type a project name in the search box and press Enter.',
                'Look at the <b>Status</b> pill: green means done, amber means still working, red means it failed.',
                'Click the row to open the full report and see what the engineer wrote and photographed.',
            ],
        ],
        'projects' => [
            'intro' => 'A status board of every project (site/unit) we track. Each project groups all the visits made to that one location.',
            'does'  => [
                'Filter by status — Done, Pending, or On Hold.',
                'See the latest step, how many visits happened, and the engineer in charge.',
                'Check the tentative end date to know if a project is running late.',
            ],
            'steps' => [
                'Use the <b>All status</b> dropdown and pick "Hold" to see only stuck projects.',
                'Click a project to open its full timeline of visits.',
                'Example: a client asks "how is Tower-B going?" — search "Tower-B" here and open it.',
            ],
        ],
        'calendar' => [
            'intro' => 'A month-view calendar of site visits and key dates, so you can see when work happened or is planned.',
            'does'  => [
                'Move between months with the arrows.',
                'See which days had site visits and how many.',
                'Spot gaps — days or weeks where no visit was recorded.',
            ],
            'steps' => [
                'Click a date to see the reports logged that day.',
                'Example: to check last month\'s activity, press the back arrow once and scan the coloured days.',
            ],
        ],
        'holds' => [
            'intro' => 'A focused list of projects that are stuck or paused ("on hold") — the ones most likely to need a decision or a follow-up call.',
            'does'  => [
                'See every held project in one place, with the reason it stopped.',
                'Know how long each has been waiting.',
                'Prioritise which client or site to chase first.',
            ],
            'steps' => [
                'Read the reason column to understand why it stopped (e.g. "material not delivered").',
                'Pick the oldest one and follow up.',
                'Once work resumes and a new report comes in, it leaves this list automatically.',
            ],
        ],
        'notifications' => [
            'intro' => 'The alert inbox. The system watches the data and raises a flag when something looks wrong — like a site with no visit for too long, or a report that failed to send.',
            'does'  => [
                'See Critical (act now), Warning (watch), and Snoozed alerts separately.',
                'Filter by Open, Resolved, or All.',
                'Snooze an alert to hide it for a while, or resolve it when handled.',
            ],
            'steps' => [
                'Start with the <b>Critical Open</b> box — those need action today.',
                'Click an alert to see the details and what caused it.',
                'After you fix it (e.g. re-send a report), mark it <b>Resolved</b> so it clears.',
            ],
        ],
        'developers' => [
            'intro' => 'The list of developers / builders (the companies who own the projects). Use it to see all sites grouped by the client they belong to.',
            'does'  => [
                'See each developer and how many projects they have with us.',
                'Jump from a developer to their projects.',
            ],
            'steps' => [
                'Click a developer name to see every project under that client.',
                'Example: to prepare for a meeting with one builder, open them here to review all their sites at once.',
            ],
        ],
        'workforce' => [
            'intro' => 'The people on site — engineers and workers who submit reports. See who is active and how much work each person is logging.',
            'does'  => [
                'See every engineer/worker and their recent activity.',
                'Open a person to see all reports they submitted.',
                'Spot who is very active and who has gone quiet.',
            ],
            'steps' => [
                'Click a name to open that person\'s report history.',
                'Example: to check if an engineer visited site this week, open them and look at the latest dates.',
            ],
        ],
        'pipeline' => [
            'intro' => 'A health check of the whole reporting process — like a traffic light. Green means data is flowing fine; amber and red mean something in the chain (email, WhatsApp, PDF) is slow or broken.',
            'does'  => [
                'See Green / Amber / Red health for the report pipeline.',
                'Find where reports get stuck (which step is failing).',
            ],
            'steps' => [
                'If you see Red, look at which step failed (e.g. WhatsApp send).',
                'Cross-check on the <b>Notifications</b> page for the exact error.',
                'Fix the setting (often in <b>Settings</b>) and the light returns to Green.',
            ],
            'legend' => 'status',
        ],
        'settings' => [
            'intro' => 'The control room. Here you switch things on/off and set who receives emails and WhatsApp messages. Changes here affect the live system, so change carefully.',
            'does'  => [
                'Turn email and WhatsApp sending ON (live) or OFF (test).',
                'Set the phone numbers and email addresses that receive site updates.',
                'Adjust timing and other behaviour of the automatic reports.',
            ],
            'steps' => [
                'Before going live, keep modes in <b>test</b> and put your own number as the receiver.',
                'Submit a test report and confirm the message reaches you.',
                'When happy, switch the mode to <b>live</b> and set the real client contacts. Always press <b>Save</b>.',
            ],
        ],
        'users' => [
            'intro' => 'Manage who can log into this admin panel. Add teammates, set their role, or remove access.',
            'does'  => [
                'Add a new admin user with a username and password.',
                'Give a role — full admin (can change things) or viewer (look only).',
                'Remove someone when they no longer need access.',
            ],
            'steps' => [
                'Click <b>Add user</b>, type their name and a password.',
                'Choose <b>Viewer</b> if they should only read, not change settings.',
                'Share the login with them. They can sign in from the login page.',
            ],
        ],
        'sync' => [
            'intro' => 'A refresh button for the data. It re-reads all submitted reports and rebuilds the project, workforce and alert lists. It only reads reports — it never changes or re-sends them.',
            'does'  => [
                'Force the panel to update immediately instead of waiting.',
                'Fix a page that looks out of date after new reports came in.',
            ],
            'steps' => [
                'If a new report is not showing on a page, come here and press <b>Sync now</b>.',
                'Wait for the "done" message, then go back to the page — it will be up to date.',
            ],
        ],
        'project' => [
            'intro' => 'The full file for one project — every visit, photo, status and date for this single site, in time order.',
            'does'  => [
                'Read the complete history of visits for this project.',
                'See the current status and the tentative end date.',
                'Open any single report for full details.',
            ],
            'steps' => [
                'Scroll the timeline to follow the project from first visit to now.',
                'Example: a client asks "what was done on my site last week?" — find that date in the timeline and open the report.',
            ],
            'legend' => 'status',
        ],
        'submission' => [
            'intro' => 'One single site report in full — what the engineer saw, the photos taken, and whether the email, WhatsApp and PDF went out successfully.',
            'does'  => [
                'Read the engineer\'s notes and view the site photos.',
                'Check whether each delivery step (email / WhatsApp / PDF) succeeded.',
                'Use it to confirm the client received their update.',
            ],
            'steps' => [
                'Look at the status badges to confirm the message was delivered.',
                'If something shows failed, note the reason, then re-check <b>Settings</b> or ask for a re-send.',
            ],
            'legend' => 'status',
        ],
        'worker' => [
            'intro' => 'The profile of one worker/engineer — all the reports they have submitted and their recent activity.',
            'does'  => [
                'See every report this person filed and when.',
                'Judge how active they have been lately.',
            ],
            'steps' => [
                'Scan the list of dates to see their latest site visit.',
                'Example: confirm an engineer actually visited before approving their work.',
            ],
        ],
        'contractor' => [
            'intro' => 'The profile of one contractor — the projects they are linked to and their activity across sites.',
            'does'  => [
                'See which projects this contractor is involved in.',
                'Review their recent site activity.',
            ],
            'steps' => [
                'Open a linked project from here to see the full site history.',
                'Example: before paying a contractor, check the visits recorded against their sites.',
            ],
        ],
    ];

    /** Render the topbar ℹ button + plain-language help dropdown for a page key. */
    public static function pageInfo(string $key): void
    {
        $d = self::$info[$key] ?? null;
        if (!$d) { return; }
        echo '<div class="info-menu">';
        echo '<button class="info-btn" id="pageInfoBtn" type="button" aria-label="About this page" title="What is this page?"><i class="bi bi-info-lg"></i></button>';
        echo '<div class="info-dd" id="pageInfoDd">';
        echo '<div class="info-dd-head"><i class="bi bi-info-circle"></i> About this page — what it does &amp; how to use it</div>';
        echo '<div class="info-dd-body">';
        echo '<p>' . $d['intro'] . '</p>';
        if (!empty($d['does'])) {
            echo '<div class="pi-h"><i class="bi bi-check2-square"></i> What you can do here</div><ul>';
            foreach ($d['does'] as $li) { echo '<li>' . $li . '</li>'; }
            echo '</ul>';
        }
        if (!empty($d['steps'])) {
            echo '<div class="pi-h"><i class="bi bi-signpost-2"></i> Example — try this</div>';
            echo '<div class="pi-eg"><ol>';
            foreach ($d['steps'] as $li) { echo '<li>' . $li . '</li>'; }
            echo '</ol></div>';
        }
        if (!empty($d['legend'])) {
            echo '<div class="pi-h"><i class="bi bi-palette"></i> What the colours &amp; labels mean</div>';
            echo '<ul class="pi-legend">';
            foreach (self::legend((string)$d['legend']) as [$tone, $label, $desc]) {
                echo '<li><span class="pill pill-' . $tone . '">' . $label . '</span><span class="pi-lg-txt">' . $desc . '</span></li>';
            }
            echo '</ul>';
        }
        echo '</div></div></div>';
    }

    /** Shared legends explaining every coloured badge/symbol used on the page. */
    private static function legend(string $set): array
    {
        // Traffic-light health + step-status pills + delivery badges.
        $status = [
            ['ok',    'Green',       'Everything went through fine — email, WhatsApp and PDF all sent to the client.'],
            ['warn',  'Amber',       'Something is slow or only partly done — worth a quick look.'],
            ['bad',   'Red',         'Something failed or is blocked — needs action now.'],
            ['ok',    'Done',        'That work step is finished.'],
            ['warn',  'Pending',     'The step has started but is not finished yet (also shown as "In progress").'],
            ['bad',   'Hold',        'The step is paused / stuck — waiting on someone (e.g. "stuck by VAPL").'],
            ['muted', 'Not started', 'The step has not begun yet.'],
            ['ok',    'WhatsApp',    'Green = the WhatsApp update reached the client. Red = it failed to send.'],
            ['ok',    'Email',       'Green = the email update was sent. Red = it failed to send.'],
        ];
        return $set === 'status' ? $status : [];
    }

    public static function head(string $title, string $active = '', string $infoKey = ''): void
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
        echo '<link rel="stylesheet" href="' . $b . '/assets/admin.css?v=12">';
        echo '</head><body>';

        echo '<div class="admin-shell">';
        echo '<div class="sidebar-backdrop" id="sbBackdrop"></div>';

        // Sidebar
        echo '<aside class="sidebar" id="sidebar">';
        echo '<div class="sidebar-brand"><img src="' . $A . '/logo.png" alt="VAPL"><div><div class="sb-title">PMS Admin</div><div class="sb-sub">Site Report Tracker</div></div></div>';
        // open-alert badge for the Notifications nav item
        $alertN = 0;
        try { $alertN = (int)Admin::db()->query("SELECT COUNT(*) FROM alerts WHERE status IN ('open','ack')")->fetchColumn(); } catch (Throwable $e) {}

        echo '<nav class="sidebar-nav">';
        foreach (self::$nav as $key => [$href, $icon, $label]) {
            if ($key === 'users' && Admin::isViewer()) {
                continue;
            }
            $cls = $key === $active ? ' active' : '';
            $badge = ($key === 'notifications' && $alertN > 0) ? '<span class="nav-badge">' . $alertN . '</span>' : '';
            echo '<a class="nav-link' . $cls . '" href="' . $b . '/' . $href . '"><i class="bi ' . $icon . '"></i><span>' . $label . '</span>' . $badge . '</a>';
        }
        echo '</nav>';
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
        self::pageInfo($infoKey !== '' ? $infoKey : $active);
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
           . 'var ib=document.getElementById("pageInfoBtn"),idd=document.getElementById("pageInfoDd");'
           . 'if(ib&&idd){ib.addEventListener("click",function(e){e.stopPropagation();idd.classList.toggle("open");});'
           . 'idd.addEventListener("click",function(e){e.stopPropagation();});'
           . 'document.addEventListener("click",function(){idd.classList.remove("open");});}'
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

    /** Coloured pill for a project lifecycle status. */
    public static function lifecyclePill(string $lc): string
    {
        $map = [
            'Not Started' => 'muted', 'Active' => 'info', 'At Risk' => 'warn',
            'On Hold' => 'bad', 'Commissioning Pending' => 'info',
            'Commissioned' => 'ok', 'Closed' => 'muted',
        ];
        $tone = $map[$lc] ?? 'muted';
        return '<span class="pill pill-' . $tone . '">' . Admin::e($lc ?: '—') . '</span>';
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
