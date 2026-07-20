<?php
/**
 * Report WhatsApp sender — port of sendReportwhatsapp.js. Sends the
 * daily_site_updates template with the report link to every client number
 * (Orders "phone" columns, or developer map), via the Meta Cloud API.
 * Honors MODE OFF/TEST/LIVE.
 */
class Whatsapp
{
    /** @var Sheets */ private $sheets;
    /** @var Drive */  private $drive;
    /** @var array */  private $cfg;
    private $phoneMap = null;

    public function __construct(Sheets $sheets, Drive $drive, array $waCfg)
    {
        $this->sheets = $sheets;
        $this->drive = $drive;
        $this->cfg = $waCfg;
    }

    public function mode(): string
    {
        return strtoupper($this->cfg['mode'] ?? 'OFF');
    }

    /**
     * Sends the report link to a submission's client numbers.
     * @return array ['status'=>SENT|PARTIAL|FAILED|SKIPPED, 'detail'=>string, 'phones'=>[]]
     */
    /**
     * @param array $pdf ['drive_id'=>..., 'path'=>local file, 'name'=>filename]
     */
    public function sendReport(string $clientType, string $developer, string $projectName, array $pdf): array
    {
        $mode = $this->mode();
        if ($mode === 'OFF') {
            return ['status' => 'SKIPPED', 'detail' => 'MODE=OFF', 'phones' => []];
        }
        if (empty($this->cfg['token'])) {
            return ['status' => 'FAILED', 'detail' => 'no META token (set whatsapp.token)', 'phones' => []];
        }

        $phones = ($mode === 'TEST')
            ? array_filter([$this->formatPhone($this->cfg['test_to'] ?? '')])
            : $this->resolvePhones($clientType, $developer, $projectName);
        if (!$phones) {
            return ['status' => 'FAILED', 'detail' => 'no client number', 'phones' => []];
        }

        $delivery = strtolower($this->cfg['delivery'] ?? 'link');

        if ($delivery === 'document') {
            // Attach the real PDF: upload it once, reference the media id per number.
            $path = $pdf['path'] ?? '';
            if ($path === '' || !is_file($path)) {
                return ['status' => 'FAILED', 'detail' => 'no PDF file for document send', 'phones' => $phones];
            }
            $fileName = $this->docFileName($pdf['name'] ?? 'Site Report.pdf');
            try {
                $mediaId = $this->uploadMedia((string)file_get_contents($path), $fileName);
            } catch (Throwable $e) {
                return ['status' => 'FAILED', 'detail' => 'media upload: ' . $e->getMessage(), 'phones' => $phones];
            }
            $send = fn(string $ph) => $this->sendDocumentTemplate($ph, $mediaId, $fileName, $projectName);
            $detail = 'doc media=' . $mediaId;
        } else {
            $link = $this->reportLink($pdf['drive_id'] ?? '');
            if ($link === '') {
                return ['status' => 'FAILED', 'detail' => 'no report link (PDF not on Drive)', 'phones' => $phones];
            }
            $send = fn(string $ph) => $this->sendTemplate($ph, $link);
            $detail = 'link=' . $link;
        }

        $ok = 0; $bad = 0; $errs = [];
        foreach ($phones as $ph) {
            $r = $send($ph);
            if ($r['ok']) { $ok++; } else { $bad++; $errs[] = $ph . ': ' . $r['error']; }
        }
        $status = $bad === 0 ? 'SENT' : ($ok > 0 ? "PARTIAL ($ok/" . ($ok + $bad) . ')' : 'FAILED');
        return [
            'status' => $status,
            'detail' => ($errs ? implode('; ', $errs) . ' | ' : '') . $detail,
            'phones' => $phones,
        ];
    }

    /* ---------------- Meta send ---------------- */

    /** LINK template (the original daily_site_updates body-with-link). */
    private function sendTemplate(string $toPhone, string $link): array
    {
        $bodyParam = $this->cfg['use_named_params']
            ? ['type' => 'text', 'parameter_name' => 'report_link', 'text' => $link]
            : ['type' => 'text', 'text' => $link];

        return $this->postTemplate($toPhone, $this->cfg['template_name'], [
            ['type' => 'body', 'parameters' => [$bodyParam]],
        ]);
    }

