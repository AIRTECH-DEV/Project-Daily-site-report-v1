<?php
/**
 * Delivers a client share link over email and WhatsApp.
 *
 * Deliberately separate from Mailer/Whatsapp (the daily-report senders): a share
 * carries a live credential, so it gets its own subject, its own body, its own
 * approved WhatsApp template, and its own per-recipient result trail. It does
 * honour the same OFF/TEST/LIVE switches — a share must never quietly reach a
 * real client while the panel says the channel is in TEST.
 */
require_once __DIR__ . '/Smtp.php';
require_once __DIR__ . '/Whatsapp.php';

class ShareSender
{
    /** @var array */ private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function emailMode(): string
    {
        return strtoupper($this->cfg['email']['mode'] ?? 'OFF');
    }

    public function waMode(): string
    {
        return strtoupper($this->cfg['whatsapp']['mode'] ?? 'OFF');
    }

    /* ---------------- email ---------------- */

    /**
     * @param array $ctx ['label','stage','pct','ttl','name']
     * @return array ['ok'=>bool,'to'=>string,'error'=>string]
     */
    public function email(string $to, array $ctx, string $url): array
    {
        $mode = $this->emailMode();
        if ($mode === 'OFF') {
            return ['ok' => false, 'to' => $to, 'error' => 'email MODE=OFF'];
        }
        $real = $to;
        if ($mode === 'TEST') {
            $to = (string)($this->cfg['email']['test_to'] ?? '') ?: $to;
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'to' => $to, 'error' => 'invalid address'];
        }
        $subject = (string)($this->cfg['share']['subject_prefix'] ?? 'Project progress: ')
            . $ctx['label'] . ' (link valid ' . $ctx['ttl'] . ')';
        try {
            // No CC: a share link is addressed to one recipient, and CC'ing the
            // internal list would hand the same credential to more inboxes.
            (new Smtp($this->cfg['email']))->send($to, $subject, $this->html($ctx, $url));
            return ['ok' => true, 'to' => $to, 'error' => $mode === 'TEST' ? 'TEST mode — real recipient was ' . $real : ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'to' => $to, 'error' => $e->getMessage()];
        }
    }

    public function html(array $ctx, string $url): string
    {
        $label = htmlspecialchars((string)$ctx['label'], ENT_QUOTES);
        $stage = htmlspecialchars((string)($ctx['stage'] ?: 'in progress'), ENT_QUOTES);
        $pct   = htmlspecialchars((string)$ctx['pct'], ENT_QUOTES);
        $ttl   = htmlspecialchars((string)$ctx['ttl'], ENT_QUOTES);
        $link  = htmlspecialchars($url, ENT_QUOTES);

        return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222b38;line-height:1.55">
  <p>Dear Sir/Madam,</p>

  <p>Please find below the live progress link for <b>$label</b>.</p>

  <p style="margin:0 0 4px">Current stage: <b>$stage</b> &nbsp;·&nbsp; Work completed: <b>$pct</b></p>

  <table cellpadding="0" cellspacing="0" style="margin:16px 0 20px"><tr>
    <td style="background:#d71920;border-radius:8px">
      <a href="$link" style="display:inline-block;padding:13px 26px;color:#ffffff;font-weight:700;
         text-decoration:none;font-size:15px">View project progress</a>
    </td></tr>
  </table>

  <p style="margin:0 0 6px"><b>The page shows:</b></p>
  <ul style="margin:0 0 16px;padding-left:20px">
    <li>Current stage and overall completion</li>
    <li>Full stage timeline with planned and actual dates</li>
    <li>Site photos, drawings and measurement reports</li>
    <li>Target completion date and the next planned activity</li>
  </ul>

  <p style="background:#fff7e6;border-left:3px solid #e49a1f;padding:10px 12px;margin:0 0 16px;font-size:13px">
    For your security this link expires in <b>$ttl</b> and opens only your project.
    Need it again? Reply to this email and we will send a fresh link.
  </p>

  <p style="margin:0 0 4px">For any query, reply to this email or contact your project engineer.</p>

  <p style="margin:18px 0 0">
    <span style="color:#d71920;font-weight:bold;font-size:16px">Vakharia Airtech Pvt. Ltd.</span><br>
    <a href="https://www.vakhariaairtech.com/" style="color:#3160c8">www.vakhariaairtech.com</a>
  </p>
</div>
HTML;
    }

    /* ---------------- whatsapp ---------------- */

    /**
     * Sends the approved share template. The token travels as the URL button's
     * suffix, so the destination host is fixed by the template, not by us.
     *
     * @return array ['ok'=>bool,'to'=>string,'error'=>string]
     */
    public function whatsapp(string $phone, array $ctx, string $token): array
    {
        $mode = $this->waMode();
        if ($mode === 'OFF') {
            return ['ok' => false, 'to' => $phone, 'error' => 'WhatsApp MODE=OFF'];
        }
        if (empty($this->cfg['whatsapp']['token'])) {
            return ['ok' => false, 'to' => $phone, 'error' => 'no META token (config/secrets.php)'];
        }
        $wa   = new Whatsapp(null, null, $this->cfg['whatsapp']);
        $real = $phone;
        if ($mode === 'TEST') {
            $phone = (string)($this->cfg['whatsapp']['test_to'] ?? '') ?: $phone;
        }
        $to = $wa->normalizePhone($phone);
        if ($to === '') {
            return ['ok' => false, 'to' => $phone, 'error' => 'invalid number'];
        }

        $r = $wa->sendUrlButtonTemplate(
            $to,
            (string)($this->cfg['share']['wa_template'] ?? 'project_progress_link'),
            [
                (string)($ctx['name'] ?: 'Sir/Madam'),
                (string)$ctx['label'],
                (string)($ctx['stage'] ?: 'in progress'),
                (string)$ctx['pct'],
            ],
            $token,
            (string)($this->cfg['share']['wa_language'] ?? 'en')
        );
        return [
            'ok'    => !empty($r['ok']),
            'to'    => $to,
            'error' => $r['ok'] ? ($mode === 'TEST' ? 'TEST mode — real recipient was ' . $real : '') : (string)$r['error'],
        ];
    }

    /* ---------------- contacts on file ---------------- */

    /**
     * Client contacts already on record for a project, for the share dialog.
     * Developer units read the admin-maintained map (config/overrides.json);
     * General sites fall back to the Orders sheet lookups the report pipeline
     * uses. Sheet access is slow and can 403, so the caller loads this
     * asynchronously and the dialog stays usable when it comes back empty.
     */
    public function contactsFor(array $pr): array
    {
        $out = ['emails' => [], 'phones' => [], 'note' => ''];
        $dev = trim((string)($pr['developer'] ?? ''));
        $isDev = (string)($pr['client_type'] ?? '') === 'Developer';

        if ($isDev && $dev !== '') {
            foreach (($this->cfg['email']['developer_emails'] ?? []) as $name => $mail) {
                if (strcasecmp((string)$name, $dev) === 0) {
                    $out['emails'] = $this->splitEmails((string)$mail);
                }
            }
            foreach (($this->cfg['whatsapp']['developer_phones'] ?? []) as $name => $nums) {
                if (strcasecmp((string)$name, $dev) === 0) {
                    $wa = new Whatsapp(null, null, $this->cfg['whatsapp']);
                    foreach (preg_split('#[/,;&\n]#', (string)$nums) as $n) {
                        $f = $wa->normalizePhone($n);
                        if ($f !== '') { $out['phones'][] = $f; }
                    }
                }
            }
            $out['note'] = ($out['emails'] || $out['phones'])
                ? 'From the developer contact list (Settings › Client contacts).'
                : 'No contact saved for this developer yet — add one in Settings, or type one below.';
            return $out;
        }

        // General site: Orders sheet / scrape tab, via the pipeline's own resolvers.
        $name = trim((string)($pr['project_name'] ?? ''));
        try {
            require_once __DIR__ . '/Bootstrap.php';
            Bootstrap::autoload();
            $app = Bootstrap::init();
            $mailer = new Mailer($app->sheets, $this->cfg['email']);
            $mail = $mailer->resolveRecipient('General', $dev, $name);
            if ($mail !== '' && strcasecmp($mail, (string)($this->cfg['email']['fallback_to'] ?? '')) !== 0) {
                $out['emails'] = $this->splitEmails($mail);
            }
            $wa = new Whatsapp($app->sheets, $app->drive, $this->cfg['whatsapp']);
            $out['phones'] = $wa->resolvePhones('General', $dev, $name);
            $out['note'] = ($out['emails'] || $out['phones'])
                ? 'From the Orders sheet.'
                : 'Nothing found in the Orders sheet for this project — type a contact below.';
        } catch (Throwable $e) {
            $out['note'] = 'Could not reach the Orders sheet — type the contact below.';
        }
        return $out;
    }

    private function splitEmails(string $raw): array
    {
        $ok = [];
        foreach (preg_split('#[,;/\s]+#', $raw) as $part) {
            $a = trim($part, " \t\n\r\0\x0B<>\"'.");
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) {
                $ok[strtolower($a)] = $a;
            }
        }
        return array_values($ok);
    }
}
