<?php
/**
 * Weekly PE report — one email per Project Engineer, sent Saturday evening.
 *
 * "What you did this week": every site the PE touched Mon→Sat, day by day, with
 * the step progress, the current blocker, the plan they committed to next, and
 * the target end date. Plus the things a PE forgets: plans that slipped, sites
 * we (VAPL) are blocking, sites with no update, target dates about to pass, and
 * their own reporting compliance.
 *
 * READ-ONLY over submissions/projects/visit_workers/alerts — it never writes to
 * the live submit → sheet → PDF → email/WhatsApp pipeline.
 *
 * GATED: nothing is sent unless overrides.json sets pe_weekly.mode to TEST or
 * LIVE (default OFF). Recipients come from the Team & Alerts "PE / Staff
 * contacts" table (team_contacts) — never client contacts.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../src/Smtp.php';

class PeWeekly
{
    /** Mon..Sat — the reporting week a PE is measured on. */
    const WORK_DAYS = 6;
    /** An Active site with no report for this many days is flagged. */
    const STALE_DAYS = 3;
    /** Target end within this many days is flagged as "closing in". */
    const NEAR_END_DAYS = 7;

    /* ============================== window ============================== */

    /**
     * The Mon→$end week containing $end (Y-m-d, normally a Saturday).
     * @return array{start:string,end:string,label:string,days:array<string>}
     */
    public static function window(string $end): array
    {
        $endTs = strtotime($end) ?: time();
        $dow   = (int)date('N', $endTs);                       // 1=Mon … 7=Sun
        $start = strtotime('-' . ($dow - 1) . ' days', $endTs);

        $days = [];
        for ($t = $start; $t <= $endTs; $t = strtotime('+1 day', $t)) {
            $days[] = date('Y-m-d', $t);
        }
        return [
            'start' => date('Y-m-d', $start),
            'end'   => date('Y-m-d', $endTs),
            'label' => date('d M', $start) . ' – ' . date('d M Y', $endTs),
            'days'  => $days,
        ];
    }

    /* =============================== build ============================== */

    /**
     * Builds the whole week's report, keyed by PE name.
     *
     * @param array $opts ['pe' => only this PE, 'include_empty' => bool]
     * @return array<string,array> pe => report data
     */
    public static function build(PDO $db, array $win, array $opts = []): array
    {
        $onlyPe = trim((string)($opts['pe'] ?? ''));
        $from   = $win['start'] . ' 00:00:00';
        $to     = $win['end']   . ' 23:59:59';

        // ---- this week's visits (multi-flat rows exploded into one per flat) ----
        $st = $db->prepare(
            "SELECT id, project, order_id, client_type, site_type, developer, building, floor, flat_no,
                    engineer, current_status, status, hold_reason, hold_reason_detail, activity,
                    next_plan, tentative_end, work_done_by, contractor_name, people,
                    amendment, amendment_why, drawing_change, measurement, payload_json, created_at
               FROM submissions
              WHERE created_at BETWEEN ? AND ?
              ORDER BY created_at ASC"
        );
        $st->execute([$from, $to]);
        $visits = expandVisits($st->fetchAll(PDO::FETCH_ASSOC));

        // ---- masters ----
        $projects = [];
        foreach ($db->query("SELECT * FROM projects") as $p) {
            $projects[$p['project_key']] = $p;
        }
        // Alerts, minus the rules "Needs your attention" already words better —
        // otherwise every hold/no-report/slipped plan is printed twice.
        $covered = ['project_on_hold', 'no_report', 'plan_missed', 'end_overdue', 'end_soon'];
        $alerts = [];
        foreach ($db->query(
            "SELECT rule, owner, title, detail, project_label, severity, created_at
               FROM alerts
              WHERE status IN ('open','ack') AND severity IN ('critical','warning')
              ORDER BY FIELD(severity,'critical','warning'), id DESC"
        ) as $a) {
            if (in_array($a['rule'], $covered, true)) continue;
            $alerts[trim((string)$a['owner'])][] = $a;
        }
        $crew = [];
        $cw = $db->prepare(
            "SELECT engineer, worker_name, type, contractor_name
               FROM visit_workers WHERE visit_date BETWEEN ? AND ?"
        );
        $cw->execute([$win['start'], $win['end']]);
        foreach ($cw->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $crew[trim((string)$w['engineer'])][] = $w;
        }

        // ---- every PE that either reported this week or owns a project ----
        $peSet = [];
        foreach ($visits as $v) {
            $n = trim((string)$v['engineer']);
            if ($n !== '') $peSet[$n] = true;
        }
        foreach ($projects as $p) {
            $n = trim((string)($p['primary_pe'] ?? ''));
            if ($n !== '' && self::isOpen($p)) $peSet[$n] = true;
        }
        $peNames = array_keys($peSet);
        sort($peNames, SORT_NATURAL | SORT_FLAG_CASE);

        $out = [];
        foreach ($peNames as $pe) {
            if ($onlyPe !== '' && strcasecmp($pe, $onlyPe) !== 0) continue;
            $rep = self::forPe($pe, $win, $visits, $projects, $alerts[$pe] ?? [], $crew[$pe] ?? []);
            if (!$rep['has_content'] && empty($opts['include_empty'])) continue;
            $out[$pe] = $rep;
        }
        return $out;
    }

    /** One PE's week. */
    private static function forPe(string $pe, array $win, array $visits, array $projects, array $alerts, array $crew): array
    {
        $today = date('Y-m-d');

        // ---------- sites worked this week ----------
        $sites = [];                 // project_key => site block
        $daysActive = [];
        $stepsDone = 0; $flags = [];
        foreach ($visits as $v) {
            if (strcasecmp(trim((string)$v['engineer']), $pe) !== 0) continue;

            $key   = projectKey($v);
            $day   = substr((string)$v['created_at'], 0, 10);
            $daysActive[$day] = true;

            $pl    = json_decode((string)$v['payload_json'], true) ?: [];
            $steps = parseSteps($pl);
            $stepsDone += count($steps['done']);

            if (!isset($sites[$key])) {
                $proj = $projects[$key] ?? null;
                $sites[$key] = [
                    'key'       => $key,
                    'label'     => projectLabel($v),
                    'order_id'  => trim((string)$v['order_id']),
                    'site_type' => (string)$v['site_type'],
                    'proj'      => $proj,
                    'visits'    => 0,
                    'log'       => [],      // day-by-day rows
                    'done'      => [],      // steps completed this week
                    'holds'     => [],
                    'last'      => $v,
                ];
            }
            $s = &$sites[$key];
            $s['visits']++;
            $s['last'] = $v;
            foreach ($steps['done'] as $d)  { $s['done'][$d] = true; }
            foreach ($steps['hold'] as $h)  { $s['holds'][$h['step']] = $h; }
            $s['log'][] = [
                'day'      => $day,
                'done'     => $steps['done'],
                'pending'  => $steps['pending'],
                'hold'     => $steps['hold'],
                'activity' => trim((string)$v['activity']),
                'crew'     => trim((string)$v['work_done_by']),
            ];
            unset($s);

            // paperwork raised on the visit — manager follow-ups the PE started
            if (strcasecmp((string)$v['amendment'], 'Yes') === 0) {
                $flags[] = ['Amendment raised', projectLabel($v), trim((string)$v['amendment_why'])];
            }
            if (strcasecmp((string)$v['drawing_change'], 'Yes') === 0) {
                $flags[] = ['Drawing change', projectLabel($v), ''];
            }
            if (strcasecmp((string)$v['measurement'], 'Yes') === 0) {
                $flags[] = ['Measurement submitted', projectLabel($v), ''];
            }
        }
        ksort($sites);

        // ---------- the PE's open portfolio (not only what they visited) ----------
        $mine = [];
        foreach ($projects as $k => $p) {
            if (strcasecmp(trim((string)($p['primary_pe'] ?? '')), $pe) !== 0) continue;
            if (!self::isOpen($p)) continue;
            $mine[$k] = $p;
        }

        // ---------- next week's plan ----------
        $nextFrom = date('Y-m-d', strtotime($win['end'] . ' +1 day'));
        $nextTo   = date('Y-m-d', strtotime($win['end'] . ' +7 days'));
        $plan = [];
        foreach ($mine as $p) {
            $d = (string)($p['next_plan_date'] ?? '');
            if ($d === '' || $d < $nextFrom || $d > $nextTo) continue;
            $plan[] = [
                'date'  => $d,
                'label' => (string)$p['label'],
                'steps' => trim((string)$p['next_plan_steps']) ?: '—',
            ];
        }
        usort($plan, fn($a, $b) => strcmp($a['date'], $b['date']));

        // ---------- needs attention ----------
        $attn = [];
        foreach ($mine as $p) {
            $label = (string)$p['label'];

            $holdOwner = trim((string)($p['hold_owner'] ?? ''));
            if ($holdOwner !== '' && stripos($holdOwner, 'vapl') !== false) {
                $since = (string)($p['hold_since'] ?? '');
                $attn[] = ['bad', 'We are the blocker', $label,
                    'On hold on VAPL' . ($since ? ' since ' . fmtDate($since) . ' (' . self::daysBetween($since, $today) . ' days)' : '')];
            } elseif ($holdOwner !== '') {
                $since = (string)($p['hold_since'] ?? '');
                $attn[] = ['warn', 'Waiting on ' . $holdOwner, $label,
                    'Chase it' . ($since ? ' — stuck since ' . fmtDate($since) : '')];
            }

            $npd = (string)($p['next_plan_date'] ?? '');
            $last = substr((string)($p['last_report_at'] ?? ''), 0, 10);
            if ($npd !== '' && $npd < $today && ($last === '' || $last < $npd)) {
                $attn[] = ['bad', 'Plan slipped', $label,
                    'Planned ' . fmtDate($npd) . ' — ' . (trim((string)$p['next_plan_steps']) ?: 'steps') . ' — no report filed since'];
            }

            if ($last !== '' && self::daysBetween($last, $today) >= self::STALE_DAYS && $holdOwner === '') {
                $attn[] = ['warn', 'No update ' . self::daysBetween($last, $today) . ' days', $label,
                    'Last report ' . fmtDate($last)];
            }

            $tgt = (string)($p['target_end'] ?? '');
            if ($tgt !== '') {
                $left = self::daysBetween($today, $tgt);
                if ($tgt < $today) {
                    $attn[] = ['bad', 'Target end passed', $label, 'Was due ' . fmtDate($tgt) . ' — ' . abs($left) . ' days over'];
                } elseif ($left <= self::NEAR_END_DAYS) {
                    $attn[] = ['warn', 'Target end in ' . $left . ' day' . ($left === 1 ? '' : 's'), $label, 'Due ' . fmtDate($tgt)];
                }
            }
        }
        // criticals first, cap the list so the mail stays readable
        usort($attn, fn($a, $b) => ($a[0] === 'bad' ? 0 : 1) <=> ($b[0] === 'bad' ? 0 : 1));
        $attn = array_slice($attn, 0, 14);

        // ---------- crew deployed ----------
        $workers = []; $contractors = [];
        foreach ($crew as $w) {
            $n = trim((string)$w['worker_name']);
            if ($n !== '') $workers[$n] = true;
            $c = trim((string)$w['contractor_name']);
            if ($c !== '') $contractors[$c] = true;
        }

        // ---------- reporting compliance ----------
        $workDays = array_slice($win['days'], 0, self::WORK_DAYS);
        $missed = [];
        foreach ($workDays as $d) {
            if ($d > date('Y-m-d')) continue;                 // future day in a mid-week preview
            if (empty($daysActive[$d])) $missed[] = $d;
        }

        return [
            'pe'          => $pe,
            'window'      => $win,
            'sites'       => array_values($sites),
            'plan'        => $plan,
            'attention'   => $attn,
            'flags'       => $flags,
            'alerts'      => $alerts,
            'workers'     => array_keys($workers),
            'contractors' => array_keys($contractors),
            'stats'       => [
                'sites'       => count($sites),
                'reports'     => array_sum(array_map(fn($s) => $s['visits'], $sites)),
                'steps'       => $stepsDone,
                'days'        => count($daysActive),
                'open'        => count($mine),
                'holds'       => count(array_filter($mine, fn($p) => trim((string)($p['hold_owner'] ?? '')) !== '')),
                'missed_days' => count($missed),
            ],
            'missed_days' => $missed,
            'has_content' => (bool)$sites || (bool)$mine,
        ];
    }

    /* ================================ mail ============================== */

    public static function subject(array $rep): string
    {
        return 'Your Week — ' . $rep['pe'] . ' · ' . $rep['window']['label'];
    }

    /** Full HTML body for one PE. Table/inline-style only (email clients). */
    public static function html(array $rep): string
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $s = $rep['stats'];
        $h = '';

        // ---- header ----
        $h .= '<div style="background:#0f1b30;color:#fff;padding:22px 24px;border-radius:12px 12px 0 0">'
            . '<div style="font-size:12px;letter-spacing:.09em;text-transform:uppercase;color:#8fb0e0">Vakharia Airtech · Weekly Site Report</div>'
            . '<div style="font-size:23px;font-weight:700;margin-top:6px">' . $e($rep['pe']) . '</div>'
            . '<div style="font-size:13.5px;color:#b9cbe6;margin-top:3px">' . $e($rep['window']['label']) . '</div></div>';

        $h .= '<div style="border:1px solid #e6ecf5;border-top:0;border-radius:0 0 12px 12px;padding:20px 24px;background:#fff">';

        // ---- snapshot ----
        $tiles = [
            ['Sites worked',   $s['sites']],
            ['Reports filed',  $s['reports']],
            ['Steps done',     $s['steps']],
            ['Days on site',   $s['days'] . '/' . self::WORK_DAYS],
            ['Open sites',     $s['open']],
            ['On hold',        $s['holds']],
        ];
        $h .= '<table cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 6px"><tr>';
        foreach ($tiles as $i => $t) {
            $h .= '<td width="16.6%" style="padding:10px 6px;text-align:center;background:#f7f9fc;border:1px solid #eef2f8;border-radius:9px">'
                . '<div style="font-size:20px;font-weight:800;color:#0f1b30">' . $e($t[1]) . '</div>'
                . '<div style="font-size:10.5px;color:#7b8ba3;text-transform:uppercase;letter-spacing:.04em;margin-top:2px">' . $e($t[0]) . '</div></td>';
            if ($i < count($tiles) - 1) $h .= '<td width="6"></td>';
        }
        $h .= '</tr></table>';

        // ---- site by site ----
        $h .= self::heading('Site by site — what you did this week', count($rep['sites']));
        if (!$rep['sites']) {
            $h .= self::muted('No site reports filed this week.');
        }
        foreach ($rep['sites'] as $site) {
            $h .= self::siteCard($site, $e);
        }

        // ---- next week ----
        $h .= self::heading('Planned for next week', count($rep['plan']));
        if ($rep['plan']) {
            $h .= '<table cellpadding="0" cellspacing="0" width="100%" style="font-size:13px;border-collapse:collapse">';
            foreach ($rep['plan'] as $p) {
                $h .= '<tr>'
                    . '<td style="padding:7px 10px 7px 0;color:#0f1b30;font-weight:700;white-space:nowrap;border-bottom:1px solid #f1f4f9;width:105px">'
                    . $e(date('D d M', strtotime($p['date']))) . '</td>'
                    . '<td style="padding:7px 10px 7px 0;color:#334155;border-bottom:1px solid #f1f4f9">' . $e($p['label']) . '</td>'
                    . '<td style="padding:7px 0;color:#5b6b82;border-bottom:1px solid #f1f4f9">' . $e($p['steps']) . '</td></tr>';
            }
            $h .= '</table>';
        } else {
            $h .= self::muted('No next plan date recorded on your sites. Fill "next plan" on your reports so this fills in.');
        }

        // ---- attention ----
        $h .= self::heading('Needs your attention', count($rep['attention']));
        if ($rep['attention']) {
            foreach ($rep['attention'] as $a) {
                [$tone, $title, $label, $detail] = $a;
                $col = $tone === 'bad' ? '#dc2626' : '#d97706';
                $bg  = $tone === 'bad' ? '#fef4f3' : '#fffaf0';
                $h .= '<div style="border-left:3px solid ' . $col . ';background:' . $bg . ';padding:9px 12px;margin:0 0 7px;border-radius:0 7px 7px 0">'
                    . '<span style="color:' . $col . ';font-weight:700;font-size:12.5px">' . $e($title) . '</span>'
                    . '<span style="color:#0f1b30;font-size:13px"> · ' . $e($label) . '</span>'
                    . ($detail !== '' ? '<div style="color:#5b6b82;font-size:12px;margin-top:2px">' . $e($detail) . '</div>' : '')
                    . '</div>';
            }
        } else {
            $h .= self::muted('Nothing pending — all your sites are moving. Keep it up.');
        }

        // ---- open alerts raised on the PE ----
        if ($rep['alerts']) {
            $h .= self::heading('Other open alerts', count($rep['alerts']));
            $h .= '<ul style="margin:0;padding-left:18px;color:#334155;font-size:13px;line-height:1.75">';
            foreach ($rep['alerts'] as $a) {
                $h .= '<li><b>' . $e($a['title']) . '</b> — ' . $e($a['detail'])
                    . ' <span style="color:#94a3b8">(' . $e(fmtDate($a['created_at'])) . ')</span></li>';
            }
            $h .= '</ul>';
        }

        // ---- paperwork raised ----
        if ($rep['flags']) {
            $h .= self::heading('Raised this week', count($rep['flags']));
            $h .= '<ul style="margin:0;padding-left:18px;color:#334155;font-size:13px;line-height:1.75">';
            foreach ($rep['flags'] as $f) {
                $h .= '<li><b>' . $e($f[0]) . '</b> — ' . $e($f[1]) . ($f[2] !== '' ? ': ' . $e(snip($f[2], 90)) : '') . '</li>';
            }
            $h .= '</ul>';
        }

        // ---- crew + reporting ----
        $h .= self::heading('Your week in numbers', 0, false);
        $crewTxt = $rep['workers']
            ? count($rep['workers']) . ' worker(s): ' . $e(implode(', ', array_slice($rep['workers'], 0, 12)))
            : 'No manpower recorded on your reports this week.';
        if ($rep['contractors']) {
            $crewTxt .= ' · Contractors: ' . $e(implode(', ', $rep['contractors']));
        }
        $h .= '<div style="font-size:13px;color:#334155;line-height:1.8">'
            . '<div><b>Manpower deployed:</b> ' . $crewTxt . '</div>'
            . '<div><b>Reporting:</b> filed on ' . (int)$s['days'] . ' of ' . self::WORK_DAYS . ' working days'
            . ($rep['missed_days']
                ? ' — <span style="color:#dc2626">no report on ' . $e(implode(', ', array_map(fn($d) => date('D d M', strtotime($d)), $rep['missed_days']))) . '</span>'
                : ' — <span style="color:#16a34a">full attendance on reports</span>')
            . '</div></div>';

        $h .= '<div style="margin-top:20px;padding-top:14px;border-top:1px solid #eef2f8;font-size:11.5px;color:#94a3b8;line-height:1.6">'
            . 'Automated weekly summary from the PMS admin panel — built from the site reports you filed. '
            . 'Something wrong or missing? It came from a report, so fix it on the next one or tell the office.'
            . '</div></div>';

        return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:680px;margin:0 auto;background:#f4f7fb;padding:16px">' . $h . '</div>';
    }

    /** One project block: progress, day-by-day, status now, next plan, target. */
    private static function siteCard(array $site, callable $e): string
    {
        // A site reported since the last admin sync has no projects row yet — every
        // read below falls back to the submission itself, so the card still renders.
        $p = is_array($site['proj']) ? $site['proj'] : [];
        $done = (int)($p['steps_done'] ?? 0);
        $tot  = (int)($p['steps_total'] ?? 0);
        $pct  = $tot > 0 ? min(100, (int)round($done * 100 / $tot)) : 0;
        $life = trim((string)($p['lifecycle'] ?? ''));
        $tone = ['On Hold' => '#dc2626', 'At Risk' => '#d97706', 'Active' => '#16a34a',
                 'Commissioned' => '#2563eb', 'Closed' => '#64748b'][$life] ?? '#64748b';

        $h = '<div style="border:1px solid #e6ecf5;border-radius:10px;padding:14px 16px;margin:0 0 11px;background:#fdfefe">';

        // title row
        $h .= '<div style="font-size:15px;font-weight:700;color:#0f1b30">' . $e($site['label']) . '</div>'
            . '<div style="font-size:11.5px;color:#8190a5;margin-top:2px">'
            . ($site['order_id'] !== '' ? 'Order ' . $e($site['order_id']) . ' · ' : '')
            . $e($site['site_type']) . ' · ' . (int)$site['visits'] . ' report' . ($site['visits'] === 1 ? '' : 's') . ' this week'
            . ($life !== '' ? ' · <span style="color:' . $tone . ';font-weight:700">' . $e($life) . '</span>' : '')
            . '</div>';

        // progress
        if ($tot > 0) {
            $h .= '<table cellpadding="0" cellspacing="0" width="100%" style="margin:10px 0 4px"><tr>'
                . '<td style="background:#eef2f8;border-radius:5px;height:8px;padding:0">'
                . '<table cellpadding="0" cellspacing="0" width="' . $pct . '%" style="height:8px"><tr>'
                . '<td style="background:#2f81f7;border-radius:5px;height:8px;font-size:0;line-height:0">&nbsp;</td></tr></table></td>'
                . '<td width="120" style="padding-left:10px;font-size:11.5px;color:#5b6b82;white-space:nowrap">'
                . $done . '/' . $tot . ' steps · ' . $pct . '%</td></tr></table>';
        }

        // day-by-day
        $h .= '<div style="margin-top:9px">';
        foreach ($site['log'] as $l) {
            $bits = [];
            if ($l['done'])    $bits[] = '<span style="color:#16a34a;font-weight:700">Done:</span> ' . $e(implode(', ', $l['done']));
            if ($l['hold'])    $bits[] = '<span style="color:#dc2626;font-weight:700">Hold:</span> ' . $e(implode(', ', array_map(fn($x) => $x['step'] . ' (' . $x['party'] . ')', $l['hold'])));
            if ($l['pending']) $bits[] = '<span style="color:#8190a5">Pending:</span> ' . $e(implode(', ', array_slice($l['pending'], 0, 4)));
            $h .= '<div style="font-size:12.5px;color:#334155;padding:5px 0;border-top:1px solid #f3f6fa">'
                . '<b style="color:#0f1b30">' . $e(date('D d M', strtotime($l['day']))) . '</b> — '
                . ($bits ? implode(' · ', $bits) : $e(snip($l['activity'], 110)))
                . ($bits && $l['activity'] !== '' ? '<div style="color:#7b8ba3;font-size:11.5px;margin-top:2px">' . $e(snip($l['activity'], 130)) . '</div>' : '')
                . '</div>';
        }
        $h .= '</div>';

        // where it stands + what's next
        $rows = [];
        $cur = trim((string)($p['current_step'] ?? '')) ?: trim((string)$site['last']['current_status']);
        if ($cur !== '') $rows[] = ['Current step', $e(snip($cur, 90))];

        if ($site['holds']) {
            $hb = [];
            foreach ($site['holds'] as $x) {
                $hb[] = $x['step'] . ' — stuck on ' . ($x['party'] ?: '?') . ($x['detail'] !== '' ? ' (' . snip($x['detail'], 60) . ')' : '');
            }
            $rows[] = ['Blocked', '<span style="color:#dc2626">' . $e(implode('; ', $hb)) . '</span>'];
        }

        $npd = trim((string)($p['next_plan_date'] ?? ''));
        $nps = trim((string)($p['next_plan_steps'] ?? '')) ?: trim((string)$site['last']['next_plan']);
        if ($npd !== '' || $nps !== '') {
            $rows[] = ['Next', ($npd !== '' ? '<b>' . $e(date('D d M', strtotime($npd))) . '</b> — ' : '') . $e(snip($nps, 110))];
        }

        $tgt = trim((string)($p['target_end'] ?? '')) ?: trim((string)$site['last']['tentative_end']);
        if ($tgt !== '' && strtotime($tgt)) {
            $left = self::daysBetween(date('Y-m-d'), date('Y-m-d', strtotime($tgt)));
            $note = $left < 0 ? '<span style="color:#dc2626">' . abs($left) . ' days overdue</span>'
                  : ($left <= self::NEAR_END_DAYS ? '<span style="color:#d97706">' . $left . ' days left</span>'
                  : '<span style="color:#5b6b82">' . $left . ' days left</span>');
            $rows[] = ['Target end', $e(fmtDate($tgt)) . ' · ' . $note];
        }

        if ($rows) {
            $h .= '<table cellpadding="0" cellspacing="0" width="100%" style="margin-top:9px;font-size:12.5px;border-collapse:collapse">';
            foreach ($rows as $r) {
                $h .= '<tr><td width="86" style="padding:4px 10px 4px 0;color:#8190a5;vertical-align:top;white-space:nowrap">' . $r[0] . '</td>'
                    . '<td style="padding:4px 0;color:#334155">' . $r[1] . '</td></tr>';
            }
            $h .= '</table>';
        }

        return $h . '</div>';
    }

    private static function heading(string $t, int $n = 0, bool $count = true): string
    {
        return '<div style="margin:22px 0 10px;font-size:13px;font-weight:700;color:#0f1b30;text-transform:uppercase;letter-spacing:.05em;border-bottom:2px solid #eef2f8;padding-bottom:6px">'
            . htmlspecialchars($t) . ($count ? ' <span style="color:#94a3b8;font-weight:600">(' . $n . ')</span>' : '') . '</div>';
    }

    private static function muted(string $t): string
    {
        return '<div style="color:#94a3b8;font-size:13px;padding:4px 0">' . htmlspecialchars($t) . '</div>';
    }

    /* ================================ send ============================== */

    /**
     * Builds and sends the week's mails.
     *
     * @param array $opts ['date'=>Y-m-d week end, 'pe'=>only this PE, 'test'=>bool,
     *                     'force'=>bool (ignore mode/empty), 'dry'=>bool (build only)]
     * @return array ['status','detail','sent'=>int,'skipped'=>int,'reports'=>[pe=>['to','subject','html']]]
     */
    public static function run(PDO $db, array $cfg, array $opts = []): array
    {
        $w    = $cfg['pe_weekly'] ?? [];
        $mode = strtoupper((string)($w['mode'] ?? 'OFF'));
        $test = !empty($opts['test']);
        $dry  = !empty($opts['dry']);

        if (!$test && !$dry && $mode === 'OFF') {
            return ['status' => 'SKIPPED', 'detail' => 'pe_weekly mode=OFF', 'sent' => 0, 'skipped' => 0, 'reports' => []];
        }

        $end = $opts['date'] ?? date('Y-m-d');
        $win = self::window($end);
        $reports = self::build($db, $win, [
            'pe'            => $opts['pe'] ?? '',
            'include_empty' => !empty($w['include_empty']) || $test || !empty($opts['force']),
        ]);
        if (!$reports) {
            return ['status' => 'SKIPPED', 'detail' => 'no PE activity in ' . $win['label'], 'sent' => 0, 'skipped' => 0, 'reports' => []];
        }

        // contacts from the Team & Alerts table
        $ov   = self::overrides();
        $team = is_array($ov['team_contacts'] ?? null) ? $ov['team_contacts'] : [];
        $cc   = !empty($w['cc_manager']) ? trim((string)($ov['alert_manager_email'] ?? '')) : '';
        $testTo = trim((string)($w['test_to'] ?? '')) ?: trim((string)($cfg['email']['test_to'] ?? ''));

        $out = []; $sent = 0; $skipped = 0; $errs = [];
        $smtp = ($dry) ? null : new Smtp($cfg['email']);

        foreach ($reports as $pe => $rep) {
            $subject = self::subject($rep);
            $html    = self::html($rep);

            if ($test || $mode === 'TEST') {
                $to = $testTo;
                $subject = '[TEST → ' . $pe . '] ' . $subject;
                $ccTo = '';
            } else {
                $to = self::peEmail($team, $pe);
                $ccTo = $cc;
            }
            $out[$pe] = ['to' => $to, 'subject' => $subject, 'html' => $html];

            if ($dry) continue;
            if ($to === '') { $skipped++; $errs[] = $pe . ': no email set'; continue; }
            try {
                $smtp->send($to, $subject, $html, null, $ccTo);
                $sent++;
            } catch (Throwable $e) {
                $skipped++; $errs[] = $pe . ': ' . $e->getMessage();
            }
            if ($test || $mode === 'TEST') break;      // one sample is enough in TEST
        }

        $status = $dry ? 'BUILT' : ($sent > 0 ? ($skipped ? "PARTIAL ($sent/" . ($sent + $skipped) . ')' : 'SENT') : 'FAILED');
        $detail = count($reports) . ' PE report(s)' . ($errs ? ' — ' . implode('; ', array_slice($errs, 0, 5)) : '');
        return ['status' => $status, 'detail' => $detail, 'sent' => $sent, 'skipped' => $skipped, 'reports' => $out];
    }

    /* =============================== utils ============================= */

    /** Case-insensitive lookup in team_contacts. */
    private static function peEmail(array $team, string $pe): string
    {
        foreach ($team as $name => $c) {
            if (strcasecmp((string)$name, $pe) === 0) {
                $mails = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($c['email'] ?? ''))),
                    fn($m) => (bool)filter_var($m, FILTER_VALIDATE_EMAIL));
                return implode(',', $mails);
            }
        }
        return '';
    }

    /** Projects still worth nagging about. */
    private static function isOpen(array $p): bool
    {
        return !in_array(trim((string)($p['lifecycle'] ?? '')), ['Commissioned', 'Closed'], true);
    }

    /** Whole days from $a to $b (negative when $b is before $a). */
    private static function daysBetween(string $a, string $b): int
    {
        $ta = strtotime(substr($a, 0, 10)); $tb = strtotime(substr($b, 0, 10));
        if (!$ta || !$tb) return 0;
        return (int)round(($tb - $ta) / 86400);
    }

    private static function overrides(): array
    {
        $f = __DIR__ . '/../../config/overrides.json';
        if (is_file($f)) { $o = json_decode((string)file_get_contents($f), true); if (is_array($o)) return $o; }
        return [];
    }
}
