<?php
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Whatsapp.php';

/**
 * Fires the report email + WhatsApp for one submission and records both as
 * process_log steps. Kept separate from SubmitService so it can also be driven
 * by a backfill/retry worker later. Never throws — a notification failure never
 * fails the submission.
 *
 * Mirrors the old event-driven design (submit -> scheduleReportEmail_/WhatsApp_)
 * but sends right after the PDF is ready instead of on a 2-minute trigger.
 */
class NotificationService
{
    /** @var Mailer */   private $mailer;
    /** @var Whatsapp */ private $whatsapp;
    /** @var ResponseSheet */ private $writer;

    public function __construct(Sheets $sheets, Drive $drive, array $cfg, ResponseSheet $writer)
    {
        $this->mailer   = new Mailer($sheets, $cfg['email']);
        $this->whatsapp = new Whatsapp($sheets, $drive, $cfg['whatsapp']);
        $this->writer   = $writer;
    }

    /**
     * @param array $ctx [clientType, developer, projectName, tab, row, headers,
     *                    pdfPath (local file for email attach), pdfDriveId,
     *                    pdfName]
     * @return array warnings collected (for the submit response)
     */
    public function process(Tracker $tracker, array $ctx): array
    {
        $warnings = [];
        $this->doEmail($tracker, $ctx, $warnings);
        $this->doWhatsapp($tracker, $ctx, $warnings);
        return $warnings;
    }

    private function doEmail(Tracker $tracker, array $ctx, array &$warnings): void
    {
        $log = $tracker->stepStart('email');
        try {
            if ($this->mailer->mode() === 'OFF') {
                $tracker->stepSkipped($log, 'email MODE=OFF');
                return;
            }
            $bytes = is_file($ctx['pdfPath']) ? (string)file_get_contents($ctx['pdfPath']) : '';
            if ($bytes === '') {
                $tracker->stepFailed($log, 'PDF file missing for email attachment');
                $warnings[] = 'Email not sent: PDF missing.';
                return;
            }
            $r = $this->mailer->sendReport(
                $ctx['clientType'] ?? '', $ctx['developer'] ?? '', $ctx['projectName'] ?? '',
                $bytes, $ctx['pdfName'] ?? 'SiteReport.pdf'
            );
            if ($r['sent']) {
                $tracker->stepDone($log, 'emailed', $r['to']);
                if ($this->mailer->mode() === 'LIVE') {
                    $this->stamp($ctx, 'mail status', 'SENT');
                }
            } else {
                $tracker->stepFailed($log, $r['error'] . ($r['to'] ? ' (to ' . $r['to'] . ')' : ''));
                $warnings[] = 'Email failed: ' . $r['error'];
                if ($this->mailer->mode() === 'LIVE') {
                    $this->stamp($ctx, 'mail status', 'MAIL ERROR: ' . $r['error']);
                }
            }
        } catch (Throwable $e) {
            $tracker->stepFailed($log, $e->getMessage());
            $warnings[] = 'Email error: ' . $e->getMessage();
        }
    }

    private function doWhatsapp(Tracker $tracker, array $ctx, array &$warnings): void
    {
        $log = $tracker->stepStart('whatsapp');
        try {
            if ($this->whatsapp->mode() === 'OFF') {
                $tracker->stepSkipped($log, 'whatsapp MODE=OFF');
                return;
            }
            $r = $this->whatsapp->sendReport(
                $ctx['clientType'] ?? '', $ctx['developer'] ?? '', $ctx['projectName'] ?? '',
                [
                    'drive_id' => $ctx['pdfDriveId'] ?? '',
                    'path'     => $ctx['pdfPath'] ?? '',
                    'name'     => $ctx['pdfName'] ?? 'Site Report.pdf',
                ]
            );
            $target = $r['phones'] ? implode(', ', $r['phones']) : null;
            if ($r['status'] === 'SENT' || strpos($r['status'], 'PARTIAL') === 0) {
                $tracker->stepDone($log, $r['status'] . ' — ' . $r['detail'], $target);
            } elseif ($r['status'] === 'SKIPPED') {
                $tracker->stepSkipped($log, $r['detail']);
            } else {
                $tracker->stepFailed($log, $r['detail'], $target);
                $warnings[] = 'WhatsApp: ' . $r['detail'];
            }
            // Stamp the WhatsApp Status column (LIVE/TEST) for at-a-glance status.
            if ($this->whatsapp->mode() !== 'OFF' && $r['status'] !== 'SKIPPED') {
                $this->stampWa($ctx, $r['status']);
            }
        } catch (Throwable $e) {
            $tracker->stepFailed($log, $e->getMessage());
            $warnings[] = 'WhatsApp error: ' . $e->getMessage();
        }
    }

    private function stamp(array $ctx, string $header, $value): void
    {
        try {
            $this->writer->stampCell($ctx['tab'], (int)$ctx['row'], $ctx['headers'], $header, $value);
        } catch (Throwable $e) {}
    }

    private function stampWa(array $ctx, string $value): void
    {
        try {
            $this->writer->stampOrCreateCell($ctx['tab'], (int)$ctx['row'], 'WhatsApp Status', $value);
        } catch (Throwable $e) {}
    }
}
