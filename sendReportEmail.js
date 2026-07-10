/**
 * ============================================================================
 * SITE REPORT EMAILER  (trigger-based)
 * ----------------------------------------------------------------------------
 * Emails the generated site-report PDF to the right client, the same way the
 * old sendPendingEmails() did, but wired to the CURRENT response sheet.
 *
 * HOW IT FITS THE FLOW
 *   submitSiteReport() (code.js) stamps each new row:
 *       Mail Status = "PDF GENERATED"   and   PDF ID = <drive file id>
 *   once the PDF is ready. A time-driven trigger runs sendPendingReportEmails(),
 *   which picks up those rows, emails the PDF, and marks them "SENT".
 *
 * SAFE LOCAL TESTING  ->  EMAIL_CFG.MODE decides everything:
 *   'OFF'  = send nothing at all. Use this on your local copy while developing.
 *   'TEST' = every mail goes ONLY to EMAIL_CFG.TEST_TO (yourself). Clients get
 *            nothing, CC is skipped, rows are NOT marked SENT (re-run freely).
 *   'LIVE' = real clients emailed, CC added, rows marked SENT. Production only.
 *
 * QUICK START
 *   1. Local test that mail works at all      -> run  testReportEmail()
 *   2. See who LIVE would email (no send)      -> run  previewPendingReportEmails()
 *   3. Dry-run the real loop to yourself       -> set MODE='TEST', run sendPendingReportEmails()
 *   4. Go live                                 -> set MODE='LIVE', run installReportEmailTrigger()
 *   5. Stop auto-send (e.g. on local)          -> run  removeReportEmailTrigger()
 *
 * Uses RESPONSE_SHEET_ID, TAB_NAMES, findColIndex from code.js (shared scope).
 * ============================================================================
 */

const EMAIL_CFG = {
  MODE: 'OFF',                                    // 'OFF' | 'TEST' | 'LIVE'  <-- keep 'OFF' locally
  TEST_TO: 'devops@vakhariaairtech.com',          // where TEST-mode + testReportEmail() mail lands
  FROM: 'crm@vakhariaairtech.com',                // send-as alias (LIVE only; must exist on the account)
  FROM_NAME: 'CRM Vakharia Airtech',
  CC: 'crm@vakhariaairtech.com,mis@vakhariaairtech.com,piyush@vakhariaairtech.com',
  FALLBACK_TO: 'crm@vakhariaairtech.com',         // used when no client email is found
  READY_STATUS: 'PDF GENERATED',                  // Mail Status value that means "ready to send"
  SENT_STATUS: 'SENT',

  // GENERAL client-email lookup (two tiers, matched by Project Name):
  //   1) the OCR scrape tab "VRV Scraped Data1"   <- checked FIRST
  //   2) the Orders "Client Email Id" column       <- used only if 1) misses
  // Then FALLBACK_TO if neither has it. TEST mode ignores all of this.
  SCRAPE_SS_ID: '1hPvEw0rxaOmg2JDtdp9q8XqjbrV98PZL2jiZVz6XWFI',
  SCRAPE_TAB: 'VRV Scraped Data1',
  SCRAPE_NAME_COL: 1,   // 0-based: Project Name (col B)
  SCRAPE_EMAIL_COL: 3,  // 0-based: Scraped Emails (col D)

  ORDER_SS_IDS: [
    '1HwYDM6ARcDomEqmhqBTxRe3OsAzeezTq_8Wnhsm3_eY',
    '1SV_WhGa_sEdUkj1X46xRtoCNo5KG3Khi1jRkdl9LSz0'
  ],
  ORDER_TAB: 'Orders',

  TRIGGER_MINUTES: 10,  // (legacy polling) how often the auto-send trigger fires (1,5,10,15,30)
  DELAY_MINUTES: 2      // per-submit send: fire the email this many minutes after submit
};

