<?php
/**
 * Sync — the read-only normalizer. Reads submissions/payload_json and rebuilds
 * the master tables (workers, contractors, visit_workers, projects) and the
 * alert inbox. Idempotent: safe to run repeatedly (button + scheduled --once).
 *
 * It NEVER writes to submissions/process_log/attachments or the live pipeline —
 * it only reads them, so running it cannot affect report submission.
 *
 * Requires helpers.php (projectKey/projectLabel/parseSteps/canonicalSteps/…).
 */
class Sync
{
    /** Alert thresholds (overridable via overrides.json → 'alert_rules'). */
    private static function thr(array $cfg): array
    {
        $d = [
            'hold_unresolved_days'  => 3,
            'pending_stale_days'    => 4,
            'no_report_warn_hours'  => 24,
            'no_report_crit_hours'  => 48,
            'end_approaching_days'  => 7,
            'pe_overload_projects'  => 6,
        ];
        return array_merge($d, is_array($cfg['alert_rules'] ?? null) ? $cfg['alert_rules'] : []);
    }

    public static function run(PDO $db, array $cfg = []): array
    {
        $subs = $db->query(
            "SELECT id, site_type, client_type, developer, building, flat_no, project, order_id,
                    engineer, current_status, status, tentative_end, payload_json, created_at
             FROM submissions ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $wf   = self::rebuildWorkforce($db, $subs);
        $proj = self::rebuildProjects($db, $subs);
        $al   = self::rebuildAlerts($db, self::thr($cfg));

        return [
            'submissions'   => count($subs),
            'workers'       => $wf['workers'],
            'contractors'   => $wf['contractors'],
            'visit_workers' => $wf['visit_workers'],
            'projects'      => $proj,
            'alerts_open'   => $al['open'],
            'alerts_new'    => $al['new'],
            'alerts_closed' => $al['resolved'],
        ];
    }

    /* ---------------- workforce ---------------- */

    private static function nameKey(string $s): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($s)));
    }

    private static function rebuildWorkforce(PDO $db, array $subs): array
    {
        $db->exec("TRUNCATE TABLE visit_workers");

        $insVW = $db->prepare(
            "INSERT INTO visit_workers (submission_id, project_key, worker_name, type, contractor_name, steps, engineer, visit_date)
             VALUES (?,?,?,?,?,?,?,?)"
        );

        foreach ($subs as $s) {
            $pl = json_decode((string)$s['payload_json'], true) ?: [];
            $rows = self::peopleRows($pl);
            $pkey = projectKey($s);
            $vdate = date('Y-m-d', strtotime((string)$s['created_at']));
            foreach ($rows as $p) {
                $insVW->execute([
                    $s['id'], $pkey, $p['name'], $p['type'], $p['contractor'],
                    implode(', ', $p['steps']), (string)$s['engineer'], $vdate,
                ]);
            }
        }

        // upsert contractors (preserve manual phone/trade), then workers, then link + counts
        $db->exec(
            "INSERT INTO contractors (name, name_key, first_seen, last_seen, visits)
             SELECT MAX(contractor_name), LOWER(TRIM(contractor_name)),
                    MIN(visit_date), MAX(visit_date), COUNT(*)
             FROM visit_workers WHERE type='Contractor' AND contractor_name<>''
             GROUP BY LOWER(TRIM(contractor_name))
             ON DUPLICATE KEY UPDATE first_seen=VALUES(first_seen), last_seen=VALUES(last_seen), visits=VALUES(visits)"
        );
        $db->exec(
            "INSERT INTO workers (name, name_key, type, first_seen, last_seen, visits)
             SELECT MAX(worker_name), LOWER(TRIM(worker_name)), type,
                    MIN(visit_date), MAX(visit_date), COUNT(*)
             FROM visit_workers WHERE worker_name<>''
             GROUP BY LOWER(TRIM(worker_name)), type
             ON DUPLICATE KEY UPDATE first_seen=VALUES(first_seen), last_seen=VALUES(last_seen), visits=VALUES(visits)"
        );
        // link workers/visit rows to their master ids
        $db->exec(
            "UPDATE visit_workers vw
             LEFT JOIN workers w      ON w.name_key = LOWER(TRIM(vw.worker_name)) AND w.type = vw.type
             LEFT JOIN contractors c  ON c.name_key = LOWER(TRIM(vw.contractor_name))
             SET vw.worker_id = w.id, vw.contractor_id = c.id"
        );
        $db->exec(
            "UPDATE workers w
             JOIN visit_workers vw ON vw.worker_id = w.id AND vw.type='Contractor'
             JOIN contractors c ON c.id = vw.contractor_id
             SET w.contractor_id = c.id"
        );

        return [
            'workers'       => (int)$db->query("SELECT COUNT(*) FROM workers")->fetchColumn(),
            'contractors'   => (int)$db->query("SELECT COUNT(*) FROM contractors")->fetchColumn(),
            'visit_workers' => (int)$db->query("SELECT COUNT(*) FROM visit_workers")->fetchColumn(),
        ];
    }

    /** Structured per-visit workers from payload peopleRows (fallback: workDoneBy text). */
    private static function peopleRows(array $pl): array
    {
        $out = [];
        $rows = $pl['peopleRows'] ?? null;
        if (is_array($rows) && $rows) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $name = trim((string)($r['name'] ?? ''));
                if ($name === '') continue;
                $type = (stripos((string)($r['techType'] ?? ''), 'contract') !== false) ? 'Contractor' : 'VAPL';
                $steps = $r['workDone'] ?? [];
                if (!is_array($steps)) $steps = array_filter(array_map('trim', explode(',', (string)$steps)));
                $out[] = [
                    'name'       => $name,
                    'type'       => $type,
                    'contractor' => $type === 'Contractor' ? trim((string)($r['contractorName'] ?? '')) : '',
                    'steps'      => array_values(array_filter(array_map(fn($x) => trim((string)$x), $steps), fn($x) => $x !== '')),
                ];
            }
            return $out;
        }
        // fallback: "Name [Role] - Step, Step"
        $txt = (string)($pl['workDoneBy'] ?? '');
        if ($txt !== '' && preg_match_all('/([^,\[]+)\[([^\]]+)\]\s*-\s*([^\[]+?)(?=(?:,\s*[^,\[]+\[)|$)/', $txt, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $role = trim($mm[2]);
                $type = stripos($role, 'contract') !== false ? 'Contractor' : 'VAPL';
                $contractor = '';
                if ($type === 'Contractor' && preg_match('/contractor\s*-\s*(.+)/i', $role, $cm)) $contractor = trim($cm[1]);
                $out[] = [
                    'name'       => trim($mm[1]),
                    'type'       => $type,
                    'contractor' => $contractor,
                    'steps'      => array_values(array_filter(array_map('trim', explode(',', $mm[3])))),
                ];
            }
        }
        return $out;
    }

    /* ---------------- projects rollup + lifecycle ---------------- */

    private static function rebuildProjects(PDO $db, array $subs): int
    {
        // group submissions by project
        $groups = [];
        foreach ($subs as $s) {
            $groups[projectKey($s)][] = $s;
        }

        $sel = $db->prepare("SELECT id, lifecycle, lifecycle_locked, commissioned_at, closed_at, closed_by FROM projects WHERE project_key=?");
        $ins = $db->prepare(
            "INSERT INTO projects
              (project_key,label,site_type,client_type,developer,building,flat_no,project_name,order_id,primary_pe,
               report_count,steps_total,steps_done,current_step,hold_owner,hold_since,first_report_at,last_report_at,
               next_plan_date,next_plan_steps,target_end,lifecycle,commissioned_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               label=VALUES(label),site_type=VALUES(site_type),client_type=VALUES(client_type),developer=VALUES(developer),
               building=VALUES(building),flat_no=VALUES(flat_no),project_name=VALUES(project_name),order_id=VALUES(order_id),
               primary_pe=VALUES(primary_pe),report_count=VALUES(report_count),steps_total=VALUES(steps_total),
               steps_done=VALUES(steps_done),current_step=VALUES(current_step),hold_owner=VALUES(hold_owner),
               hold_since=VALUES(hold_since),first_report_at=VALUES(first_report_at),last_report_at=VALUES(last_report_at),
               next_plan_date=VALUES(next_plan_date),next_plan_steps=VALUES(next_plan_steps),target_end=VALUES(target_end),
               lifecycle=VALUES(lifecycle),commissioned_at=VALUES(commissioned_at)"
        );

        foreach ($groups as $pkey => $rows) {
            $latest = $rows[count($rows) - 1];
            $first  = $rows[0];

            // PE tally (most frequent, tie → latest)
            $peCount = [];
            foreach ($rows as $r) { $e = trim((string)$r['engineer']); if ($e !== '') $peCount[$e] = ($peCount[$e] ?? 0) + 1; }
            arsort($peCount);
            $primaryPe = $peCount ? array_key_first($peCount) : trim((string)$latest['engineer']);

            // done steps union + hold/pending from latest
            $doneKeys = [];
            foreach ($rows as $r) {
                $pl = json_decode((string)$r['payload_json'], true) ?: [];
                foreach (parseSteps($pl)['done'] as $st) $doneKeys[stepKey($st)] = $st;
            }
            $canon = canonicalSteps((string)$latest['site_type']);
            $stepsTotal = count($canon);
            $stepsDone  = count($doneKeys);

            $plLatest = json_decode((string)$latest['payload_json'], true) ?: [];
            $ps = parseSteps($plLatest);
            $holdOwner = ''; $holdSince = null;
            if ((string)$latest['status'] === 'Hold' && $ps['hold']) {
                $parties = array_map(fn($h) => $h['party'], $ps['hold']);
                $holdOwner = (in_array('Client', $parties, true)) ? 'Client' : ($parties[0] ?? 'VAPL');
                $holdSince = date('Y-m-d', strtotime((string)$latest['created_at']));
            }
            // current step = first hold, else first pending, else next undone canonical step
            $curStep = $ps['hold'][0]['step'] ?? ($ps['pending'][0] ?? '');
            if ($curStep === '') {
                foreach ($canon as $st) { if (!isset($doneKeys[stepKey($st)])) { $curStep = $st; break; } }
            }

            // target end (latest non-empty tentative_end), next plan (latest)
            $targetEnd = null;
            foreach ($rows as $r) { if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r['tentative_end'])) $targetEnd = $r['tentative_end']; }
            $nextSteps = $plLatest['tomorrowSteps'] ?? [];
            if (is_string($nextSteps)) $nextSteps = json_decode($nextSteps, true) ?: [];
            $nextDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($plLatest['nextStepStartDate'] ?? ''))) ? $plLatest['nextStepStartDate'] : null;

            $orderId = '';
            foreach ($rows as $r) { if (trim((string)$r['order_id']) !== '') $orderId = $r['order_id']; }

            // manual-lock aware lifecycle
            $sel->execute([$pkey]); $existing = $sel->fetch(PDO::FETCH_ASSOC);
            $commissionedDone = false;
            foreach ($doneKeys as $st) { if (isCommissioning($st)) { $commissionedDone = true; break; } }
            $commissionedAt = $existing['commissioned_at'] ?? null;

            if (!empty($existing['lifecycle_locked'])) {
                $lifecycle = $existing['lifecycle'];   // manual Commissioned/Closed — leave it
            } else {
                $lifecycle = self::deriveLifecycle($stepsDone, $stepsTotal, (string)$latest['status'], $commissionedDone, $targetEnd, (string)$latest['created_at']);
                if ($lifecycle === 'Commissioned' && !$commissionedAt) $commissionedAt = date('Y-m-d H:i:s');
            }

            $ins->execute([
                $pkey, projectLabel($latest), $latest['site_type'], $latest['client_type'],
                $latest['developer'], $latest['building'], $latest['flat_no'], $latest['project'], $orderId, $primaryPe,
                count($rows), $stepsTotal, $stepsDone, $curStep, $holdOwner, $holdSince,
                $first['created_at'], $latest['created_at'], $nextDate,
                (is_array($nextSteps) ? implode(', ', $nextSteps) : ''), $targetEnd, $lifecycle, $commissionedAt,
            ]);
        }

        // drop stale project rows no longer present (unlikely, but keep clean)
        return (int)$db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
    }

    private static function deriveLifecycle(int $done, int $total, string $latestStatus, bool $commissioned, ?string $targetEnd, string $lastReportAt): string
    {
        if ($commissioned) return 'Commissioned';
        if ($latestStatus === 'Hold') return 'On Hold';
        $overdue = $targetEnd && strtotime($targetEnd) < strtotime(date('Y-m-d'));
        $staleH  = (time() - strtotime($lastReportAt)) / 3600;
        if ($done === 0) return $overdue ? 'At Risk' : 'Not Started';
        if ($total > 0 && $done >= $total - 1) return 'Commissioning Pending';
        if ($overdue || $staleH > 72) return 'At Risk';
        return 'Active';
    }

    /* ---------------- alerts ---------------- */

    private static function rebuildAlerts(PDO $db, array $thr): array
    {
        $projects = $db->query("SELECT * FROM projects")->fetchAll(PDO::FETCH_ASSOC);
        $now = time();
        $active = [];   // dedupe_key => [rule,severity,project_key,project_label,owner,title,detail,submission_id,due_at]

        // PE workload (for overload rule)
        $peLoad = [];
        foreach ($projects as $p) {
            if (in_array($p['lifecycle'], ['Active','At Risk','On Hold','Commissioning Pending'], true) && $p['primary_pe']) {
                $peLoad[$p['primary_pe']] = ($peLoad[$p['primary_pe']] ?? 0) + 1;
            }
        }

        foreach ($projects as $p) {
            $pk = $p['project_key']; $lbl = $p['label']; $pe = $p['primary_pe'] ?: '';
            $active_lc = in_array($p['lifecycle'], ['Active','At Risk','On Hold','Commissioning Pending'], true);
            $lastH = $p['last_report_at'] ? ($now - strtotime($p['last_report_at'])) / 3600 : 9999;

            if ($p['lifecycle'] === 'On Hold') {
                $days = $p['hold_since'] ? floor(($now - strtotime($p['hold_since'])) / 86400) : 0;
                $sev = $days >= $thr['hold_unresolved_days'] ? 'critical' : 'warning';
                $active["project_on_hold|$pk"] = compact_alert('project_on_hold', $sev, $pk, $lbl, $pe,
                    'On hold' . ($p['hold_owner'] ? ' — stuck on ' . $p['hold_owner'] : ''),
                    'Current step "' . $p['current_step'] . '" on hold' . ($days ? " for {$days} day(s)" : '') . '.');
            }
            if ($active_lc && $lastH >= $thr['no_report_crit_hours']) {
                $active["no_report|$pk"] = compact_alert('no_report', 'critical', $pk, $lbl, $pe,
                    'No report in ' . floor($lastH) . 'h', 'Active project with no site report for over ' . $thr['no_report_crit_hours'] . 'h.');
            } elseif ($active_lc && $lastH >= $thr['no_report_warn_hours']) {
                $active["no_report|$pk"] = compact_alert('no_report', 'warning', $pk, $lbl, $pe,
                    'No report in ' . floor($lastH) . 'h', 'No site report for over ' . $thr['no_report_warn_hours'] . 'h.');
            }
            if ($active_lc && $p['next_plan_date'] && strtotime($p['next_plan_date']) < strtotime(date('Y-m-d'))
                && (!$p['last_report_at'] || strtotime($p['last_report_at']) < strtotime($p['next_plan_date']))) {
                $active["plan_missed|$pk"] = compact_alert('plan_missed', 'warning', $pk, $lbl, $pe,
                    'Planned work date passed', 'Planned "' . snip($p['next_plan_steps'], 40) . '" for ' . $p['next_plan_date'] . ' but no report since.');
            }
            if ($p['target_end'] && $p['lifecycle'] !== 'Commissioned' && $p['lifecycle'] !== 'Closed') {
                $daysToEnd = floor((strtotime($p['target_end']) - strtotime(date('Y-m-d'))) / 86400);
                if ($daysToEnd < 0) {
                    $active["end_overdue|$pk"] = compact_alert('end_overdue', 'critical', $pk, $lbl, $pe,
                        'Target end overdue by ' . abs($daysToEnd) . 'd', 'Target end ' . $p['target_end'] . ' passed; not commissioned.');
                } elseif ($daysToEnd <= $thr['end_approaching_days'] && $p['steps_total'] > 0 && $p['steps_done'] < 0.8 * $p['steps_total']) {
                    $active["end_soon|$pk"] = compact_alert('end_soon', 'warning', $pk, $lbl, $pe,
                        'Target end in ' . $daysToEnd . 'd, ' . round($p['steps_done'] * 100 / max(1,$p['steps_total'])) . '% done',
                        'Tentative end approaching with insufficient progress.');
                }
            }
            if ($pe && ($peLoad[$pe] ?? 0) >= $thr['pe_overload_projects']) {
                $active["pe_overload|" . self::nameKey($pe)] = compact_alert('pe_overload', 'warning', '', 'PE workload', $pe,
                    $pe . ' has ' . $peLoad[$pe] . ' active projects', 'PE assigned to too many simultaneous sites.');
            }
        }

        // notification coverage: latest submission per project without an email/whatsapp done log
        $missing = $db->query(
            "SELECT s.id, s.project, s.developer, s.building, s.flat_no, s.client_type, s.engineer
             FROM submissions s
             WHERE s.overall_status IN ('done','partial')
               AND NOT EXISTS (SELECT 1 FROM process_log p WHERE p.submission_id=s.id AND p.step IN ('email','whatsapp') AND p.status='done')
             ORDER BY s.id DESC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($missing as $m) {
            $pk = projectKey($m);
            $active["notify_missing|" . $m['id']] = compact_alert('notify_missing', 'warning', $pk, projectLabel($m),
                (string)$m['engineer'], 'No client notification logged', 'Report #' . $m['id'] . ' has no email/WhatsApp delivery record.', (int)$m['id']);
        }

        // pipeline failures
        $fails = $db->query(
            "SELECT p.submission_id, p.step, p.message, s.project, s.developer, s.building, s.flat_no, s.client_type, s.engineer
             FROM process_log p JOIN submissions s ON s.id=p.submission_id
             WHERE p.status='failed' ORDER BY p.id DESC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($fails as $f) {
            $pk = projectKey($f);
            $active["pipeline_fail|" . $f['submission_id'] . '|' . $f['step']] = compact_alert('pipeline_fail', 'critical', $pk, projectLabel($f),
                (string)$f['engineer'], 'Pipeline failure: ' . str_replace('_',' ',$f['step']), snip((string)$f['message'], 160), (int)$f['submission_id']);
        }

        return self::reconcileAlerts($db, $active);
    }

    /** Create new, update existing, auto-resolve cleared. Returns counts. */
    private static function reconcileAlerts(PDO $db, array $active): array
    {
        $new = 0;
        $selById = $db->prepare("SELECT id, status FROM alerts WHERE dedupe_key=?");
        $insA = $db->prepare(
            "INSERT INTO alerts (rule, dedupe_key, severity, project_key, project_label, submission_id, owner, title, detail, status)
             VALUES (?,?,?,?,?,?,?,?,?,'open')"
        );
        $updA = $db->prepare("UPDATE alerts SET severity=?, project_label=?, owner=?, title=?, detail=?, submission_id=? WHERE id=?");
        $reopen = $db->prepare("UPDATE alerts SET status='open', severity=?, title=?, detail=?, resolved_at=NULL, resolved_by=NULL WHERE id=?");
        $insEv = $db->prepare("INSERT INTO alert_events (alert_id, event, actor, note) VALUES (?,?,?,?)");

        foreach ($active as $key => $a) {
            $selById->execute([$key]); $ex = $selById->fetch(PDO::FETCH_ASSOC);
            if (!$ex) {
                $insA->execute([$a['rule'],$key,$a['severity'],$a['project_key'],$a['project_label'],$a['submission_id'],$a['owner'],$a['title'],$a['detail']]);
                $id = (int)$db->lastInsertId();
                $insEv->execute([$id, 'created', 'system', $a['title']]);
                $new++;
            } elseif ($ex['status'] === 'resolved') {
                $reopen->execute([$a['severity'],$a['title'],$a['detail'],$ex['id']]);
                $insEv->execute([$ex['id'], 'reopened', 'system', 'condition returned']);
            } else {
                $updA->execute([$a['severity'],$a['project_label'],$a['owner'],$a['title'],$a['detail'],$a['submission_id'],$ex['id']]);
            }
        }

        // auto-resolve open/ack/snoozed alerts whose condition cleared
        $resolved = 0;
        $openRows = $db->query("SELECT id, dedupe_key FROM alerts WHERE status IN ('open','ack','snoozed')")->fetchAll(PDO::FETCH_ASSOC);
        $res = $db->prepare("UPDATE alerts SET status='resolved', resolved_at=NOW(), resolved_by='system' WHERE id=?");
        foreach ($openRows as $r) {
            if (!isset($active[$r['dedupe_key']])) {
                $res->execute([$r['id']]);
                $insEv->execute([$r['id'], 'auto-resolved', 'system', 'condition cleared']);
                $resolved++;
            }
        }

        return [
            'new'      => $new,
            'resolved' => $resolved,
            'open'     => (int)$db->query("SELECT COUNT(*) FROM alerts WHERE status IN ('open','ack','snoozed')")->fetchColumn(),
        ];
    }
}

/** Build an alert row array (module-level so closures above stay compact). */
function compact_alert(string $rule, string $sev, string $pk, string $lbl, string $owner, string $title, string $detail, ?int $subId = null): array
{
    return ['rule'=>$rule,'severity'=>$sev,'project_key'=>$pk,'project_label'=>$lbl,'owner'=>$owner,'title'=>$title,'detail'=>$detail,'submission_id'=>$subId];
}
