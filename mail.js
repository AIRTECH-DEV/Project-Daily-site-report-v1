/**
 * Scrapes emails with Rate-Limit Handling (Exponential Backoff).
 * Pulls from BPMS VRV -> Saves to Daily Site Updates.
 */
/*function scrapeEmailsFromPDFs() {
  const SOURCE_SS_ID = "1HwYDM6ARcDomEqmhqBTxRe3OsAzeezTq_8Wnhsm3_eY"; 
  const TARGET_SS_ID = "1hPvEw0rxaOmg2JDtdp9q8XqjbrV98PZL2jiZVz6XWFI"; 

  const sourceSS = SpreadsheetApp.openById(SOURCE_SS_ID);
  const targetSS = SpreadsheetApp.openById(TARGET_SS_ID);
  const sourceSheet = sourceSS.getSheetByName("Orders");
  
  if (!sourceSheet) {
    console.error("Error: Could not find 'Orders' tab.");
    return;
  }

  let targetSheet = targetSS.getSheetByName("VRV Scraped Email");
  if (!targetSheet) {
    targetSheet = targetSS.insertSheet("VRV Scraped Email");
    targetSheet.appendRow(["Order ID", "Project Name", "Source PDF Link", "Scraped Emails", "Status", "Timestamp"]);
  }

  // Column Indices (0-based)
  const ORDER_ID_IDX = 1;     // Column B
  const PROJECT_NAME_IDX = 3; // Column D
  const PDF_LINK_IDX = 17;    // Column R

  const sourceData = sourceSheet.getDataRange().getValues();
  const targetData = targetSheet.getDataRange().getValues();
  const existingIds = new Set(targetData.map(row => row[0].toString().trim())); 

  for (let i = 1; i < sourceData.length; i++) {
    const row = sourceData[i];
    const orderId = row[ORDER_ID_IDX] ? row[ORDER_ID_IDX].toString().trim() : "";
    const projectName = row[PROJECT_NAME_IDX] || "N/A";
    const pdfUrl = row[PDF_LINK_IDX];

    if (!orderId || existingIds.has(orderId)) continue;

    if (pdfUrl && pdfUrl.toString().includes("drive.google.com")) {
      let retryCount = 0;
      let success = false;
      let emails = [];

      // Retry loop to handle "Rate Limit Exceeded"
      while (retryCount < 3 && !success) {
        try {
          const fileId = extractFileId(pdfUrl);
          emails = getEmailsFromPDF(fileId);
          success = true;
        } catch (e) {
          if (e.message.includes("rate limit") || e.message.includes("Limit Exceeded")) {
            retryCount++;
            console.warn(`Rate limit hit for ${orderId}. Retrying in ${retryCount * 2} seconds...`);
            Utilities.sleep(retryCount * 2000); // Wait 2, 4, then 6 seconds
          } else {
            console.error(`Permanent error for ${orderId}: ${e.message}`);
            break; 
          }
        }
      }

      if (success) {
        targetSheet.appendRow([orderId, projectName, pdfUrl, emails.join(", "), "Processed", new Date()]);
        existingIds.add(orderId);
      } else {
        targetSheet.appendRow([orderId, projectName, pdfUrl, "N/A", "Error: Rate Limit/Access", new Date()]);
      }
      
      // Small intentional pause to prevent hitting the limit again immediately
      Utilities.sleep(500); 
    }
  }
  console.log("Process Finished.");
}

/**
 * OCR Logic with Drive API v2
 */
/*function getEmailsFromPDF(fileId) {
  const file = DriveApp.getFileById(fileId);
  const blob = file.getBlob();
  const resource = { title: "Temp_OCR_" + fileId, mimeType: blob.getContentType() };
  
  // This is the call that usually hits the rate limit
  const tempDocFile = Drive.Files.insert(resource, blob, { ocr: true });
  
  const doc = DocumentApp.openById(tempDocFile.id);
  const text = doc.getBody().getText();
  Drive.Files.remove(tempDocFile.id);

  const emailRegex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
  const matches = text.match(emailRegex);
  return matches ? [...new Set(matches)] : ["No emails found"];
}

function extractFileId(url) {
  const match = url.match(/[-\w]{25,}/);
  return match ? match[0] : null;
}*/

/***********************************************End here */



