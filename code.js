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

const NONVRV_ORDERS_SHEET_ID = '1hPvEw0rxaOmg2JDtdp9q8XqjbrV98PZL2jiZVz6XWFI';
const NONVRV_ORDERS_GID = 1766836681;

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
 * Serves the web app UI.
 */
function doGet() {
  return HtmlService.createHtmlOutputFromFile('Index')
    .setTitle('Site Visit Report')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
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
    // Non-VRV response sheet uses "Select Project Name"; the VRV
    // Order-to-Delivery sheet just uses "Project Name" — try both.
    // The exclusion guards against headers like "Site / Project executive by".
    let projectCol = findColIndex(headers, 'select project name');
    if (projectCol === -1) projectCol = findColIndex(headers, 'project name', 'executive');
    if (projectCol === -1) {
      Logger.log('getProjectNames: no project-name header found in ' + sheetId +
        '. Actual headers: ' + headers.join(' | '));
      return [];
    }

    const data = sheet.getRange(2, projectCol + 1, lastRow - 1, 1).getValues();
    const cleaned = data
      .map(function (r) { return r[0] ? r[0].toString().trim().replace(/\s+/g, ' ') : ''; })
      .filter(function (v) { return v !== ''; });

    return Array.from(new Set(cleaned)).sort();
  } catch (err) {
    Logger.log('getProjectNames error for ' + siteType + ' (' + sheetId + '): ' + err);
    return [];
  }
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
 *   project, people, engineer, activity, nextPlan,
 *   photos: [{base64,mimeType,name}, ...],
 *   amendment, amendmentWhy,
 *   drawingChange, drawingPhoto: {base64,mimeType,name}|null,
 *   measurement, measurementFile: {base64,mimeType,name}|null
 * }
 */
function submitSiteReport(payload) {
  try {
    const ss = SpreadsheetApp.openById(RESPONSE_SHEET_ID);
    const tabName = payload.siteType === 'VRV' ? TAB_NAMES.VRV : TAB_NAMES.NONVRV;
    const sheet = ss.getSheetByName(tabName);
    if (!sheet) {
      throw new Error('Response tab "' + tabName + '" not found — run setupResponseSheets() first.');
    }

    const lastCol = sheet.getLastColumn();
    const headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0];

    const projectName = (payload.project || 'General_Reports').trim();
    const folder = getOrCreateProjectFolder(projectName);

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

    const email = Session.getActiveUser().getEmail() || 'unknown';

    const row = new Array(headers.length).fill('');
    const set = function (idx, value) { if (idx > -1) row[idx] = value; };

    set(findColIndex(headers, 'timestamp'), new Date());
    set(findColIndex(headers, 'email address'), email);
    set(findColIndex(headers, 'site type'), payload.siteType || 'Non-VRV');
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
    } catch (pdfErr) {
      const pdfMessage = pdfErr && pdfErr.message ? pdfErr.message : pdfErr.toString();
      Logger.log('PDF generation failed: ' + pdfMessage);
      if (mailStatusCol > -1) {
        sheet.getRange(newRow, mailStatusCol + 1).setValue('PDF FAILED: ' + pdfMessage);
      }
      return { success: false, error: 'Data saved, but PDF generation failed: ' + pdfMessage };
    }

    return { success: true, row: newRow, pdfUrl: pdfUrl };
  } catch (err) {
    Logger.log('submitSiteReport error: ' + err);
    return { success: false, error: err.message };
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

  body.appendParagraph('').setSpacingAfter(8);

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
          body.appendParagraph('').setSpacingBefore(20).setSpacingAfter(4);
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
    // No forced page break — let content flow so short reports stay compact
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
