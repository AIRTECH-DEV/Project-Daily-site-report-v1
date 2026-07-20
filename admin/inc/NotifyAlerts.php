<?php
/**
 * Internal alert notifier — emails critical alerts to the PE/manager and sends
 * the morning/evening/weekly digests. GATED: does nothing unless
 * overrides.json sets alerts_mode to TEST or LIVE (default OFF). Reuses the raw
 * Smtp class + the existing email SMTP creds; no client contacts are ever used.
 *
 * WhatsApp internal alerts are intentionally not sent here — business-initiated
 * WhatsApp needs an approved template (like the report one); left for later.
 */
require_once __DIR__ . '/../../src/Smtp.php';

class NotifyAlerts
{
    /** OFF | TEST | LIVE */
    public static function mode(array $ov): string
    {
        $m = strtoupper((string)($ov['alerts_mode'] ?? 'OFF'));
        return in_array($m, ['OFF','TEST','LIVE'], true) ? $m : 'OFF';
    }

    /** Email recipients for an alert given the mode + team/manager contacts. */
    private static function recipients(array $alert, array $ov, array $emailCfg): string
    {
        $mode = self::mode($ov);
        if ($mode === 'OFF') return '';
        if ($mode === 'TEST') return (string)($emailCfg['test_to'] ?? '');
        // LIVE: owner (PE) contact + manager/ops recipients
        $to = [];
        $team = $ov['team_contacts'] ?? [];
        $owner = trim((string)($alert['owner'] ?? ''));
        if ($owner !== '' && is_array($team)) {
            foreach ($team as $name => $c) {
                if (strcasecmp($name, $owner) === 0 && !empty($c['email'])) $to[] = $c['email'];
            }
        }
        foreach (preg_split('/[,;\s]+/', (string)($ov['alert_manager_email'] ?? '')) as $m) {
            if (filter_var($m, FILTER_VALIDATE_EMAIL)) $to[] = $m;
        }
        return implode(',', array_unique(array_filter($to)));
    }

    /** Sends unsent critical alerts. Returns count sent. Never throws. */
    public static function notifyCritical(PDO $db, array $cfg): int
    {
        $ov = self::overrides();
        if (self::mode($ov) === 'OFF' || empty($ov['alerts_email'])) return 0;

        $rows = $db->query(
            "SELECT * FROM alerts WHERE severity='critical' AND status IN ('open','ack') AND notified_at IS NULL ORDER BY id ASC LIMIT 40"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return 0;

        $smtp = new Smtp($cfg['email']);
        $sent = 0;
        foreach ($rows as $a) {
            $to = self::recipients($a, $ov, $cfg['email']);
            if ($to === '') continue;
            $html = self::alertHtml($a);
            try {
                $smtp->send($to, '[PMS Alert] ' . $a['title'], $html);
                $db->prepare("UPDATE alerts SET notified_at=NOW() WHERE id=?")->execute([$a['id']]);
                $db->prepare("INSERT INTO alert_events (alert_id, event, actor, note) VALUES (?,?,?,?)")->execute([$a['id'], 'notified', 'system', $to]);
                $sent++;
            } catch (Throwable $e) { /* leave un-notified; retry next run */ }
        }
        return $sent;
    }

    /** Sends a digest email. $type = morning|evening|weekly. Returns bool sent. */
    public static function digest(PDO $db, array $cfg, string $type): bool
    {
        $ov = self::overrides();
        $mode = self::mode($ov);
        if ($mode === 'OFF' || empty($ov['alerts_email'])) return false;
        $to = $mode === 'TEST' ? (string)($cfg['email']['test_to'] ?? '') : (string)($ov['alert_manager_email'] ?? '');
        if (trim($to) === '') return false;

        [$subject, $html] = self::digestBody($db, $type);
        try { (new Smtp($cfg['email']))->send($to, $subject, $html); return true; }
        catch (Throwable $e) { return false; }
    }

    /* ---------------- HTML ---------------- */

    private static function alertHtml(array $a): string
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $color = ['critical'=>'#dc2626','warning'=>'#d97706','info'=>'#2563eb'][$a['severity']] ?? '#334155';
        return '<div style="font-family:Arial,sans-serif;max-width:560px">'
            . '<div style="background:' . $color . ';color:#fff;padding:14px 18px;border-radius:10px 10px 0 0;font-weight:700">'
            . strtoupper($e($a['severity'])) . ' · ' . $e($a['title']) . '</div>'
            . '<div style="border:1px solid #e6ecf5;border-top:0;padding:16px 18px;border-radius:0 0 10px 10px">'
            . '<p style="margin:0 0 10px;color:#334155">' . $e($a['detail']) . '</p>'
            . '<table style="font-size:13px;color:#5b6b82"><tr><td style="padding:2px 10px 2px 0">Project</td><td><b>' . $e($a['project_label']) . '</b></td></tr>'
            . ($a['owner'] ? '<tr><td style="padding:2px 10px 2px 0">PE / owner</td><td>' . $e($a['owner']) . '</td></tr>' : '')
            . '<tr><td style="padding:2px 10px 2px 0">Raised</td><td>' . $e($a['created_at']) . '</td></tr></table>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#94a3b8">PMS admin — automated internal alert.</p>'
            . '</div></div>';
    }

