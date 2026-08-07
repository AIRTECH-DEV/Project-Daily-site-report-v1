<?php
/**
 * Drives the REAL Pms / SubmitService logic against a FAKE spreadsheet and asserts
 * dismantle end dates, Other Activity remarks, pipeline gating and client-delivery
 * suppression. Touches no Google API, no database and no config — safe to run any time.
 *
 *   php scripts/test_progress_rules.php
 *
 * Exit code 0 = everything passed.
 */
$ROOT = dirname(__DIR__);
require_once $ROOT . '/src/Sheets.php';
require_once $ROOT . '/src/Pms.php';
require_once $ROOT . '/src/PmsDates.php';
require_once $ROOT . '/src/Drive.php';
require_once $ROOT . '/src/Tracker.php';
require_once $ROOT . '/src/ResponseSheet.php';
require_once $ROOT . '/src/Pdf.php';
require_once $ROOT . '/src/JobQueue.php';
require_once $ROOT . '/src/Orders.php';
require_once $ROOT . '/src/NotificationService.php';
require_once $ROOT . '/src/PreCommissioningPdf.php';
require_once $ROOT . '/src/SubmitService.php';
const CFG = ['timezone' => 'Asia/Kolkata'];

$pass = 0; $fail = 0; $failures = [];
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; $failures[] = $name . ($extra ? "  -> $extra" : ''); echo "  FAIL  $name" . ($extra ? "  -> $extra" : '') . "\n"; }
}
function group(string $t): void { echo "\n== $t ==\n"; }

/** Sheets stub: records every write, serves a canned grid. */
class FakeSheets extends Sheets
{
    public $writes = [];
    public $grid = [];
    public function __construct(array $grid) { $this->grid = $grid; }
    public function titleForName(string $id, string $wanted): ?string { return $wanted; }
    public function getTab(string $id, string $tab): array { return $this->grid; }
    public function setCell(string $id, string $tab, int $row, int $col, $value): void {
        $this->writes[] = ['row' => $row, 'col' => $col, 'value' => $value];
    }
    public function written(int $col) {
        foreach (array_reverse($this->writes) as $w) { if ($w['col'] === $col) { return $w['value']; } }
        return null;
    }
}

/**
 * Two header rows exactly like the sheet screenshot: one merged "Main Ducting"
 * group holding Start Date | End Date | status | Dismental End Date | Dismental Status.
 * Columns are 1-based: 1 Project Name, 2..6 Main Ducting, 7 Remarks, 8 Other Activity Remarks.
 */
function fixture(array $dataRow = null): array {
    return [
        ['Project Name', 'Main Ducting', '',         '',       '',                   '',                 'Remarks', 'Other Activity Remarks'],
        ['',             'Start Date',   'End Date', 'status', 'Dismental End Date', 'Dismental Status', '',        ''],
        $dataRow ?: ['ACME SITE', '', '', '', '', '', '', ''],
    ];
}
const C_END = 3, C_STATUS = 4, C_DIS_END = 5, C_DIS_STATUS = 6, C_REMARKS = 7, C_OA = 8;

/** Runs the private updateRow() against the fixture and returns the fake sheet. */
function stamp(array $payload, array $dataRow = null): FakeSheets {
    $grid = fixture($dataRow);
    $sheets = new FakeSheets($grid);
    $pms = new Pms($sheets, CFG);
    $ref = new ReflectionClass(Pms::class);
    $hi = $ref->getMethod('headerInfo'); $hi->setAccessible(true);
    $info = $hi->invoke($pms, $grid);
    $ur = $ref->getMethod('updateRow'); $ur->setAccessible(true);
    $ur->invoke($pms, 'SS', 'TAB', $grid, 3, $info, $payload, false);
    return $sheets;
}
function readBack(string $method, array $dataRow): array {
    $grid = fixture($dataRow);
    $sheets = new FakeSheets($grid);
    $pms = new Pms($sheets, CFG);
    $ref = new ReflectionClass(Pms::class);
    $hi = $ref->getMethod('headerInfo'); $hi->setAccessible(true);
    $m = $ref->getMethod($method); $m->setAccessible(true);
    return $m->invoke($pms, $grid, 3, $hi->invoke($pms, $grid));
}