/**
 * Developer client emails. Developer reports carry the developer's NAME as the
 * project (not an Orders project), so their client email can't come from the
 * Orders lookup — it comes from here instead. Keyed by the "Developer" column
 * value. Leave blank to route that developer to FALLBACK_TO (crm@) for now;
 * fill the real address later (here or straight on Apps Script). Comma-separate
 * for multiple recipients.
 */
const DEVELOPER_EMAILS = {
  'Kasturi': 'devops@vakhariaairtech.com',   // TODO: replace with real Kasturi client email
  'Suyog Navkar': ''                          // TODO: Suyog Navkar client email
};

/**
 * MAIN — called by the trigger. Emails every "PDF GENERATED" row's PDF.
 * Safe to run by hand too. Honors EMAIL_CFG.MODE.
 */
function sendPendingReportEmails() {
  if (EMAIL_CFG.MODE === 'OFF') {
    Logger.log('sendPendingReportEmails: MODE=OFF — nothing sent.');
    return;
  }

  const ss = SpreadsheetApp.openById(RESPONSE_SHEET_ID);
  const scrapeMap = buildScrapeEmailMap_();
  const ordersMap = buildClientEmailMap_();
  let sent = 0, failed = 0;

  Object.keys(TAB_NAMES).forEach(function (key) {
    const sheet = ss.getSheetByName(TAB_NAMES[key]);
    if (!sheet || sheet.getLastRow() < 2) return;

    const lastCol = sheet.getLastColumn();
    const headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    const statusCol = findColIndex(headers, 'mail status');
    const pdfIdCol  = findColIndex(headers, 'pdf id');
    const projCol   = findColIndex(headers, 'select project name');
    const ctCol     = findColIndex(headers, 'client type');
    const devCol    = findColIndex(headers, 'developer');
    if (statusCol < 0 || pdfIdCol < 0 || projCol < 0) {
      Logger.log('tab "' + TAB_NAMES[key] + '": missing Mail Status / PDF ID / Project column — skipped.');
      return;
    }

    const data = sheet.getRange(2, 1, sheet.getLastRow() - 1, lastCol).getValues();
    for (let i = 0; i < data.length; i++) {
      const rowNum = i + 2;
      const status = (data[i][statusCol] || '').toString().trim().toUpperCase();
      const pdfId  = (data[i][pdfIdCol] || '').toString().trim();
      if (status !== EMAIL_CFG.READY_STATUS.toUpperCase() || !pdfId) continue;

      const projectName = (data[i][projCol] || '').toString().trim();
      const clientType  = ctCol  > -1 ? (data[i][ctCol]  || '').toString().trim() : '';
      const developer   = devCol > -1 ? (data[i][devCol] || '').toString().trim() : '';
      let to = resolveRecipient_(clientType, developer, projectName, scrapeMap, ordersMap);
      if (EMAIL_CFG.MODE === 'TEST') to = EMAIL_CFG.TEST_TO;

      try {
        const blob = DriveApp.getFileById(pdfId).getBlob();
        sendReportMail_(to, projectName, blob);
        sent++;
        Logger.log('SENT [' + EMAIL_CFG.MODE + '] row ' + rowNum + ' "' + projectName + '" -> ' + to);
        // Only LIVE mode advances the row so TEST runs can repeat safely.
        if (EMAIL_CFG.MODE === 'LIVE') {
          sheet.getRange(rowNum, statusCol + 1).setValue(EMAIL_CFG.SENT_STATUS);
        }
      } catch (err) {
        failed++;
        Logger.log('FAILED row ' + rowNum + ' "' + projectName + '": ' + err);
        if (EMAIL_CFG.MODE === 'LIVE') {
          sheet.getRange(rowNum, statusCol + 1).setValue('MAIL ERROR: ' + (err.message || err));
        }
      }
    }
  });

  Logger.log('sendPendingReportEmails done — mode=' + EMAIL_CFG.MODE + ' sent=' + sent + ' failed=' + failed);
}

