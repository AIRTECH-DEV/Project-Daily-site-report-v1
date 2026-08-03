<?php
/**
 * Weekly PE report — "Preview mails" / "Send test now" from the Settings page.
 *
 *   action=preview : builds the real mails for the chosen week and prints them
 *                    in a new tab. SENDS NOTHING.
 *   action=send    : mails ONE sample to the test inbox, ignoring the mode and
 *                    the day/time gate, then flashes the result on Settings.
 *
 * POST + CSRF + editor only. Read-only over the tracker tables.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();

$back = Admin::BASE . '/settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Admin::checkCsrf()) {
    $_SESSION['pe_weekly_flash'] = ['type' => 'bad', 'msg' => 'Invalid request. Refresh and try again.'];
    header('Location: ' . $back); exit;
}
Admin::requireEditor();

require_once __DIR__ . '/inc/PeWeekly.php';

$cfg    = Admin::cfg();
$action = ($_POST['pe_weekly_action'] ?? 'preview') === 'send' ? 'send' : 'preview';

// honour just-typed (unsaved) values so a preview reflects what is on screen
$typedTo = trim((string)($_POST['pe_weekly_test_to'] ?? ''));
if ($typedTo !== '') $cfg['pe_weekly']['test_to'] = $typedTo;
$cfg['pe_weekly']['cc_manager'] = !empty($_POST['pe_weekly_cc']) ? 1 : 0;

$date = trim((string)($_POST['pe_weekly_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$pe   = trim((string)($_POST['pe_weekly_pe'] ?? ''));
$win  = PeWeekly::window($date);

try {
    $res = PeWeekly::run(Admin::db(), $cfg, [
        'date' => $date, 'pe' => $pe, 'force' => true,
        'dry'  => $action === 'preview', 'test' => $action === 'send',
    ]);
} catch (Throwable $e) {
    if ($action === 'preview') { http_response_code(500); echo 'Preview error: ' . Admin::e($e->getMessage()); exit; }
    $_SESSION['pe_weekly_flash'] = ['type' => 'bad', 'msg' => 'Test error: ' . $e->getMessage()];
    header('Location: ' . $back); exit;
}

/* ---------------- send ---------------- */
if ($action === 'send') {
    Admin::audit('pe_weekly_test', 'email', null, '', json_encode([
        'week' => $win['label'], 'status' => $res['status'], 'sent' => $res['sent'], 'skipped' => $res['skipped'],
    ]));
    $to = '';
    foreach ($res['reports'] as $r) { $to = $r['to']; break; }
    if ($res['sent'] > 0) {
        $_SESSION['pe_weekly_flash'] = ['type' => 'ok',
            'msg' => "Sample weekly report for {$win['label']} sent to " . ($to ?: 'the test inbox') . ". Check the inbox."];
    } else {
        $_SESSION['pe_weekly_flash'] = ['type' => 'bad',
            'msg' => "Test not sent — {$res['status']}: {$res['detail']}" . ($to === '' ? ' (set a test inbox above and save)' : '')];
    }
    header('Location: ' . $back); exit;
}

/* ---------------- preview ---------------- */
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Weekly PE report preview — ' . Admin::e($win['label']) . '</title>'
   . '<body style="margin:0;background:#e9eef6;font-family:Arial,Helvetica,sans-serif">';
echo '<div style="max-width:680px;margin:0 auto;padding:18px 16px 4px;color:#41506a;font-size:13px">'
   . '<b>Preview only — nothing was sent.</b> Week ' . Admin::e($win['label'])
   . ' · ' . count($res['reports']) . ' engineer(s)</div>';

if (!$res['reports']) {
    echo '<div style="max-width:680px;margin:0 auto;padding:16px;color:#8190a5">No engineer data for this week.</div>';
}
foreach ($res['reports'] as $name => $r) {
    echo '<div style="max-width:680px;margin:16px auto 0;padding:9px 14px;background:#fff;border:1px dashed #c3d0e2;border-radius:8px;font-size:12px;color:#5b6b82">'
       . '<b>TO:</b> ' . Admin::e($r['to'] ?: '(no email set — this PE would be skipped)')
       . ' &nbsp;·&nbsp; <b>SUBJECT:</b> ' . Admin::e($r['subject']) . '</div>';
    echo $r['html'];
}
echo '</body>';