/**
 * Scrapes Contact Numbers & Emails with Rate-Limit Handling.
 * Pulls from BPMS VRV -> Saves to Daily Site Updates.
 * correct code working up to 18/4/26
 */
/*function scrapeContactsFromPDFs() {
  const SOURCE_SS_ID = "1HwYDM6ARcDomEqmhqBTxRe3OsAzeezTq_8Wnhsm3_eY"; 
  const TARGET_SS_ID = "1hPvEw0rxaOmg2JDtdp9q8XqjbrV98PZL2jiZVz6XWFI"; 
  // 1. Add both IDs to an array
 
  const sourceSS = SpreadsheetApp.openById(SOURCE_SS_ID);
  const targetSS = SpreadsheetApp.openById(TARGET_SS_ID);
  const sourceSheet = sourceSS.getSheetByName("Orders");
  
  if (!sourceSheet) {
    console.error("Error: Could not find 'Orders' tab.");
    return;
  }

  let targetSheet = targetSS.getSheetByName("VRV Scraped Data1");
  if (!targetSheet) {
    targetSheet = targetSS.insertSheet("VRV Scraped Data1");
    // Added "Scraped Phones" to the header
    targetSheet.appendRow(["Order ID", "Project Name", "Source PDF Link", "Scraped Emails", "Scraped Phones", "Status", "Timestamp"]);
  }
  
  const ORDER_ID_IDX = 1;     // Column B
  const PROJECT_NAME_IDX = 3; // Column D
  const PDF_LINK_IDX = 17;    // Column R

  const sourceData = sourceSheet.getDataRange().getValues();
  const targetData = targetSheet.getDataRange().getValues();
  const existingIds = new Set(targetData.map(row => row[0].toString().trim())); 

  for (let i = 1; i < sourceData.length; i++) {
    const row = sourceData[i];
    const orderId = row[ORDER_ID_IDX] ? row[ORDER_ID_IDX].toString().trim() : "";
    const projectName = row[PROJECT_NAME_IDX] || "N/A";
    const pdfUrl = row[PDF_LINK_IDX];

    if (!orderId || existingIds.has(orderId)) continue;

    if (pdfUrl && pdfUrl.toString().includes("drive.google.com")) {
      let retryCount = 0;
      let success = false;
      let extractedData = { emails: [], phones: [] };

      while (retryCount < 3 && !success) {
        try {
          const fileId = extractFileId(pdfUrl);
          // Now returns an object containing both
          extractedData = getDetailsFromPDF(fileId);
          success = true;
        } catch (e) {
          if (e.message.includes("rate limit") || e.message.includes("Limit Exceeded")) {
            retryCount++;
            console.warn(`Rate limit hit for ${orderId}. Retrying in ${retryCount * 2} seconds...`);
            Utilities.sleep(retryCount * 2000);
          } else {
            console.error(`Permanent error for ${orderId}: ${e.message}`);
            break; 
          }
        }
      }

      if (success) {
        targetSheet.appendRow([
          orderId, 
          projectName, 
          pdfUrl, 
          extractedData.emails.join(", "), 
          extractedData.phones.join(", "), 
          "Processed", 
          new Date()
        ]);
        existingIds.add(orderId);
      } else {
        targetSheet.appendRow([orderId, projectName, pdfUrl, "N/A", "N/A", "Error: Rate Limit/Access", new Date()]);
      }
      
      Utilities.sleep(500); 
    }
  }
  console.log("Process Finished.");
}*/
/**
 * Scrapes contact details from PDFs listed in multiple source spreadsheets.
 */
