<?php
require_once __DIR__ . '/Smtp.php';

/**
 * Report emailer — port of sendReportEmail.js. Resolves the client address
 * (scrape tab -> Orders "Client Email Id" -> developer map -> fallback), builds
 * the same HTML body, and sends the PDF over SMTP. Honors MODE OFF/TEST/LIVE.
 */
class Mailer
{
    /** @var Sheets */ private $sheets;
    /** @var array */  private $cfg;
    private $scrapeMap = null;
    private $ordersMap = null;

    public function __construct(Sheets $sheets, array $emailCfg)
    {
        $this->sheets = $sheets;
        $this->cfg = $emailCfg;
    }

    public function mode(): string
    {
        return strtoupper($this->cfg['mode'] ?? 'OFF');
    }

    /**
     * Sends the report for one submission. Returns ['sent'=>bool,'to'=>string,'error'=>string].
     */
    public function sendReport(string $clientType, string $developer, string $projectName, string $pdfBytes, string $pdfName): array
    {
        $mode = $this->mode();
        if ($mode === 'OFF') {
            return ['sent' => false, 'to' => '', 'error' => 'MODE=OFF'];
        }
        $to = $this->resolveRecipient($clientType, $developer, $projectName);
        if ($mode === 'TEST') {
            $to = $this->cfg['test_to'] ?: $to;
        }
        if ($to === '') {
            return ['sent' => false, 'to' => '', 'error' => 'no recipient'];
        }

        $cc = ($mode === 'LIVE') ? (string)($this->cfg['cc'] ?? '') : '';
        $subject = ($this->cfg['subject_prefix'] ?? 'Site Report: ') . $projectName;
        $html = $this->buildReportHtml($projectName);
        $att = ['name' => $pdfName, 'mime' => 'application/pdf', 'bytes' => $pdfBytes];

        try {
            (new Smtp($this->cfg))->send($to, $subject, $html, $att, $cc);
            return ['sent' => true, 'to' => $to, 'error' => ''];
        } catch (Throwable $e) {
            return ['sent' => false, 'to' => $to, 'error' => $e->getMessage()];
        }
    }

    public function buildReportHtml(string $projectName): string
    {
        $p = htmlspecialchars($projectName, ENT_QUOTES);
        return "<div style='font-family:Arial,sans-serif;font-size:14px;color:#333;'>"
            . "<p>Dear Customer,</p>"
            . "<p>Please find the attached site progress report for <b>$p</b>.</p><br>"
            . "<span style='color:red;font-weight:bold;font-size:16px;'>Vakharia Airtech Pvt. Ltd.</span><br>"
            . "<a href='https://www.vakhariaairtech.com/'>www.vakhariaairtech.com</a>"
            . "</div>";
    }

    /* ---------------- recipient resolution ---------------- */

    public function resolveRecipient(string $clientType, string $developer, string $projectName): string
    {
        // 1) Known developer (by developer column or project name) -> its email.
        $devMail = $this->developerEmail($developer) ?: $this->developerEmail($projectName);
        if ($devMail !== '') {
            return $devMail;
        }
        // 2) Marked developer but no email yet -> fallback (skip general lookup).
        $isDev = strtolower(trim($clientType)) === 'developer'
            || $this->isDeveloperName($developer) || $this->isDeveloperName($projectName);
        if ($isDev) {
            return (string)($this->cfg['fallback_to'] ?? '');
        }
        // 3) General -> scrape tab, then Orders, then fallback.
        $key = strtolower(trim($projectName));
        $scrape = $this->scrapeMap();
        $orders = $this->ordersMap();
        return $scrape[$key] ?? $orders[$key] ?? (string)($this->cfg['fallback_to'] ?? '');
    }

    private function scrapeMap(): array
    {
        if ($this->scrapeMap !== null) {
            return $this->scrapeMap;
        }
        $map = [];
        try {
            $rows = $this->sheets->getTab($this->cfg['scrape_ss_id'], $this->cfg['scrape_tab']);
            for ($i = 1; $i < count($rows); $i++) {
                $name = strtolower(trim((string)($rows[$i][$this->cfg['scrape_name_col']] ?? '')));
                $mail = $this->cleanRecipients($rows[$i][$this->cfg['scrape_email_col']] ?? '');
                if ($name !== '' && $mail !== '') {
                    $map[$name] = $mail;
                }
            }
        } catch (Throwable $e) {
            // scrape sheet not shared/available -> just skip this tier
        }
        return $this->scrapeMap = $map;
    }

    private function ordersMap(): array
    {
        if ($this->ordersMap !== null) {
            return $this->ordersMap;
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
                $mailCol = Sheets::findColIndex($headers, 'client email');
                if ($mailCol < 0) { $mailCol = Sheets::findColIndex($headers, 'email'); }
                if ($mailCol < 0) {
                    continue;
                }
                for ($i = 1; $i < count($rows); $i++) {
                    $name = strtolower(trim((string)($rows[$i][$nameCol] ?? '')));
                    $mail = $this->cleanRecipients($rows[$i][$mailCol] ?? '');
                    if ($name !== '' && $mail !== '') {
                        $map[$name] = $mail; // later sheet wins
                    }
                }
            } catch (Throwable $e) {
                // sheet not accessible -> skip
            }
        }
        return $this->ordersMap = $map;
    }

    private function developerEmail(string $developer): string
    {
        $key = strtolower(trim($developer));
        if ($key === '') {
            return '';
        }
        foreach (($this->cfg['developer_emails'] ?? []) as $name => $mail) {
            if (strtolower($name) === $key) {
                return $this->cleanRecipients($mail);
            }
        }
        return '';
    }

    private function isDeveloperName(string $name): bool
    {
        $key = strtolower(trim($name));
        if ($key === '') {
            return false;
        }
        foreach (array_keys($this->cfg['developer_emails'] ?? []) as $dev) {
            if (strtolower($dev) === $key) {
                return true;
            }
        }
        return false;
    }

    /** Keeps only real addresses from "a@x.com, No emails found". */
    private function cleanRecipients($raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $parts = preg_split('/[,;\s]+/', (string)$raw);
        $ok = array_filter($parts, function ($s) {
            $at = strpos($s, '@');
            return $at > 0 && strpos($s, '.', $at) !== false;
        });
        return implode(',', $ok);
    }
}
