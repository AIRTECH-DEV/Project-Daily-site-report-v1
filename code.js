/**
 * SITE VISIT REPORT — Web App Backend
 * Handles both VRV and Non-VRV site visits from one app. The user picks
 * the site type as the first step; that choice determines which Orders
 * sheet feeds the project dropdown, and which tab the submission is
 * written to in the response spreadsheet.
 */


// Response spreadsheet — created by Setup.gs, holds "VRV" and "Non-VRV" tabs
const RESPONSE_SHEET_ID = '1LL9yDxL5uCv_szvnv-7MOQmJgDuDEuXhdS-WvVnLbOI';
const TAB_NAMES = { VRV: 'VRV', NONVRV: 'Non-VRV' };

// Project dropdown sources — one Form_Responses-style sheet per site type.
// Layout is Timestamp / Email / Select Project Name / Number of people / ...
// so the project name lives in column C (3), not D.
// Project dropdown sources — one Form_Responses-style sheet per site type.
// Column is no longer hardcoded — getProjectNames() finds the
// "Select Project Name" header itself, whatever column it's in.
const VRV_ORDERS_SHEET_ID = '1SV_WhGa_sEdUkj1X46xRtoCNo5KG3Khi1jRkdl9LSz0';
const VRV_ORDERS_GID = 290389899;

const NONVRV_ORDERS_SHEET_ID = '1hvqgSI3f05d1wSoQVxaBPDzr4maHhTz5MLqhmN4a5Is';
const NONVRV_ORDERS_GID = 290389899;

// PMS progress spreadsheet for General (non-developer) clients. One tab per
// site type. Rows are matched by Project Name; each project step (Copper
// Piping, Cable, …) is a merged group header with Start Date / End Date /
// Status sub-columns, so columns are resolved by header text, never index.
const GENERAL_PMS_SHEET_ID = '13b916quVfpOKvwqn-kcaSb3M3i6RoeSIe7P57pHdc2I';
const GENERAL_PMS_TABS = { VRV: 'PMS - VRV', NONVRV: 'PMS - NonVRV' };

// Developer -> building progress spreadsheet. Each building is a tab; rows
// are matched flat-wise by Flat No. Building names sent from the client are
// matched to tab names by normalized (whitespace/case-insensitive) compare.
const DEVELOPER_BUILDING_SHEETS = {
  'Suyog Navkar': {
    spreadsheetId: '1OJHBUMhIpcG3gGGubd8jeRRC16AX3P6t7aPOQPgdIiM',
    buildings: ['Agam', 'Shruta', 'Kalpa']
  },
  'Kasturi': {
    spreadsheetId: '1_Gmi34cOm-NBEcaw99qi3gmk3CT7Da-kFxpdaLqLb-E',
    buildings: [
      'Balmoral River side D-wing',
      'Balmoral River side C-wing',
      'Balmoral TowerD-wing',
      'Balmoral TowerC-wing'
    ]
  }
};

const PARENT_FOLDER_ID = '1jdw5IgOuvn1M9xF5aeI00XN8A76sPI4W';
const LOGO_URL = 'https://drive.google.com/file/d/1TU2KKJN4AQKkG7nMtMlCoiYX1wMD2QtB/view?usp=drive_link';
const TEMPLATE_DOC_ID = '1_dsXZdnwCajnmrfk4w3BgJI9-rz_vdDsCEJhHDisELo';

// Edit these two values only if you want to test one exact response row.
const TEST_PDF_TAB_NAME = 'Non-VRV';
const TEST_PDF_ROW_NUMBER = 2;

/**
 * Run this once from the Apps Script editor after updating appsscript.json.
 * It forces Google to show the Sheets/Drive/Docs authorization prompt.
 */
function authorizeSiteReportApp() {
  const responseName = SpreadsheetApp.openById(RESPONSE_SHEET_ID).getName();
  const folderName = DriveApp.getFolderById(PARENT_FOLDER_ID).getName();
  const logoId = LOGO_URL.match(/[-\w]{25,}/)[0];
  const logoName = DriveApp.getFileById(logoId).getName();

  const doc = DocumentApp.create('Site_Report_Authorization_Check');
  doc.getBody().appendParagraph('Authorization check complete.');
  doc.saveAndClose();
  DriveApp.getFileById(doc.getId()).setTrashed(true);

  Logger.log('Authorization OK. Response sheet: ' + responseName +
    ', folder: ' + folderName + ', logo: ' + logoName);
}

/**
 * Serves the web app UI. Index.html is kept small (no inline base64
 * images) so the served HTML never crosses Apps Script's size limit —
 * the hero/logo images are fetched separately via getUiImages().
 */
function doGet() {
  return HtmlService.createHtmlOutputFromFile('Index')
    .setTitle('Site Visit Report')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

/**
 * Returns the hero + logo images as base64 data URIs, read on demand from
 * the chunked HeroJs / LogoJs project files. The client requests these
 * after the UI has rendered, so the large image payloads stay out of the
 * initial page HTML.
 */
function getUiImages() {
  return {
    hero: extractDataUri_('HeroJs'),
    logo: extractDataUri_('LogoJs')
  };
}

/**
 * Returns the whole app script (AppJs) as a string. Apps Script truncates
 * served HTML around ~32KB, so the 40KB+ of client code lives in its own
 * file and is fetched over google.script.run (no size cap) and injected by
 * the small loader in Index.html. Keeps the served page tiny and complete.
 */
function getAppJs() {
  // getRawContent() returns the JS verbatim. getContent() would parse it as
  // HTML and fail with "Malformed HTML content" (the code is full of <, >, &).
  return HtmlService.createTemplateFromFile('AppJs').getRawContent();
}

/**
 * Pulls the data:image/... URI out of a HeroJs/LogoJs file. Those files
 * define the URI as chunked string concatenation (var X = "" + "..." +
 * "...";), so we just join every double-quoted segment in the file.
 */
function extractDataUri_(fileName) {
  // getRawContent() returns the file verbatim; getContent() would try to
  // parse it as HTML and choke ("Malformed HTML content").
  const content = HtmlService.createTemplateFromFile(fileName).getRawContent();
  const parts = content.match(/"([^"]*)"/g) || [];
  return parts.map(function (s) { return s.slice(1, -1); }).join('');
}

/**
 * Returns the deduped, sorted project list for the given site type.
 * siteType: 'VRV' or 'Non-VRV'
 * Finds the "Select Project Name" column by its header text rather than
 * assuming a fixed column number, so it keeps working even if the two
 * source sheets aren't laid out identically.
 */
