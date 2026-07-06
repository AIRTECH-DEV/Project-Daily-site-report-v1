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

const PARENT_FOLDER_ID = '1xlYIM-BH80gbymALM6ehIF5NwhZzAAGP';
const LOGO_URL = 'https://drive.google.com/file/d/1TU2KKJN4AQKkG7nMtMlCoiYX1wMD2QtB/view?usp=drive_link';

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

    try {
      const pdfFile = generateSiteReportPDF(sheet, headers, newRow, projectName, folder);
      const pdfIdCol = findColIndex(headers, 'pdf id');
      if (pdfIdCol > -1 && pdfFile) {
        sheet.getRange(newRow, pdfIdCol + 1).setValue(pdfFile.getId());
      }
    } catch (pdfErr) {
      Logger.log('PDF generation failed: ' + pdfErr);
    }

    return { success: true, row: newRow };
  } catch (err) {
    Logger.log('submitSiteReport error: ' + err);
    return { success: false, error: err.message };
  }
}

/**
 * Same letterhead/table/photo layout as the original autoGeneratePDF,
 * sourced from a row number instead of a Form-submit event.
 */
function generateSiteReportPDF(sheet, headers, rowNum, projectName, folder) {
  const rowData = sheet.getRange(rowNum, 1, 1, headers.length).getValues()[0];

  const doc = DocumentApp.create('Temp_Report_' + projectName);
  const body = doc.getBody();
  body.setMarginTop(36).setMarginLeft(40).setMarginRight(40).setMarginBottom(36);

  const headerTable = body.appendTable([['']]);
  const cell = headerTable.getRow(0).getCell(0);
  headerTable.setBorderWidth(0);
  cell.setBackgroundColor('#FFFFFF');
  cell.setPaddingTop(20).setPaddingBottom(15);
  headerTable.setColumnWidth(0, 520);

  try {
    const logoId = LOGO_URL.match(/[-\w]{25,}/)[0];
    const logoBlob = DriveApp.getFileById(logoId).getBlob();
    const image = cell.appendImage(logoBlob);
    const ratio = image.getWidth() / image.getHeight();
    image.setWidth(560);
    image.setHeight(560 / ratio);
    image.getParent().asParagraph().setAlignment(DocumentApp.HorizontalAlignment.CENTER);

    const companyText = cell.appendParagraph('Site Report');
    companyText.setAlignment(DocumentApp.HorizontalAlignment.CENTER)
      .setBold(true).setForegroundColor('#721c24').setFontSize(16).setSpacingBefore(10);
  } catch (err) {
    cell.appendParagraph('VAKHARIA AIRTECH PVT. LTD.')
      .setAlignment(DocumentApp.HorizontalAlignment.CENTER);
  }

  const drawingPhotoColIdx = findColIndex(headers, 'upload photo here');
  if (drawingPhotoColIdx > -1) {
    const drawingVal = rowData[drawingPhotoColIdx];
    if (drawingVal && drawingVal !== 'N/A' && drawingVal !== '') {
      try {
        const idMatch = drawingVal.toString().match(/[-\w]{25,}/);
        if (idMatch) {
          const imgBlob = DriveApp.getFileById(idMatch[0]).getBlob();
          body.appendParagraph('\nDRAWING CHANGE PHOTO:').setBold(true);
          const dImg = body.appendImage(imgBlob);
          const dRatio = dImg.getWidth() / dImg.getHeight();
          dImg.setWidth(450).setHeight(450 / dRatio);
          body.appendParagraph('');
        }
      } catch (e) {
        body.appendParagraph('Could not load drawing image: ' + e.message);
      }
    }
  }

  body.appendParagraph('\n');
  const excludeHeaders = [
    'email address', 'mail status', 'pdf id',
    'measurement report created today', 'upload the measurement report here',
    'what is the next plan for this site tomorrow', 'upload photo here'
  ];
  const tableData = [];
  headers.forEach(function (header, idx) {
    const hLower = (header || '').toString().toLowerCase();
    const skip = excludeHeaders.some(function (ex) { return hLower.indexOf(ex) !== -1; });
    if (skip) return;
    const value = rowData[idx] || 'N/A';
    if (hLower.indexOf('upload site photo') !== -1 && value !== 'N/A') {
      tableData.push([header, 'Images attached below']);
    } else {
      tableData.push([header, value]);
    }
  });
  const table = body.appendTable(tableData);
  table.setBorderWidth(1);
  table.setColumnWidth(0, 150);

  const sitePhotoColIdx = findColIndex(headers, 'upload site photo');
  if (sitePhotoColIdx > -1 && rowData[sitePhotoColIdx]) {
    const entries = rowData[sitePhotoColIdx].toString().split(',');
    body.appendParagraph('\nSITE PHOTO:').setBold(true);
    entries.forEach(function (entry) {
      try {
        const idMatch = entry.trim().match(/[-\w]{25,}/);
        if (idMatch) {
          const blob = DriveApp.getFileById(idMatch[0]).getBlob();
          const img = body.appendImage(blob);
          const r = img.getWidth() / img.getHeight();
          img.setWidth(450).setHeight(450 / r);
          img.getParent().asParagraph().setAlignment(DocumentApp.HorizontalAlignment.CENTER);
          body.appendParagraph('');
        }
      } catch (e) {
        body.appendParagraph('Could not load image: ' + e.message);
      }
    });
  }

  doc.saveAndClose();
  const pdfBlob = DriveApp.getFileById(doc.getId()).getAs(MimeType.PDF);
  const pdfName = Utilities.formatDate(new Date(), 'GMT+5:30', 'dd_MMM_yyyy') + '_' + projectName + '_SiteReport.pdf';
  const pdfFile = folder.createFile(pdfBlob).setName(pdfName);
  DriveApp.getFileById(doc.getId()).setTrashed(true);
  return pdfFile;
}
