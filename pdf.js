/**
 * VAKHARIA AIRTECH — SITE REPORT PDF GENERATOR + AUTO EMAIL
 * VERSION FINAL v9 — Auto email on form submit, v8 PDF format preserved
 */


const TEMPLATE_DOC_ID  = '1_dsXZdnwCajnmrfk4w3BgJI9-rz_vdDsCEJhHDisELo';

// ─────────────────────────────────────────────────────────────────────────────
// PART 1 — On Form Submit trigger  (set this as your trigger)
// ─────────────────────────────────────────────────────────────────────────────
function autoGeneratePDF(e) {
  const sheet   = e.range.getSheet();
  const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  const rowData = e.values;
  const rowNum  = e.range.getRow();

  const projIndex   = headers.indexOf('Select Project Name');
  const statusIndex = headers.indexOf("Mail Status") + 1;
  const pdfIdIndex  = headers.indexOf("PDF ID") + 1;
  const projectName = (projIndex > -1 && rowData[projIndex]) ? rowData[projIndex] : "General_Reports";

  // ── Find or create project folder ──
  const parentFolder = DriveApp.getFolderById(PARENT_FOLDER_ID);
  const subFolders   = parentFolder.getFoldersByName(projectName);
  const targetFolder = subFolders.hasNext() ? subFolders.next() : parentFolder.createFolder(projectName);

  const data = {};
  headers.forEach((h, i) => { data[h] = rowData[i] || ""; });

  // ── Build PDF ──
  const pdfFile = buildDocAndExportPDF(headers, rowData, data, projectName, targetFolder, false);

  // ── Save PDF ID to sheet ──
  if (pdfIdIndex > 0) sheet.getRange(rowNum, pdfIdIndex).setValue(pdfFile.getId());


// ── Look up ALL emails for this project from VRV Scraped Data1 (Col D) ──
let toAddresses = "crm@vakhariaairtech.com"; // fallback
try {
  const ss          = SpreadsheetApp.getActiveSpreadsheet();
  const scrapeSheet = ss.getSheetByName("VRV Scraped Data1");

  if (scrapeSheet) {
    const scrapeData = scrapeSheet.getDataRange().getValues();

    for (let k = 1; k < scrapeData.length; k++) {
      const rowProject = scrapeData[k][1] ? scrapeData[k][1].toString().trim().toLowerCase() : "";
      const rawEmails  = scrapeData[k][3] ? scrapeData[k][3].toString().trim() : "";

      if (rowProject === projectName.trim().toLowerCase()) {
        // ✅ Split by comma OR semicolon, clean each email
        const emailList = rawEmails
          .split(/[,;]+/)                        // split on comma or semicolon
          .map(e => e.trim())                    // remove spaces
          .filter(e => e.includes("@"));         // keep only valid emails

        if (emailList.length > 0) {
          toAddresses = emailList.join(",");
          Logger.log("✅ Found " + emailList.length + " email(s): " + toAddresses);
        } else {
          Logger.log("⚠️ Row matched but no valid emails in Col D");
        }
        break; // project found, stop scanning
      }
    }
  } else {
    Logger.log("❌ Sheet 'VRV Scraped Data1' not found");
  }

} catch (lookupErr) {
  Logger.log("Email lookup error: " + lookupErr);
}

Logger.log("📧 Final TO: " + toAddresses);

// ── Send email ──
try {
  GmailApp.sendEmail(toAddresses, "Site Report: " + projectName, "", {
    from: "crm@vakhariaairtech.com",
    name: "CRM Vakharia Airtech",
    cc: "crm@vakhariaairtech.com,mis@vakhariaairtech.com,piyush@vakhariaairtech.com",
    attachments: [pdfFile.getBlob()],
    htmlBody:
      "<div style='font-family:Arial,sans-serif;font-size:14px;color:#333;'>" +
      "<p>Dear Customer,</p>" +
      "<p>Please find the attached site progress report for <b>" + projectName + "</b>.</p><br>" +
      "<span style='color:red;font-weight:bold;font-size:16px;'>Vakharia Airtech Pvt. Ltd.</span><br>" +
      "<a href='https://www.vakhariaairtech.com/'>www.vakhariaairtech.com</a>" +
      "</div>"
  });

  if (statusIndex > 0) sheet.getRange(rowNum, statusIndex).setValue("SENT");
  Logger.log("✅ Email sent to: " + toAddresses);

} catch (mailErr) {
  if (statusIndex > 0) sheet.getRange(rowNum, statusIndex).setValue("ERROR: " + mailErr.message);
  Logger.log("❌ Email failed: " + mailErr);
}
}