function getProjectNames(siteType) {
  const isVRV = siteType === 'VRV';
  const sheetId = isVRV ? VRV_ORDERS_SHEET_ID : NONVRV_ORDERS_SHEET_ID;
  const gid = isVRV ? VRV_ORDERS_GID : NONVRV_ORDERS_GID;

  try {
    const ss = SpreadsheetApp.openById(sheetId);
    const sheet = ss.getSheetById(gid);
    if (!sheet) {
      Logger.log('getProjectNames: no sheet with gid ' + gid + ' found in ' + sheetId);
      return [];
    }

    const lastRow = sheet.getLastRow();
    const lastCol = sheet.getLastColumn();
    if (lastRow < 2 || lastCol < 1) return [];

    const headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    // The source sheets carry the pickable name in more than one header, and
    // the Non-VRV Order-to-Delivery sheet splits it across TWO columns —
    // a "Project Name" column AND a "Billing Customer Name" column (some
    // clients, e.g. KEYWEST REALTY, exist only in the latter). So collect
    // every column whose header matches any of these and MERGE their values,
    // instead of picking just the first match. The "executive" exclusion
    // guards against headers like "Site / Project executive by".
    const projectCols = [];
    headers.forEach(function (h, i) {
      const hl = (h || '').toString().toLowerCase();
      const isProject =
        hl.indexOf('select project name') !== -1 ||
        (hl.indexOf('project name') !== -1 && hl.indexOf('executive') === -1) ||
        hl.indexOf('billing customer name') !== -1;
      if (isProject) projectCols.push(i);
    });
    if (projectCols.length === 0) {
      Logger.log('getProjectNames: no project-name header found in ' + sheetId +
        '. Actual headers: ' + headers.join(' | '));
      return [];
    }

    const cleaned = [];
    projectCols.forEach(function (colIdx) {
      const data = sheet.getRange(2, colIdx + 1, lastRow - 1, 1).getValues();
      data.forEach(function (r) {
        const v = r[0] ? r[0].toString().trim().replace(/\s+/g, ' ') : '';
        if (v) cleaned.push(v);
      });
    });

    return Array.from(new Set(cleaned)).sort();
  } catch (err) {
    Logger.log('getProjectNames error for ' + siteType + ' (' + sheetId + '): ' + err);
    return [];
  }
}

/* ------------------------------------------------------------------ *
 * PROGRESS-SHEET UPDATERS
 * On submit we also stamp the matching row in a PMS progress sheet:
 *  - General  -> GENERAL_PMS_SHEET_ID, matched by Project Name
 *  - Developer-> the developer's sheet + building tab, matched by Flat No
 * Every column is located by HEADER TEXT (two-row grouped headers are
 * supported), so adding/removing/reordering sheet columns never breaks
 * this — nothing is tied to a fixed column index.
 * ------------------------------------------------------------------ */

// lower-cased, single-spaced, trimmed
function normalizeKey_(value) {
  return (value === null || value === undefined ? '' : value.toString())
    .toLowerCase().replace(/\s+/g, ' ').trim();
}

// alphanumerics only — tolerates spacing/punctuation drift like
// "Fresh Air - PVC PIPE / Duct" vs "Fresh Air -PVC PIPE / Duct"
function compactKey_(value) {
  return normalizeKey_(value).replace(/[^a-z0-9]/g, '');
}

// Finds a tab by normalized name; falls back to best token overlap.
function findSheetByName_(ss, wanted) {
  const sheets = ss.getSheets();
  const target = normalizeKey_(wanted);
  let i;
  for (i = 0; i < sheets.length; i++) {
    if (normalizeKey_(sheets[i].getName()) === target) return sheets[i];
  }
  const wantedTokens = target.replace(/[^a-z0-9 ]/g, ' ').split(/\s+/)
    .filter(function (t) { return t; });
  let best = null, bestScore = 0;
  for (i = 0; i < sheets.length; i++) {
    const nameTokens = normalizeKey_(sheets[i].getName())
      .replace(/[^a-z0-9 ]/g, ' ').split(/\s+/);
    let score = 0;
    wantedTokens.forEach(function (t) { if (nameTokens.indexOf(t) !== -1) score++; });
    if (score > bestScore) { bestScore = score; best = sheets[i]; }
  }
  return bestScore > 0 ? best : null;
}

/**
 * Parses the (possibly two-row) header of a PMS sheet.
 * Detects the sub-header row (the one full of Start Date/End Date/Status),
 * treats the row above it as group headers, and forward-fills each group
 * name across its Start/End/Status columns (merged cells report blank in
 * all but the first column). Returns group/sub header arrays + the first
 * data row.
 */
function getPmsHeaderInfo_(sheet) {
  const lastCol = sheet.getLastColumn();
  const lastRow = sheet.getLastRow();
  const scan = Math.min(6, lastRow);
  if (scan < 1 || lastCol < 1) {
    return { subRowIndex: 1, dataStartRow: 2, lastCol: lastCol, groupVals: [], subVals: [] };
  }
  const rows = sheet.getRange(1, 1, scan, lastCol).getValues();

  let subRow = 0, bestCount = -1, r, c;
  for (r = 0; r < scan; r++) {
    let count = 0;
    for (c = 0; c < lastCol; c++) {
      const t = normalizeKey_(rows[r][c]);
      if (t === 'status' || t === 'start date' || t === 'end date') count++;
    }
    if (count > bestCount) { bestCount = count; subRow = r; }
  }
  const groupRow = subRow > 0 ? subRow - 1 : subRow;
  const subVals = rows[subRow].slice();
  const groupVals = rows[groupRow].slice();

  let lastGroup = '';
  for (c = 0; c < lastCol; c++) {
    if (groupVals[c] !== '' && groupVals[c] !== null && groupVals[c] !== undefined) {
      lastGroup = groupVals[c];
    } else {
      const s = normalizeKey_(subVals[c]);
      if ((s === 'status' || s === 'start date' || s === 'end date') && lastGroup) {
        groupVals[c] = lastGroup;
      }
    }
  }

  return {
    subRowIndex: subRow + 1,
    dataStartRow: subRow + 2,
    lastCol: lastCol,
    groupVals: groupVals,
    subVals: subVals
  };
}

// 1-based Status column for a named step, e.g. "Copper Piping".
// Falls back to a single stand-alone column named like the step
// (e.g. "LS Material Delivery" has no Start/End/Status sub-columns).
function findStepStatusCol_(info, stepName) {
  const step = compactKey_(stepName);
  if (!step) return -1;
  let i;
  for (i = 0; i < info.lastCol; i++) {
    if (compactKey_(info.groupVals[i]) === step && normalizeKey_(info.subVals[i]) === 'status') {
      return i + 1;
    }
  }
  for (i = 0; i < info.lastCol; i++) {
    const g = compactKey_(info.groupVals[i]);
    const s = compactKey_(info.subVals[i]);
    if ((g === step && !s) || s === step) return i + 1;
  }
  return -1;
}

// 1-based sub-column ("Start Date" / "End Date" / "Status") under a step
// group, e.g. the End Date column of "Copper Piping". -1 if the step has no
// such sub-column (stand-alone steps like "LS Material Delivery").
function findStepSubCol_(info, stepName, subLabel) {
  const step = compactKey_(stepName);
  const sub = normalizeKey_(subLabel);
  if (!step || !sub) return -1;
  for (let i = 0; i < info.lastCol; i++) {
    if (compactKey_(info.groupVals[i]) === step && normalizeKey_(info.subVals[i]) === sub) {
      return i + 1;
    }
  }
  return -1;
}

// 1-based column for a stand-alone header (Project Name, Flat No, Remarks, …)
function findNamedCol_(info, name) {
  const n = compactKey_(name);
  if (!n) return -1;
  for (let i = 0; i < info.lastCol; i++) {
    const g = compactKey_(info.groupVals[i]);
    const s = compactKey_(info.subVals[i]);
    if (s === n || (g === n && !s) || (g + s) === n) return i + 1;
  }
  return -1;
}