/* ------------------------------------------------------------------ ITEM 1 */
group('ITEM 1 â€” dismantle Done stamps its OWN "Dismental End Date"');
{
    $s = stamp(['stepStatuses' => [['step' => 'Main Ducting Dismental', 'status' => 'Done']]]);
    ok('Dismental Status = Done', $s->written(C_DIS_STATUS) === 'Done', var_export($s->written(C_DIS_STATUS), true));
    ok('Dismental End Date filled', !empty($s->written(C_DIS_END)), var_export($s->written(C_DIS_END), true));
    ok('base End Date left alone', $s->written(C_END) === null, var_export($s->written(C_END), true));
    ok('base status left alone', $s->written(C_STATUS) === null, var_export($s->written(C_STATUS), true));
}
{
    $s = stamp(['stepStatuses' => [
        ['step' => 'Main Ducting',            'status' => 'Done'],
        ['step' => 'Main Ducting Dismental',  'status' => 'Done'],
    ]]);
    ok('base AND dismantle Done in one visit: base status', $s->written(C_STATUS) === 'Done');
    ok('...dismantle status', $s->written(C_DIS_STATUS) === 'Done');
    ok('...two SEPARATE dates written', !empty($s->written(C_END)) && !empty($s->written(C_DIS_END)));
}
{
    // Base already dated on an earlier visit; the dismantle must not reuse that cell.
    $s = stamp(['stepStatuses' => [['step' => 'Main Ducting Dismental', 'status' => 'Done']]],
               ['ACME SITE', '', '31-Jul-2026', 'Done', '', '', '', '']);
    ok('existing base End Date never overwritten', $s->written(C_END) === null, var_export($s->written(C_END), true));
    ok('dismantle date goes to its own column', !empty($s->written(C_DIS_END)));
}
{
    // Old tab that has NOT got the new column yet.
    $grid = [
        ['Project Name', 'Main Ducting', '',         '',       ''],
        ['',             'Start Date',   'End Date', 'status', 'Dismental Status'],
        ['ACME SITE', '', '', '', ''],
    ];
    $sheets = new FakeSheets($grid);
    $pms = new Pms($sheets, CFG);
    $ref = new ReflectionClass(Pms::class);
    $hi = $ref->getMethod('headerInfo'); $hi->setAccessible(true);
    $ur = $ref->getMethod('updateRow'); $ur->setAccessible(true);
    $ur->invoke($pms, 'SS', 'TAB', $grid, 3, $hi->invoke($pms, $grid),
        ['stepStatuses' => [['step' => 'Main Ducting Dismental', 'status' => 'Done']]], false);
    ok('tab without the new column: status still stamped', $sheets->written(5) === 'Done');
    ok('...and the base End Date is NOT hijacked', $sheets->written(3) === null, var_export($sheets->written(3), true));
}
{
    $done = readBack('readDoneSteps', ['ACME SITE', '', '', '', '04-Aug-2026', 'Done', '', '']);
    ok('dismantle Done reads back as a done step', in_array('Main Ducting Dismental', $done, true), implode('|', $done));
    ok('the Dismental End Date column is not mistaken for a step',
       !in_array('Dismental End Date', $done, true) && count($done) === 1, implode('|', $done));
}
{
    $hidden = readBack('readHiddenSteps', ['ACME SITE', '', '', '', '', 'Not Required', '', '']);
    ok('"Not Required" dismantle is remembered as hidden', in_array('Main Ducting Dismental', $hidden, true), implode('|', $hidden));
}