/**
 * The actual Gmail send. From-alias, name and CC are applied only in LIVE mode
 * so TEST mail sends plainly from your own account (no alias needed, no CC spam).
 */
function sendReportMail_(to, projectName, pdfBlob) {
  const opts = {
    attachments: pdfBlob ? [pdfBlob] : [],
    htmlBody: buildReportHtml_(projectName)
  };
  if (EMAIL_CFG.MODE === 'LIVE') {
    opts.from = EMAIL_CFG.FROM;
    opts.name = EMAIL_CFG.FROM_NAME;
    opts.cc = EMAIL_CFG.CC;
  }
  GmailApp.sendEmail(to, 'Site Report: ' + projectName, '', opts);
}

function buildReportHtml_(projectName) {
  return "<div style='font-family:Arial,sans-serif;font-size:14px;color:#333;'>" +
    "<p>Dear Customer,</p>" +
    "<p>Please find the attached site progress report for <b>" + projectName + "</b>.</p><br>" +
    "<span style='color:red;font-weight:bold;font-size:16px;'>Vakharia Airtech Pvt. Ltd.</span><br>" +
    "<a href='https://www.vakhariaairtech.com/'>www.vakhariaairtech.com</a>" +
    "</div>";
}

/* ---------------------------------------------------------------------------
 * TESTING HELPERS — none of these email a client.
 * ------------------------------------------------------------------------- */

/**
 * TEST #1 — proves Gmail sending works. Sends ONE mail to yourself (TEST_TO),
 * attaching the newest report PDF if there is one, else a tiny text file.
 * Touches no client and no sheet cell.  Run this first.
 */
function testReportEmail() {
  const to = EMAIL_CFG.TEST_TO || Session.getActiveUser().getEmail();
  let blob = null, projectName = 'TEST PROJECT';

  const latest = findLatestReport_();
  if (latest && latest.pdfId) {
    try {
      blob = DriveApp.getFileById(latest.pdfId).getBlob();
      projectName = (latest.projectName || 'TEST PROJECT') + ' (TEST)';
    } catch (e) {
      Logger.log('testReportEmail: could not load latest PDF (' + e + ') — using dummy attachment.');
    }
  }
  if (!blob) blob = Utilities.newBlob('This is a test attachment.', 'text/plain', 'test.txt');

  GmailApp.sendEmail(to, 'TEST — Site Report: ' + projectName, '', {
    attachments: [blob],
    htmlBody: buildReportHtml_(projectName)
  });
  Logger.log('testReportEmail: test mail sent to ' + to + '. Check that inbox.');
}

/**
 * TEST #2 — DRY RUN. Logs exactly which rows LIVE mode would email and to whom,
 * WITHOUT sending anything. Use it to sanity-check the client-email lookup.
 */
function previewPendingReportEmails() {
  const ss = SpreadsheetApp.openById(RESPONSE_SHEET_ID);
  const scrapeMap = buildScrapeEmailMap_();
  const ordersMap = buildClientEmailMap_();
  let count = 0;

  Object.keys(TAB_NAMES).forEach(function (key) {
    const sheet = ss.getSheetByName(TAB_NAMES[key]);
    if (!sheet || sheet.getLastRow() < 2) return;
    const lastCol = sheet.getLastColumn();
    const headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    const statusCol = findColIndex(headers, 'mail status');
    const pdfIdCol  = findColIndex(headers, 'pdf id');
    const projCol   = findColIndex(headers, 'select project name');
    const ctCol     = findColIndex(headers, 'client type');
    const devCol    = findColIndex(headers, 'developer');
    if (statusCol < 0 || pdfIdCol < 0 || projCol < 0) return;

    const data = sheet.getRange(2, 1, sheet.getLastRow() - 1, lastCol).getValues();
    for (let i = 0; i < data.length; i++) {
      const status = (data[i][statusCol] || '').toString().trim().toUpperCase();
      const pdfId  = (data[i][pdfIdCol] || '').toString().trim();
      if (status !== EMAIL_CFG.READY_STATUS.toUpperCase() || !pdfId) continue;
      const projectName = (data[i][projCol] || '').toString().trim();
      const clientType  = ctCol  > -1 ? (data[i][ctCol]  || '').toString().trim() : '';
      const developer   = devCol > -1 ? (data[i][devCol] || '').toString().trim() : '';
      const to = resolveRecipient_(clientType, developer, projectName, scrapeMap, ordersMap);
      const devName = isDeveloperName_(developer) ? developer : (isDeveloperName_(projectName) ? projectName : '');
      count++;
      Logger.log('[' + TAB_NAMES[key] + ' row ' + (i + 2) + '] ' + (devName ? '[DEV ' + devName + '] ' : '') + '"' + projectName + '" -> ' + to + '  (pdf ' + pdfId + ')');
    }
  });
  Logger.log('previewPendingReportEmails: ' + count + ' report(s) ready to send.');
}