// 1-based row where the given column's value matches (normalized).
function findRowByColValue_(sheet, info, colIndex, wanted) {
  if (colIndex < 1) return -1;
  const lastRow = sheet.getLastRow();
  if (lastRow < info.dataStartRow) return -1;
  const vals = sheet.getRange(info.dataStartRow, colIndex, lastRow - info.dataStartRow + 1, 1).getValues();
  const w = normalizeKey_(wanted);
  if (!w) return -1;
  for (let i = 0; i < vals.length; i++) {
    if (normalizeKey_(vals[i][0]) === w) return info.dataStartRow + i;
  }
  return -1;
}

/**
 * Flat-tolerant row finder. The form takes Flat No as free text, so a user
 * may type "302" while the sheet stores "D-302" (or vice-versa). Match order:
 *   1) exact / punctuation-insensitive ("D-302" == "d302")
 *   2) same digits when the letter (wing) parts don't conflict — so "302"
 *      matches "D-302", but "C-302" never matches "D-302".
 * The digit fallback is only trusted when it hits exactly ONE row, so a sheet
 * with duplicate flats never silently updates the wrong one.
 */
function findFlatRow_(sheet, info, colIndex, wanted) {
  if (colIndex < 1) return -1;
  const lastRow = sheet.getLastRow();
  if (lastRow < info.dataStartRow) return -1;
  const vals = sheet.getRange(info.dataStartRow, colIndex, lastRow - info.dataStartRow + 1, 1).getValues();
  const wNorm = normalizeKey_(wanted);
  if (!wNorm) return -1;
  const wComp = compactKey_(wanted);
  const wLetters = wNorm.replace(/[^a-z]/g, '');
  const wDigits = wNorm.replace(/\D/g, '');

  let digitRow = -1, digitHits = 0;
  for (let i = 0; i < vals.length; i++) {
    const cell = vals[i][0];
    if (cell === '' || cell === null || cell === undefined) continue;
    const cNorm = normalizeKey_(cell);
    if (cNorm === wNorm || compactKey_(cell) === wComp) return info.dataStartRow + i;
    const cLetters = cNorm.replace(/[^a-z]/g, '');
    const cDigits = cNorm.replace(/\D/g, '');
    const lettersOk = !wLetters || !cLetters || wLetters === cLetters;
    if (lettersOk && wDigits && cDigits === wDigits) {
      digitHits++;
      if (digitRow < 0) digitRow = info.dataStartRow + i;
    }
  }
  return digitHits === 1 ? digitRow : -1;
}

// True for a header that names the shared Order ID key. Matches "Order ID",
// "Order No", "Order Number", "Order Code", "Order Ref", or a bare "Order" —
// but never "Order Date". Order ID is the ONE column present (and stable) in
// BOTH the Orders sheet and the PMS sheet, so it bridges the two files where
// the free-text project name drifts.
function isOrderIdHeader_(text) {
  const t = normalizeKey_(text);
  if (!t || t.indexOf('order') === -1) return false;
  if (t.indexOf('date') !== -1) return false;
  return t === 'order' || t.indexOf('orderid') !== -1 ||
    /(^|[^a-z])(id|no|no\.|number|code|ref)([^a-z]|$)/.test(t);
}

// 1-based Order ID column in a (grouped-header) PMS sheet, or -1.
function findOrderIdCol_(info) {
  for (let i = 0; i < info.lastCol; i++) {
    if (isOrderIdHeader_(info.subVals[i]) || isOrderIdHeader_(info.groupVals[i])) return i + 1;
  }
  return -1;
}

/**
 * Looks up the Order ID for a picked project in the Orders sheet (the same
 * source that fed the dropdown, so the name matches exactly — no drift).
 * Searches the same project/billing name columns getProjectNames() merges.
 * Returns '' if the sheet, the Order ID column, or the row isn't found.
 */
function getOrderIdForProject_(siteType, projectName) {
  const want = normalizeKey_(projectName);
  if (!want) return '';
  try {
    const isVRV = siteType === 'VRV';
    const ss = SpreadsheetApp.openById(isVRV ? VRV_ORDERS_SHEET_ID : NONVRV_ORDERS_SHEET_ID);
    const sheet = ss.getSheetById(isVRV ? VRV_ORDERS_GID : NONVRV_ORDERS_GID);
    if (!sheet) return '';
    const lastRow = sheet.getLastRow();
    const lastCol = sheet.getLastColumn();
    if (lastRow < 2 || lastCol < 1) return '';

    const headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    let orderCol = -1;
    const nameCols = [];
    headers.forEach(function (h, i) {
      if (orderCol < 0 && isOrderIdHeader_(h)) orderCol = i;
      const hl = (h || '').toString().toLowerCase();
      if (hl.indexOf('select project name') !== -1 ||
          (hl.indexOf('project name') !== -1 && hl.indexOf('executive') === -1) ||
          hl.indexOf('billing customer name') !== -1) nameCols.push(i);
    });
    if (orderCol < 0 || !nameCols.length) return '';

    const data = sheet.getRange(2, 1, lastRow - 1, lastCol).getValues();
    for (let r = 0; r < data.length; r++) {
      for (let c = 0; c < nameCols.length; c++) {
        if (normalizeKey_(data[r][nameCols[c]]) === want) {
          const oid = data[r][orderCol];
          return (oid === null || oid === undefined) ? '' : oid.toString().trim();
        }
      }
    }
    return '';
  } catch (err) {
    Logger.log('getOrderIdForProject_ error: ' + err);
    return '';
  }
}

/**
 * Writes the submitted status/remark/etc. onto one PMS row, all by header
 * name. Status cell = Done/Pending, or the hold reason when on Hold; the
 * detailed hold reason goes into Remarks.
 */
function updatePmsRow_(sheet, row, info, payload, isDeveloper) {
  const setByName = function (name, val) {
    if (val === '' || val === null || val === undefined) return;
    const col = findNamedCol_(info, name);
    if (col > 0) sheet.getRange(row, col).setValue(val);
  };

  if (isDeveloper) {
    setByName('Timestamp', new Date());
    setByName('Project Exective By', payload.engineer || '');
  }

  const statusCol = findStepStatusCol_(info, payload.currentStatus);
  if (statusCol > 0 && payload.status) {
    const statusCellVal = payload.status === 'Hold'
      ? (payload.holdReason || 'Hold')
      : payload.status;
    // Developer building tabs put a strict dropdown on each step's Status cell
    // (often only "Done" is accepted), which makes a raw setValue of "Pending"
    // or a hold reason get rejected and left blank. Route through the helper so
    // the value is appended to that cell's list + allow-invalid, and it sticks.
    writeAllowingCustomList_(sheet.getRange(row, statusCol), statusCellVal);

    // Step marked Done -> stamp its End Date with today's date, but only if
    // empty so a previously recorded completion date is never overwritten.
    if (payload.status === 'Done') {
      const endCol = findStepSubCol_(info, payload.currentStatus, 'End Date');
      if (endCol > 0) {
        const endCell = sheet.getRange(row, endCol);
        const cur = endCell.getValue();
        if (cur === '' || cur === null) endCell.setValue(new Date());
      }
    }
  } else if (payload.currentStatus) {
    Logger.log('updatePmsRow_: no status column found for step "' + payload.currentStatus + '"');
  }

  // On Hold: Remarks gets one combined line joined by " - ":
  //   "<step> - <reason> - stuck by <who>"
  // e.g. "Copper Piping - due to heavy rain - stuck by VAPL".
  // <who> is pulled from the hold reason ("Stuck BY VAPL" -> "VAPL");
  // if it has no "by" part (e.g. "Other") the "stuck by" piece is dropped.
  if (payload.status === 'Hold') {
    const parts = [];
    if (payload.currentStatus) parts.push(payload.currentStatus);
    if (payload.holdReasonDetail) parts.push(payload.holdReasonDetail);
    const whoMatch = String(payload.holdReason || '').match(/by\s+(.+)$/i);
    if (whoMatch) parts.push('stuck by ' + whoMatch[1].trim());
    if (parts.length) setByName('Remarks', parts.join(' - '));
  } else if (payload.status) {
    // No longer on hold (Done/Pending) -> wipe the old hold remark so a stale
    // "stuck by ..." reason never lingers after the project moves on.
    const remCol = findNamedCol_(info, 'Remarks');
    if (remCol > 0) sheet.getRange(row, remCol).setValue('');
  }

  const wdb = payload.workDoneBy === 'Contractor'
    ? (payload.contractorName || 'Contractor')
    : (payload.workDoneBy || '');
  if (wdb !== '' && wdb !== null && wdb !== undefined) {
    const wCol = findNamedCol_(info, 'Work Done BY');
    if (wCol > 0) writeAllowingCustomList_(sheet.getRange(row, wCol), wdb);
  }

  if (payload.tentativeEndDate) {
    const d = new Date(payload.tentativeEndDate);
    setByName('Tentitive Project End date', isNaN(d.getTime()) ? payload.tentativeEndDate : d);
  }
}