    private static function digestBody(PDO $db, string $type): array
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $today = date('Y-m-d');
        $blocks = [];
        $title = 'PMS Daily Digest';

        if ($type === 'morning') {
            $title = 'PMS — Today\'s Work (' . date('d M Y') . ')';
            $planned = $db->prepare("SELECT label, primary_pe, next_plan_steps FROM projects WHERE next_plan_date=? ORDER BY label");
            $planned->execute([$today]);
            $blocks[] = self::listBlock('Work planned today', array_map(fn($r) => $e($r['label']) . ' — ' . $e($r['next_plan_steps']) . ' <i>(' . $e($r['primary_pe']) . ')</i>', $planned->fetchAll(PDO::FETCH_ASSOC)));
        } elseif ($type === 'evening') {
            $title = 'PMS — Missing Updates & Holds (' . date('d M Y') . ')';
            $holds = $db->query("SELECT label, hold_owner, primary_pe FROM projects WHERE lifecycle='On Hold' ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
            $blocks[] = self::listBlock('On hold', array_map(fn($r) => $e($r['label']) . ' — stuck on ' . $e($r['hold_owner']) . ' <i>(' . $e($r['primary_pe']) . ')</i>', $holds));
            $stale = $db->query("SELECT label, primary_pe FROM projects WHERE lifecycle IN ('Active','At Risk') AND last_report_at < DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY last_report_at")->fetchAll(PDO::FETCH_ASSOC);
            $blocks[] = self::listBlock('No update 24h+', array_map(fn($r) => $e($r['label']) . ' <i>(' . $e($r['primary_pe']) . ')</i>', $stale));
        } else {
            $title = 'PMS — Weekly Summary';
            $lc = $db->query("SELECT lifecycle, COUNT(*) c FROM projects GROUP BY lifecycle")->fetchAll(PDO::FETCH_ASSOC);
            $blocks[] = self::listBlock('Portfolio', array_map(fn($r) => $e($r['lifecycle']) . ': <b>' . (int)$r['c'] . '</b>', $lc));
        }

        // always include open criticals
        $crit = $db->query("SELECT title, project_label FROM alerts WHERE severity='critical' AND status IN ('open','ack') ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        $blocks[] = self::listBlock('Open critical alerts', array_map(fn($r) => $e($r['title']) . ' — ' . $e($r['project_label']), $crit));

        $html = '<div style="font-family:Arial,sans-serif;max-width:600px">'
            . '<h2 style="color:#0f1b30">' . $e($title) . '</h2>' . implode('', $blocks)
            . '<p style="font-size:12px;color:#94a3b8;margin-top:18px">PMS admin — automated digest.</p></div>';
        return [$title, $html];
    }

    private static function listBlock(string $heading, array $items): string
    {
        $h = '<div style="margin:14px 0"><div style="font-weight:700;color:#334155;border-bottom:2px solid #eef2f8;padding-bottom:5px;margin-bottom:8px">'
            . htmlspecialchars($heading) . ' <span style="color:#94a3b8;font-weight:500">(' . count($items) . ')</span></div>';
        if (!$items) return $h . '<div style="color:#94a3b8;font-size:13px">None.</div></div>';
        $h .= '<ul style="margin:0;padding-left:18px;color:#334155;font-size:13.5px;line-height:1.7">';
        foreach ($items as $it) $h .= '<li>' . $it . '</li>';
        return $h . '</ul></div>';
    }

    private static function overrides(): array
    {
        $f = __DIR__ . '/../../config/overrides.json';
        if (is_file($f)) { $o = json_decode((string)file_get_contents($f), true); if (is_array($o)) return $o; }
        return [];
    }
}
