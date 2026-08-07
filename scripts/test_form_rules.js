// Drives the REAL form functions from AppJs.html and asserts the Current Status /
// Team on Site rules. No browser and no npm install: AppJs.html is loaded into a
// tiny fake DOM (formenv.js) and its own functions are called directly.
//
// Run it with any node. If node is not installed, VS Code ships one:
//   set ELECTRON_RUN_AS_NODE=1
//   "%LOCALAPPDATA%\Programs\Microsoft VS Code\Code.exe" scripts\test_form_rules.js
// Exit code 0 = everything passed.
const { loadForm } = require('./formenv.js');
const APP = require('path').join(__dirname, '..', 'AppJs.html');

let pass = 0, fail = 0;
const failures = [];
function ok(name, cond, extra) {
  if (cond) { pass++; console.log('  PASS  ' + name); }
  else { fail++; failures.push(name + (extra ? '  -> ' + extra : '')); console.log('  FAIL  ' + name + (extra ? '  -> ' + extra : '')); }
}
function group(t) { console.log('\n== ' + t + ' =='); }

// Fresh form with a sane, otherwise-valid page-1 state.
function fresh(over) {
  const s = loadForm(APP);
  s.answers = Object.assign({
    siteType: 'VRV', clientType: 'General', project: 'ACME SITE', projectSelected: true,
    engineer: 'Dada', activity: 'did work', photo: [{ name: 'a.jpg' }], amendment: 'No',
    tentativeEndDate: '2026-12-31',
    teams: [{ people: [{ name: 'Ravi', techType: 'VAPL-Technician', contractorName: '' }], workDone: ['Marking'] }],
    noTeam: false, doneSteps: [], stepStatuses: [], lockedSteps: [], hiddenSteps: [],
    tomorrowSteps: [], flats: [], activeFlat: 0,
  }, over || {});
  s.projectList = ['ACME SITE', 'OTHER SITE'];
  return s;
}
const names = ss => ss.map(e => e.step + '=' + e.status).join(',');
// Only the Current Status checklist — the step names also appear in the team
// "what work done" dropdown, so a whole-page search gives false positives.
function checklist(s) {
  const html = s.renderStep1();
  const a = html.indexOf('step-check-list');
  const b = html.indexOf('status-apply', a);
  return html.slice(a, b > a ? b : undefined);
}
// Apply one real step so the page is otherwise valid.
function applyStep(s, step, status) {
  s.toggleStep(step); s.answers.status = status || 'Done'; s.applyStepStatus();
}

/* ---------------------------------------------------------------- ITEM 1 */
group('ITEM 1 — dismantle steps are independent (no more hiding)');
{
  const s = fresh();
  const shown = () => s.STATUS_STEPS['VRV'].filter(x => !(s.answers.hiddenSteps || []).some(h => s.compact(h) === s.compact(x)));

  s.toggleStep('Main Ducting');
  s.answers.status = 'Done';
  s.applyStepStatus();
  ok('base step applied', names(s.answers.stepStatuses) === 'Main Ducting=Done', names(s.answers.stepStatuses));

  s.toggleStep('Main Ducting Dismental');
  ok('dismantle still tickable after base is Done',
     s.answers.doneSteps.some(x => x === 'Main Ducting Dismental'), JSON.stringify(s.answers.doneSteps));

  s.answers.status = 'Done';
  s.applyStepStatus();
  ok('base AND dismantle both Done in one report',
     names(s.answers.stepStatuses) === 'Main Ducting=Done,Main Ducting Dismental=Done', names(s.answers.stepStatuses));

  ok('dismantle rows still listed (nothing hidden)', shown().includes('Main Ducting Dismental'));
  ok('helpers pairEngaged/pairedStep are gone', typeof s.pairEngaged === 'undefined' && typeof s.pairedStep === 'undefined');
}
{
  const s = fresh({ hiddenSteps: ['Drain Dismental'] });
  const list = checklist(s);
  ok('"Not Required" dismantle from sheet stays hidden next visit', !list.includes('>Drain Dismental<'));
  ok('...but its base step still shows', list.includes('>Drain<'));
}