/* ------------------------------------------------------------------ ITEM 4 */
group('ITEM 4 â€” Other Activity remarks column');
{
    $s = stamp(['otherActivity' => 'Yes', 'otherActivityRemarks' => 'Shifted material to store.']);
    $v = (string)$s->written(C_OA);
    ok('note written to Other Activity Remarks', strpos($v, 'Shifted material to store.') !== false, $v);
    ok('note is date-stamped', (bool)preg_match('/\d/', $v), $v);
    ok('no step column touched', $s->written(C_STATUS) === null && $s->written(C_DIS_STATUS) === null);
    ok('normal Remarks column untouched', $s->written(C_REMARKS) === null, var_export($s->written(C_REMARKS), true));
}
{
    $s = stamp(['otherActivity' => 'Yes', 'otherActivityRemarks' => 'Second visit note.'],
               ['ACME SITE', '', '', '', '', '', '', '01-Aug-2026: First note.']);
    $v = (string)$s->written(C_OA);
    ok('repeat visit APPENDS, keeps the old note', strpos($v, 'First note.') !== false && strpos($v, 'Second visit note.') !== false, $v);
}
{
    $today = (new ReflectionMethod(Pms::class, 'today'));
    $today->setAccessible(true);
    $prev = $today->invoke(new Pms(new FakeSheets([]), CFG)) . ': Same note.';
    $s = stamp(['otherActivity' => 'Yes', 'otherActivityRemarks' => 'Same note.'],
               ['ACME SITE', '', '', '', '', '', '', $prev]);
    ok('re-submitting the same note does not duplicate it', $s->written(C_OA) === null, var_export($s->written(C_OA), true));
}
{
    // Tab with no Other Activity Remarks column -> falls back to Remarks.
    $grid = [
        ['Project Name', 'Main Ducting', '',         '',       '',                 'Remarks'],
        ['',             'Start Date',   'End Date', 'status', 'Dismental Status', ''],
        ['ACME SITE', '', '', '', '', ''],
    ];
    $sheets = new FakeSheets($grid);
    $pms = new Pms($sheets, CFG);
    $ref = new ReflectionClass(Pms::class);
    $hi = $ref->getMethod('headerInfo'); $hi->setAccessible(true);
    $ur = $ref->getMethod('updateRow'); $ur->setAccessible(true);
    $ur->invoke($pms, 'SS', 'TAB', $grid, 3, $hi->invoke($pms, $grid),
        ['otherActivity' => 'Yes', 'otherActivityRemarks' => 'Fallback note.'], false);
    ok('missing column falls back to Remarks', strpos((string)$sheets->written(6), 'Fallback note.') !== false, var_export($sheets->written(6), true));
}
{
    $done = readBack('readDoneSteps', ['ACME SITE', '', '', '', '', '', '', '05-Aug-2026: some note']);
    ok('Other Activity Remarks never reads back as a completed step', $done === [], implode('|', $done));
}
{
    // A report carrying BOTH steps and a note: the note goes to its own column, and the
    // step statuses still land normally.
    $s = stamp([
        'otherActivity' => 'Yes', 'otherActivityRemarks' => 'Also cleared the store room.',
        'stepStatuses' => [['step' => 'Main Ducting', 'status' => 'Done']],
    ]);
    ok('mixed report: step still stamped', $s->written(C_STATUS) === 'Done');
    ok('mixed report: note still stamped', strpos((string)$s->written(C_OA), 'store room') !== false, var_export($s->written(C_OA), true));
}
{
    // Old tab WITHOUT the column + a mixed report: the note must NOT be forced into
    // Remarks, which this visit's hold logic owns.
    $grid = [
        ['Project Name', 'Main Ducting', '',         '',       '',                 'Remarks'],
        ['',             'Start Date',   'End Date', 'status', 'Dismental Status', ''],
        ['ACME SITE', '', '', '', '', ''],
    ];
    $sheets = new FakeSheets($grid);
    $pms = new Pms($sheets, CFG);
    $ref = new ReflectionClass(Pms::class);
    $hi = $ref->getMethod('headerInfo'); $hi->setAccessible(true);
    $ur = $ref->getMethod('updateRow'); $ur->setAccessible(true);
    $ur->invoke($pms, 'SS', 'TAB', $grid, 3, $hi->invoke($pms, $grid), [
        'otherActivity' => 'Yes', 'otherActivityRemarks' => 'Note.',
        'stepStatuses' => [['step' => 'Main Ducting', 'status' => 'Done']],
    ], false);
    ok('no column + mixed report: hold Remarks not clobbered by the note',
       strpos((string)$sheets->written(6), 'Note.') === false, var_export($sheets->written(6), true));
}
{
    $s = stamp(['otherActivity' => 'No', 'otherActivityRemarks' => 'ignored',
                'stepStatuses' => [['step' => 'Main Ducting', 'status' => 'Done']]]);
    ok('normal report writes no Other Activity remark', $s->written(C_OA) === null, var_export($s->written(C_OA), true));
}

