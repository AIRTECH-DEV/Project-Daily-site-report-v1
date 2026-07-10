/**
 * ============================================================================
 * SITE REPORT WHATSAPP SENDER  (trigger-based)
 * ----------------------------------------------------------------------------
 * WhatsApps the generated site-report link to the right client numbers, the
 * same way sendReportEmail.js mails the PDF — but over the "daily_site_updates"
 * WhatsApp template instead of Gmail.
 *
 * HOW IT FITS THE FLOW
 *   submitSiteReport() (code.js) writes the row, generates the PDF, stamps
 *       PDF ID = <drive file id>   and   Mail Status = "PDF GENERATED"
 *   then calls scheduleReportWhatsApp_(row, tab). ~DELAY_MINUTES later the
 *   one-shot trigger runScheduledReportWhatsApp_() fires, reads the row, looks
 *   up the client phone numbers, and sends the report link to every number.
 *   Result is stamped in a "WhatsApp Status" column (auto-created if missing).
 *
 *   sendPendingReportWhatsApp() is a manual/interval BACKFILL that catches any
 *   row that has a PDF ID but no WhatsApp Status yet (e.g. a missed trigger).
 *
 * WHERE THE NUMBERS COME FROM  (matched by Project Name)
 *   Non-VRV Orders  1hvqgSI3f05d1wSoQVxaBPDzr4maHhTz5MLqhmN4a5Is  ->
 *       "Phone Number for Payment Followup" + "Client Site engineer Phone Number"
 *   VRV Orders      1SV_WhGa_sEdUkj1X46xRtoCNo5KG3Khi1jRkdl9LSz0  ->
 *       "Owner / End user Phone Number" + "Client Purchase / Site engineer
 *        Phone Number" + "Client Alternate Phone Number"
 *   Every column whose header contains "phone" is picked up, so all of the
 *   fields above are covered. Multi-number cells ("911.. / 985..") are split,
 *   junk / names / "NA" are dropped, duplicates removed.
 *   DEVELOPER reports carry the developer NAME as the project, so their numbers
 *   come from DEVELOPER_PHONES below (fill multiple numbers there).
 *
 * ONE REPORT = ONE SEND. A row is messaged only when it has a PDF but a BLANK
 * WhatsApp Status. As soon as it is picked up it is stamped (QUEUED -> SENT),
 * so nothing ever re-sends. The interval "backfill" only exists to catch a row
 * whose per-submit trigger was missed; it does NOT re-send handled rows. So a
 * report goes out once, right after it is submitted, and never again until the
 * next report is filled.
 *
 * SAFE LOCAL TESTING  ->  WA_CFG.MODE decides everything:
 *   'OFF'  = send nothing, schedule nothing. Keep this on your local copy.
 *   'TEST' = every message goes ONLY to WA_CFG.TEST_TO; row IS stamped (so it
 *            won't loop). For repeatable testing use testReportWhatsApp() — it
 *            sends to TEST_TO without touching any row.
 *   'LIVE' = real client numbers messaged, rows stamped SENT/FAILED. Prod only.
 *
 * QUICK START
 *   1. Put the token in Script Properties:  key META_ACCESS_TOKEN = <token>
 *      (File > Project Settings > Script Properties). Or it falls back to a
 *      global META_ACCESS_TOKEN const if one already exists in the project.
 *   2. Prove sending works ->  set MODE='TEST', TEST_TO=<your number>, run
 *      testReportWhatsApp(). Check WhatsApp on that number.
 *   3. See who LIVE would message (no send) ->  run previewPendingReportWhatsApp()
 *   4. Go live ->  set MODE='LIVE'. Per-submit send already fires from
 *      submitSiteReport(); optionally run installReportWhatsAppTrigger() so the
 *      backfill also runs every 5 min.
 *   5. Stop auto-send (local) ->  set MODE='OFF' and run removeReportWhatsAppTrigger()
 *
 * Uses RESPONSE_SHEET_ID, TAB_NAMES, findColIndex, findLatestReport_ (shared scope).
 * ============================================================================
 */