/* ---------------------------------------------------------------- ITEM 2 */
group('ITEM 2 — project must be picked from the list');
{
  const s = fresh({ project: 'TYPED BY HAND', projectSelected: false });
  applyStep(s, 'Marking');                       // everything else on the page valid
  ok('hand-typed project blocks Save & Next', s.isStep1Valid() === false);
  ok('...and says so in the missing list', s.missingFields().some(m => /Project \/ Client/.test(m)), s.missingFields().join(' | '));

  s.selectProject('ACME SITE');
  ok('picking from the dropdown unblocks it', s.isStep1Valid() === true, s.missingFields().join(' | '));

  // Typing again after a valid pick must invalidate immediately.
  s.answers.project = 'ACME SITE typo'; s.answers.projectSelected = false;
  ok('editing the text after picking re-blocks it', s.isStep1Valid() === false);

  const s2 = fresh({ project: 'GHOST SITE', projectSelected: true });
  applyStep(s2, 'Marking');
  ok('a name not in the list is rejected even if flagged selected', s2.isStep1Valid() === false);
  ok('...and the reason named is the project', s2.missingFields().some(m => /Project \/ Client/.test(m)), s2.missingFields().join(' | '));
}

/* ---------------------------------------------------------------- ITEM 4 */
group('ITEM 4 — Other Activity: Done only');
{
  const s = fresh();
  s.toggleStep('Other Activity');
  ok('ticking Other Activity pre-picks Done', s.answers.status === 'Done');
  const html = s.renderStep1();
  const opts = (html.match(/<option value="(Done|Pending|Hold|Not Required)"/g) || []);
  ok('status dropdown offers Done only', opts.length === 1 && opts[0].includes('Done'), opts.join(','));

  s.answers.status = 'Pending';
  ok('Pending cannot be applied to Other Activity', s.canApplyStatus() === false);
  s.answers.status = 'Hold'; s.answers.holdReason = 'Stuck BY Client'; s.answers.holdReasonDetail = 'x';
  ok('Hold cannot be applied to Other Activity either', s.canApplyStatus() === false);
  s.answers.status = 'Done';
  ok('Done can be applied', s.canApplyStatus() === true);
}

group('ITEM 4 — one Apply click cannot mix the two (Done-only stays honest)');
{
  const s = fresh();
  s.toggleStep('Copper Piping'); s.toggleStep('Collar');
  s.toggleStep('Other Activity');
  ok('cannot tick Other Activity into a batch of ticked steps',
     !s.answers.doneSteps.some(x => x === 'Other Activity'), JSON.stringify(s.answers.doneSteps));
  ok('Other Activity row is greyed out for this click', /step-check blocked[^]*?Other Activity/.test(checklist(s)));
}
{
  const s = fresh();
  s.toggleStep('Other Activity');
  s.toggleStep('Copper Piping');
  ok('cannot tick a real step into an Other Activity batch',
     !s.answers.doneSteps.some(x => x === 'Copper Piping'), JSON.stringify(s.answers.doneSteps));
}

group('ITEM 4 — but BOTH can live in one report (applied separately)');
{
  const s = fresh();
  applyStep(s, 'Other Activity');                 // Apply #1
  s.toggleStep('Copper Piping');
  ok('after applying Other Activity, real steps are tickable again',
     s.answers.doneSteps.some(x => x === 'Copper Piping'), JSON.stringify(s.answers.doneSteps));
  s.answers.status = 'Done'; s.applyStepStatus(); // Apply #2
  ok('report now holds both', names(s.answers.stepStatuses) === 'Other Activity=Done,Copper Piping=Done', names(s.answers.stepStatuses));

  s.answers.activity = 'Piping done. Also cleared the store room.';
  const p = s.buildReportPayload();
  ok('the real step is sent as progress', p.stepStatuses.length === 1 && p.stepStatuses[0].step === 'Copper Piping');
  ok('Other Activity still earns no step credit', !p.stepStatuses.some(e => /Other Activity/i.test(e.step)));
  ok('the note still travels', p.otherActivity === 'Yes' && p.otherActivityRemarks !== '');
  ok('report HAS real progress -> client gets it', s.hasRealProgress() === true);
}
{
  const s = fresh();
  applyStep(s, 'Copper Piping');                  // steps first this time
  s.toggleStep('Other Activity');
  ok('Other Activity can be added after real steps were applied',
     s.answers.doneSteps.some(x => x === 'Other Activity'), JSON.stringify(s.answers.doneSteps));
  ok('...and it is still forced to Done', s.answers.status === 'Done');
  s.applyStepStatus();
  ok('both applied', s.answers.stepStatuses.length === 2);
}
{
  const s = fresh();
  applyStep(s, 'Other Activity');
  ok('Other Activity alone -> no real progress -> held back', s.hasRealProgress() === false);
}
{
  const s = fresh();
  s.toggleStep('Other Activity');
  s.toggleStep('Other Activity');            // untick
  ok('unticking Other Activity clears the pre-picked status', s.answers.status === '');
  s.toggleStep('Copper Piping');
  ok('...and real steps are tickable again', s.answers.doneSteps.some(x => x === 'Copper Piping'));
}