// ─────────────────────────────────────────────────────────────────────────────
// PART 2 — TEST function (run manually from Apps Script editor)
// ─────────────────────────────────────────────────────────────────────────────
function TEST_autoGeneratePDF() {
  const TEST_FOLDER_ID = "1gS6OUjSOffA-9CslWReJlkthZ94dZUas";

  const ss      = SpreadsheetApp.getActiveSpreadsheet();
  const sheet   = ss.getSheets()[0];
  const lastRow = sheet.getLastRow();
  const lastCol = sheet.getLastColumn();

  const headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
  const values  = sheet.getRange(lastRow, 1, 1, lastCol).getValues()[0];

  Logger.log("=== TEST STARTED ===");
  Logger.log("Row: " + lastRow);

  const projIndex   = headers.indexOf("Select Project Name");
  const projectName = (projIndex > -1 && values[projIndex]) ? values[projIndex] : "General_Reports";
  Logger.log("Project: " + projectName);

  const testParent   = DriveApp.getFolderById(TEST_FOLDER_ID);
  const subFolders   = testParent.getFoldersByName(projectName);
  const targetFolder = subFolders.hasNext() ? subFolders.next() : testParent.createFolder(projectName);

  const data = {};
  headers.forEach((h, i) => { data[h] = values[i] || ""; });

  const pdfFile = buildDocAndExportPDF(headers, values, data, projectName, targetFolder, true);

  Logger.log("=== PDF CREATED ===");
  Logger.log("PDF Link: https://drive.google.com/file/d/" + pdfFile.getId() + "/view");
  Logger.log("Folder:   https://drive.google.com/drive/folders/" + TEST_FOLDER_ID);
  Logger.log("=== DONE ===");
}