    /**
     * DOCUMENT template — sends the actual PDF as the template's document header.
     * Requires an APPROVED template (doc_template_name) whose header format is
     * DOCUMENT. Any body variables come from cfg doc_body_params (use the token
     * {project} to inject the project name).
     */
    private function sendDocumentTemplate(string $toPhone, string $mediaId, string $fileName, string $projectName): array
    {
        $components = [[
            'type'       => 'header',
            'parameters' => [[
                'type'     => 'document',
                'document' => ['id' => $mediaId, 'filename' => $fileName],
            ]],
        ]];
        $bodyParams = [];
        foreach (($this->cfg['doc_body_params'] ?? []) as $p) {
            $text = str_replace('{project}', $projectName, (string)$p);
            $bodyParams[] = $this->cfg['use_named_params']
                ? ['type' => 'text', 'parameter_name' => 'project', 'text' => $text]
                : ['type' => 'text', 'text' => $text];
        }
        if ($bodyParams) {
            $components[] = ['type' => 'body', 'parameters' => $bodyParams];
        }

        $tplName = $this->cfg['doc_template_name'] ?: $this->cfg['template_name'];
        return $this->postTemplate($toPhone, $tplName, $components);
    }

    /**
     * Sends a template whose HEADER format is IMAGE. The image is referenced by a
     * media id (upload it first with uploadMedia). Optional body params are sent
     * positionally. Used by the PE-plan reminder (the plan card is the header).
     */
    public function sendImageHeaderTemplate(string $toPhone, string $mediaId, string $templateName, array $bodyParams = []): array
    {
        $components = [[
            'type'       => 'header',
            'parameters' => [['type' => 'image', 'image' => ['id' => $mediaId]]],
        ]];
        if ($bodyParams) {
            $params = [];
            foreach ($bodyParams as $p) { $params[] = ['type' => 'text', 'text' => (string)$p]; }
            $components[] = ['type' => 'body', 'parameters' => $params];
        }
        return $this->postTemplate($toPhone, $templateName, $components);
    }

    /** Public phone normalizer (91XXXXXXXXXX or '' if invalid) for callers. */
    public function normalizePhone($raw): string
    {
        return $this->formatPhone($raw);
    }