group('ITEM 4 — Other Activity is not a step (payload)');
{
  const s = fresh();
  s.toggleStep('Other Activity'); s.answers.status = 'Done'; s.applyStepStatus();
  s.answers.activity = 'Cleaned the site store room and shifted material.';
  const p = s.buildReportPayload();
  ok('flag set', p.otherActivity === 'Yes');
  ok('remarks carry the activity notes', p.otherActivityRemarks === 'Cleaned the site store room and shifted material.');
  ok('no step statuses reach the server', Array.isArray(p.stepStatuses) && p.stepStatuses.length === 0, JSON.stringify(p.stepStatuses));
  ok('currentStatus summary is empty (legacy fallback cannot resurrect it)', p.currentStatus === '', p.currentStatus);
  ok('representative status is empty', p.status === '', p.status);
  ok('form still lets the PE continue', s.isStep1Valid() === true, s.missingFields().join(' | '));
}
{
  const s = fresh();
  s.toggleStep('Copper Piping'); s.answers.status = 'Done'; s.applyStepStatus();
  const p = s.buildReportPayload();
  ok('normal report is unaffected: flag No', p.otherActivity === 'No');
  ok('normal report keeps its steps', p.stepStatuses.length === 1 && p.stepStatuses[0].step === 'Copper Piping');
  ok('normal report keeps currentStatus', p.currentStatus === 'Copper Piping (Done)', p.currentStatus);
}

group('ITEM 4 — Other Activity never disappears / no auto-suggestion');
{
  const tickable = list => /<input type="checkbox"(?![^>]*disabled)[^>]*onchange="toggleStep\('Other Activity'\)"/.test(list);
  const s = fresh();
  ok('Other Activity is offered as a fresh tick every visit', tickable(checklist(s)));
  // Even if the sheet ever echoed it back as done/not-required, it must stay usable.
  const s1 = fresh({ lockedSteps: ['Other Activity', 'Marking'] });
  ok('a stale "locked" flag cannot take it away', tickable(checklist(s1)), checklist(s1).slice(0, 200));
  const s2 = fresh({ hiddenSteps: ['Other Activity'] });
  ok('a stale "hidden" flag cannot take it away', tickable(checklist(s2)));
  // ...while real steps still obey those flags.
  ok('a locked real step is still shown as done', /step-check locked/.test(checklist(s1)));
  ok('a hidden real step is still hidden', !checklist(fresh({ hiddenSteps: ['Marking'] })).includes('>Marking<'));
  // Applied once, it can be applied again on the NEXT visit (nothing persists it).
  const s3 = fresh();
  applyStep(s3, 'Other Activity');
  ok('after applying, it shows as assigned with a remove button', /step-check assigned[^]*?Other Activity/.test(checklist(s3)));
}
{
  const s = fresh();
  s.toggleStep('Other Activity'); s.answers.status = 'Done'; s.applyStepStatus();
  const html = s.renderStep1();
  ok('no ready-made suggestion chip for Other Activity', !/plan-chip[^>]*>Other Activity/.test(html));
  ok('but the PE is told to write it themselves', html.includes('write that activity here in your own words'));
  s.answers.activity = '';
  s.addActivityStatement('Other Activity');
  ok('addActivityStatement writes nothing for it', (s.answers.activity || '') === '', s.answers.activity);
  ok('Other Activity is not offered as tomorrow work', !s.remainingSteps().includes('Other Activity'));
}