/* ------------------------------------------------- ITEM 3 + delivery gating */
group('ITEM 3 / ITEM 4 â€” pipeline gating and client delivery');
{
    $ref = new ReflectionClass(SubmitService::class);
    $pre = $ref->getMethod('preCommissioningExpected'); $pre->setAccessible(true);
    $oth = $ref->getMethod('holdFromClient');           $oth->setAccessible(true);
    $svc = $ref->newInstanceWithoutConstructor();

    ok('plain visit -> no pre-commissioning row at all',
       $pre->invoke($svc, [['stepStatuses' => [['step' => 'Marking', 'status' => 'Done']]]]) === false);
    ok('Pre-Commissioning Done -> tracked',
       $pre->invoke($svc, [['stepStatuses' => [['step' => 'Pre-Commissining', 'status' => 'Done']]]]) === true);
    ok('Pre-Commissioning only PENDING -> still no row',
       $pre->invoke($svc, [['stepStatuses' => [['step' => 'Pre-Commissining', 'status' => 'Pending']]]]) === false);
    ok('report already attached -> tracked even without the step',
       $pre->invoke($svc, [['preCommissioningFile' => ['base64' => 'x']]]) === true);
    ok('one flat of many reaching Pre-Commissioning -> tracked',
       $pre->invoke($svc, [['stepStatuses' => []], ['stepStatuses' => [['step' => 'Pre-Commissining', 'status' => 'Done']]]]) === true);

    $steps = [['step' => 'Copper Piping', 'status' => 'Done']];

    ok('normal visit is delivered to the client', $oth->invoke($svc, ['otherActivity' => 'No']) === false);
    ok('legacy payload (no flag) is delivered', $oth->invoke($svc, []) === false);
    ok('other activity ONLY -> held back', $oth->invoke($svc, ['otherActivity' => 'Yes', 'stepStatuses' => []]) === true);
    ok('other activity + a real step -> DELIVERED',
       $oth->invoke($svc, ['otherActivity' => 'Yes', 'stepStatuses' => $steps]) === false);
    ok('MIXED developer visit still goes to the client',
       $oth->invoke($svc, ['flats' => [
           ['otherActivity' => 'No',  'stepStatuses' => $steps],
           ['otherActivity' => 'Yes', 'stepStatuses' => []],
       ]]) === false);
    ok('developer visit where EVERY flat is other-activity-only is held back',
       $oth->invoke($svc, ['flats' => [
           ['otherActivity' => 'Yes', 'stepStatuses' => []],
           ['otherActivity' => 'Yes', 'stepStatuses' => []],
       ]]) === true);
    ok('a flat with other activity AND steps keeps the visit deliverable',
       $oth->invoke($svc, ['flats' => [['otherActivity' => 'Yes', 'stepStatuses' => $steps]]]) === false);
    ok('developer visit with no Other Activity at all is delivered',
       $oth->invoke($svc, ['flats' => [['otherActivity' => 'No'], ['otherActivity' => 'No']]]) === false);
}