const WA_CFG = {
  MODE: 'OFF',                       // 'OFF' | 'TEST' | 'LIVE'  <-- keep 'OFF' locally
  TEST_TO: '',                       // TEST-mode target, e.g. '919876543210'

  PHONE_NUMBER_ID: '1002193126304358',
  TEMPLATE_NAME: 'daily_site_updates',
  LANGUAGE_CODE: 'en',               // template shows "English" = en. If send fails
                                     // with error 132001, switch to 'en_US'.
  GRAPH_VERSION: 'v21.0',
  USE_NAMED_PARAMS: true,            // template var is named "report_link".
                                     // If template is positional {{1}}, set false.

  DELAY_MINUTES: 2,                  // fire WhatsApp this many minutes after submit
  MAKE_PDF_VIEWABLE: true,           // share PDF "anyone with link, viewer" so the
                                     // client number can actually open the report

  STATUS_COL_NAME: 'WhatsApp Status',// column stamped with the result (auto-created)
  SENT: 'SENT', FAILED: 'FAILED', QUEUED: 'QUEUED',

  // Phone lookup — both Orders sheets, tab "Orders", keyed by Project Name.
  ORDER_SS_IDS: [
    '1hvqgSI3f05d1wSoQVxaBPDzr4maHhTz5MLqhmN4a5Is',   // Non-VRV Orders
    '1SV_WhGa_sEdUkj1X46xRtoCNo5KG3Khi1jRkdl9LSz0'    // VRV Orders
  ],
  ORDER_TAB: 'Orders',

  FALLBACK_PHONES: '',   // used if no client number found; blank = skip (no send)
  BACKFILL_MINUTES: 5    // interval for the optional backfill trigger
};

/**
 * Developer report client numbers. Developer reports store the developer NAME as
 * the project, so their numbers can't come from the Orders lookup — put them
 * here. Keyed by the "Developer" column value. Comma or "/"-separate for
 * multiple numbers. Leave '' to fall back to WA_CFG.FALLBACK_PHONES.
 */
const DEVELOPER_PHONES = {
  'Kasturi': '',        // TODO: Kasturi client number(s), e.g. '9812345678, 9898989898'
  'Suyog Navkar': ''    // TODO: Suyog Navkar client number(s)
};

/* ===========================================================================
 * STEP A — schedule (called from submitSiteReport after the PDF is generated)
 * ========================================================================= */
function scheduleReportWhatsApp_(rowNum, tabName) {
  if (WA_CFG.MODE === 'OFF') { Logger.log('WA: MODE=OFF — not scheduling.'); return; }
  try {
    const fireAt = new Date(Date.now() + WA_CFG.DELAY_MINUTES * 60 * 1000);
    const trigger = ScriptApp.newTrigger('runScheduledReportWhatsApp_')
      .timeBased().at(fireAt).create();

    PropertiesService.getScriptProperties().setProperty(
      'WA_ROW_' + trigger.getUniqueId(),
      JSON.stringify({ row: rowNum, tab: tabName })
    );
    // Mark QUEUED immediately so nothing ever re-sends a row that's already handled.
    markWhatsAppStatus_(tabName, rowNum, WA_CFG.QUEUED);
    Logger.log('WA: scheduled row ' + rowNum + ' (' + tabName + ') at ' + fireAt);
  } catch (e) {
    Logger.log('WA: scheduleReportWhatsApp_ failed: ' + e);
  }
}

/* ===========================================================================
 * STEP B — the one-shot trigger fires here ~DELAY_MINUTES later
 * ========================================================================= */
function runScheduledReportWhatsApp_(e) {
  const uid   = e ? e.triggerUid : '';
  const props = PropertiesService.getScriptProperties();
  const key   = 'WA_ROW_' + uid;
  const stored = props.getProperty(key);

  // Always clean up this one-shot trigger + its stored property.
  if (uid) {
    ScriptApp.getProjectTriggers().forEach(function (t) {
      if (t.getUniqueId() === uid) ScriptApp.deleteTrigger(t);
    });
  }
  if (!stored) { Logger.log('WA: no stored row for ' + key); return; }
  props.deleteProperty(key);

  const parsed = JSON.parse(stored);
  sendReportWhatsAppForRow_(parsed.tab, parsed.row);
}

/* ===========================================================================
 * CORE — send the report link to every client number for one response row
 * ========================================================================= */
