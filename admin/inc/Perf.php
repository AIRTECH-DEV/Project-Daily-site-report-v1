<?php
/**
 * Perf — the Performance & Incentive engine.
 *
 * Answers the two questions the incentive scheme needs:
 *   1. Did each project finish before its end date?  (start date comes from the
 *      PMS sheet: "Marking" → Start Date, kept fresh by syncSheets().)
 *   2. Who actually moved the work — which PE, which VAPL worker, which
 *      contractor — and how fast?
 *
 * READ-ONLY over the report pipeline. It reads submissions / visit_workers /
 * projects and the PMS sheets, writes only to projects' own analytics columns
 * and project_step_dates. It can never affect a live submit.
 *
 * Requires helpers.php (stepKey/parseSteps/projectLabel).
 */
class Perf
{
    /** Tunables — overridable from the page's Settings box (overrides.json → 'perf'). */
    const DEFAULTS = [
        'window_days'       => 90,   // scoring period
        'on_time_step_days' => 2,    // a step done within N days of its start = "fast"
        'min_score'         => 55,   // incentive eligibility floor (grade C)
        'min_visits'        => 3,    // worker must have at least this many visits
        'pe_pool'           => 0,    // ₹ pool split across eligible PEs
        'worker_pool'       => 0,    // ₹ pool split across eligible VAPL workers
    ];

    /**
     * Neutral percentage used when a component cannot be measured for someone
     * (e.g. no project of theirs has finished yet). Applied only after the peer
     * average is also unavailable, so an unmeasurable component never becomes a
     * free 100% for one person and a punishing 0% for another.
     */
    const NEUTRAL = 50.0;

    /** Score weights (must total 100 within each group). */
    const W_PE     = ['ontime' => 35, 'discipline' => 25, 'throughput' => 25, 'holds' => 15];
    const W_WORKER = ['steps' => 40, 'attendance' => 30, 'productivity' => 20, 'speed' => 10];
    const W_CON    = ['steps' => 30, 'productivity' => 20, 'ontime' => 30, 'speed' => 20];

    /* ---------------- config ---------------- */

