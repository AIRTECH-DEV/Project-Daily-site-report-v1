// /**
//  * RUN THIS ONCE from the Apps Script editor (select setupResponseSheets,
//  * click Run). It creates the VRV and Non-VRV tabs in the new response
//  * spreadsheet with the correct headers. Safe to run again later — it
//  * just re-writes the header row, it won't touch existing data rows.
//  * Delete this file once you've run it.
//  */
// function setupResponseSheets() {
//   const RESPONSE_SPREADSHEET_ID = '1LL9yDxL5uCv_szvnv-7MOQmJgDuDEuXhdS-WvVnLbOI';

//   const HEADERS = [
//     'Timestamp',
//     'Email Address',
//     'Site Type',
//     'Select Project Name',
//     'Number of people',
//     'Project Engineer Name',
//     "Today's Activity",
//     'Upload Site Photo',
//     'What is the next plan for this site tomorrow?',
//     'Any amendment/PO/ approval Required? \n',
//     'Any amendment/PO/ approval Required?\nIf Yes : why?',
//     'Any Changes in Drawing as per Project Condition?',
//     'Any Changes in Drawing as per Project Condition?\nif Yes :Upload photo here ',
//     'Measurement Report Created Today?',
//     'Measurement Report Created Today?\nif Yes: Upload the Measurement Report Here',
//     'Mail Status',
//     'PDF ID'
//   ];

//   const ss = SpreadsheetApp.openById(RESPONSE_SPREADSHEET_ID);

//   ['VRV', 'Non-VRV'].forEach(function (tabName) {
//     let sheet = ss.getSheetByName(tabName);
//     if (!sheet) {
//       sheet = ss.insertSheet(tabName);
//       Logger.log('Created tab: ' + tabName);
//     } else {
//       Logger.log('Tab already exists, refreshing headers: ' + tabName);
//     }
//     sheet.getRange(1, 1, 1, HEADERS.length).setValues([HEADERS]);
//     sheet.getRange(1, 1, 1, HEADERS.length).setFontWeight('bold');
//     sheet.setFrozenRows(1);
//   });

//   // Clean up the default blank "Sheet1" if it's still sitting there empty
//   const defaultSheet = ss.getSheetByName('Sheet1');
//   if (defaultSheet && defaultSheet.getLastRow() === 0 && ss.getSheets().length > 2) {
//     ss.deleteSheet(defaultSheet);
//     Logger.log('Removed empty default Sheet1');
//   }

//   Logger.log('Done. Tabs ready: VRV, Non-VRV');
// }