function sendReportWhatsAppForRow_(tabName, rowNum) {
  try {
    if (WA_CFG.MODE === 'OFF') { Logger.log('WA: MODE=OFF.'); return; }

    const sheet = SpreadsheetApp.openById(RESPONSE_SHEET_ID).getSheetByName(tabName);
    if (!sheet) { Logger.log('WA: tab "' + tabName + '" not found.'); return; }

    const lastCol  = sheet.getLastColumn();
    const headers  = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    const rowData  = sheet.getRange(rowNum, 1, 1, lastCol).getValues()[0];

    const projCol  = findColIndex(headers, 'select project name');
    const ctCol    = findColIndex(headers, 'client type');
    const devCol   = findColIndex(headers, 'developer');
    const pdfIdCol = findColIndex(headers, 'pdf id');

    const projectName = projCol > -1 ? String(rowData[projCol] || '').trim() : '';
    const clientType  = ctCol  > -1 ? String(rowData[ctCol]  || '').trim() : '';
    const developer   = devCol > -1 ? String(rowData[devCol] || '').trim() : '';

    // 1) Report link (from PDF ID). Poll briefly in case the row was just written.
    const pdfId = pollPdfId_(sheet, pdfIdCol, rowNum);
    if (!pdfId) {
      Logger.log('WA: no PDF ID at row ' + rowNum + ' — skipping.');
      markWhatsAppStatus_(tabName, rowNum, WA_CFG.FAILED + ': no PDF');
      return;
    }
    const reportLink = getReportLink_(pdfId);
    if (!reportLink) {
      markWhatsAppStatus_(tabName, rowNum, WA_CFG.FAILED + ': no link');
      return;
    }

    // 2) Client numbers.
    let phones = (WA_CFG.MODE === 'TEST')
      ? [formatPhone_(WA_CFG.TEST_TO)].filter(Boolean)
      : resolvePhones_(clientType, developer, projectName);

    if (!phones.length) {
      Logger.log('WA: no numbers for "' + projectName + '" (clientType=' + clientType + ') — skipping.');
      markWhatsAppStatus_(tabName, rowNum, WA_CFG.FAILED + ': no phone');
      return;
    }

    // 3) Send to each number.
    let ok = 0, bad = 0;
    phones.forEach(function (ph) {
      const r = sendWhatsAppTemplate_(ph, reportLink);
      if (r.ok) { ok++; Logger.log('WA: sent ' + ph + ' id=' + r.id); }
      else      { bad++; Logger.log('WA: FAILED ' + ph + ' — ' + r.error); }
    });

    const status = bad === 0 ? WA_CFG.SENT
                 : (ok > 0 ? 'PARTIAL (' + ok + '/' + (ok + bad) + ')' : WA_CFG.FAILED);
    // Stamp in every mode (not just LIVE) so a sent row is NEVER sent again.
    markWhatsAppStatus_(tabName, rowNum, status);

    Logger.log('WA: row ' + rowNum + ' "' + projectName + '" [' + WA_CFG.MODE + '] sent=' +
               ok + ' failed=' + bad + ' -> ' + phones.join(', ') + ' | ' + reportLink);
  } catch (err) {
    Logger.log('WA: critical error row ' + rowNum + ': ' + err);
  }
}

/* ===========================================================================
 * BACKFILL — catch rows with a PDF but no WhatsApp Status yet
 * Safe to run by hand or on a trigger; skips SENT / QUEUED / PARTIAL rows.
 * ========================================================================= */
function sendPendingReportWhatsApp() {
  if (WA_CFG.MODE === 'OFF') { Logger.log('WA: MODE=OFF — backfill skipped.'); return; }
  const ss = SpreadsheetApp.openById(RESPONSE_SHEET_ID);
  let handled = 0;

  Object.keys(TAB_NAMES).forEach(function (key) {
    const tabName = TAB_NAMES[key];
    const sheet = ss.getSheetByName(tabName);
    if (!sheet || sheet.getLastRow() < 2) return;

    const lastCol  = sheet.getLastColumn();
    const headers  = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    const pdfIdCol = findColIndex(headers, 'pdf id');
    const waCol    = findColIndex(headers, WA_CFG.STATUS_COL_NAME);
    if (pdfIdCol < 0) return;

    const data = sheet.getRange(2, 1, sheet.getLastRow() - 1, lastCol).getValues();
    for (let i = 0; i < data.length; i++) {
      const rowNum = i + 2;
      const pdfId  = String(data[i][pdfIdCol] || '').trim();
      if (!pdfId) continue;
      // Any non-blank status (QUEUED/SENT/FAILED/PARTIAL) = already handled -> never re-send.
      // Only a brand-new row (PDF present, status still blank) gets sent, exactly once.
      const waStatus = waCol > -1 ? String(data[i][waCol] || '').trim() : '';
      if (waStatus) continue;
      sendReportWhatsAppForRow_(tabName, rowNum);
      handled++;
    }
  });
  Logger.log('WA: backfill done — ' + handled + ' row(s) processed.');
}