    /** POSTs a template message with the given components. */
    private function postTemplate(string $toPhone, string $templateName, array $components): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'       => $toPhone,
            'type'     => 'template',
            'template' => [
                'name'       => $templateName,
                'language'   => ['code' => $this->cfg['language_code']],
                'components' => $components,
            ],
        ];
        return $this->postMessage(json_encode($payload));
    }

    /** Raw POST to /{phone_number_id}/messages. */
    private function postMessage(string $json): array
    {
        $url = 'https://graph.facebook.com/' . $this->cfg['graph_version']
            . '/' . $this->cfg['phone_number_id'] . '/messages';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->cfg['token'],
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => 'curl: ' . $err];
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $res = json_decode((string)$body, true);
        if (isset($res['error'])) {
            return ['ok' => false, 'error' => $res['error']['code'] . ' - ' . $res['error']['message']];
        }
        if ($code >= 200 && $code < 300 && !empty($res['messages'])) {
            return ['ok' => true, 'id' => $res['messages'][0]['id']];
        }
        return ['ok' => false, 'error' => "HTTP $code $body"];
    }

    /**
     * Uploads a file to WhatsApp media, returning the media id (referenced by a
     * document-header template). Media is hosted by Meta; the id is reusable
     * across recipients for this phone number.
     */
    public function uploadMedia(string $bytes, string $fileName, string $mime = 'application/pdf'): string
    {
        $url = 'https://graph.facebook.com/' . $this->cfg['graph_version']
            . '/' . $this->cfg['phone_number_id'] . '/media';
        $post = [
            'messaging_product' => 'whatsapp',
            'type'              => $mime,
            'file'              => new CURLStringFile($bytes, $fileName, $mime),
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,     // multipart/form-data (curl sets boundary)
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->cfg['token']],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('curl: ' . $err);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $res = json_decode((string)$body, true);
        if (isset($res['error'])) {
            throw new RuntimeException($res['error']['code'] . ' - ' . $res['error']['message']);
        }
        if ($code < 200 || $code >= 300 || empty($res['id'])) {
            throw new RuntimeException("HTTP $code $body");
        }
        return $res['id'];
    }

    /** WhatsApp shows the filename to the client; keep it clean and .pdf. */
    private function docFileName(string $name): string
    {
        $name = preg_replace('/[\r\n\t]+/', ' ', $name);
        if (!preg_match('/\.pdf$/i', $name)) {
            $name .= '.pdf';
        }
        return $name;
    }

    private function reportLink(string $pdfDriveId): string
    {
        if ($pdfDriveId === '') {
            return '';
        }
        if (!empty($this->cfg['make_pdf_viewable'])) {
            try { $this->drive->makeLinkViewable($pdfDriveId); } catch (Throwable $e) {}
        }
        return 'https://drive.google.com/file/d/' . $pdfDriveId . '/view';
    }

    /* ---------------- phone resolution ---------------- */

    public function resolvePhones(string $clientType, string $developer, string $projectName): array
    {
        // 1) Developer numbers (by developer col or project name).
        $dev = $this->developerPhones($developer) ?: $this->developerPhones($projectName);
        if ($dev) {
            return $dev;
        }
        // 2) Marked developer but none filled -> fallback.
        $isDev = strtolower(trim($clientType)) === 'developer'
            || $this->isDeveloperName($developer) || $this->isDeveloperName($projectName);
        if ($isDev) {
            return $this->splitPhones($this->cfg['fallback_phones'] ?? '');
        }
        // 3) General -> Orders phone map, else fallback.
        $map = $this->phoneMap();
        $found = $map[strtolower(trim($projectName))] ?? [];
        return $found ?: $this->splitPhones($this->cfg['fallback_phones'] ?? '');
    }

    private function phoneMap(): array
    {
        if ($this->phoneMap !== null) {
            return $this->phoneMap;
        }
        $map = [];
        foreach ($this->cfg['order_ss_ids'] as $ssId) {
            try {
                $rows = $this->sheets->getTab($ssId, $this->cfg['order_tab']);
                if (count($rows) < 2) {
                    continue;
                }
                $headers = $rows[0];
                $nameCol = Sheets::findColIndex($headers, 'project name');
                if ($nameCol < 0) { $nameCol = 3; }
                $phoneCols = [];
                foreach ($headers as $i => $h) {
                    if (stripos((string)$h, 'phone') !== false) { $phoneCols[] = $i; }
                }
                if (!$phoneCols) {
                    continue;
                }
                for ($r = 1; $r < count($rows); $r++) {
                    $key = strtolower(trim((string)($rows[$r][$nameCol] ?? '')));
                    if ($key === '') { continue; }
                    $nums = [];
                    foreach ($phoneCols as $c) {
                        $nums = array_merge($nums, $this->splitPhones($rows[$r][$c] ?? ''));
                    }
                    $nums = array_values(array_unique($nums));
                    if ($nums) {
                        $map[$key] = array_values(array_unique(array_merge($map[$key] ?? [], $nums)));
                    }
                }
            } catch (Throwable $e) {
                // sheet not accessible -> skip
            }
        }
        return $this->phoneMap = $map;
    }

    private function developerPhones(string $name): array
    {
        $key = strtolower(trim($name));
        if ($key === '') {
            return [];
        }
        foreach (($this->cfg['developer_phones'] ?? []) as $dev => $nums) {
            if (strtolower($dev) === $key) {
                return $this->splitPhones($nums);
            }
        }
        return [];
    }

    private function isDeveloperName(string $name): bool
    {
        $key = strtolower(trim($name));
        if ($key === '') {
            return false;
        }
        foreach (array_keys($this->cfg['developer_phones'] ?? []) as $dev) {
            if (strtolower($dev) === $key) {
                return true;
            }
        }
        return false;
    }

    private function splitPhones($raw): array
    {
        if ($raw === '' || $raw === null) {
            return [];
        }
        $parts = preg_split('#[/,;&\n]|(?:\s+or\s+)#i', (string)$raw);
        $out = [];
        foreach ($parts as $p) {
            $f = $this->formatPhone($p);
            if ($f !== '') { $out[] = $f; }
        }
        return $out;
    }

    /** Normalizes one number to 91XXXXXXXXXX, or '' if not a valid 10-digit number. */
    private function formatPhone($raw): string
    {
        $d = preg_replace('/\D/', '', (string)$raw);
        if ($d === '') { return ''; }
        if (strlen($d) === 12 && strpos($d, '91') === 0) { return $d; }
        if (strlen($d) === 11 && $d[0] === '0') { $d = substr($d, 1); }
        if (strlen($d) === 10) { return '91' . $d; }
        if (strlen($d) > 10)   { return '91' . substr($d, -10); }
        return '';
    }
}