function scrapeContactsFromPDFs() {
  // 1. Array of all Source Spreadsheet IDs
  const SOURCE_SS_IDS = [
    "1HwYDM6ARcDomEqmhqBTxRe3OsAzeezTq_8Wnhsm3_eY", // Original Sheet
    "1SV_WhGa_sEdUkj1X46xRtoCNo5KG3Khi1jRkdl9LSz0"  // New Sheet provided
  ];
  
  // Target Spreadsheet where data is consolidated
  const TARGET_SS_ID = "1hPvEw0rxaOmg2JDtdp9q8XqjbrV98PZL2jiZVz6XWFI";
  const targetSS = SpreadsheetApp.openById(TARGET_SS_ID);
  
  let targetSheet = targetSS.getSheetByName("VRV Scraped Data1");
  if (!targetSheet) {
    targetSheet = targetSS.insertSheet("VRV Scraped Data1");
    targetSheet.appendRow(["Order ID", "Project Name", "Source PDF Link", "Scraped Emails", "Scraped Phones", "Status", "Timestamp"]);
  }

  // Define Column Indexes (0-based)
  const ORDER_ID_IDX = 1;     // Column B
  const PROJECT_NAME_IDX = 3; // Column D
  const PDF_LINK_IDX = 17;    // Column R

  // Get existing Order IDs to avoid duplicates
  const targetData = targetSheet.getDataRange().getValues();
  const existingIds = new Set(targetData.map(row => row[0].toString().trim()));

  // 2. Loop through each Source Spreadsheet
  SOURCE_SS_IDS.forEach(ssId => {
    try {
      console.log("Processing Spreadsheet ID: " + ssId);
      const sourceSS = SpreadsheetApp.openById(ssId);
      const sourceSheet = sourceSS.getSheetByName("Orders");
      
      if (!sourceSheet) {
        console.warn("Could not find 'Orders' tab in sheet: " + ssId);
        return; // Skip to next spreadsheet in array
      }

      const sourceData = sourceSheet.getDataRange().getValues();

      // Loop through rows (skipping header)
      for (let i = 1; i < sourceData.length; i++) {
        const row = sourceData[i];
        const orderId = row[ORDER_ID_IDX] ? row[ORDER_ID_IDX].toString().trim() : "";
        const projectName = row[PROJECT_NAME_IDX] || "N/A";
        const pdfUrl = row[PDF_LINK_IDX];

        // Skip if empty or already processed
        if (!orderId || existingIds.has(orderId)) continue;

        if (pdfUrl && pdfUrl.toString().includes("drive.google.com")) {
          let retryCount = 0;
          let success = false;
          let extractedData = { emails: [], phones: [] };

          while (retryCount < 3 && !success) {
            try {
              const fileId = extractFileId(pdfUrl);
              extractedData = getDetailsFromPDF(fileId);
              success = true;
            } catch (e) {
              if (e.message.includes("rate limit") || e.message.includes("Limit Exceeded")) {
                retryCount++;
                console.warn(`Rate limit hit for ${orderId}. Retrying...`);
                Utilities.sleep(retryCount * 2000);
              } else {
                console.error(`Permanent error for ${orderId}: ${e.message}`);
                break; 
              }
            }
          }

          if (success) {
            targetSheet.appendRow([
              orderId, 
              projectName, 
              pdfUrl, 
              extractedData.emails.join(", "), 
              extractedData.phones.join(", "), 
              "Processed", 
              new Date()
            ]);
            existingIds.add(orderId);
          } else {
            targetSheet.appendRow([orderId, projectName, pdfUrl, "N/A", "N/A", "Error: Rate Limit/Access", new Date()]);
          }
          
          Utilities.sleep(500); // Small pause to prevent hitting API limits
        }
      }
    } catch (err) {
      console.error("Error accessing Spreadsheet " + ssId + ": " + err.message);
    }
  });

  console.log("Process Finished for all sheets.");
}

/**
 * OCR Logic to extract Emails and Phone Numbers
 */
function getDetailsFromPDF(fileId) {
  const file = DriveApp.getFileById(fileId);
  const blob = file.getBlob();
  const resource = { title: "Temp_OCR_" + fileId, mimeType: blob.getContentType() };
  
  const tempDocFile = Drive.Files.insert(resource, blob, { ocr: true });
  const doc = DocumentApp.openById(tempDocFile.id);
  const text = doc.getBody().getText();
  
  // Cleanup
  Drive.Files.remove(tempDocFile.id);

  // Email Regex
  const emailRegex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
  
  // Phone Regex: Matches standard formats like +1-123-456-7890, (123) 456 7890, 1234567890, etc.
  // Note: This matches strings of 10-15 digits that may include spaces, dots, or dashes.
  const phoneRegex = /(\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4,5}/g;

  const emailMatches = text.match(emailRegex);
  const phoneMatches = text.match(phoneRegex);

  return {
    emails: emailMatches ? [...new Set(emailMatches)] : ["No emails found"],
    phones: phoneMatches ? [...new Set(phoneMatches.map(p => p.trim()))] : ["No phones found"]
  };
}

function extractFileId(url) {
  const match = url.match(/[-\w]{25,}/);
  return match ? match[0] : null;
}