/* ===========================================================================
 * TESTING HELPERS
 * ========================================================================= */

/** Sends ONE test message to WA_CFG.TEST_TO using the newest report link. */
function testReportWhatsApp() {
  const to = formatPhone_(WA_CFG.TEST_TO);
  if (!to) { Logger.log('WA test: set WA_CFG.TEST_TO to a valid number first.'); return; }

  let link = 'https://www.vakhariaairtech.com/';
  try {
    const latest = (typeof findLatestReport_ === 'function') ? findLatestReport_() : null;
    if (latest && latest.pdfId) {
      const l = getReportLink_(latest.pdfId);
      if (l) link = l;
    }
  } catch (e) { Logger.log('WA test: could not load latest report (' + e + ') — using site link.'); }

  const r = sendWhatsAppTemplate_(to, link);
  Logger.log('WA test -> ' + to + ' : ' + (r.ok ? 'SENT id=' + r.id : 'FAILED ' + r.error) + ' | link=' + link);
}

/** DRY RUN — logs which rows LIVE would message and to whom. Sends nothing. */
function previewPendingReportWhatsApp() {
  const ss = SpreadsheetApp.openById(RESPONSE_SHEET_ID);
  let count = 0;

  Object.keys(TAB_NAMES).forEach(function (key) {
    const tabName = TAB_NAMES[key];
    const sheet = ss.getSheetByName(tabName);
    if (!sheet || sheet.getLastRow() < 2) return;

    const lastCol  = sheet.getLastColumn();
    const headers  = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    const projCol  = findColIndex(headers, 'select project name');
    const ctCol    = findColIndex(headers, 'client type');
    const devCol   = findColIndex(headers, 'developer');
    const pdfIdCol = findColIndex(headers, 'pdf id');
    const waCol    = findColIndex(headers, WA_CFG.STATUS_COL_NAME);
    if (pdfIdCol < 0) return;

    const data = sheet.getRange(2, 1, sheet.getLastRow() - 1, lastCol).getValues();
    for (let i = 0; i < data.length; i++) {
      const pdfId = String(data[i][pdfIdCol] || '').trim();
      if (!pdfId) continue;
      const waStatus = waCol > -1 ? String(data[i][waCol] || '').trim() : '';
      if (waStatus) continue;   // already handled — never re-send

      const projectName = projCol > -1 ? String(data[i][projCol] || '').trim() : '';
      const clientType  = ctCol  > -1 ? String(data[i][ctCol]  || '').trim() : '';
      const developer   = devCol > -1 ? String(data[i][devCol] || '').trim() : '';
      const phones = resolvePhones_(clientType, developer, projectName);
      count++;
      Logger.log('[' + tabName + ' row ' + (i + 2) + '] "' + projectName + '" (' +
                 (clientType || 'General') + ') -> ' + (phones.join(', ') || '(no number)'));
    }
  });
  Logger.log('previewPendingReportWhatsApp: ' + count + ' report(s) ready to WhatsApp.');
}

/* ===========================================================================
 * BACKFILL TRIGGER MANAGEMENT (optional — per-submit send already runs)
 * ========================================================================= */
function installReportWhatsAppTrigger() {
  removeReportWhatsAppTrigger();
  ScriptApp.newTrigger('sendPendingReportWhatsApp')
    .timeBased().everyMinutes(WA_CFG.BACKFILL_MINUTES).create();
  Logger.log('WA: installed backfill trigger every ' + WA_CFG.BACKFILL_MINUTES + ' min. MODE=' + WA_CFG.MODE);
}

function removeReportWhatsAppTrigger() {
  let n = 0;
  ScriptApp.getProjectTriggers().forEach(function (t) {
    if (t.getHandlerFunction() === 'sendPendingReportWhatsApp') { ScriptApp.deleteTrigger(t); n++; }
  });
  Logger.log('WA: removed ' + n + ' backfill trigger(s).');
}