// ─────────────────────────────────────────────────────────────────────────────
// CORE — Copy template, write with DocumentApp, export PDF
// ─────────────────────────────────────────────────────────────────────────────
function buildDocAndExportPDF(headers, rowData, data, projectName, targetFolder, isTest) {

  // 1. Copy the template (preserves original header/footer layout)
  const tempName   = 'Temp_' + projectName + '_' + new Date().getTime();
  const tempFolder = DriveApp.getFolderById(
    isTest ? "1gS6OUjSOffA-9CslWReJlkthZ94dZUas" : PARENT_FOLDER_ID
  );
  const copyFile = DriveApp.getFileById(TEMPLATE_DOC_ID).makeCopy(tempName, tempFolder);
  const docId    = copyFile.getId();
  const doc      = DocumentApp.openById(docId);
  const body     = doc.getBody();

  // 2. Clear body only (header/footer stay untouched)
  body.clear();

  // ── Colors ──
  const RED   = '#D0312D';
  const DARK  = '#1A1A1A';
  const GRAY  = '#777777';
  const LGRAY = '#AAAAAA';
  const AMBER = '#C8860A';
  const GREEN = '#1A7A4A';
  const WHITE = '#FFFFFF';
  const BGRAY = '#F5F5F5';

  // ── 3. Format timestamp ──
  var ts = data["Timestamp"] ? data["Timestamp"].toString() : "";
  var tsFormatted = ts;
  try {
    var d = new Date(ts);
    if (!isNaN(d.getTime())) {
      var months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
      tsFormatted = d.getDate() + " " + months[d.getMonth()] + " " + d.getFullYear() +
                    " | " +
                    ("0" + d.getHours()).slice(-2) + ":" + ("0" + d.getMinutes()).slice(-2);
    }
  } catch(ex) {}

  // ── 4. TITLE ROW — "SITE REPORT" left, timestamp right ──
  var titleTable = body.appendTable([['', '']]);
  titleTable.setBorderWidth(0);
  titleTable.setBorderColor(WHITE);

  var leftCell = titleTable.getCell(0, 0);
  leftCell.setPaddingTop(4).setPaddingBottom(4).setPaddingLeft(0).setPaddingRight(4);
  leftCell.setBackgroundColor(WHITE);
  var titlePara = leftCell.getChild(0).asParagraph();
  titlePara.setAlignment(DocumentApp.HorizontalAlignment.LEFT);
  titlePara.setSpacingBefore(0).setSpacingAfter(0);
  titlePara.clear();
  titlePara.appendText('SITE ').setFontFamily('Arial').setFontSize(22).setBold(true).setForegroundColor(DARK);
  titlePara.appendText('REPORT').setFontFamily('Arial').setFontSize(22).setBold(true).setForegroundColor(RED);

  var rightCell = titleTable.getCell(0, 1);
  rightCell.setPaddingTop(4).setPaddingBottom(4).setPaddingLeft(4).setPaddingRight(0);
  rightCell.setBackgroundColor(WHITE);
  var datePara = rightCell.getChild(0).asParagraph();
  datePara.setAlignment(DocumentApp.HorizontalAlignment.RIGHT);
  datePara.setSpacingBefore(0).setSpacingAfter(0);
  datePara.clear();
  datePara.appendText(tsFormatted)
    .setFontFamily('Courier New').setFontSize(11).setBold(true).setForegroundColor(AMBER);

  // Red divider line
  var redLine = body.appendParagraph('');
  redLine.setSpacingBefore(4).setSpacingAfter(10);
  redLine.editAsText().setForegroundColor(RED);

  // ── 5. TODAY'S ACTIVITY ──
  appendSectionHeader(body, "TODAY'S ACTIVITY", RED);

  var activityValue = data["Today's Activity"] || "N/A";
  var actPara = body.appendParagraph(activityValue);
  actPara.setSpacingBefore(2).setSpacingAfter(12);
  actPara.editAsText()
    .setFontFamily('Arial').setFontSize(13).setBold(true).setForegroundColor(DARK);

  body.appendParagraph('').setSpacingAfter(8);

  // ── 6. PROJECT DETAILS ──
  appendSectionHeader(body, 'PROJECT DETAILS', RED);

  function shouldSkipHeader(headerText) {
    const lower = headerText.toLowerCase();
    // ONLY skip backend/system columns and photo-upload fields
    // (photo fields are shown as "Images Attached Below" in the table,
    //  actual images are rendered separately in the SITE PHOTOS section)
    const skipPatterns = [
      "email address",
      "mail status",
      "pdf id",
      "timestamp",                              // shown in title row already
      "today's activity",                       // shown in its own section above
      "if yes: upload the measurement report",  // file upload — not useful as text
      "upload site photo",                      // handled in SITE PHOTOS section
      "site photos",                            // handled in SITE PHOTOS section
      "if yes :upload photo here",              // drawing change photo — shown separately
      "if yes: upload photo here"
    ];
    return skipPatterns.some(pattern => lower.includes(pattern));
  }

  var tableData = [];
  headers.forEach(function(h, i) {
    if (shouldSkipHeader(h)) return;
    var val = rowData[i] ? rowData[i].toString() : "N/A";
    if (!val || val.trim() === "") val = "N/A";
    tableData.push([h.toUpperCase(), val]);
  });

  if (tableData.length > 0) {
    var detailTable = body.appendTable(tableData);
    detailTable.setBorderWidth(0);
    styleDetailTable(detailTable, BGRAY, GRAY, DARK, GREEN, AMBER, LGRAY, WHITE);
  }

  body.appendParagraph('').setSpacingAfter(8);

  // ── 7. DRAWING CHANGE PHOTO ──
  var drawingInserted = false;
  headers.forEach(function(h, i) {
    if (drawingInserted) return;
    const lower = h.toLowerCase();
    // Match: "Any Changes in Drawing as per Project Condition? if Yes :Upload photo here"
    if (lower.includes("changes in drawing") &&
        (lower.includes("upload") || lower.includes("photo here")) &&
        rowData[i] && rowData[i] !== "N/A" && rowData[i].toString().trim() !== "") {
      try {
        var drawingId = rowData[i].match(/[-\w]{25,}/);
        if (drawingId) {
          body.appendParagraph('').setSpacingBefore(20).setSpacingAfter(4);
          appendSectionHeader(body, 'DRAWING CHANGE PHOTO', RED);
          var imgBlob = DriveApp.getFileById(drawingId[0]).getBlob();
          var img = body.appendImage(imgBlob);
          img.setWidth(250).setHeight(180);
          body.appendParagraph('').setSpacingAfter(10);
          drawingInserted = true;
        }
      } catch(err) { Logger.log("Drawing photo error: " + err); }
    }
  });

  // ── 8. SITE PHOTOS (page break before, 2-column grid) ──
  var sitePhotoKey = null;
  for (var hi = 0; hi < headers.length; hi++) {
    const hl = headers[hi].toLowerCase();
    if (hl === "upload site photo" || hl === "site photos" ||
        (hl.includes("upload") && hl.includes("site photo"))) {
      sitePhotoKey = headers[hi];
      break;
    }
  }
  var sitePhotoVal = sitePhotoKey ? (data[sitePhotoKey] || "") : "";

  body.appendPageBreak();
  body.appendParagraph('').setSpacingBefore(8).setSpacingAfter(0);
  appendSectionHeader(body, 'SITE PHOTOS', RED);
  body.appendParagraph('').setSpacingBefore(12).setSpacingAfter(6);

  if (sitePhotoVal && sitePhotoVal !== "N/A" && sitePhotoVal.trim() !== "") {
    var photoEntries = sitePhotoVal.split(',');
    var photoBlobs   = [];

    photoEntries.forEach(function(entry) {
      try {
        var photoId = entry.trim().match(/[-\w]{25,}/);
        if (photoId) {
          photoBlobs.push(DriveApp.getFileById(photoId[0]).getBlob());
        }
      } catch(err) { Logger.log("Photo load error: " + err); }
    });

    // 2-column photo grid
    for (var p = 0; p < photoBlobs.length; p += 2) {
      var photoTable = body.appendTable([['', '']]);
      photoTable.setBorderWidth(0);
      photoTable.setColumnWidth(0, 240);
      photoTable.setColumnWidth(1, 240);

      var pc0 = photoTable.getCell(0, 0);
      pc0.clear();
      pc0.setPaddingTop(6).setPaddingBottom(6).setPaddingLeft(0).setPaddingRight(6);
      var pi0 = pc0.insertImage(0, photoBlobs[p]);
      pi0.setWidth(228).setHeight(160);

      var pc1 = photoTable.getCell(0, 1);
      pc1.clear();
      pc1.setPaddingTop(6).setPaddingBottom(6).setPaddingLeft(6).setPaddingRight(0);
      if (photoBlobs[p + 1]) {
        var pi1 = pc1.insertImage(0, photoBlobs[p + 1]);
        pi1.setWidth(228).setHeight(160);
      }

      body.appendParagraph('').setSpacingBefore(8).setSpacingAfter(4);
    }

  } else {
    var noPhoto = body.appendParagraph('No photos attached.');
    noPhoto.editAsText().setFontFamily('Arial').setFontSize(11).setForegroundColor(LGRAY);
    body.appendParagraph('').setSpacingAfter(6);
  }

  // ── 9. FOOTER — preserve Daikin logo from template ──
  try {
    let footer = doc.getFooter();
    if (!footer) footer = doc.addFooter();

    let logoBlob = null;
    const existingElements = footer.getNumChildren();
    for (let i = 0; i < existingElements; i++) {
      const elem = footer.getChild(i);
      if (elem.getType() === DocumentApp.ElementType.PARAGRAPH) {
        const para   = elem.asParagraph();
        const images = para.getImages();
        if (images.length > 0) {
          logoBlob = images[0].getBlob();
          break;
        }
      }
    }

    footer.clear();

    const footerTable = footer.appendTable([['']]);
    footerTable.setBorderWidth(0);
    footerTable.setColumnWidth(0, 200);

    const logoCell = footerTable.getCell(0, 0);
    logoCell.setPaddingTop(4).setPaddingBottom(4).setPaddingLeft(0).setPaddingRight(0);
    logoCell.setVerticalAlignment(DocumentApp.VerticalAlignment.MIDDLE);

    if (logoBlob) {
      const logoImage = logoCell.insertImage(0, logoBlob);
      logoImage.setWidth(100).setHeight(40);
    } else {
      const logoText = logoCell.appendParagraph("DAIKIN");
      logoText.setAlignment(DocumentApp.HorizontalAlignment.LEFT);
      logoText.editAsText().setFontFamily('Arial').setFontSize(12).setBold(true).setForegroundColor(RED);
    }
  } catch(footerErr) {
    Logger.log("Footer rewrite error: " + footerErr);
  }

  // ── 10. Save & export PDF via Drive export API ──
  doc.saveAndClose();
  Logger.log("Doc written OK");

  const token   = ScriptApp.getOAuthToken();
  const pdfUrl  = "https://docs.google.com/feeds/download/documents/export/Export?id=" + docId + "&exportFormat=pdf";
  const pdfResp = UrlFetchApp.fetch(pdfUrl, {
    headers: { "Authorization": "Bearer " + token },
    muteHttpExceptions: true
  });

  DriveApp.getFileById(docId).setTrashed(true);
  Logger.log("Temp Doc deleted");

  var suffix  = isTest ? "_SiteReport_TEST.pdf" : "_SiteReport.pdf";
  var pdfName = Utilities.formatDate(new Date(), "GMT+5:30", "dd_MMM_yyyy") + "_" + projectName + suffix;
  var pdfFile = targetFolder.createFile(pdfResp.getBlob()).setName(pdfName);

  return pdfFile;
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────
function appendSectionHeader(body, text, color) {
  var para = body.appendParagraph(text);
  para.setSpacingBefore(12).setSpacingAfter(6);
  para.editAsText()
    .setFontFamily('Arial').setFontSize(10).setBold(true)
    .setForegroundColor(color || '#D0312D');
  return para;
}

function styleDetailTable(table, BGRAY, GRAY, DARK, GREEN, AMBER, LGRAY, WHITE) {
  for (var r = 0; r < table.getNumRows(); r++) {
    var row = table.getRow(r);

    var keyCell = row.getCell(0);
    keyCell.setBackgroundColor(BGRAY);
    keyCell.setPaddingTop(8).setPaddingBottom(8).setPaddingLeft(10).setPaddingRight(6);
    keyCell.editAsText()
      .setFontFamily('Arial').setFontSize(9).setBold(true).setForegroundColor(GRAY);

    var valCell = row.getCell(1);
    var rawVal  = valCell.getText().toLowerCase().trim();
    valCell.setPaddingTop(8).setPaddingBottom(8).setPaddingLeft(10).setPaddingRight(6);
    valCell.setBackgroundColor(r % 2 === 0 ? WHITE : '#FAFAFA');

    var vt = valCell.editAsText();
    vt.setFontFamily('Arial').setFontSize(11).setBold(false);

    if (rawVal === 'no' || rawVal === 'done') {
      vt.setForegroundColor(GREEN).setBold(true);
    } else if (rawVal === 'yes') {
      vt.setForegroundColor(AMBER).setBold(true);
    } else if (rawVal === 'n/a' || rawVal === '') {
      vt.setForegroundColor(LGRAY);
    } else {
      vt.setForegroundColor(DARK);
    }
  }
}