    public static function opts(array $overrides): array
    {
        $o = is_array($overrides['perf'] ?? null) ? $overrides['perf'] : [];
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $k => $v) {
            if (isset($o[$k]) && is_numeric($o[$k])) {
                $out[$k] = (float)$o[$k];
            }
        }
        return $out;
    }

    /* ---------------- schema (idempotent, runs on page load) ---------------- */

    /**
     * Adds the analytics columns/table if they are missing. MySQL 8 has no
     * "ADD COLUMN IF NOT EXISTS", so existence is checked first.
     */
    public static function ensureSchema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $cols = [
                'start_date'       => 'DATE DEFAULT NULL',
                'start_source'     => 'VARCHAR(20) DEFAULT NULL',
                'actual_end_date'  => 'DATE DEFAULT NULL',
                'sheet_target_end' => 'DATE DEFAULT NULL',
                'sheet_synced_at'  => 'DATETIME DEFAULT NULL',
            ];
            $have = [];
            $st = $db->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'"
            );
            foreach ($st as $r) {
                $have[strtolower($r['COLUMN_NAME'])] = true;
            }
            foreach ($cols as $name => $ddl) {
                if (!isset($have[$name])) {
                    $db->exec("ALTER TABLE `projects` ADD COLUMN `$name` $ddl");
                }
            }
            $db->exec(
                "CREATE TABLE IF NOT EXISTS `project_step_dates` (
                   `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                   `project_key` VARCHAR(255) NOT NULL,
                   `step`        VARCHAR(190) NOT NULL,
                   `step_key`    VARCHAR(190) NOT NULL,
                   `start_date`  DATE DEFAULT NULL,
                   `end_date`    DATE DEFAULT NULL,
                   `status`      VARCHAR(40)  DEFAULT NULL,
                   `synced_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   PRIMARY KEY (`id`),
                   UNIQUE KEY `uq_proj_step` (`project_key`, `step_key`),
                   KEY `idx_pkey` (`project_key`),
                   KEY `idx_step` (`step_key`)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) { /* non-fatal: the page degrades to "no start dates yet" */ }
    }

    /* ---------------- PMS-sheet date sync ---------------- */

    public static function stampFile(): string
    {
        return __DIR__ . '/../../storage/.perf_sheet_sync';
    }

    public static function lastSheetSync(): int
    {
        $f = self::stampFile();
        return is_file($f) ? (int)@file_get_contents($f) : 0;
    }

    /**
     * Reads every PMS tab once (PmsDates) and stores each project's start date
     * ("Marking" → Start Date), actual finish (final Commissioning → End Date),
     * sheet target end, and the full per-step date grid.
     *
     * Slow (one Sheets read per tab) — run it from the button or the CLI script,
     * not on every page view. Never throws.
     */
    public static function syncSheets(PDO $db): array
    {
        self::ensureSchema($db);
        @file_put_contents(self::stampFile(), (string)time());   // stamp first: no stampede

        $stats = ['rows' => 0, 'matched' => 0, 'with_start' => 0, 'with_end' => 0, 'steps' => 0, 'warnings' => []];
        try {
            require_once __DIR__ . '/../../src/Bootstrap.php';
            Bootstrap::autoload();
            require_once __DIR__ . '/../../src/PmsDates.php';

            $app  = Bootstrap::init();
            $scan = new PmsDates($app->sheets, $app->cfg);
            $rows = $scan->scanAll();
            $stats['warnings'] = $scan->warnings;
            $stats['rows'] = count($rows);

            // Known project keys, plus a loosened key so a stray space or case
            // difference between sheet and report still lines up.
            $known = [];
            foreach ($db->query("SELECT project_key FROM projects") as $r) {
                $known[$r['project_key']] = $r['project_key'];
                $known[self::looseKey($r['project_key'])] = $r['project_key'];
            }

            $upProj = $db->prepare(
                "UPDATE projects
                    SET start_date=?, start_source=?, actual_end_date=?, sheet_target_end=?, sheet_synced_at=NOW()
                  WHERE project_key=?"
            );
            $upStep = $db->prepare(
                "INSERT INTO project_step_dates (project_key, step, step_key, start_date, end_date, status, synced_at)
                 VALUES (?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE step=VALUES(step), start_date=VALUES(start_date),
                   end_date=VALUES(end_date), status=VALUES(status), synced_at=NOW()"
            );

            foreach ($rows as $key => $row) {
                $pkey = $known[$key] ?? ($known[self::looseKey($key)] ?? null);
                if ($pkey === null) {
                    continue;   // a sheet row nobody has reported on yet
                }
                $stats['matched']++;
                if ($row['start_date']) $stats['with_start']++;
                if ($row['end_date'])   $stats['with_end']++;

                $upProj->execute([
                    $row['start_date'], $row['start_source'] ?: null,
                    $row['end_date'], $row['target_end'], $pkey,
                ]);
                foreach ($row['steps'] as $s) {
                    if (!$s['start'] && !$s['end'] && trim((string)$s['status']) === '') {
                        continue;
                    }
                    $upStep->execute([
                        $pkey, $s['step'], stepKey($s['step']),
                        $s['start'], $s['end'], substr(trim((string)$s['status']), 0, 40),
                    ]);
                    $stats['steps']++;
                }
            }
        } catch (Throwable $e) {
            $stats['warnings'][] = 'Sheet sync failed: ' . $e->getMessage();
        }
        return $stats;
    }

    /** Punctuation/space-insensitive project key, for tolerant sheet↔report matching. */
    private static function looseKey(string $k): string
    {
        return preg_replace('/[^a-z0-9|]/', '', strtolower($k));
    }

    /* ---------------- the analysis ---------------- */

    /**
     * Everything the page renders:
     *   delivery   — per-project start / target / actual / variance / verdict
     *   pe         — PE scorecards (incentive)
     *   workers    — VAPL worker scorecards (incentive)
     *   contractors— contractor scorecards (evaluation only, no incentive)
     *   totals     — headline counters
     */
    public static function analyse(PDO $db, array $o): array
    {
        $days = max(1, (int)$o['window_days']);
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $today = date('Y-m-d');
        $fastDays = max(0, (int)$o['on_time_step_days']);

        /* --- projects + their sheet dates --- */
        $projects = $db->query("SELECT * FROM projects")->fetchAll(PDO::FETCH_ASSOC);
        $pByKey = [];
        foreach ($projects as $p) {
            $pByKey[$p['project_key']] = $p;
        }

        $stepDates = [];   // pkey => stepKey => row
        try {
            foreach ($db->query("SELECT project_key, step_key, start_date, end_date FROM project_step_dates") as $r) {
                $stepDates[$r['project_key']][$r['step_key']] = $r;
            }
        } catch (Throwable $e) { /* table not there yet */ }

        /* --- delivery verdict per project --- */
        $delivery = [];
        $onTimeByKey = [];        // pkey => true|false (only for finished projects)
        foreach ($projects as $p) {
            $start  = self::d($p['start_date'] ?? null);
            $target = self::d($p['sheet_target_end'] ?? null) ?: self::d($p['target_end'] ?? null);
            $end    = self::d($p['actual_end_date'] ?? null);
            if (!$end && !empty($p['commissioned_at'])) {
                $end = date('Y-m-d', strtotime((string)$p['commissioned_at']));
            }
            $finished = (bool)$end || in_array($p['lifecycle'], ['Commissioned', 'Closed'], true);

            $verdict = 'No dates';
            $variance = null;
            if ($end && $target) {
                $variance = self::dayDiff($target, $end);         // +ve = late
                $verdict  = $variance <= 0 ? 'On time' : 'Late';
                $onTimeByKey[$p['project_key']] = ($variance <= 0);
            } elseif ($end) {
                $verdict = 'Done (no target)';
            } elseif ($target) {
                $variance = self::dayDiff($target, $today);
                $verdict  = $variance > 0 ? 'Overdue' : 'Running';
            } elseif ($start) {
                $verdict = 'Running';
            }

            $delivery[] = [
                'project_key'  => $p['project_key'],
                'label'        => $p['label'],
                'pe'           => $p['primary_pe'],
                'lifecycle'    => $p['lifecycle'],
                'start'        => $start,
                'start_source' => $p['start_source'] ?? '',
                'target'       => $target,
                'end'          => $end,
                'planned_days' => ($start && $target) ? self::dayDiff($start, $target) : null,
                'actual_days'  => ($start && $end) ? self::dayDiff($start, $end) : (($start && !$end) ? self::dayDiff($start, $today) : null),
                'variance'     => $variance,
                'verdict'      => $verdict,
                'finished'     => $finished,
                'progress'     => (int)$p['steps_total'] > 0 ? round((int)$p['steps_done'] * 100 / (int)$p['steps_total']) : 0,
            ];
        }
        usort($delivery, function ($a, $b) {
            $rank = ['Overdue' => 0, 'Late' => 1, 'Running' => 2, 'On time' => 3, 'Done (no target)' => 4, 'No dates' => 5];
            $ra = $rank[$a['verdict']] ?? 9; $rb = $rank[$b['verdict']] ?? 9;
            return $ra !== $rb ? $ra <=> $rb : strcmp((string)$a['label'], (string)$b['label']);
        });

        /* --- pass 1: submissions → who first completed which step --- */
        $subs = $db->query(
            "SELECT id, engineer, project, developer, building, flat_no, client_type,
                    payload_json, created_at, status
             FROM submissions ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $seen = [];            // pkey|stepKey => 1 (first completion wins the credit)
        $creditSteps = [];     // submission id => [stepKey => stepName]
        $subMeta = [];         // submission id => ['pkey','date','engineer']
        $pe = [];              // engineer => stats

        foreach ($subs as $s) {
            $pkey = projectKey($s);
            $date = date('Y-m-d', strtotime((string)$s['created_at']));
            $inWindow = ($date >= $from);
            $subMeta[$s['id']] = ['pkey' => $pkey, 'date' => $date, 'engineer' => trim((string)$s['engineer'])];

            $pl = json_decode((string)$s['payload_json'], true) ?: [];
            $ps = parseSteps($pl);

            $fresh = [];
            foreach ($ps['done'] as $st) {
                $k = stepKey($st);
                if ($k === '' || isset($seen[$pkey . '|' . $k])) {
                    continue;
                }
                $seen[$pkey . '|' . $k] = 1;
                $fresh[$k] = $st;
            }
            $creditSteps[$s['id']] = $fresh;

            if (!$inWindow) {
                continue;
            }
            $eng = trim((string)$s['engineer']);
            if ($eng === '') {
                continue;
            }
            if (!isset($pe[$eng])) {
                $pe[$eng] = ['name' => $eng, 'reports' => 0, 'days' => [], 'projects' => [],
                             'steps' => 0, 'holds' => 0, 'last' => ''];
            }
            $pe[$eng]['reports']++;
            $pe[$eng]['days'][$date] = 1;
            $pe[$eng]['projects'][$pkey] = 1;
            $pe[$eng]['steps'] += count($fresh);
            $pe[$eng]['holds'] += count($ps['hold']);
            if ($date > $pe[$eng]['last']) {
                $pe[$eng]['last'] = $date;
            }
        }

        /* --- pass 2: visit_workers → per-person / per-contractor credit --- */
        $workers = [];      // name|type => stats
        $cons    = [];      // contractor name => stats
        try {
            $vw = $db->query(
                "SELECT submission_id, project_key, worker_name, type, contractor_name, steps, visit_date
                 FROM visit_workers WHERE worker_name <> ''"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $vw = [];
        }

        foreach ($vw as $r) {
            $date = (string)$r['visit_date'];
            if ($date === '' || $date < $from) {
                continue;
            }
            $sid  = (int)$r['submission_id'];
            $pkey = (string)$r['project_key'];
            $done = $creditSteps[$sid] ?? [];

            // steps this person worked on that ALSO first-completed on this visit
            $mine = [];
            foreach (array_filter(array_map('trim', explode(',', (string)$r['steps']))) as $st) {
                $k = stepKey($st);
                if ($k !== '' && isset($done[$k])) {
                    $mine[$k] = $done[$k];
                }
            }
            // step turnaround from the sheet's Start/End dates
            $fast = 0; $turn = [];
            foreach ($mine as $k => $_) {
                $sd = $stepDates[$pkey][$k] ?? null;
                if ($sd && $sd['start_date'] && $sd['end_date']) {
                    $t = self::dayDiff($sd['start_date'], $sd['end_date']);
                    if ($t >= 0) {
                        $turn[] = $t;
                        if ($t <= $fastDays) $fast++;
                    }
                }
            }

            $isCon = ($r['type'] === 'Contractor');
            $wKey  = strtolower($r['worker_name']) . '|' . $r['type'];
            if (!isset($workers[$wKey])) {
                $workers[$wKey] = ['name' => $r['worker_name'], 'type' => $r['type'], 'company' => (string)$r['contractor_name'],
                                   'visits' => 0, 'days' => [], 'projects' => [], 'steps' => 0,
                                   'fast' => 0, 'rated' => 0, 'turn' => [], 'last' => ''];
            }
            $w = &$workers[$wKey];
            $w['visits']++;
            $w['days'][$date] = 1;
            $w['projects'][$pkey] = 1;
            $w['steps'] += count($mine);
            $w['fast']  += $fast;
            $w['rated'] += count($turn);
            foreach ($turn as $t) $w['turn'][] = $t;
            if ($date > $w['last']) $w['last'] = $date;
            if ($w['company'] === '' && $r['contractor_name']) $w['company'] = (string)$r['contractor_name'];
            unset($w);

            if ($isCon && trim((string)$r['contractor_name']) !== '') {
                $cKey = strtolower(trim((string)$r['contractor_name']));
                if (!isset($cons[$cKey])) {
                    $cons[$cKey] = ['name' => trim((string)$r['contractor_name']), 'visits' => 0, 'days' => [],
                                    'projects' => [], 'people' => [], 'steps' => 0, 'fast' => 0, 'rated' => 0,
                                    'turn' => [], 'last' => ''];
                }
                $c = &$cons[$cKey];
                $c['visits']++;
                $c['days'][$date] = 1;
                $c['projects'][$pkey] = 1;
                $c['people'][strtolower($r['worker_name'])] = 1;
                $c['steps'] += count($mine);
                $c['fast']  += $fast;
                $c['rated'] += count($turn);
                foreach ($turn as $t) $c['turn'][] = $t;
                if ($date > $c['last']) $c['last'] = $date;
                unset($c);
            }
        }

        /* --- PE scores --- */
        $peRows = [];
        foreach ($pe as $eng => $s) {
            $keys = array_keys($s['projects']);
            $delivered = 0; $onTime = 0; $active = 0; $overdue = 0; $compliant = 0; $holdDays = 0;
            foreach ($keys as $k) {
                $p = $pByKey[$k] ?? null;
                if (!$p) continue;
                if (isset($onTimeByKey[$k])) {
                    $delivered++;
                    if ($onTimeByKey[$k]) $onTime++;
                }
                if (in_array($p['lifecycle'], ['Active', 'At Risk', 'On Hold', 'Commissioning Pending'], true)) {
                    $active++;
                    $t = self::d($p['sheet_target_end'] ?? null) ?: self::d($p['target_end'] ?? null);
                    if ($t && $t < $today) $overdue++;
                    if (!empty($p['last_report_at']) && (time() - strtotime((string)$p['last_report_at'])) / 3600 <= 48) $compliant++;
                    if ($p['lifecycle'] === 'On Hold' && !empty($p['hold_since'])) {
                        $holdDays += max(0, self::dayDiff((string)$p['hold_since'], $today));
                    }
                }
            }
            $peRows[$eng] = [
                'name'      => $eng,
                'reports'   => $s['reports'],
                'days'      => count($s['days']),
                'projects'  => count($keys),
                'active'    => $active,
                'steps'     => $s['steps'],
                'delivered' => $delivered,
                'ontime'    => $onTime,
                'overdue'   => $overdue,
                'holds'     => $s['holds'],
                'hold_days' => $holdDays,
                'last'      => $s['last'],
                'ontime_pct'=> $delivered > 0 ? round($onTime * 100 / $delivered) : null,
                'comply_pct'=> $active > 0 ? round($compliant * 100 / $active) : null,
            ];
        }
        $maxSteps   = self::maxOf($peRows, 'steps');
        $maxReports = self::maxOf($peRows, 'reports');
        $maxHold    = self::maxOf($peRows, 'hold_days');
        $avgComply  = self::avgOf($peRows, 'comply_pct');
        foreach ($peRows as $k => $r) {
            // No deliveries yet → judge on schedule health (active projects not
            // overdue). Nothing active either → neutral, so an unmeasurable
            // component neither rewards nor punishes.
            $ontime = $r['ontime_pct'] !== null
                ? (float)$r['ontime_pct']
                : ($r['active'] > 0 ? round((1 - $r['overdue'] / $r['active']) * 100) : self::NEUTRAL);
            $comply = $r['comply_pct'] !== null ? (float)$r['comply_pct'] : self::neutral($avgComply);
            $discipline = 0.6 * $comply + 0.4 * (100 * self::norm($r['reports'], $maxReports));
            $parts = [
                'ontime'     => self::W_PE['ontime']     * $ontime / 100,
                'discipline' => self::W_PE['discipline'] * $discipline / 100,
                'throughput' => self::W_PE['throughput'] * self::norm($r['steps'], $maxSteps),
                'holds'      => self::W_PE['holds']      * (1 - self::norm($r['hold_days'], $maxHold)),
            ];
            $peRows[$k]['parts']  = array_map(fn($v) => round($v, 1), $parts);
            $peRows[$k]['score']  = round(array_sum($parts), 1);
            $peRows[$k]['grade']  = self::grade($peRows[$k]['score']);
            $peRows[$k]['ontime_used'] = round($ontime, 1);
            $peRows[$k]['comply_used'] = round($comply, 1);
        }
        $peRows = array_values($peRows);
        usort($peRows, fn($a, $b) => $b['score'] <=> $a['score']);

        /* --- VAPL worker + contractor-labour scores --- */
        $wRows = [];
        foreach ($workers as $s) {
            $visits = max(1, $s['visits']);
            $wRows[] = [
                'name'     => $s['name'],
                'type'     => $s['type'],
                'company'  => $s['company'],
                'visits'   => $s['visits'],
                'days'     => count($s['days']),
                'projects' => count($s['projects']),
                'steps'    => $s['steps'],
                'per_visit'=> round($s['steps'] / $visits, 2),
                'fast_pct' => $s['rated'] > 0 ? round($s['fast'] * 100 / $s['rated']) : null,
                'avg_turn' => $s['turn'] ? round(array_sum($s['turn']) / count($s['turn']), 1) : null,
                'last'     => $s['last'],
            ];
        }
        $vapl = array_values(array_filter($wRows, fn($r) => $r['type'] !== 'Contractor'));
        $conLabour = array_values(array_filter($wRows, fn($r) => $r['type'] === 'Contractor'));
        self::scoreWorkers($vapl);
        self::scoreWorkers($conLabour);

        /* --- contractor companies --- */
        $cRows = [];
        foreach ($cons as $s) {
            $visits = max(1, $s['visits']);
            $keys = array_keys($s['projects']);
            $delivered = 0; $onTime = 0;
            foreach ($keys as $k) {
                if (isset($onTimeByKey[$k])) {
                    $delivered++;
                    if ($onTimeByKey[$k]) $onTime++;
                }
            }
            $cRows[] = [
                'name'      => $s['name'],
                'visits'    => $s['visits'],
                'days'      => count($s['days']),
                'people'    => count($s['people']),
                'projects'  => count($keys),
                'steps'     => $s['steps'],
                'per_visit' => round($s['steps'] / $visits, 2),
                'delivered' => $delivered,
                'ontime'    => $onTime,
                'ontime_pct'=> $delivered > 0 ? round($onTime * 100 / $delivered) : null,
                'fast_pct'  => $s['rated'] > 0 ? round($s['fast'] * 100 / $s['rated']) : null,
                'avg_turn'  => $s['turn'] ? round(array_sum($s['turn']) / count($s['turn']), 1) : null,
                'last'      => $s['last'],
            ];
        }
        $maxCSteps  = self::maxOf($cRows, 'steps');
        $maxCPer    = self::maxOf($cRows, 'per_visit');
        $maxCTurn   = self::maxOf($cRows, 'avg_turn');
        $avgCOnTime = self::avgOf($cRows, 'ontime_pct');
        $avgCTurn   = self::avgOf($cRows, 'avg_turn');
        foreach ($cRows as $i => $r) {
            // Unmeasurable components (no finished project / no step dates) fall
            // back to the peer average — never to a free 100%.
            $ontime = $r['ontime_pct'] !== null ? (float)$r['ontime_pct'] : self::neutral($avgCOnTime);
            $speedFrac = $r['avg_turn'] !== null
                ? 1 - self::norm($r['avg_turn'], $maxCTurn)
                : ($avgCTurn !== null ? 1 - self::norm($avgCTurn, $maxCTurn) : self::NEUTRAL / 100);
            $parts = [
                'steps'        => self::W_CON['steps']        * self::norm($r['steps'], $maxCSteps),
                'productivity' => self::W_CON['productivity'] * self::norm($r['per_visit'], $maxCPer),
                'ontime'       => self::W_CON['ontime']       * $ontime / 100,
                'speed'        => self::W_CON['speed']        * $speedFrac,
            ];
            $cRows[$i]['parts'] = array_map(fn($v) => round($v, 1), $parts);
            $cRows[$i]['score'] = round(array_sum($parts), 1);
            $cRows[$i]['grade'] = self::grade($cRows[$i]['score']);
            $cRows[$i]['ontime_used'] = round($ontime, 1);
        }
        usort($cRows, fn($a, $b) => $b['score'] <=> $a['score']);

        /* --- incentive split (PEs + VAPL workers only) --- */
        $peShare = self::split($peRows, (float)$o['pe_pool'], (float)$o['min_score'], 0);
        $wkShare = self::split($vapl,   (float)$o['worker_pool'], (float)$o['min_score'], (int)$o['min_visits']);

        /* --- headline totals --- */
        $finished = array_values(array_filter($delivery, fn($d) => $d['verdict'] === 'On time' || $d['verdict'] === 'Late'));
        $onTimeN  = count(array_filter($finished, fn($d) => $d['verdict'] === 'On time'));
        $withStart = count(array_filter($delivery, fn($d) => (bool)$d['start']));

        return [
            'from'        => $from,
            'to'          => $today,
            'delivery'    => $delivery,
            'pe'          => $peRows,
            'workers'     => $vapl,
            'con_labour'  => $conLabour,
            'contractors' => $cRows,
            'pe_share'    => $peShare,
            'worker_share'=> $wkShare,
            'totals'      => [
                'projects'    => count($delivery),
                'with_start'  => $withStart,
                'finished'    => count($finished),
                'ontime'      => $onTimeN,
                'ontime_pct'  => count($finished) > 0 ? round($onTimeN * 100 / count($finished)) : null,
                'overdue'     => count(array_filter($delivery, fn($d) => $d['verdict'] === 'Overdue')),
                'pe_count'    => count($peRows),
                'worker_count'=> count($vapl),
                'con_count'   => count($cRows),
            ],
        ];
    }

    /* ---------------- scoring helpers ---------------- */

    /** Scores a worker list in place (steps / attendance / productivity / speed). */
    private static function scoreWorkers(array &$rows): void
    {
        $maxSteps = self::maxOf($rows, 'steps');
        $maxDays  = self::maxOf($rows, 'days');
        $maxPer   = self::maxOf($rows, 'per_visit');
        $avgFast  = self::avgOf($rows, 'fast_pct');
        foreach ($rows as $i => $r) {
            // No step had both a sheet start AND end date → speed is unmeasurable
            // for this person; use the peer average rather than a punishing 0.
            $fast = $r['fast_pct'] !== null ? (float)$r['fast_pct'] : self::neutral($avgFast);
            $parts = [
                'steps'        => self::W_WORKER['steps']        * self::norm($r['steps'], $maxSteps),
                'attendance'   => self::W_WORKER['attendance']   * self::norm($r['days'], $maxDays),
                'productivity' => self::W_WORKER['productivity'] * self::norm($r['per_visit'], $maxPer),
                'speed'        => self::W_WORKER['speed']        * $fast / 100,
            ];
            $rows[$i]['parts'] = array_map(fn($v) => round($v, 1), $parts);
            $rows[$i]['score'] = round(array_sum($parts), 1);
            $rows[$i]['grade'] = self::grade($rows[$i]['score']);
            $rows[$i]['fast_used'] = round($fast, 1);
        }
        usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
    }

    /** Splits a ₹ pool across eligible rows, proportional to score. */
    private static function split(array $rows, float $pool, float $minScore, int $minVisits): array
    {
        $out = ['pool' => $pool, 'eligible' => [], 'excluded' => [], 'total_score' => 0.0];
        foreach ($rows as $r) {
            $visits = (int)($r['visits'] ?? $r['reports'] ?? 0);
            $why = '';
            if ((float)$r['score'] < $minScore) {
                $why = 'score below ' . rtrim(rtrim(number_format($minScore, 1, '.', ''), '0'), '.');
            } elseif ($minVisits > 0 && $visits < $minVisits) {
                $why = 'fewer than ' . $minVisits . ' visits';
            }
            if ($why !== '') {
                $out['excluded'][] = ['name' => $r['name'], 'score' => $r['score'], 'why' => $why];
                continue;
            }
            $out['eligible'][] = ['name' => $r['name'], 'score' => (float)$r['score'], 'grade' => $r['grade'], 'amount' => 0.0];
            $out['total_score'] += (float)$r['score'];
        }
        if ($pool > 0 && $out['total_score'] > 0) {
            foreach ($out['eligible'] as $i => $e) {
                $out['eligible'][$i]['amount'] = round($pool * $e['score'] / $out['total_score'], 2);
            }
        }
        return $out;
    }

    private static function norm($v, $max): float
    {
        $v = (float)$v; $max = (float)$max;
        return $max > 0 ? max(0.0, min(1.0, $v / $max)) : 0.0;
    }

    private static function maxOf(array $rows, string $key): float
    {
        $m = 0.0;
        foreach ($rows as $r) {
            $m = max($m, (float)($r[$key] ?? 0));
        }
        return $m;
    }

    /** Mean of a column across the rows that HAVE it, or null when nobody does. */
    private static function avgOf(array $rows, string $key): ?float
    {
        $sum = 0.0; $n = 0;
        foreach ($rows as $r) {
            if (isset($r[$key]) && $r[$key] !== null) {
                $sum += (float)$r[$key];
                $n++;
            }
        }
        return $n > 0 ? $sum / $n : null;
    }

    /** Peer average when there is one, otherwise the neutral 50%. */
    private static function neutral(?float $peerAvg): float
    {
        return $peerAvg !== null ? $peerAvg : self::NEUTRAL;
    }

    public static function grade(float $score): string
    {
        if ($score >= 85) return 'A';
        if ($score >= 70) return 'B';
        if ($score >= 55) return 'C';
        return 'D';
    }

    public static function gradeLabel(string $g): string
    {
        return ['A' => 'Excellent', 'B' => 'Good', 'C' => 'Average', 'D' => 'Needs improvement'][$g] ?? '';
    }

    /* ---------------- date helpers ---------------- */

    /** Normalizes a DB date to 'Y-m-d', or '' when empty/zero. */
    private static function d($v): string
    {
        $v = trim((string)$v);
        if ($v === '' || strncmp($v, '0000', 4) === 0) {
            return '';
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : '';
    }

    /** Whole days from $a to $b (positive when $b is later). */
    public static function dayDiff(string $a, string $b): int
    {
        $ta = strtotime($a); $tb = strtotime($b);
        if (!$ta || !$tb) {
            return 0;
        }
        return (int)round(($tb - $ta) / 86400);
    }
}
