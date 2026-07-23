<?php
/**
 * "Send test now" for the PE Plan reminder. Renders tomorrow's real plan image
 * and sends it to the test number immediately (ignores mode + time gate), then
 * flashes the result back on the Settings page. POST + CSRF + editor only.
 */
require __DIR__ . '/inc/bootstrap.php';
Admin::requireAuth();

$back = Admin::BASE . '/settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Admin::checkCsrf()) {
    $_SESSION['pe_plan_flash'] = ['type' => 'bad', 'msg' => 'Invalid request. Refresh and try again.'];
    header('Location: ' . $back); exit;
}
Admin::requireEditor();

$cfg = Admin::cfg();

// honour a just-typed (unsaved) test number
$typed = trim((string)($_POST['pe_plan_test_to'] ?? ''));
if ($typed !== '') $cfg['pe_plan']['test_to'] = $typed;

require_once __DIR__ . '/../src/PePlanSender.php';

// which day to preview (defaults to tomorrow — the real reminder day)
$opts = ['test' => true, 'force' => true];
$td = trim((string)($_POST['pe_plan_test_date'] ?? ''));
$sentDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $td) ? $td : date('Y-m-d', strtotime('+1 day'));
$opts['date'] = $sentDate;
$dLabel = date('d M Y', strtotime($sentDate));

try {
    $sender = new PePlanSender($cfg);
    $res = $sender->run(Admin::db(), $opts);
    Admin::audit('pe_plan_test', 'whatsapp', null, '', json_encode([
        'date' => $sentDate, 'status' => $res['status'], 'to' => $res['recipients'], 'groups' => $res['groups'], 'sites' => $res['sites'],
    ]));

    $to = implode(', ', $res['recipients']) ?: '(none)';
    if ($res['status'] === 'SENT' || strpos($res['status'], 'PARTIAL') === 0) {
        $_SESSION['pe_plan_flash'] = ['type' => 'ok',
            'msg' => "Test plan for $dLabel sent to $to ({$res['groups']} engineer(s), {$res['sites']} site(s)). Check WhatsApp."];
    } else {
        $_SESSION['pe_plan_flash'] = ['type' => 'bad',
            'msg' => "Test not sent — {$res['detail']}. (Template must be APPROVED and mode reachable.)"];
    }
} catch (Throwable $e) {
    $_SESSION['pe_plan_flash'] = ['type' => 'bad', 'msg' => 'Test error: ' . $e->getMessage()];
}

header('Location: ' . $back);
exit;