/**
 * DIAGNOSTIC — run from the Apps Script editor (pick this function, press Run),
 * then read the log under Executions. Dumps how the Kasturi "Balmoral TowerD-
 * wing" tab header is parsed and whether the Copper Piping Status column and
 * the Remarks column actually resolve — the two cells that came up empty.
 */
function diagBalmoralDHeader() {
  const ss = SpreadsheetApp.openById(DEVELOPER_BUILDING_SHEETS['Kasturi'].spreadsheetId);
  const sheet = findSheetByName_(ss, 'Balmoral TowerD-wing');
  if (!sheet) { Logger.log('TAB "Balmoral TowerD-wing" NOT FOUND'); return; }
  const info = getPmsHeaderInfo_(sheet);
  Logger.log('subRowIndex=%s dataStartRow=%s lastCol=%s', info.subRowIndex, info.dataStartRow, info.lastCol);
  for (let i = 0; i < info.lastCol; i++) {
    const g = info.groupVals[i], s = info.subVals[i];
    if (g || s) Logger.log('col %s | group="%s" | sub="%s"', i + 1, g, s);
  }
  Logger.log('--> Copper Piping Status col = %s', findStepStatusCol_(info, 'Copper Piping'));
  Logger.log('--> Remarks col = %s', findNamedCol_(info, 'Remarks'));
  Logger.log('--> Work Done BY col = %s', findNamedCol_(info, 'Work Done BY'));
}

/**
 * Writes a value into a cell that may carry a "value in list" dropdown
 * (e.g. the Work Done BY contractor list). If the value is a custom name not
 * already in the list, the name is appended to that cell's dropdown and
 * invalid input is allowed — so the custom contractor name sticks cleanly
 * with no red "invalid" flag while the dropdown of known names is kept.
 * Never throws: falls back to a plain setValue on any error.
 */
function writeAllowingCustomList_(cell, value) {
  try {
    const rule = cell.getDataValidation();
    if (rule && rule.getCriteriaType() === SpreadsheetApp.DataValidationCriteria.VALUE_IN_LIST) {
      const args = rule.getCriteriaValues();      // [ [allowed values], showDropdown ]
      const list = (args[0] || []).map(function (x) { return String(x); });
      if (list.indexOf(String(value)) === -1) {
        const newRule = SpreadsheetApp.newDataValidation()
          .requireValueInList(list.concat([String(value)]), true)
          .setAllowInvalid(true)
          .build();
        cell.setDataValidation(newRule);
      }
    }
  } catch (err) {
    Logger.log('writeAllowingCustomList_: ' + err);
  }
  cell.setValue(value);
}

/**
 * Routes a submission to the right progress sheet/row and stamps it.
 * Never throws to the caller — failures are returned as a warning so the
 * submission + PDF still complete, but the submitter is told the progress
 * sheet was NOT updated (e.g. flat/project not found).
 * Returns { updated: boolean, warning: string }.
 */
function updateProgressSheets_(payload) {
  const skip = function (msg) { Logger.log('updateProgressSheets_: ' + msg); return { updated: false, warning: msg }; };

  if (payload.clientType === 'Developer') {
    const dev = DEVELOPER_BUILDING_SHEETS[payload.developer];
    if (!dev || !dev.spreadsheetId) {
      return skip('No progress sheet configured for developer "' + payload.developer + '".');
    }
    const devSs = SpreadsheetApp.openById(dev.spreadsheetId);
    const devSheet = findSheetByName_(devSs, payload.building);
    if (!devSheet) {
      return skip('Building tab "' + payload.building + '" not found in ' + payload.developer + "'s progress sheet.");
    }
    const devInfo = getPmsHeaderInfo_(devSheet);
    const flatCol = findNamedCol_(devInfo, 'Flat No');
    if (flatCol < 1) {
      return skip('No "Flat No" column found in building tab "' + payload.building + '".');
    }
    const devRow = findFlatRow_(devSheet, devInfo, flatCol, payload.flatNo);
    if (devRow < 0) {
      return skip('Flat "' + payload.flatNo + '" not found in building "' + payload.building + '". Progress sheet not updated — check the flat number.');
    }
    updatePmsRow_(devSheet, devRow, devInfo, payload, true);
    return { updated: true, warning: '' };
  }

  const ss = SpreadsheetApp.openById(GENERAL_PMS_SHEET_ID);
  const tabName = payload.siteType === 'VRV' ? GENERAL_PMS_TABS.VRV : GENERAL_PMS_TABS.NONVRV;
  const sheet = findSheetByName_(ss, tabName);
  if (!sheet) {
    return skip('PMS tab "' + tabName + '" not found.');
  }
  const info = getPmsHeaderInfo_(sheet);

  // Match by Order ID first — the picked name came from the Orders sheet, so
  // its Order ID is exact, and Order ID is the stable shared key in the PMS
  // sheet too (the free-text name drifts between the two files).
  let pmsRow = -1;
  const orderId = getOrderIdForProject_(payload.siteType, payload.project);
  if (orderId) {
    const orderCol = findOrderIdCol_(info);
    if (orderCol > 0) pmsRow = findRowByColValue_(sheet, info, orderCol, orderId);
  }
  // Fallback: match by project name (PMS sheets with no Order ID column).
  if (pmsRow < 0) {
    const projCol = findNamedCol_(info, 'Project Name');
    pmsRow = findRowByColValue_(sheet, info, projCol, payload.project);
  }
  if (pmsRow < 0) {
    return skip('Project "' + payload.project + '"' +
      (orderId ? ' (Order ID ' + orderId + ')' : '') +
      ' not found in ' + tabName + '. Progress sheet not updated.');
  }
  updatePmsRow_(sheet, pmsRow, info, payload, false);
  return { updated: true, warning: '' };
}

/**
 * Finds a header's column index (0-based) by substring, case-insensitive.
 */