/* ===========================================================================
 * INTERNAL HELPERS
 * ========================================================================= */

/** Meta token: Script Property first, then a global const if one exists. */
function getMetaToken_() {
  const p = PropertiesService.getScriptProperties().getProperty('META_ACCESS_TOKEN');
  if (p) return p;
  if (typeof META_ACCESS_TOKEN !== 'undefined' && META_ACCESS_TOKEN) return META_ACCESS_TOKEN;
  return '';
}

/** POSTs the daily_site_updates template. Returns {ok, id} or {ok:false, error}. */
function sendWhatsAppTemplate_(toPhone, reportLink) {
  const token = getMetaToken_();
  if (!token) return { ok: false, error: 'no META token (set Script Property META_ACCESS_TOKEN)' };
  if (!toPhone) return { ok: false, error: 'empty phone' };

  const bodyParam = WA_CFG.USE_NAMED_PARAMS
    ? { type: 'text', parameter_name: 'report_link', text: String(reportLink) }
    : { type: 'text', text: String(reportLink) };

  const payload = {
    messaging_product: 'whatsapp',
    to: toPhone,
    type: 'template',
    template: {
      name: WA_CFG.TEMPLATE_NAME,
      language: { code: WA_CFG.LANGUAGE_CODE },
      components: [{ type: 'body', parameters: [bodyParam] }]
    }
  };

  const options = {
    method: 'post',
    contentType: 'application/json',
    headers: { Authorization: 'Bearer ' + token },
    payload: JSON.stringify(payload),
    muteHttpExceptions: true
  };

  try {
    const resp = UrlFetchApp.fetch(
      'https://graph.facebook.com/' + WA_CFG.GRAPH_VERSION + '/' + WA_CFG.PHONE_NUMBER_ID + '/messages',
      options
    );
    const code   = resp.getResponseCode();
    const result = JSON.parse(resp.getContentText() || '{}');
    if (result.error) return { ok: false, error: result.error.code + ' - ' + result.error.message };
    if (code >= 200 && code < 300 && result.messages) return { ok: true, id: result.messages[0].id };
    return { ok: false, error: 'HTTP ' + code + ' ' + resp.getContentText() };
  } catch (e) {
    return { ok: false, error: String(e) };
  }
}

/** Reads PDF ID, retrying a few times in case the row was just written. */
function pollPdfId_(sheet, pdfIdCol, rowNum) {
  if (pdfIdCol < 0) return '';
  for (let i = 0; i < 6; i++) {                 // 6 x 5s = up to 30s
    const v = String(sheet.getRange(rowNum, pdfIdCol + 1).getValue() || '').trim();
    if (v) return v;
    Utilities.sleep(5000);
  }
  return '';
}

/** Drive view URL for the PDF; makes it link-viewable so the client can open it. */
function getReportLink_(pdfId) {
  try {
    const file = DriveApp.getFileById(pdfId);
    if (WA_CFG.MAKE_PDF_VIEWABLE) {
      try { file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW); }
      catch (e) { Logger.log('WA: setSharing failed for ' + pdfId + ': ' + e); }
    }
    return file.getUrl();
  } catch (e) {
    Logger.log('WA: getReportLink_ failed for ' + pdfId + ': ' + e);
    return '';
  }
}

/** Picks the client numbers for a row (developer map, else Orders lookup, else fallback). */
function resolvePhones_(clientType, developer, projectName) {
  // 1) Known developer (by developer column OR by project name) -> its numbers.
  const devPhones = developerPhones_(developer).length ? developerPhones_(developer)
                                                       : developerPhones_(projectName);
  if (devPhones.length) return devPhones;

  // 2) Marked developer but no number filled yet -> fallback (skip Orders lookup).
  const isDev = String(clientType || '').toLowerCase() === 'developer'
             || isDeveloperName_(developer) || isDeveloperName_(projectName);
  if (isDev) return splitPhones_(WA_CFG.FALLBACK_PHONES);

  // 3) General report -> Orders phone map by project name, else fallback.
  const map = buildPhoneMap_();
  const found = map[String(projectName || '').trim().toLowerCase()] || [];
  return found.length ? found : splitPhones_(WA_CFG.FALLBACK_PHONES);
}