/* ---------------------------------------------------------------------------
 * TRIGGER MANAGEMENT
 * ------------------------------------------------------------------------- */

/**
 * Run ONCE to start auto-sending. Installs a time-driven trigger that fires
 * sendPendingReportEmails() every EMAIL_CFG.TRIGGER_MINUTES minutes.
 * Set EMAIL_CFG.MODE = 'LIVE' before relying on it.
 */
function installReportEmailTrigger() {
  removeReportEmailTrigger();
  ScriptApp.newTrigger('sendPendingReportEmails')
    .timeBased()
    .everyMinutes(EMAIL_CFG.TRIGGER_MINUTES)
    .create();
  Logger.log('Installed trigger: sendPendingReportEmails every ' + EMAIL_CFG.TRIGGER_MINUTES + ' min. MODE=' + EMAIL_CFG.MODE);
}

/**
 * Run to STOP auto-sending. Deletes the trigger — use this on your local copy
 * so nothing goes out while you develop.
 */
function removeReportEmailTrigger() {
  let n = 0;
  ScriptApp.getProjectTriggers().forEach(function (t) {
    if (t.getHandlerFunction() === 'sendPendingReportEmails') {
      ScriptApp.deleteTrigger(t);
      n++;
    }
  });
  Logger.log('Removed ' + n + ' sendPendingReportEmails trigger(s).');
}

/* ---------------------------------------------------------------------------
 * INTERNAL HELPERS
 * ------------------------------------------------------------------------- */

/**
 * Builds { projectName(lowercased) : "email[,email]" } from the OCR scrape tab
 * "VRV Scraped Data1". This is the FIRST-choice source for General reports.
 */
function buildScrapeEmailMap_() {
  const map = {};
  try {
    const sh = SpreadsheetApp.openById(EMAIL_CFG.SCRAPE_SS_ID).getSheetByName(EMAIL_CFG.SCRAPE_TAB);
    if (!sh || sh.getLastRow() < 2) {
      Logger.log('buildScrapeEmailMap_: no rows in "' + EMAIL_CFG.SCRAPE_TAB + '".');
      return map;
    }
    const rows = sh.getDataRange().getValues();
    for (let i = 1; i < rows.length; i++) {
      const name = (rows[i][EMAIL_CFG.SCRAPE_NAME_COL] || '').toString().trim().toLowerCase();
      const mail = cleanRecipients_(rows[i][EMAIL_CFG.SCRAPE_EMAIL_COL]);
      if (name && mail) map[name] = mail;
    }
  } catch (e) {
    Logger.log('buildScrapeEmailMap_ error: ' + e);
  }
  return map;
}

/**
 * Builds { projectName(lowercased) : "email[,email]" } by reading the
 * "Client Email Id" column of each Orders source sheet, keyed by Project Name.
 * Columns are located by header text (falls back to Project Name = col D).
 * This is the FALLBACK source when the scrape tab has no email for the project.
 */