function findColIndex(headers, mustInclude, mustExclude) {
  const inc = mustInclude.toLowerCase();
  const exc = mustExclude ? mustExclude.toLowerCase() : null;
  for (let i = 0; i < headers.length; i++) {
    const h = (headers[i] || '').toString().toLowerCase();
    if (h.indexOf(inc) !== -1 && (!exc || h.indexOf(exc) === -1)) return i;
  }
  return -1;
}

function formatPdfCellValue(value) {
  if (value === null || value === undefined || value === '') return 'N/A';
  if (Object.prototype.toString.call(value) === '[object Date]') {
    return Utilities.formatDate(value, Session.getScriptTimeZone(), 'dd-MMM-yyyy HH:mm');
  }
  return value.toString();
}

function setTableColumnWidth(table, columnIndex, width) {
  if (typeof table.setColumnWidth === 'function') {
    table.setColumnWidth(columnIndex, width);
    return;
  }

  for (let row = 0; row < table.getNumRows(); row++) {
    const tableRow = table.getRow(row);
    if (tableRow.getNumCells() > columnIndex) {
      tableRow.getCell(columnIndex).setWidth(width);
    }
  }
}

function fitImageToBox(image, maxWidth, maxHeight) {
  const width = image.getWidth();
  const height = image.getHeight();
  if (!width || !height) {
    image.setWidth(maxWidth);
    return image;
  }

  const scale = Math.min(maxWidth / width, maxHeight / height, 1);
  image.setWidth(Math.round(width * scale));
  image.setHeight(Math.round(height * scale));
  return image;
}

function appendDivider(body, color, width) {
  const divider = body.appendTable([[' ']]);
  divider.setBorderWidth(0);
  setTableColumnWidth(divider, 0, width);
  const cell = divider.getCell(0, 0);
  cell.setBackgroundColor(color || '#D0312D');
  cell.setPaddingTop(0).setPaddingBottom(0).setPaddingLeft(0).setPaddingRight(0);
  // cell height = one text line; 2pt font makes bar a thin rule, not a block
  const para = cell.getChild(0).asParagraph();
  para.setSpacingBefore(0).setSpacingAfter(0);
  cell.editAsText().setFontSize(2);
  return divider;
}

function getOrCreateProjectFolder(projectName) {
  const parent = DriveApp.getFolderById(PARENT_FOLDER_ID);
  const name = projectName || 'General_Reports';
  const existing = parent.getFoldersByName(name);
  return existing.hasNext() ? existing.next() : parent.createFolder(name);
}

function saveBase64File(fileObj, folder, prefix) {
  if (!fileObj || !fileObj.base64) return null;
  const bytes = Utilities.base64Decode(fileObj.base64);
  const blob = Utilities.newBlob(bytes, fileObj.mimeType, prefix + '_' + fileObj.name);
  const file = folder.createFile(blob);
  return file.getUrl();
}

function generateMissingSiteReportPDFs() {
  generateSiteReportPDFsForRows(false);
}

function regenerateAllSiteReportPDFs() {
  generateSiteReportPDFsForRows(true);
}

function testLatestSiteReportPDF() {
  const target = findLatestSiteReportRow_();
  if (!target) {
    throw new Error('No saved site report rows found in VRV or Non-VRV tabs.');
  }

  return testSiteReportPDFForRow_(target.sheet, target.headers, target.rowNum);
}

function testConfiguredSiteReportPDF() {
  const ss = SpreadsheetApp.openById(RESPONSE_SHEET_ID);
  const sheet = ss.getSheetByName(TEST_PDF_TAB_NAME);
  if (!sheet) {
    throw new Error('Test tab not found: ' + TEST_PDF_TAB_NAME);
  }
  if (TEST_PDF_ROW_NUMBER < 2 || TEST_PDF_ROW_NUMBER > sheet.getLastRow()) {
    throw new Error('Test row ' + TEST_PDF_ROW_NUMBER + ' is outside the data range.');
  }

  const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  return testSiteReportPDFForRow_(sheet, headers, TEST_PDF_ROW_NUMBER);
}

function findLatestSiteReportRow_() {
  const ss = SpreadsheetApp.openById(RESPONSE_SHEET_ID);
  let latest = null;

  Object.keys(TAB_NAMES).forEach(function (key) {
    const sheet = ss.getSheetByName(TAB_NAMES[key]);
    if (!sheet || sheet.getLastRow() < 2) return;

    const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
    const timestampCol = findColIndex(headers, 'timestamp');
    const rowNum = sheet.getLastRow();
    let sortValue = rowNum;

    if (timestampCol > -1) {
      const stamp = sheet.getRange(rowNum, timestampCol + 1).getValue();
      if (Object.prototype.toString.call(stamp) === '[object Date]') {
        sortValue = stamp.getTime();
      }
    }

    if (!latest || sortValue > latest.sortValue) {
      latest = { sheet: sheet, headers: headers, rowNum: rowNum, sortValue: sortValue };
    }
  });

  return latest;
}

function testSiteReportPDFForRow_(sheet, headers, rowNum) {
  const projectCol = findColIndex(headers, 'select project name');
  const pdfIdCol = findColIndex(headers, 'pdf id');
  const mailStatusCol = findColIndex(headers, 'mail status');
  const projectName = projectCol > -1
    ? (sheet.getRange(rowNum, projectCol + 1).getValue() || 'General_Reports').toString().trim()
    : 'General_Reports';

  const folder = getOrCreateProjectFolder(projectName);
  const pdfFile = generateSiteReportPDF(sheet, headers, rowNum, projectName, folder);

  if (pdfIdCol > -1) {
    sheet.getRange(rowNum, pdfIdCol + 1).setValue(pdfFile.getId());
  }
  if (mailStatusCol > -1) {
    sheet.getRange(rowNum, mailStatusCol + 1).setValue('PDF TEST GENERATED');
  }

  Logger.log('Test PDF created for ' + sheet.getName() + ' row ' + rowNum + ': ' + pdfFile.getUrl());
  return pdfFile.getUrl();
}

function generateSiteReportPDFsForRows(regenerateExisting) {
  const ss = SpreadsheetApp.openById(RESPONSE_SHEET_ID);
  Object.keys(TAB_NAMES).forEach(function (key) {
    const sheet = ss.getSheetByName(TAB_NAMES[key]);
    if (!sheet || sheet.getLastRow() < 2) return;

    const lastCol = sheet.getLastColumn();
    const headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
    const projectCol = findColIndex(headers, 'select project name');
    const pdfIdCol = findColIndex(headers, 'pdf id');
    const mailStatusCol = findColIndex(headers, 'mail status');
    if (pdfIdCol === -1) return;

    for (let rowNum = 2; rowNum <= sheet.getLastRow(); rowNum++) {
      if (!regenerateExisting && sheet.getRange(rowNum, pdfIdCol + 1).getValue()) continue;

      try {
        const projectName = projectCol > -1
          ? (sheet.getRange(rowNum, projectCol + 1).getValue() || 'General_Reports').toString().trim()
          : 'General_Reports';
        const folder = getOrCreateProjectFolder(projectName);
        const pdfFile = generateSiteReportPDF(sheet, headers, rowNum, projectName, folder);
        sheet.getRange(rowNum, pdfIdCol + 1).setValue(pdfFile.getId());
        if (mailStatusCol > -1) {
          sheet.getRange(rowNum, mailStatusCol + 1).setValue('PDF GENERATED');
        }
      } catch (err) {
        const message = err && err.message ? err.message : err.toString();
        Logger.log('generateMissingSiteReportPDFs row ' + rowNum + ' failed: ' + message);
        if (mailStatusCol > -1) {
          sheet.getRange(rowNum, mailStatusCol + 1).setValue('PDF FAILED: ' + message);
        }
      }
    }
  });
}