/**
 * Builds { projectName(lowercased) : [phone, ...] } from both Orders sheets.
 * Every column whose header contains "phone" is collected, so all the required
 * fields (payment-followup, site-engineer, owner, purchase, alternate) are covered.
 */
function buildPhoneMap_() {
  const map = {};
  WA_CFG.ORDER_SS_IDS.forEach(function (ssId) {
    try {
      const sh = SpreadsheetApp.openById(ssId).getSheetByName(WA_CFG.ORDER_TAB);
      if (!sh || sh.getLastRow() < 2) { Logger.log('WA: no "' + WA_CFG.ORDER_TAB + '" rows in ' + ssId); return; }

      const rows    = sh.getDataRange().getValues();
      const headers = rows[0];
      let nameCol = findColIndex(headers, 'project name');
      if (nameCol < 0) nameCol = 3;                     // fallback: col D
      const phoneCols = [];
      headers.forEach(function (h, i) {
        if (String(h || '').toLowerCase().indexOf('phone') !== -1) phoneCols.push(i);
      });
      if (!phoneCols.length) { Logger.log('WA: no phone columns in ' + ssId); return; }

      for (let r = 1; r < rows.length; r++) {
        const key = String(rows[r][nameCol] || '').trim().toLowerCase();
        if (!key) continue;
        let nums = [];
        phoneCols.forEach(function (c) { nums = nums.concat(splitPhones_(rows[r][c])); });
        nums = dedupe_(nums);
        if (nums.length) map[key] = dedupe_((map[key] || []).concat(nums));   // merge across sheets
      }
    } catch (e) {
      Logger.log('WA: buildPhoneMap_ error for ' + ssId + ': ' + e);
    }
  });
  return map;
}

/** Splits a raw cell into formatted numbers, dropping names/junk/"NA". */
function splitPhones_(raw) {
  if (raw === '' || raw === null || raw === undefined) return [];
  return String(raw)
    .split(/[\/,;&\n]|(?:\s+or\s+)/i)     // separators between distinct numbers
    .map(formatPhone_)
    .filter(Boolean);
}

/** Normalizes one number to 91XXXXXXXXXX, or '' if it isn't a valid 10-digit number. */
function formatPhone_(raw) {
  let d = String(raw || '').replace(/\D/g, '');     // keep digits only (spaces inside a number are fine)
  if (!d) return '';
  if (d.length === 12 && d.indexOf('91') === 0) return d;      // already 91XXXXXXXXXX
  if (d.length === 11 && d.charAt(0) === '0') d = d.substring(1);  // strip leading 0
  if (d.length === 10) return '91' + d;
  if (d.length > 10)   return '91' + d.slice(-10);            // stray prefix -> take last 10
  return '';                                                   // too short -> not a number
}

function dedupe_(arr) {
  const seen = {}, out = [];
  arr.forEach(function (x) { if (x && !seen[x]) { seen[x] = 1; out.push(x); } });
  return out;
}

/** Developer's numbers from DEVELOPER_PHONES (case-insensitive), [] if none. */
function developerPhones_(name) {
  const key = String(name || '').trim().toLowerCase();
  if (!key) return [];
  for (const dev in DEVELOPER_PHONES) {
    if (dev.toLowerCase() === key) return splitPhones_(DEVELOPER_PHONES[dev]);
  }
  return [];
}

/** True if the name matches a DEVELOPER_PHONES key (case-insensitive). */
function isDeveloperName_(name) {
  const key = String(name || '').trim().toLowerCase();
  if (!key) return false;
  for (const dev in DEVELOPER_PHONES) { if (dev.toLowerCase() === key) return true; }
  return false;
}

/** Stamps the "WhatsApp Status" column (creates it at the end if missing). */
function markWhatsAppStatus_(tabName, rowNum, statusText) {
  try {
    const sheet = SpreadsheetApp.openById(RESPONSE_SHEET_ID).getSheetByName(tabName);
    if (!sheet) return;
    const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
    let col = findColIndex(headers, WA_CFG.STATUS_COL_NAME);
    if (col < 0) {
      col = headers.length;                                  // append at the end
      sheet.getRange(1, col + 1).setValue(WA_CFG.STATUS_COL_NAME);
    }
    sheet.getRange(rowNum, col + 1).setValue(statusText);
  } catch (e) {
    Logger.log('WA: markWhatsAppStatus_ failed: ' + e);
  }
}