/* ------------------------------------------------------- NO-TEAM (regression) */
group('REGRESSION — "no team working" tick still behaves');
{
  const s = fresh({ teams: [{ people: [{ name: '', techType: '', contractorName: '' }], workDone: [] }] });
  s.toggleStep('Marking'); s.answers.status = 'Done'; s.applyStepStatus();
  ok('empty team blocks Save & Next', s.isStep1Valid() === false);
  s.setNoTeam(true);
  ok('ticking "no team" unblocks it', s.isStep1Valid() === true, s.missingFields().join(' | '));
  const p = s.buildReportPayload();
  ok('no team -> no workers submitted', p.teams.length === 0 && p.people === 0);
  ok('no team -> Work Done BY says so', p.workDoneBy === 'No team on site');
}

/* ------------------------------------------------------------ EDGE CASES */
group('EDGE — a stale draft is repaired on load');
{
  const s = fresh();
  // Applied steps of both kinds are legal now and must survive untouched.
  const a = {
    stepStatuses: [{ step: 'Copper Piping', status: 'Done' }, { step: 'Other Activity', status: 'Done' }],
    doneSteps: [], status: '',
  };
  s.sanitizeOtherActivity(a);
  ok('a report holding both kinds is left alone', a.stepStatuses.length === 2, names(a.stepStatuses));

  const b = { stepStatuses: [{ step: 'Other Activity', status: 'Done' }], doneSteps: [], status: '' };
  s.sanitizeOtherActivity(b);
  ok('an Other-Activity-only draft is left alone', b.stepStatuses.length === 1);

  const c = { stepStatuses: [], doneSteps: ['Other Activity'], status: 'Hold' };
  s.sanitizeOtherActivity(c);
  ok('stale Hold on a ticked Other Activity is repaired to Done', c.status === 'Done');

  const d = { stepStatuses: [], doneSteps: ['Other Activity', 'Marking'], status: 'Done' };
  s.sanitizeOtherActivity(d);
  ok('mixed ticks are cleaned to the real steps', d.doneSteps.join(',') === 'Marking', d.doneSteps.join(','));
}

group('EDGE — multi-flat developer visit');
{
  const s = fresh({ clientType: 'Developer', developer: 'Suyog Navkar', building: 'Agam', flatNo: 'A-101' });
  applyStep(s, 'Marking');
  s.ensureFlats(); s.saveActiveFlat();
  const p1 = s.buildReportPayload();
  ok('flat with real progress is a normal, deliverable report', p1.otherActivity === 'No');

  const s2 = fresh({ clientType: 'Developer', developer: 'Suyog Navkar', building: 'Agam', flatNo: 'A-102' });
  applyStep(s2, 'Other Activity');
  s2.answers.activity = 'Site locked, could not work.';
  const p2 = s2.buildReportPayload();
  ok('flat filed as Other Activity flags itself', p2.otherActivity === 'Yes');
  ok('...and carries its own remark', p2.otherActivityRemarks === 'Site locked, could not work.');
}

group('EDGE — Other Activity does not disturb the rest of the form');
{
  const s = fresh();
  applyStep(s, 'Other Activity');
  ok('pre-commissioning box stays shut', s.hasPreCommissioningDone() === false);
  ok('every real step is still available to plan for tomorrow', s.remainingSteps().length > 5);
  ok('page 2 still needs a plan or notes', s.isStep2Valid() === false);
  s.answers.nextPlan = 'resume piping'; s.answers.drawingChange = 'No';
  ok('...and accepts one', s.isStep2Valid() === true);
}

console.log('\n──────────────────────────────');
console.log(fail === 0 ? `ALL ${pass} CHECKS PASSED` : `${pass} passed, ${fail} FAILED`);
if (fail) { failures.forEach(f => console.log('  * ' + f)); process.exitCode = 1; }
