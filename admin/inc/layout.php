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
        'planner'     => ['planner.php',     'bi-calendar2-check', 'Daily Plan'],
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
            'intro' => 'Portfolio overview. Summarises every project in one place: active count, reports received today, work planned, and items needing attention — all rebuilt from submitted reports.',
            'does'  => [
                'Read the top KPI tiles for active projects, today\'s reports, planned work, and stuck sites.',
                'Scan the charts for site-visit and completion trends.',
                'Act on red and amber items — these correspond to open alerts.',
            ],
            'steps' => [
                'Open this page each morning to check the day\'s activity.',
                'If a number looks wrong (e.g. "0 reports today" at 5 PM), click <b>Site Reports</b> in the left menu to check details.',
                'Click any project name to jump straight into that project\'s full history.',
            ],
            'buttons' => [
                ['bi-grid-1x2',    'Coloured KPI tiles', 'The number cards at the top — blue = totals, green = good/done, amber = watch, red = needs action.'],
                ['bi-box-arrow-up-right', 'Project links', 'Click a project name in any table to open its full page.'],
            ],
            'legend' => 'status',
        ],
        'submissions' => [
            'intro' => 'The log of every site report submitted from the field form, newest first. Each row is one site visit.',
            'does'  => [
                'Search by project name or order id.',
                'Check each report\'s processing status — PDF generated, email sent, WhatsApp sent.',
                'Open a row for the full report: notes, workforce, photos and delivery record.',
            ],
            'steps' => [
                'Type a project name in the search box and press Enter.',
                'Look at the <b>Status</b> pill: green means done, amber means still working, red means it failed.',
                'Click the row to open the full report and see what the engineer wrote and photographed.',
            ],
            'buttons' => [
                ['bi-search',        'Search box', 'Find a report by project name or order id — press Enter.'],
                ['bi-card-list',     'Row',        'Click any report row to open it in full.'],
            ],
            'legend' => 'status',
        ],
        'projects' => [
            'intro' => 'Status board of every project (site/unit). Each project consolidates all site visits made to that location into one record: latest step, progress, engineer, and lifecycle status.',
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
            'buttons' => [
                ['bi-funnel',        'All status dropdown', 'Pick Done / Pending / Hold — the list filters instantly, no button needed.'],
                ['bi-search',        'Search box',          'Type a project name or order id and press Enter to find it.'],
                ['bi-arrow-repeat',  'Reset',               'Clears the search and filter, showing every project again.'],
                ['bi-chevron-right', 'Row arrow',           'Click any row to open that project\'s full page.'],
            ],
            'legend' => 'status',
        ],
        'calendar' => [
            'intro' => 'Month view of scheduled work and key dates. Plots each project\'s planned work (from reports) and its target-end date onto a calendar, with an upcoming-work list alongside.',
            'does'  => [
                'Move between months with the arrows.',
                'See which days had site visits and how many.',
                'Spot gaps — days or weeks where no visit was recorded.',
            ],
            'steps' => [
                'Click a date to see the reports logged that day.',
                'Example: to check last month\'s activity, press the back arrow once and scan the coloured days.',
            ],
            'buttons' => [
                ['bi-chevron-left',  'Left arrow',  'Go to the previous month.'],
                ['bi-chevron-right', 'Right arrow', 'Go to the next month.'],
                ['bi-calendar3',     'Today',       'Jump back to the current month.'],
                ['bi-square-fill',   'Coloured chips', 'Each chip is an event — click it to open that report.'],
            ],
            'legend' => 'calendar',
        ],
        'planner' => [
            'intro' => 'Forward schedule by engineer (PE), built from each report\'s "planned for tomorrow" steps and next-step start date. Groups planned work by PE for today and tomorrow, and flags delayed plans — a planned date passed with no new report — which also raise a Warning alert in Notifications.',
            'does'  => [
                'See tomorrow\'s planned work grouped by the PE responsible for it.',
                'See today\'s plan, and anything scheduled for later days.',
                'Spot delayed plans in red — planned day gone, still no report.',
            ],
            'steps' => [
                'Each evening, open this to see who is going to which site tomorrow.',
                'If a job appears under <b>Delayed</b>, follow up with that PE — the same delay also shows in <b>Notifications</b>.',
                'Click any project name to open its full timeline.',
            ],
            'buttons' => [
                ['bi-exclamation-triangle', 'Red "N days late" chip', 'How many days ago the planned day passed with still no report.'],
                ['bi-person',               'PE name',                'The engineer responsible for that planned work.'],
                ['bi-calendar-event',       'Date chip',              'The planned day. "(next day)" means it was inferred as report-day + 1 when no exact date was given.'],
                ['bi-box-arrow-up-right',   'Open',                   'Opens the report that made this plan.'],
            ],
            'legend' => 'status',
        ],
        'holds' => [
            'intro' => 'Projects whose latest report placed a step on hold. Shows what is blocked, who it is waiting on (Client or VAPL), and how long — so the oldest blockers can be cleared first.',
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
            'buttons' => [
                ['bi-flag',  'Stuck-on chip', 'Shows who the work is waiting on — Client or VAPL.'],
                ['bi-box-arrow-up-right', 'Project link', 'Open the held project to see its full timeline.'],
            ],
            'legend' => 'lifecycle',
        ],
        'notifications' => [
            'intro' => 'The alert inbox. The system continuously checks report data against fixed rules and raises an alert when a project breaches one — a stale project, a missed plan, an overdue target end, or a failed delivery. Alerts auto-resolve when the condition clears.',
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
            'buttons' => [
                ['bi-eye',                   'Acknowledge (eye)',   'Marks that you have <i>seen</i> the alert (adds an "ack" tag). It does not clear it — work is still pending.'],
                ['bi-alarm',                 'Snooze (clock)',      'Hides the alert for 1 day. If the problem is still there, it comes back automatically.'],
                ['bi-check-lg',              'Resolve (blue tick)', 'Marks it handled — the alert leaves the open list. Use once the issue is actually fixed.'],
                ['bi-arrow-counterclockwise','Reopen',              'Only appears on resolved alerts — brings one back to the open list if it was closed too early.'],
                ['bi-funnel',                'Open / Resolved / All','Top filter buttons — switch which alerts you are looking at.'],
            ],
            'legend' => 'alerts',
        ],
        'developers' => [
            'intro' => 'Developers / builders (the clients who own the projects), each with their project count. Use it to review all sites belonging to one client together.',
            'does'  => [
                'See each developer and how many projects they have with us.',
                'Jump from a developer to their projects.',
            ],
            'steps' => [
                'Click a developer name to see every project under that client.',
                'Example: to prepare for a meeting with one builder, open them here to review all their sites at once.',
            ],
            'buttons' => [
                ['bi-box-arrow-up-right', 'Developer link', 'Click a developer to see all their projects grouped together.'],
            ],
        ],
        'workforce' => [
            'intro' => 'Directory of everyone recorded on site — VAPL staff and contractor workers — derived from the workforce named in reports. Shows activity levels and the VAPL-vs-contractor split.',
            'does'  => [
                'See every engineer/worker and their recent activity.',
                'Open a person to see all reports they submitted.',
                'Spot who is very active and who has gone quiet.',
            ],
            'steps' => [
                'Click a name to open that person\'s report history.',
                'Example: to check if an engineer visited site this week, open them and look at the latest dates.',
            ],
            'buttons' => [
                ['bi-person',  'VAPL (blue) vs Contractor (amber)', 'The pill next to a name shows if they are your own staff or an outside contractor.'],
                ['bi-box-arrow-up-right', 'Name link', 'Click any worker or contractor to open their full profile.'],
            ],
        ],
        'pipeline' => [
            'intro' => 'Delivery health for the report pipeline — the chain that turns a submitted report into a PDF and sends it to the client by email and WhatsApp. A traffic light shows whether each report completed that chain.',
            'does'  => [
                'See Green / Amber / Red health per report.',
                'Identify which processing step failed (PDF, email, or WhatsApp).',
            ],
            'steps' => [
                'On a Red item, note the failed step and its error message.',
                'Confirm the cause in <b>Notifications</b>, then correct it (often a mode or contact in <b>Settings</b>).',
                'Re-process; the item returns to Green once the chain completes.',
            ],
            'legend' => 'health',
        ],
        'settings' => [
            'intro' => 'Runtime configuration for notifications. Sets each developer\'s client email and WhatsApp recipients, the send modes (OFF/TEST/LIVE), the notify delay, and internal alert routing. Saved to config/overrides.json and applied to the next report processed.',
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
            'buttons' => [
                ['bi-toggles',       'Mode dropdowns', 'OFF = send nothing · TEST = send only to the test inbox/number · LIVE = send to the real client. Set separately for Email, WhatsApp and Alerts.'],
                ['bi-plus-lg',       '+ Add email / + Add number', 'Give one developer several client emails or phones — each report goes to all of them.'],
                ['bi-x-lg',          '× / trash',      'Remove that email, number, or developer row.'],
                ['bi-save',          'Save Settings',  'Writes your changes. Nothing is saved until you press this.'],
                ['bi-eye',           'Read-only (Viewer)', 'Viewer accounts see everything greyed out — they cannot change or save.'],
            ],
        ],
        'users' => [
            'intro' => 'Access control for the admin panel. Create logins, assign a role (Admin or Viewer), enable/disable accounts, or remove them. The last active admin cannot be removed or disabled.',
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
            'buttons' => [
                ['bi-person-plus',  'Add user',        'Create a new login (username, password, role).'],
                ['bi-toggle-on',    'Active toggle',   'Turn a login on or off without deleting it. You cannot switch off the last admin.'],
                ['bi-trash',        'Delete',          'Removes a login for good. You cannot delete the last admin.'],
                ['bi-shield-lock',  'Role: Admin / Viewer', 'Admin can change everything; Viewer is read-only.'],
            ],
        ],
        'sync' => [
            'intro' => 'Manual data rebuild. Re-reads all submitted reports and regenerates the project, workforce and alert tables. Read-only over reports — it never edits or re-sends them. Runs automatically in the background; this forces it immediately.',
            'does'  => [
                'Force the panel to update immediately instead of waiting.',
                'Fix a page that looks out of date after new reports came in.',
            ],
            'steps' => [
                'If a new report is not showing on a page, come here and press <b>Sync now</b>.',
                'Wait for the "done" message, then go back to the page — it will be up to date.',
            ],
            'buttons' => [
                ['bi-arrow-repeat', 'Sync now', 'Re-reads all reports and rebuilds the project, workforce and alert lists. Safe to press any time — it only reads, never changes reports.'],
            ],
        ],
        'project' => [
            'intro' => 'Full 360 view of one project: the step timeline (planned vs actual dates), every status change with reasons, workforce per visit, client-delivery events, and current risks — all aggregated across its reports.',
            'does'  => [
                'Read the complete history of visits for this project.',
                'See the current status and the tentative end date.',
                'Open any single report for full details.',
            ],
            'steps' => [
                'Scroll the timeline to follow the project from first visit to now.',
                'Example: a client asks "what was done on my site last week?" — find that date in the timeline and open the report.',
            ],
            'buttons' => [
                ['bi-card-list',    'Reports',           'Opens the list of every report filed for this project.'],
                ['bi-flag',         'Lifecycle menu',    'Change the project\'s overall state: Mark Commissioned, Close project, or Reopen (admins only).'],
                ['bi-pause-circle', 'Red reason chip',   'Under Status Changes — why a step is on hold (who it is stuck on).'],
                ['bi-check-circle', 'Green resolved chip','Shows a hold was cleared, and keeps a note of what it was held for — history is never lost.'],
            ],
            'legend' => 'lifecycle',
        ],
        'submission' => [
            'intro' => 'A single site report in full: the engineer\'s entries, step statuses, workforce, photos and attachments, plus the delivery record for the PDF, email and WhatsApp.',
            'does'  => [
                'Read the engineer\'s notes and view the site photos.',
                'Check whether each delivery step (email / WhatsApp / PDF) succeeded.',
                'Use it to confirm the client received their update.',
            ],
            'steps' => [
                'Look at the status badges to confirm the message was delivered.',
                'If something shows failed, note the reason, then re-check <b>Settings</b> or ask for a re-send.',
            ],
            'buttons' => [
                ['bi-file-earmark-pdf', 'View PDF',       'Opens / downloads the generated report PDF that was sent to the client.'],
                ['bi-three-dots-vertical', 'More menu',   'Extra actions for this report.'],
                ['bi-images',           'Photo thumbnails','Click a site photo to open the full-size image.'],
            ],
            'legend' => 'status',
        ],
        'worker' => [
            'intro' => 'Profile of one worker/engineer: projects worked, steps performed, activity by month, and every recorded visit — compiled from the workforce named in reports.',
            'does'  => [
                'See every report this person filed and when.',
                'Judge how active they have been lately.',
            ],
            'steps' => [
                'Scan the list of dates to see their latest site visit.',
                'Example: confirm an engineer actually visited before approving their work.',
            ],
            'buttons' => [
                ['bi-grid-1x2', 'Coloured stat tiles', 'Quick summary of this person: projects, total visits, distinct steps, last active, active months.'],
                ['bi-box-arrow-up-right', 'Project / #Report links', 'Open the project or the exact report from any visit row.'],
            ],
        ],
        'contractor' => [
            'intro' => 'Profile of one contractor company: its workers, the projects and trades it worked, and every visit. Trade/skill and phone can be recorded here for reference.',
            'does'  => [
                'See which projects this contractor is involved in.',
                'Review their recent site activity.',
            ],
            'steps' => [
                'Open a linked project from here to see the full site history.',
                'Example: before paying a contractor, check the visits recorded against their sites.',
            ],
            'buttons' => [
                ['bi-save',  'Save (Trade / Phone)', 'Store this contractor\'s skill/trade and contact number (admins only).'],
                ['bi-box-arrow-up-right', 'Project links', 'Open any project this contractor worked on.'],
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
        if (!empty($d['buttons'])) {
            echo '<div class="pi-h"><i class="bi bi-hand-index"></i> Buttons &amp; symbols on this page</div>';
            echo '<ul class="pi-btns">';
            foreach ($d['buttons'] as [$icon, $label, $desc]) {
                echo '<li><span class="pi-ic"><i class="bi ' . $icon . '"></i></span>'
                   . '<span class="pi-lg-txt"><b>' . $label . '</b> — ' . $desc . '</span></li>';
            }
            echo '</ul>';
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
        // Work-step status pills + client-delivery badges.
        $status = [
            ['ok',    'Done',                 'Work step completed.'],
            ['warn',  'Pending / In progress','Step started but not yet finished.'],
            ['bad',   'Hold',                 'Step blocked — waiting on the Client or on VAPL.'],
            ['muted', 'Not started',          'Step not yet begun.'],
            ['ok',    'Email / WhatsApp sent','Client notification delivered successfully.'],
            ['bad',   'Failed',               'A notification or processing step did not complete.'],
        ];
        // Pipeline traffic-light health.
        $health = [
            ['ok',   'Green', 'Latest report fully processed — PDF generated and the client notified.'],
            ['warn', 'Amber', 'Still processing, or delivered only in part.'],
            ['bad',  'Red',   'A processing step failed and needs attention.'],
        ];
        // Alert severity + alert status (Notifications). Thresholds match the alert engine.
        $alerts = [
            ['bad',   'Critical',     'Urgent. Raised for: no report in 48h+, target-end date passed, a pipeline failure, or a hold unresolved for 3+ days.'],
            ['warn',  'Warning',      'Needs attention. Raised for: no report in 24h+, a missed planned date, target end near with low progress, or a PE carrying 6+ active projects.'],
            ['info',  'Info',         'Informational only.'],
            ['muted', 'Acknowledged', 'Seen by an admin; not yet resolved.'],
            ['muted', 'Snoozed',      'Hidden until its wake date, then re-opens automatically.'],
            ['muted', 'Resolved',     'Closed. Re-opens automatically if the condition returns.'],
        ];
        // Calendar event colours.
        $calendar = [
            ['info', 'Planned work', 'Scheduled work for that day, taken from a report\'s "planned for tomorrow" steps.'],
            ['warn', 'Target end',   'The project\'s tentative completion date.'],
        ];
        // Project lifecycle pills — assigned automatically from report data.
        $lifecycle = [
            ['muted', 'Not Started',           'No steps completed yet.'],
            ['info',  'Active',                'Steps completed and on schedule.'],
            ['warn',  'At Risk',               'Past the target-end date, or no report in 72h+ — the schedule is slipping.'],
            ['bad',   'On Hold',               'Latest report marked a step on hold (blocked on Client or VAPL).'],
            ['info',  'Commissioning Pending', 'All steps done except final commissioning.'],
            ['ok',    'Commissioned',          'Commissioning complete.'],
            ['muted', 'Closed',                'Manually closed by an admin.'],
        ];
        return ['status'=>$status, 'health'=>$health, 'alerts'=>$alerts, 'calendar'=>$calendar, 'lifecycle'=>$lifecycle][$set] ?? [];
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
        echo '<link rel="stylesheet" href="' . $b . '/assets/admin.css?v=17">';
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
