<?php
/**
 * Central config for the PHP Site-Visit-Report backend.
 * All sheet / folder IDs are ported verbatim from the old code.js so the
 * service account writes to the exact same destinations Apps Script did.
 *
 * NOTE: keep config/ out of git — it holds the SA private key. See .gitignore.
 */

return [
    // ---- Google service account + auth ----------------------------------
    'service_account' => __DIR__ . '/google-service-account.json',
    'token_cache_dir' => __DIR__ . '/../storage/tokens',
    'scopes' => [
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive',
    ],

    // ---- Response spreadsheet (submissions land here) -------------------
    'response_sheet_id' => '1LL9yDxL5uCv_szvnv-7MOQmJgDuDEuXhdS-WvVnLbOI',
    'tab_names' => ['VRV' => 'VRV', 'NONVRV' => 'Non-VRV'],

    // ---- Orders sheets (dropdown + Order ID + phone/email lookups) ------
    'vrv_orders_sheet_id'    => '1SV_WhGa_sEdUkj1X46xRtoCNo5KG3Khi1jRkdl9LSz0',
    'vrv_orders_gid'         => 290389899,
    'nonvrv_orders_sheet_id' => '1hvqgSI3f05d1wSoQVxaBPDzr4maHhTz5MLqhmN4a5Is',
    'nonvrv_orders_gid'      => 290389899,

    // ---- General PMS progress sheet ------------------------------------
    'general_pms_sheet_id' => '1-dkSwABh61SgjPRyEwei6_v0-zQK4jj_FwqHOiGvM0M',
    'general_pms_tabs'     => ['VRV' => 'PMS - VRV', 'NONVRV' => 'PMS - NonVRV'],

    // ---- Developer building progress sheets ----------------------------
    'developer_building_sheets' => [
        'Suyog Navkar' => [
            'spreadsheetId' => '1OJHBUMhIpcG3gGGubd8jeRRC16AX3P6t7aPOQPgdIiM',
            'buildings'     => ['Agam', 'Shruta', 'Kalpa'],
        ],
        'Kasturi' => [
            'spreadsheetId' => '1_Gmi34cOm-NBEcaw99qi3gmk3CT7Da-kFxpdaLqLb-E',
            'buildings'     => [
                'Balmoral River side D-wing',
                'Balmoral River side C-wing',
                'Balmoral TowerD-wing',
                'Balmoral TowerC-wing',
            ],
        ],
    ],

    // ---- Drive (Shared Drive) for photos + PDFs -------------------------
    // Must be a Google Workspace SHARED DRIVE folder shared with the SA,
    // otherwise the SA cannot write (0 storage quota on My Drive).
    // "Daily Site Reports" Shared Drive > "Store Daily site reports" folder,
    // shared with the SA as Content manager.
    'parent_folder_id'  => '1pHLzVUOJKIbmON9niVgsy_JGYS8VuDsu',
    'shared_drive_id'   => '0AE0aKMn9wj0gUk9PVA',

    // ---- Database (process tracker + audit) ----------------------------
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'pms',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ---- App ------------------------------------------------------------
    'timezone'    => 'Asia/Kolkata',
    'uploads_dir' => __DIR__ . '/../storage/uploads', // local temp before Drive push
];