function buildClientEmailMap_() {
  const map = {};
  EMAIL_CFG.ORDER_SS_IDS.forEach(function (ssId) {
    try {
      const sh = SpreadsheetApp.openById(ssId).getSheetByName(EMAIL_CFG.ORDER_TAB);
      if (!sh || sh.getLastRow() < 2) {
        Logger.log('buildClientEmailMap_: no "' + EMAIL_CFG.ORDER_TAB + '" rows in ' + ssId);
        return;
      }
      const rows = sh.getDataRange().getValues();
      const headers = rows[0];
      let nameCol = findColIndex(headers, 'project name');
      if (nameCol < 0) nameCol = 3;                       // fallback: col D
      let mailCol = findColIndex(headers, 'client email');
      if (mailCol < 0) mailCol = findColIndex(headers, 'email');
      if (mailCol < 0) {
        Logger.log('buildClientEmailMap_: no email column found in ' + ssId);
        return;
      }
      for (let i = 1; i < rows.length; i++) {
        const name = (rows[i][nameCol] || '').toString().trim().toLowerCase();
        const mail = cleanRecipients_(rows[i][mailCol]);
        if (name && mail) map[name] = mail;               // later sheet wins on duplicate name
      }
    } catch (e) {
      Logger.log('buildClientEmailMap_ error for ' + ssId + ': ' + e);
    }
  });
  return map;
}

/**
 * Picks the recipient for a row.
 * Developer rows store the developer NAME as the project, so we match either the
 * "Developer" column OR the project name against DEVELOPER_EMAILS. Matching on
 * the name means routing still works even when the "Client Type" column is
 * blank/missing on the response row. Everyone else uses the Orders lookup.
 * Anything unknown falls back to FALLBACK_TO so a mail is never lost.
 */
function resolveRecipient_(clientType, developer, projectName, scrapeMap, ordersMap) {
  // 1) Known developer (by developer column or by project name) -> its email.
  const devMail = developerEmail_(developer) || developerEmail_(projectName);
  if (devMail) return devMail;

  // 2) Marked developer but no email filled yet -> fallback (skip general lookup).
  const isDev = (clientType || '').toString().trim().toLowerCase() === 'developer'
             || isDeveloperName_(developer) || isDeveloperName_(projectName);
  if (isDev) return EMAIL_CFG.FALLBACK_TO;

  // 3) General report -> VRV Scraped Data1 first, then Orders, then fallback.
  const key = (projectName || '').toString().trim().toLowerCase();
  return (scrapeMap && scrapeMap[key]) || (ordersMap && ordersMap[key]) || EMAIL_CFG.FALLBACK_TO;
}

/** True if the name matches a key in DEVELOPER_EMAILS (case-insensitive). */
function isDeveloperName_(name) {
  const key = (name || '').toString().trim().toLowerCase();
  if (!key) return false;
  for (const dev in DEVELOPER_EMAILS) {
    if (dev.toLowerCase() === key) return true;
  }
  return false;
}

/** Looks up a developer's client email (case-insensitive), '' if none/blank. */
function developerEmail_(developer) {
  const key = (developer || '').toString().trim().toLowerCase();
  if (!key) return '';
  for (const name in DEVELOPER_EMAILS) {
    if (name.toLowerCase() === key) return cleanRecipients_(DEVELOPER_EMAILS[name]);
  }
  return '';
}

/** Keeps only real addresses from a raw cell like "a@x.com, No emails found". */
function cleanRecipients_(raw) {
  if (!raw) return '';
  return raw.toString()
    .split(/[,;\s]+/)
    .map(function (s) { return s.trim(); })
    .filter(function (s) { return s.indexOf('@') > 0 && s.indexOf('.') > s.indexOf('@'); })
    .join(',');
}

