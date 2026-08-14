<?php
/**
 * Pushes each project that has cleared **Pre-Commissioning** to the HVAC
 * commissioning app backend (a SEPARATE service — its own DB). This is the only
 * outbound touch-point; it never changes the report → sheet → PMS → PDF pipeline.
 *
 * Hand-off point (changed): the technician gets the job as soon as the
 * "Pre-Commissining" step is done — projects.pre_commissioned_at (stamped by the
 * admin sync). lifecycle 'Commissioned'/'Closed' stays a fallback candidate so a
 * manually-commissioned project (or one whose pre step was never reported) is
 * still pushed and nothing is lost.
 *
 * Reliable + idempotent:
 *   - a project is pushed once — projects.app_pushed_at is stamped on success,
 *   - retried every run until the backend acks (survives backend downtime),
 *   - the backend upserts by project_key, so a re-push never duplicates and
 *     never re-opens a project the technician already reported.
 *
 * Client contact resolution mirrors the existing report notifiers:
 *   phone   -> Whatsapp::resolvePhones (Orders sheet phone cols / developer_phones)
 *   address -> Developer: "Developer - Building - Flat N"
 *              General:   Orders sheet address column (by project name; the
 *                         shipping/site column, falling back to billing)
 *
 * Never throws to the caller — a push problem must not affect anything else.
 * Sheets/Drive are optional: without them only the developer path is enriched
 * (phone/address for General come out blank and the technician fills them).
 */
class CommissionPush
{
    /** @var PDO */    private $db;
    /** @var array */  private $cfg;
    /** @var ?Sheets */private $sheets;
    /** @var ?Whatsapp */ private $wa = null;
    /** @var ?array */ private $orderMap = null;

    public function __construct(PDO $db, array $cfg, ?Sheets $sheets = null, ?Drive $drive = null)
    {
        $this->db     = $db;
        $this->cfg    = $cfg;
        $this->sheets = $sheets;
        if ($sheets !== null && $drive !== null && class_exists('Whatsapp')) {
            try { $this->wa = new Whatsapp($sheets, $drive, $cfg['whatsapp'] ?? []); }
            catch (Throwable $e) { $this->wa = null; }
        }
    }

    /** @return array run stats for logging */
    public function run(int $limit = 50): array
    {
        $url = rtrim(trim((string)($this->cfg['app_backend']['url'] ?? '')), '/');
        if ($url === '') {
            return ['pushed' => 0, 'failed' => 0, 'note' => 'app_backend.url blank — push OFF'];
        }
        $this->ensureColumns();

        $rows = $this->db->query(
            "SELECT project_key, label, project_name, site_type, client_type, developer,
                    building, flat_no, order_id, commissioned_at, pre_commissioned_at
               FROM projects
              WHERE app_pushed_at IS NULL
                AND (pre_commissioned_at IS NOT NULL OR lifecycle IN ('Commissioned','Closed'))
              LIMIT " . (int)$limit
        )->fetchAll(PDO::FETCH_ASSOC);

        $pushed = 0; $failed = 0;
        foreach ($rows as $p) {
            if ($this->post($url, $this->buildPayload($p))) {
                $this->db->prepare("UPDATE projects SET app_pushed_at = NOW() WHERE project_key = ?")
                         ->execute([$p['project_key']]);
                $pushed++;
            } else {
                $failed++;   // left unstamped -> retried next run
            }
        }
        return ['pushed' => $pushed, 'failed' => $failed, 'candidates' => count($rows)];
    }

    /* ---------------- payload ---------------- */

    private function buildPayload(array $p): array
    {
        $clientType  = (string)($p['client_type'] ?? '');
        $isDev       = strcasecmp($clientType, 'Developer') === 0;
        $projectName = (string)($p['project_name'] ?? ($p['label'] ?? ''));
        $developer   = (string)($p['developer'] ?? '');
        $clientName  = $isDev ? ($developer !== '' ? $developer : $projectName) : $projectName;

        $phone = '';
        if ($this->wa !== null) {
            try {
                $phones = $this->wa->resolvePhones($clientType, $developer, $projectName);
                $phone  = $phones[0] ?? '';
            } catch (Throwable $e) {}
        }

        $address = $isDev ? $this->developerAddress($p) : $this->generalField($projectName, 'address');
        $email   = $isDev
            ? (string)($this->cfg['email']['developer_emails'][$developer] ?? '')
            : $this->generalField($projectName, 'email');

        // Hand-off timestamp: pre-commissioning is the trigger now, so that is what
        // the queue is dated by. 'commissionedAt' keeps carrying it (the backend
        // sorts/displays that field) while 'preCommissionedAt'/'stage' say what it
        // really is for a backend that wants to tell the two apart.
        $preAt  = $p['pre_commissioned_at'] ?? null;
        $commAt = $p['commissioned_at'] ?? null;
        $readyAt = $preAt ?: $commAt;

        return [
            'projectKey'     => $p['project_key'],
            'projectName'    => $projectName,
            'clientName'     => $clientName,
            'clientPhone'    => $phone !== '' ? $phone : null,
            'clientEmail'    => $email !== '' ? $email : null,
            'address'        => $address !== '' ? $address : null,
            'siteType'       => $p['site_type']  ?: null,
            'clientType'     => $clientType      ?: null,
            'developer'      => $developer       ?: null,
            'building'       => $p['building']   ?: null,
            'flatNo'         => $p['flat_no']    ?: null,
            'orderId'        => $p['order_id']   ?: null,
            'stage'             => $commAt ? 'commissioned' : 'pre_commissioned',
            'preCommissionedAt' => $preAt   ? gmdate('c', strtotime((string)$preAt))   : null,
            'commissionedAt'    => $readyAt ? gmdate('c', strtotime((string)$readyAt)) : null,
            // Equipment schedule captured at Pre-Commissioning — model no., serial
            // no. and location per unit. The app prefills its machine list from
            // this, so the technician photographs machines instead of typing
            // serials. Empty array when the report never reached PMS.
            'machines'          => PreCommissioningMachines::forProject($this->db, (string)$p['project_key']),
        ];
    }

