<?php
/**
 * Pushes each newly-Commissioned project to the HVAC commissioning app backend
 * (a SEPARATE service — its own DB). This is the only outbound touch-point; it
 * never changes the report → sheet → PMS → PDF pipeline.
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
 *              General:   Orders sheet address column (by project name)
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
        $this->ensureColumn();

        $rows = $this->db->query(
            "SELECT project_key, label, project_name, site_type, client_type, developer,
                    building, flat_no, order_id, commissioned_at
               FROM projects
              WHERE lifecycle = 'Commissioned' AND app_pushed_at IS NULL
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
            'commissionedAt' => $p['commissioned_at'] ? gmdate('c', strtotime((string)$p['commissioned_at'])) : null,
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
                $nameCol = Sheets::findColIndex($headers, 'project name');
                if ($nameCol < 0) { $nameCol = 3; }
                $addrCol = -1; $emailCol = -1;
                foreach ($headers as $i => $h) {
                    $c = strtolower((string)$h);
                    if ($addrCol  < 0 && strpos($c, 'address') !== false) { $addrCol  = $i; }
                    if ($emailCol < 0 && strpos($c, 'email')   !== false) { $emailCol = $i; }
                }
                for ($r = 1; $r < count($rows); $r++) {
                    $key = strtolower(trim((string)($rows[$r][$nameCol] ?? '')));
                    if ($key === '') { continue; }
                    if (!isset($map[$key])) { $map[$key] = ['address' => '', 'email' => '']; }
                    if ($addrCol  >= 0 && $map[$key]['address'] === '') {
                        $map[$key]['address'] = trim((string)($rows[$r][$addrCol] ?? ''));
                    }
                    if ($emailCol >= 0 && $map[$key]['email'] === '') {
                        $map[$key]['email'] = trim((string)($rows[$r][$emailCol] ?? ''));
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

    /** Add projects.app_pushed_at once (survives sync's ON DUPLICATE upsert). */
    private function ensureColumn(): void
    {
        try {
            $has = (int)$this->db->query(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'projects'
                    AND column_name = 'app_pushed_at'"
            )->fetchColumn();
            if ($has === 0) {
                $this->db->exec("ALTER TABLE projects ADD COLUMN app_pushed_at DATETIME NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
            // if this fails the SELECT below will error and run() returns 0 pushed — safe
        }
    }
}