/** Newest row (by Timestamp) that has a PDF ID, across both response tabs. */
function findLatestReport_() {
  const ss = SpreadsheetApp.openById(RESPONSE_SHEET_ID);
  let best = null;
  Object.keys(TAB_NAMES).forEach(function (key) {
    const sheet = ss.getSheetByName(TAB_NAMES[key]);
    if (!sheet || sheet.getLastRow() < 2) return;
    const lastCol = sheet.getLastColumn();
    const headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    const pdfIdCol = findColIndex(headers, 'pdf id');
    const projCol  = findColIndex(headers, 'select project name');
    const tsCol    = findColIndex(headers, 'timestamp');
    if (pdfIdCol < 0) return;
    const data = sheet.getRange(2, 1, sheet.getLastRow() - 1, lastCol).getValues();
    for (let i = 0; i < data.length; i++) {
      const pdfId = (data[i][pdfIdCol] || '').toString().trim();
      if (!pdfId) continue;
      const ts = tsCol > -1 && data[i][tsCol] ? new Date(data[i][tsCol]).getTime() : i;
      if (!best || ts > best.ts) {
        best = { ts: ts, pdfId: pdfId, projectName: (projCol > -1 ? data[i][projCol] : '').toString().trim() };
      }
    }
  });
  return best;
}

/* ===========================================================================
 * PER-SUBMIT EMAIL  (same event-driven design as sendReportwhatsapp.js)
 * ---------------------------------------------------------------------------
 * Instead of the polling sendPendingReportEmails() trigger, the email now goes
 * out once, right after a report is submitted:
 *   submitSiteReport() -> scheduleReportEmail_(row, tab) -> one-shot trigger
 *   fires ~EMAIL_CFG.DELAY_MINUTES later -> runScheduledReportEmail_() ->
 *   sendReportEmailForRow_() mails that ONE row and stamps Mail Status = SENT.
 * No standing trigger, no re-sending. Honors EMAIL_CFG.MODE (OFF/TEST/LIVE).
 *
 * TO SWITCH OVER: run removeReportEmailTrigger() once (kills the old polling),
 * set EMAIL_CFG.MODE = 'LIVE', then publish a NEW web-app deployment version so
 * submitSiteReport() runs the code that calls scheduleReportEmail_().
 * ========================================================================= */

/** STEP A — called from submitSiteReport after the PDF is generated. */
function scheduleReportEmail_(rowNum, tabName) {
  if (EMAIL_CFG.MODE === 'OFF') { Logger.log('EMAIL: MODE=OFF — not scheduling.'); return; }
  try {
    const fireAt = new Date(Date.now() + (EMAIL_CFG.DELAY_MINUTES || 2) * 60 * 1000);
    const trigger = ScriptApp.newTrigger('runScheduledReportEmail_')
      .timeBased().at(fireAt).create();

    PropertiesService.getScriptProperties().setProperty(
      'EMAIL_ROW_' + trigger.getUniqueId(),
      JSON.stringify({ row: rowNum, tab: tabName })
    );
    // Move it off 'PDF GENERATED' so any leftover polling trigger won't also send.
    if (EMAIL_CFG.MODE === 'LIVE') setMailStatus_(tabName, rowNum, 'EMAIL SCHEDULED');
    Logger.log('EMAIL: scheduled row ' + rowNum + ' (' + tabName + ') at ' + fireAt);
  } catch (e) {
    Logger.log('EMAIL: scheduleReportEmail_ failed: ' + e);
  }
}

/** STEP B — the one-shot trigger fires here ~DELAY_MINUTES later. */
function runScheduledReportEmail_(e) {
  const uid    = e ? e.triggerUid : '';
  const props  = PropertiesService.getScriptProperties();
  const key    = 'EMAIL_ROW_' + uid;
  const stored = props.getProperty(key);

  if (uid) {
    ScriptApp.getProjectTriggers().forEach(function (t) {
      if (t.getUniqueId() === uid) ScriptApp.deleteTrigger(t);
    });
  }
  if (!stored) { Logger.log('EMAIL: no stored row for ' + key); return; }
  props.deleteProperty(key);

  const parsed = JSON.parse(stored);
  sendReportEmailForRow_(parsed.tab, parsed.row);
}