/**
 * Main entry point called from the client via google.script.run.
 * payload = {
 *   siteType: 'VRV'|'Non-VRV',
 *   clientType: 'General'|'Developer', developer, building, floor, flatNo,
 *   currentStatus, status: 'Done'|'Pending'|'Hold', holdReason, holdReasonDetail,
 *   tentativeEndDate, workDoneBy: 'VAPL'|'Contractor', contractorName,
 *   project, people, engineer, activity, nextPlan,
 *   photos: [{base64,mimeType,name}, ...],
 *   amendment, amendmentWhy,
 *   drawingChange, drawingPhoto: {base64,mimeType,name}|null,
 *   measurement, measurementFile: {base64,mimeType,name}|null
 * }
 */
function submitSiteReport(payload) {
  // DIAGNOSTIC: log the status/hold fields exactly as received so a missing
  // step Status / Remark can be traced to "form never sent it" vs "column not
  // matched on the sheet". Read under Extensions > Apps Script > Executions.
  Logger.log('submitSiteReport payload: clientType=%s developer=%s building=%s flatNo=%s currentStatus=%s status=%s holdReason=%s holdReasonDetail=%s',
    payload.clientType, payload.developer, payload.building, payload.flatNo,
    payload.currentStatus, payload.status, payload.holdReason, payload.holdReasonDetail);

  // Tracks the current operation so a thrown error can say WHERE it failed
  // (Drive/Sheets access errors otherwise read as a bare "You do not have
  // permission to access the requested document.").
  let step = 'opening response spreadsheet';
  try {
    const ss = SpreadsheetApp.openById(RESPONSE_SHEET_ID);
    const tabName = payload.siteType === 'VRV' ? TAB_NAMES.VRV : TAB_NAMES.NONVRV;
    const sheet = ss.getSheetByName(tabName);
    if (!sheet) {
      throw new Error('Response tab "' + tabName + '" not found — run setupResponseSheets() first.');
    }

    const lastCol = sheet.getLastColumn();
    const headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0];

    const isDeveloper = payload.clientType === 'Developer';
    // For developer submissions the response sheet's "Select Project Name"
    // holds the developer's name (Suyog / Kasturi), not the building.
    const projectName = (isDeveloper
      ? (payload.developer || 'General_Reports')
      : (payload.project || 'General_Reports')).toString().trim();
    step = 'opening/creating Drive folder "' + projectName + '"';
    const folder = getOrCreateProjectFolder(projectName);

    step = 'saving uploaded photos to folder "' + projectName + '"';
    const siteUrls = [];
    (payload.photos || []).forEach(function (f, idx) {
      const url = saveBase64File(f, folder, 'SitePhoto_' + (idx + 1));
      if (url) siteUrls.push(url);
    });
    const drawingUrl = payload.drawingChange === 'Yes'
      ? saveBase64File(payload.drawingPhoto, folder, 'DrawingChange')
      : null;
    const measurementUrl = payload.measurement === 'Yes'
      ? saveBase64File(payload.measurementFile, folder, 'MeasurementReport')
      : null;

    step = 'writing the response row';

    const email = Session.getActiveUser().getEmail() || 'unknown';

    const row = new Array(headers.length).fill('');
    const set = function (idx, value) { if (idx > -1) row[idx] = value; };

    set(findColIndex(headers, 'timestamp'), new Date());
    set(findColIndex(headers, 'email address'), email);
    set(findColIndex(headers, 'site type'), payload.siteType || 'Non-VRV');
    set(findColIndex(headers, 'client type'), payload.clientType || 'General');
    set(findColIndex(headers, 'developer'), payload.developer || '');
    set(findColIndex(headers, 'building'), payload.building || '');
    set(findColIndex(headers, 'floor'), payload.floor || '');
    set(findColIndex(headers, 'flat no'), payload.flatNo || '');
    set(findColIndex(headers, 'current status'), payload.currentStatus || '');
    set(findColIndex(headers, 'work done by'), payload.workDoneBy === 'Contractor' ? (payload.contractorName || 'Contractor') : (payload.workDoneBy || ''));
    set(findColIndex(headers, 'tentative project end date'), payload.tentativeEndDate ? new Date(payload.tentativeEndDate) : '');
    set(findColIndex(headers, 'hold reason', 'detail'), payload.status === 'Hold' ? (payload.holdReason || '') : '');
    set(findColIndex(headers, 'hold reason detail'), payload.status === 'Hold' ? (payload.holdReasonDetail || '') : '');
    set(findColIndex(headers, 'select project name'), projectName);
    set(findColIndex(headers, 'number of people'), payload.people || '');
    set(findColIndex(headers, 'project engineer name'), payload.engineer || '');
    set(findColIndex(headers, "today's activity"), payload.activity || '');
    set(findColIndex(headers, 'upload site photo'), siteUrls.join(', '));
    set(findColIndex(headers, 'what is the next plan'), payload.nextPlan || '');
    set(findColIndex(headers, 'approval required?', 'why'), payload.amendment || 'No');
    set(findColIndex(headers, 'why'), payload.amendment === 'Yes' ? (payload.amendmentWhy || '') : 'N/A');
    set(findColIndex(headers, 'changes in drawing', 'upload photo here'), payload.drawingChange || 'No');
    set(findColIndex(headers, 'upload photo here'), drawingUrl || 'N/A');
    set(findColIndex(headers, 'measurement report created today', 'upload the measurement'), payload.measurement || 'No');
    set(findColIndex(headers, 'upload the measurement report here'), measurementUrl || 'N/A');
    set(findColIndex(headers, 'mail status'), 'PENDING');

    const newRow = sheet.getLastRow() + 1;
    sheet.getRange(newRow, 1, 1, headers.length).setValues([row]);

    // Stamp the matching PMS progress row (name-based; never blocks submit).
    let pmsWarning = '';
    try {
      const pmsResult = updateProgressSheets_(payload);
      if (pmsResult && !pmsResult.updated) pmsWarning = pmsResult.warning || '';
    } catch (progErr) {
      Logger.log('updateProgressSheets_ failed: ' + progErr);
      pmsWarning = 'Progress sheet update failed: ' + progErr;
    }

    let pdfUrl = '';
    const mailStatusCol = findColIndex(headers, 'mail status');
    const pdfIdCol = findColIndex(headers, 'pdf id');

    try {
      const pdfFile = generateSiteReportPDF(sheet, headers, newRow, projectName, folder);
      if (pdfIdCol > -1 && pdfFile) {
        sheet.getRange(newRow, pdfIdCol + 1).setValue(pdfFile.getId());
      }
      if (mailStatusCol > -1) {
        sheet.getRange(newRow, mailStatusCol + 1).setValue('PDF GENERATED');
      }
      pdfUrl = pdfFile ? pdfFile.getUrl() : '';
      // Fire the WhatsApp report to the client numbers ~2 min later.
      // (defined in sendReportwhatsapp.js; no-op when WA_CFG.MODE = 'OFF')
      try { scheduleReportWhatsApp_(newRow, tabName); }
      catch (waErr) { Logger.log('scheduleReportWhatsApp_ failed: ' + waErr); }
      // Fire the report email to the client ~2 min later (same per-submit design).
      // (defined in sendReportEmail.js; no-op when EMAIL_CFG.MODE = 'OFF')
      try { scheduleReportEmail_(newRow, tabName); }
      catch (mailErr) { Logger.log('scheduleReportEmail_ failed: ' + mailErr); }
    } catch (pdfErr) {
      const pdfMessage = pdfErr && pdfErr.message ? pdfErr.message : pdfErr.toString();
      Logger.log('PDF generation failed: ' + pdfMessage);
      if (mailStatusCol > -1) {
        sheet.getRange(newRow, mailStatusCol + 1).setValue('PDF FAILED: ' + pdfMessage);
      }
      return { success: false, error: 'Data saved, but PDF generation failed: ' + pdfMessage };
    }

    return { success: true, row: newRow, pdfUrl: pdfUrl, pmsWarning: pmsWarning };
  } catch (err) {
    const msg = (err && err.message) ? err.message : String(err);
    Logger.log('submitSiteReport error while ' + step + ': ' + err);
    return { success: false, error: 'Failed while ' + step + ': ' + msg };
  }
}