    /** "Developer - Building - Flat N" (mirrors SubmitService::developerLocation). */
    private function developerAddress(array $p): string
    {
        $parts = [];
        $dev  = trim((string)($p['developer'] ?? ''));
        $bld  = trim((string)($p['building']  ?? ''));
        $flat = trim((string)($p['flat_no']   ?? ''));
        if ($dev  !== '') { $parts[] = $dev; }
        if ($bld  !== '') { $parts[] = $bld; }
        if ($flat !== '') { $parts[] = 'Flat ' . $flat; }
        return implode(' - ', $parts);
    }

    private function generalField(string $projectName, string $which): string
    {
        $row = $this->ordersMap()[strtolower(trim($projectName))] ?? null;
        return $row ? (string)($row[$which] ?? '') : '';
    }

    /**
     * project-name(lower) => ['address'=>, 'email'=>] from the Orders sheets.
     * Same sheets Whatsapp uses; address/email columns matched by header substring.
     */
    private function ordersMap(): array
    {
        if ($this->orderMap !== null) {
            return $this->orderMap;
        }
        $map = [];
        if ($this->sheets === null) {
            return $this->orderMap = $map;
        }
        $ssIds = $this->cfg['whatsapp']['order_ss_ids'] ?? [];
        $tab   = (string)($this->cfg['whatsapp']['order_tab'] ?? 'Orders');
        foreach ($ssIds as $ssId) {
            try {
                $rows = $this->sheets->getTab($ssId, $tab);
                if (count($rows) < 2) { continue; }
                $headers = $rows[0];
                // Keyed under BOTH the site name and the client's billing name — a
                // project filed under either must still carry its address/email to
                // the commissioning app.
                $cols = Orders::nameCols($headers);
                $nameCols = array_merge($cols['site'], $cols['billing']);
                if (!$nameCols) { $nameCols = [3]; }
                // Address: ranked candidates, first non-empty wins — the Non-VRV
                // sheet's plain "Address" column is empty on every row.
                $addrCols = Orders::addressCols($headers);
                $emailCol = -1;
                foreach ($headers as $i => $h) {
                    $c = strtolower((string)$h);
                    if ($emailCol < 0 && strpos($c, 'email') !== false) { $emailCol = $i; }
                }
                for ($r = 1; $r < count($rows); $r++) {
                    foreach ($nameCols as $nameCol) {
                        $key = strtolower(trim((string)($rows[$r][$nameCol] ?? '')));
                        if ($key === '') { continue; }
                        if (!isset($map[$key])) { $map[$key] = ['address' => '', 'email' => '']; }
                        if ($map[$key]['address'] === '') {
                            $map[$key]['address'] = Orders::firstFilled($rows[$r], $addrCols);
                        }
                        if ($emailCol >= 0 && $map[$key]['email'] === '') {
                            $map[$key]['email'] = trim((string)($rows[$r][$emailCol] ?? ''));
                        }
                    }
                }
            } catch (Throwable $e) {
                // sheet not accessible -> skip
            }
        }
        return $this->orderMap = $map;
    }

    /* ---------------- transport ---------------- */

    private function post(string $baseUrl, array $payload): bool
    {
        $url = $baseUrl . '/ingest/commissioned';
        $key = (string)($this->cfg['app_backend']['api_key'] ?? '');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Api-Key: ' . $key],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return false;
        }
        $res = json_decode((string)$body, true);
        return is_array($res) && !empty($res['success']);
    }

    /**
     * Add the bookkeeping columns once (they survive sync's ON DUPLICATE upsert):
     *   app_pushed_at       — push ack stamp
     *   pre_commissioned_at — hand-off trigger, normally stamped by Sync
     */
    private function ensureColumns(): void
    {
        $cols = [
            'app_pushed_at'       => "ALTER TABLE projects ADD COLUMN app_pushed_at DATETIME NULL DEFAULT NULL",
            'pre_commissioned_at' => "ALTER TABLE projects ADD COLUMN pre_commissioned_at DATETIME NULL DEFAULT NULL",
        ];
        foreach ($cols as $col => $ddl) {
            try {
                $has = (int)$this->db->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'projects'
                        AND column_name = '" . $col . "'"
                )->fetchColumn();
                if ($has === 0) {
                    $this->db->exec($ddl);
                }
            } catch (Throwable $e) {
                // if this fails the SELECT below will error and run() returns 0 pushed — safe
            }
        }
    }
}