group('EDGE - Other Activity flats are dropped from the client PDF');
{
    $ref = new ReflectionClass(SubmitService::class);
    $svc = $ref->newInstanceWithoutConstructor();
    $isOther = $ref->getMethod('isOtherActivityOnly'); $isOther->setAccessible(true);
    $steps = [['step' => 'Copper Piping', 'status' => 'Done']];

    // Mirrors buildMultiPdf(): keys are PRESERVED so $rowsInfo[$ri] still lines up.
    $reports = [
        0 => ['flatNo' => 'A-101', 'otherActivity' => 'No',  'stepStatuses' => $steps],
        1 => ['flatNo' => 'A-102', 'otherActivity' => 'Yes', 'stepStatuses' => []],
        2 => ['flatNo' => 'A-103', 'otherActivity' => 'Yes', 'stepStatuses' => $steps],
    ];
    $forClient = array_filter($reports, fn($r) => !$isOther->invoke($svc, $r));
    ok('the idle flat is dropped from the PDF', count($forClient) === 2);
    ok('...a flat with other activity AND steps is KEPT',
       implode(',', array_column($forClient, 'flatNo')) === 'A-101,A-103', implode(',', array_column($forClient, 'flatNo')));
    ok('row indexes stay aligned with rowsInfo', array_keys($forClient) === [0, 2], implode(',', array_keys($forClient)));

    $allOther = [['otherActivity' => 'Yes', 'stepStatuses' => []], ['otherActivity' => 'Yes', 'stepStatuses' => []]];
    $f2 = array_filter($allOther, fn($r) => !$isOther->invoke($svc, $r));
    ok('all-idle visit falls back to every flat (PDF never empty)', ($f2 ?: $allOther) === $allOther);
}

group('EDGE - dismantle START date never steals the base step');
{
    // Tab carrying BOTH dismantle date columns: "end" must not resolve to "start".
    $grid = [
        ['Project Name', 'Main Ducting', '',         '',       '',                     '',                   ''],
        ['',             'Start Date',   'End Date', 'status', 'Dismental Start Date', 'Dismental End Date', 'Dismental Status'],
        ['ACME SITE', '', '', '', '', '', ''],
    ];
    $sheets = new FakeSheets($grid);
    $pms = new Pms($sheets, CFG);
    $ref = new ReflectionClass(Pms::class);
    $hi = $ref->getMethod('headerInfo'); $hi->setAccessible(true);
    $ur = $ref->getMethod('updateRow'); $ur->setAccessible(true);
    $ur->invoke($pms, 'SS', 'TAB', $grid, 3, $hi->invoke($pms, $grid), [
        'stepStatuses'      => [['step' => 'Main Ducting Dismental', 'status' => 'Done']],
        'tomorrowSteps'     => ['Main Ducting Dismental'],
        'nextStepStartDate' => '2026-08-20',
    ], false);
    ok('Done date lands in Dismental END, not Dismental START',
       !empty($sheets->written(6)), 'end=' . var_export($sheets->written(6), true));
    ok('planned dismantle uses the Dismental START column',
       !empty($sheets->written(5)), 'start=' . var_export($sheets->written(5), true));
    ok('base Start Date untouched by the dismantle', $sheets->written(2) === null, var_export($sheets->written(2), true));
}
{
    // No "Dismental Start Date" column -> the BASE step's Start Date must stay empty.
    $s = stamp(['stepStatuses' => [], 'tomorrowSteps' => ['Main Ducting Dismental'], 'nextStepStartDate' => '2026-08-20']);
    ok('planned dismantle does not stamp the base Start Date', $s->written(2) === null, var_export($s->written(2), true));
}
{
    $s = stamp(['stepStatuses' => [], 'tomorrowSteps' => ['Main Ducting'], 'nextStepStartDate' => '2026-08-20']);
    ok('planning the BASE step still stamps its Start Date', !empty($s->written(2)), var_export($s->written(2), true));
}

echo "\nâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";
echo $fail === 0 ? "ALL $pass CHECKS PASSED\n" : "$pass passed, $fail FAILED\n";
foreach ($failures as $f) { echo "  * $f\n"; }
exit($fail === 0 ? 0 : 1);