function generateSiteReportPDF(sheet, headers, rowNum, projectName, folder) {
  const rowData = sheet.getRange(rowNum, 1, 1, headers.length).getValues()[0];
  const data = {};
  headers.forEach(function (header, index) {
    data[header] = rowData[index] || '';
  });

  return buildDocAndExportPDF(headers, rowData, data, projectName, folder, false);
}

function buildDocAndExportPDF(headers, rowData, data, projectName, targetFolder, isTest) {
  const tempName = 'Temp_' + projectName + '_' + new Date().getTime();
  const tempFolder = DriveApp.getFolderById(PARENT_FOLDER_ID);
  const copyFile = DriveApp.getFileById(TEMPLATE_DOC_ID).makeCopy(tempName, tempFolder);
  const docId = copyFile.getId();
  const doc = DocumentApp.openById(docId);
  const body = doc.getBody();

  body.clear();
  // body.clear() can leave template's inline images behind — strip them
  const leftoverImages = body.getImages();
  for (let li = 0; li < leftoverImages.length; li++) {
    leftoverImages[li].removeFromParent();
  }
  // Match letterhead template geometry (A4). Header block: 35.45pt offset
  // + 74.3pt logo + gold rule below ≈ 120pt, so 140pt keeps body clear of it
  // on every page. Bottom 70pt keeps text off the footer logo strip.
  body.setMarginTop(140);
  body.setMarginBottom(70);
  body.setMarginLeft(42);
  body.setMarginRight(42);

  const RED = '#D0312D';
  const DARK = '#1A1A1A';
  const GRAY = '#777777';
  const LGRAY = '#AAAAAA';
  const AMBER = '#C8860A';
  const GREEN = '#1A7A4A';
  const WHITE = '#FFFFFF';
  const BGRAY = '#F5F5F5';
  const BORDER = '#E6E6E6';
  const SOFT_RED = '#FFF7F7';
  // A4 width 595.3pt - 42pt margins each side
  const PAGE_WIDTH = 510;
  const KEY_COL_WIDTH = 180;
  const VAL_COL_WIDTH = 330;
  const PHOTO_COL_WIDTH = 250;

  let ts = data.Timestamp ? data.Timestamp.toString() : '';
  let tsFormatted = ts;
  try {
    const d = new Date(ts);
    if (!isNaN(d.getTime())) {
      const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      tsFormatted = d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() +
        ' | ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    }
  } catch (ex) {}

  const titleTable = body.appendTable([['', '']]);
  titleTable.setBorderWidth(0);
  titleTable.setBorderColor(WHITE);
  setTableColumnWidth(titleTable, 0, 320);
  setTableColumnWidth(titleTable, 1, 190);

  const leftCell = titleTable.getCell(0, 0);
  leftCell.setPaddingTop(4).setPaddingBottom(4).setPaddingLeft(0).setPaddingRight(4);
  leftCell.setBackgroundColor(WHITE);
  const titlePara = leftCell.getChild(0).asParagraph();
  titlePara.setAlignment(DocumentApp.HorizontalAlignment.LEFT);
  titlePara.setSpacingBefore(0).setSpacingAfter(0);
  titlePara.clear();
  titlePara.appendText('SITE ').setFontFamily('Arial').setFontSize(22).setBold(true).setForegroundColor(DARK);
  titlePara.appendText('REPORT').setFontFamily('Arial').setFontSize(22).setBold(true).setForegroundColor(RED);

  const rightCell = titleTable.getCell(0, 1);
  rightCell.setPaddingTop(4).setPaddingBottom(4).setPaddingLeft(4).setPaddingRight(0);
  rightCell.setBackgroundColor(WHITE);
  const datePara = rightCell.getChild(0).asParagraph();
  datePara.setAlignment(DocumentApp.HorizontalAlignment.RIGHT);
  datePara.setSpacingBefore(0).setSpacingAfter(0);
  datePara.clear();
  datePara.appendText(tsFormatted)
    .setFontFamily('Courier New').setFontSize(11).setBold(true).setForegroundColor(AMBER);

  const projectPara = body.appendParagraph(projectName || 'General_Reports');
  projectPara.setSpacingBefore(0).setSpacingAfter(8);
  projectPara.editAsText()
    .setFontFamily('Arial').setFontSize(10).setBold(true).setForegroundColor(GRAY);

  appendDivider(body, RED, PAGE_WIDTH);
  body.appendParagraph('').setSpacingBefore(0).setSpacingAfter(8);

  appendSectionHeader(body, "TODAY'S ACTIVITY", RED);
  const activityValue = data["Today's Activity"] || 'N/A';
  const activityTable = body.appendTable([[activityValue.toString()]]);
  activityTable.setBorderWidth(1);
  activityTable.setBorderColor(BORDER);
  setTableColumnWidth(activityTable, 0, PAGE_WIDTH);
  const activityCell = activityTable.getCell(0, 0);
  activityCell.setBackgroundColor(SOFT_RED);
  activityCell.setPaddingTop(9).setPaddingBottom(9).setPaddingLeft(10).setPaddingRight(10);
  activityCell.editAsText()
    .setFontFamily('Arial').setFontSize(11).setBold(false).setForegroundColor(DARK);

  body.appendParagraph('').setSpacingAfter(8);
  appendSectionHeader(body, 'PROJECT DETAILS', RED);

  function shouldSkipHeader(headerText) {
    const lower = headerText.toLowerCase();
    const skipPatterns = [
      'email address',
      'mail status',
      'whatsapp status',
      'pdf id',
      'timestamp',
      "today's activity",
      'if yes: upload the measurement report',
      'upload site photo',
      'site photos',
      'if yes :upload photo here',
      'if yes: upload photo here'
    ];
    return skipPatterns.some(function (pattern) {
      return lower.indexOf(pattern) !== -1;
    });
  }

  const tableData = [];
  headers.forEach(function (header, index) {
    if (shouldSkipHeader(header || '')) return;
    let value = formatPdfCellValue(rowData[index]);
    if (!value || value.trim() === '') value = 'N/A';
    tableData.push([(header || '').toString().toUpperCase(), value]);
  });

  if (tableData.length > 0) {
    const detailTable = body.appendTable(tableData);
    detailTable.setBorderWidth(1);
    detailTable.setBorderColor(BORDER);
    setTableColumnWidth(detailTable, 0, KEY_COL_WIDTH);
    setTableColumnWidth(detailTable, 1, VAL_COL_WIDTH);
    styleDetailTable(detailTable, BGRAY, GRAY, DARK, GREEN, AMBER, LGRAY, WHITE);
  }

  // Page 1 = activity + project details only. All photo sections start page 2.
  body.appendPageBreak();

  let drawingInserted = false;
  headers.forEach(function (header, index) {
    if (drawingInserted) return;
    const lower = (header || '').toString().toLowerCase();
    const value = formatPdfCellValue(rowData[index]);
    if (lower.indexOf('changes in drawing') !== -1 &&
      (lower.indexOf('upload') !== -1 || lower.indexOf('photo here') !== -1) &&
      value !== 'N/A' && value.trim() !== '') {
      try {
        const drawingId = value.match(/[-\w]{25,}/);
        if (drawingId) {
          appendSectionHeader(body, 'DRAWING CHANGE PHOTO', RED);
          const imgBlob = DriveApp.getFileById(drawingId[0]).getBlob();
          const img = body.appendImage(imgBlob);
          fitImageToBox(img, 320, 220);
          body.appendParagraph('').setSpacingAfter(10);
          drawingInserted = true;
        }
      } catch (err) {
        Logger.log('Drawing photo error: ' + err);
      }
    }
  });

  let sitePhotoKey = null;
  for (let index = 0; index < headers.length; index++) {
    const lower = (headers[index] || '').toString().toLowerCase();
    if (lower === 'upload site photo' || lower === 'site photos' ||
      (lower.indexOf('upload') !== -1 && lower.indexOf('site photo') !== -1)) {
      sitePhotoKey = headers[index];
      break;
    }
  }

  const sitePhotoVal = sitePhotoKey ? formatPdfCellValue(data[sitePhotoKey]) : '';
  const photoBlobs = [];

  if (sitePhotoVal && sitePhotoVal !== 'N/A' && sitePhotoVal.trim() !== '') {
    sitePhotoVal.split(',').forEach(function (entry) {
      try {
        const photoId = entry.trim().match(/[-\w]{25,}/);
        if (photoId) {
          photoBlobs.push(DriveApp.getFileById(photoId[0]).getBlob());
        }
      } catch (err) {
        Logger.log('Photo load error: ' + err);
      }
    });
  }

  if (photoBlobs.length > 0) {
    appendSectionHeader(body, 'SITE PHOTOS', RED);
    body.appendParagraph('').setSpacingBefore(2).setSpacingAfter(4);

    for (let index = 0; index < photoBlobs.length; index += 2) {
      const photoTable = body.appendTable([['', '']]);
      photoTable.setBorderWidth(1);
      photoTable.setBorderColor(BORDER);
      setTableColumnWidth(photoTable, 0, PHOTO_COL_WIDTH);
      setTableColumnWidth(photoTable, 1, PHOTO_COL_WIDTH);

      const pc0 = photoTable.getCell(0, 0);
      pc0.clear();
      pc0.setBackgroundColor('#FAFAFA');
      pc0.setPaddingTop(6).setPaddingBottom(6).setPaddingLeft(6).setPaddingRight(6);
      const pi0 = pc0.insertImage(0, photoBlobs[index]);
      fitImageToBox(pi0, PHOTO_COL_WIDTH - 14, 170);

      const pc1 = photoTable.getCell(0, 1);
      pc1.clear();
      pc1.setBackgroundColor('#FAFAFA');
      pc1.setPaddingTop(6).setPaddingBottom(6).setPaddingLeft(6).setPaddingRight(6);
      if (photoBlobs[index + 1]) {
        const pi1 = pc1.insertImage(0, photoBlobs[index + 1]);
        fitImageToBox(pi1, PHOTO_COL_WIDTH - 14, 170);
      }

      body.appendParagraph('').setSpacingBefore(8).setSpacingAfter(4);
    }
  } else {
    appendSectionHeader(body, 'SITE PHOTOS', RED);
    const noPhoto = body.appendParagraph('No photos attached.');
    noPhoto.editAsText().setFontFamily('Arial').setFontSize(11).setForegroundColor(LGRAY);
  }

  // Template footer (Daikin logo + red bar) is left untouched.

  doc.saveAndClose();
  Logger.log('Doc written OK');

  const token = ScriptApp.getOAuthToken();
  const exportUrl = 'https://docs.google.com/feeds/download/documents/export/Export?id=' +
    docId + '&exportFormat=pdf';
  const pdfResp = UrlFetchApp.fetch(exportUrl, {
    headers: { Authorization: 'Bearer ' + token },
    muteHttpExceptions: true
  });

  if (pdfResp.getResponseCode() >= 300) {
    throw new Error('PDF export failed: HTTP ' + pdfResp.getResponseCode() + ' - ' + pdfResp.getContentText());
  }

  DriveApp.getFileById(docId).setTrashed(true);
  Logger.log('Temp Doc deleted');

  const suffix = isTest ? '_SiteReport_TEST.pdf' : '_SiteReport.pdf';
  const pdfName = Utilities.formatDate(new Date(), 'GMT+5:30', 'dd_MMM_yyyy') + '_' + projectName + suffix;
  return targetFolder.createFile(pdfResp.getBlob()).setName(pdfName);
}

function appendSectionHeader(body, text, color) {
  const para = body.appendParagraph(text);
  para.setSpacingBefore(14).setSpacingAfter(5);
  para.setAlignment(DocumentApp.HorizontalAlignment.LEFT);
  para.editAsText()
    .setFontFamily('Arial').setFontSize(9).setBold(true)
    .setForegroundColor(color || '#D0312D');
  return para;
}

function styleDetailTable(table, BGRAY, GRAY, DARK, GREEN, AMBER, LGRAY, WHITE) {
  for (let rowIndex = 0; rowIndex < table.getNumRows(); rowIndex++) {
    const row = table.getRow(rowIndex);

    const keyCell = row.getCell(0);
    keyCell.setVerticalAlignment(DocumentApp.VerticalAlignment.TOP);
    keyCell.setBackgroundColor(BGRAY);
    keyCell.setPaddingTop(7).setPaddingBottom(7).setPaddingLeft(9).setPaddingRight(6);
    keyCell.editAsText()
      .setFontFamily('Arial').setFontSize(8).setBold(true).setForegroundColor(GRAY);

    const valCell = row.getCell(1);
    const rawVal = valCell.getText().toLowerCase().trim();
    valCell.setVerticalAlignment(DocumentApp.VerticalAlignment.TOP);
    valCell.setPaddingTop(7).setPaddingBottom(7).setPaddingLeft(10).setPaddingRight(8);
    valCell.setBackgroundColor(rowIndex % 2 === 0 ? WHITE : '#FAFAFA');

    const text = valCell.editAsText();
    text.setFontFamily('Arial').setFontSize(10).setBold(false);

    if (rawVal === 'no' || rawVal === 'done') {
      text.setForegroundColor(GREEN).setBold(true);
    } else if (rawVal === 'yes') {
      text.setForegroundColor(AMBER).setBold(true);
    } else if (rawVal === 'n/a' || rawVal === '') {
      text.setForegroundColor(LGRAY);
    } else {
      text.setForegroundColor(DARK);
    }
  }
}