/** CORE — mails the PDF for ONE response row to the right client. */
function sendReportEmailForRow_(tabName, rowNum) {
  try {
    if (EMAIL_CFG.MODE === 'OFF') { Logger.log('EMAIL: MODE=OFF.'); return; }

    const sheet = SpreadsheetApp.openById(RESPONSE_SHEET_ID).getSheetByName(tabName);
    if (!sheet) { Logger.log('EMAIL: tab "' + tabName + '" not found.'); return; }

    const lastCol   = sheet.getLastColumn();
    const headers   = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    const rowData   = sheet.getRange(rowNum, 1, 1, lastCol).getValues()[0];
    const statusCol = findColIndex(headers, 'mail status');
    const pdfIdCol  = findColIndex(headers, 'pdf id');
    const projCol   = findColIndex(headers, 'select project name');
    const ctCol     = findColIndex(headers, 'client type');
    const devCol    = findColIndex(headers, 'developer');

    // PDF ID (poll briefly in case the row was just written).
    let pdfId = '';
    if (pdfIdCol > -1) {
      for (let i = 0; i < 6; i++) {                 // up to 6 x 5s = 30s
        pdfId = String(sheet.getRange(rowNum, pdfIdCol + 1).getValue() || '').trim();
        if (pdfId) break;
        Utilities.sleep(5000);
      }
    }
    if (!pdfId) {
      Logger.log('EMAIL: no PDF ID at row ' + rowNum + ' — skipping.');
      if (EMAIL_CFG.MODE === 'LIVE' && statusCol > -1) sheet.getRange(rowNum, statusCol + 1).setValue('MAIL ERROR: no PDF');
      return;
    }

    const projectName = projCol > -1 ? String(rowData[projCol] || '').trim() : '';
    const clientType  = ctCol  > -1 ? String(rowData[ctCol]  || '').trim() : '';
    const developer   = devCol > -1 ? String(rowData[devCol] || '').trim() : '';

    let to = resolveRecipient_(clientType, developer, projectName, buildScrapeEmailMap_(), buildClientEmailMap_());
    if (EMAIL_CFG.MODE === 'TEST') to = EMAIL_CFG.TEST_TO;

    try {
      const blob = DriveApp.getFileById(pdfId).getBlob();
      sendReportMail_(to, projectName, blob);
      Logger.log('EMAIL: SENT [' + EMAIL_CFG.MODE + '] row ' + rowNum + ' "' + projectName + '" -> ' + to);
      if (EMAIL_CFG.MODE === 'LIVE' && statusCol > -1) sheet.getRange(rowNum, statusCol + 1).setValue(EMAIL_CFG.SENT_STATUS);
    } catch (err) {
      Logger.log('EMAIL: FAILED row ' + rowNum + ' "' + projectName + '": ' + err);
      if (EMAIL_CFG.MODE === 'LIVE' && statusCol > -1) sheet.getRange(rowNum, statusCol + 1).setValue('MAIL ERROR: ' + (err.message || err));
    }
  } catch (err) {
    Logger.log('EMAIL: critical error row ' + rowNum + ': ' + err);
  }
}

/** Stamps the "Mail Status" column for one row (no-op if the column is absent). */
function setMailStatus_(tabName, rowNum, statusText) {
  try {
    const sheet = SpreadsheetApp.openById(RESPONSE_SHEET_ID).getSheetByName(tabName);
    if (!sheet) return;
    const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
    const col = findColIndex(headers, 'mail status');
    if (col > -1) sheet.getRange(rowNum, col + 1).setValue(statusText);
  } catch (e) {
    Logger.log('EMAIL: setMailStatus_ failed: ' + e);
  }
}
