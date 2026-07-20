<?php
require_once __DIR__ . '/PePlan.php';

/**
 * Builds tomorrow's PE-plan image and sends it as an IMAGE-header WhatsApp
 * template to the configured internal numbers. Self-contained (its own curl,
 * reusing the whatsapp block's Meta credentials) so both the CLI daily job and
 * the admin "Send test now" button can call it without Google/Sheets wiring.
 */
class PePlanSender
{
    /** @var array full app config */ private $cfg;
    /** @var array whatsapp creds */  private $wa;
    /** @var array pe_plan block */   private $pe;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->wa  = $cfg['whatsapp'] ?? [];
        $this->pe  = $cfg['pe_plan'] ?? [];
    }

    public function mode(): string { return strtoupper($this->pe['mode'] ?? 'OFF'); }

    /**
     * @param array $opts ['date'=>Y-m-d, 'test'=>bool, 'force'=>bool]
     *   test  -> always send to the test number (ignores mode, used by the button)
     *   force -> render + send even when nothing is planned (test/preview)
     * @return array ['status','detail','recipients'=>[],'groups'=>int,'sites'=>int,'image'=>bytes|null]
     */
    public function run(PDO $db, array $opts = []): array
    {
        $test  = !empty($opts['test']);
        $force = !empty($opts['force']);
        $date  = $opts['date'] ?? date('Y-m-d', strtotime('+1 day'));
        $mode  = $this->mode();

        if (!$test && $mode === 'OFF') {
            return $this->res('SKIPPED', 'pe_plan mode=OFF', [], 0, 0);
        }
        if (empty($this->wa['token'])) {
            return $this->res('FAILED', 'no META token (whatsapp.token)', [], 0, 0);
        }
        $tpl = $this->pe['template_name'] ?: 'pe_plan_reminder';

        // recipients
        if ($test || $mode === 'TEST') {
            $recips = array_filter([$this->normalize($this->pe['test_to'] ?? '')]);
        } else { // LIVE
            $recips = [];
            foreach (($this->pe['numbers'] ?? []) as $n) {
                $f = $this->normalize($n);
                if ($f !== '') $recips[$f] = $f;   // de-dupe
            }
            $recips = array_values($recips);
        }
        if (!$recips) {
            return $this->res('FAILED', $test || $mode === 'TEST' ? 'no test number set' : 'no LIVE numbers set', [], 0, 0);
        }

        // plan + image
        $pp     = new PePlan($this->pe);
        $groups = $pp->planForDate($db, $date);
        $nG = count($groups); $nS = $pp->siteCount($groups);
        if (!$groups && !$force && !$test) {
            return $this->res('SKIPPED', "nothing planned for $date", [], 0, 0);
        }
        $png = $pp->renderPng($date, $groups);

        // one media upload, reused for every recipient
        try {
            $mediaId = $this->uploadImage($png, 'tomorrow-site-plan-' . $date . '.png');
        } catch (Throwable $e) {
            return $this->res('FAILED', 'image upload: ' . $e->getMessage(), $recips, $nG, $nS);
        }

        $ok = 0; $bad = 0; $errs = [];
        foreach ($recips as $ph) {
            $r = $this->sendImage($ph, $mediaId, $tpl);
            if ($r['ok']) { $ok++; } else { $bad++; $errs[] = $ph . ': ' . $r['error']; }
        }
        $status = $bad === 0 ? 'SENT' : ($ok > 0 ? "PARTIAL ($ok/" . ($ok + $bad) . ')' : 'FAILED');
        $detail = "$tpl -> $ok ok" . ($bad ? ", $bad failed: " . implode('; ', $errs) : '');
        $out = $this->res($status, $detail, $recips, $nG, $nS);
        $out['image'] = $png;
        return $out;
    }

    private function res(string $status, string $detail, array $recips, int $g, int $s): array
    {
        return ['status' => $status, 'detail' => $detail, 'recipients' => $recips,
                'groups' => $g, 'sites' => $s, 'image' => null];
    }

    /* ---------------- Meta ---------------- */

    private function sendImage(string $toPhone, string $mediaId, string $tpl): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'       => $toPhone,
            'type'     => 'template',
            'template' => [
                'name'     => $tpl,
                'language' => ['code' => $this->wa['language_code'] ?? 'en'],
                'components' => [[
                    'type'       => 'header',
                    'parameters' => [['type' => 'image', 'image' => ['id' => $mediaId]]],
                ]],
            ],
        ];
        $url = 'https://graph.facebook.com/' . ($this->wa['graph_version'] ?? 'v21.0')
             . '/' . $this->wa['phone_number_id'] . '/messages';
        [$code, $body] = $this->curl($url, json_encode($payload), [
            'Authorization: Bearer ' . $this->wa['token'], 'Content-Type: application/json',
        ]);
        $res = json_decode((string)$body, true);
        if (isset($res['error'])) {
            return ['ok' => false, 'error' => $res['error']['code'] . ' - ' . $res['error']['message']];
        }
        if ($code >= 200 && $code < 300 && !empty($res['messages'])) {
            return ['ok' => true, 'id' => $res['messages'][0]['id']];
        }
        return ['ok' => false, 'error' => "HTTP $code $body"];
    }

    /** Uploads PNG bytes to WhatsApp media, returns the reusable media id. */
    private function uploadImage(string $bytes, string $fileName): string
    {
        $url = 'https://graph.facebook.com/' . ($this->wa['graph_version'] ?? 'v21.0')
             . '/' . $this->wa['phone_number_id'] . '/media';
        $post = [
            'messaging_product' => 'whatsapp',
            'type'              => 'image/png',
            'file'              => new CURLStringFile($bytes, $fileName, 'image/png'),
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->wa['token']],
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        if ($body === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException('curl: ' . $e); }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $res = json_decode((string)$body, true);
        if (isset($res['error'])) { throw new RuntimeException($res['error']['code'] . ' - ' . $res['error']['message']); }
        if ($code < 200 || $code >= 300 || empty($res['id'])) { throw new RuntimeException("HTTP $code $body"); }
        return $res['id'];
    }

    private function curl(string $url, string $json, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        if ($body === false) { $e = curl_error($ch); curl_close($ch); return [0, json_encode(['error' => ['code' => 0, 'message' => 'curl: ' . $e]])]; }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, (string)$body];
    }

    /** 91XXXXXXXXXX or '' if not a valid number. */
    private function normalize($raw): string
    {
        $d = preg_replace('/\D/', '', (string)$raw);
        if ($d === '') return '';
        if (strlen($d) === 12 && strpos($d, '91') === 0) return $d;
        if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
        if (strlen($d) === 10) return '91' . $d;
        if (strlen($d) > 10)   return '91' . substr($d, -10);
        return '';
    }
}
