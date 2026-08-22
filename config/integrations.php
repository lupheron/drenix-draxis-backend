<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Company integration profiles (JM first — account2)
    |--------------------------------------------------------------------------
    */
    'companies' => [

        'JM' => [
            'ringcentral' => [
                'client_id' => env('RC_JM_CLIENT_ID'),
                'client_secret' => env('RC_JM_CLIENT_SECRET'),
                'jwt' => env('RC_JM_JWT'),
                'server_url' => env('RC_JM_SERVER_URL', 'https://platform.ringcentral.com'),
            ],
            'monday' => [
                'api_token' => env('MONDAY_JM_API_TOKEN', env('MONDAY_API_TOKEN')),
                // Central hire board: HR Process JDM → hires + loaded (+ rejected group)
                'hr_process_board_id' => env('MONDAY_JM_BOARD_ID', '2046464283'),
                'hr_process_groups' => ['Hired', 'Loaded'],
                'hr_process_rejected_groups' => ['Rejected'],
                'source_column_titles' => ['Source'],
                'date_column_titles' => ['Date'],
                'source_to_user' => [
                    'Alex' => 'Alex Chester',
                    'Winston' => 'Winston Smith',
                    'Isaac' => 'Isaac Taylor',
                    'Alfred' => 'Alfred Brooks',
                ],
                // Personal pipeline boards → leads + follow_up
                'user_board_map' => [
                    'Alex Chester' => [
                        'new_leads' => ['New leads Alex'],
                        'follow_up' => ['Follow up Alex'],
                    ],
                    'Winston Smith' => [
                        'new_leads' => ['New leads Winston'],
                        'follow_up' => ['Follow up Winston'],
                    ],
                    'Isaac Taylor' => [
                        'new_leads' => ['New leads Isaac'],
                        'follow_up' => ['Follow up Isaac'],
                    ],
                    'Alfred Brooks' => [
                        'new_leads' => ['New leads Alfred'],
                        'follow_up' => ['Follow up Alfred'],
                    ],
                ],
            ],

            'whitelist' => [
                [
                    'name' => 'Alex Chester',
                    'department' => 'hr',
                    'position' => 'Head of HR',
                    'monday' => true,
                    'source_labels' => ['Alex'],
                ],
                [
                    'name' => 'Winston Smith',
                    'department' => 'hr',
                    'position' => 'Recruiter',
                    'monday' => true,
                    'source_labels' => ['Winston'],
                ],
                [
                    'name' => 'Isaac Taylor',
                    'department' => 'hr',
                    'position' => 'Recruiter',
                    'monday' => true,
                    'source_labels' => ['Isaac'],
                ],
                [
                    'name' => 'Alfred Brooks',
                    'department' => 'hr',
                    'position' => 'Recruiter',
                    'monday' => true,
                    'source_labels' => ['Alfred'],
                ],
                [
                    'name' => 'Henry Safety Department',
                    'department' => 'safety',
                    'position' => 'Safety Manager',
                    'monday' => false,
                    'match_aliases' => ['Henry Safety', 'Henry'],
                ],
            ],
        ],

    ],

    'sync' => [
        'ringcentral_lookback_days' => (int) env('RC_SYNC_LOOKBACK_DAYS', 30),
        'monday_lookback_days' => (int) env('MONDAY_SYNC_LOOKBACK_DAYS', 400),
        'monday_leads_lookback_days' => (int) env('MONDAY_LEADS_LOOKBACK_DAYS', 400),
    ],

    'attendance' => [
        'timezone' => env('ATTENDANCE_TIMEZONE', 'America/Chicago'),
        'spreadsheet_id' => env('HIKVISION_ATTENDANCE_SPREADSHEET_ID', '1V8K_XHd7skbezYf361meWC0gNrnw2cVKTv98UZvRVnY'),
        'google_service_account_json' => env('GOOGLE_SERVICE_ACCOUNT_JSON'),
        'google_service_account_json_base64' => env('GOOGLE_SERVICE_ACCOUNT_JSON_BASE64'),
        'google_service_account_path' => env('GOOGLE_SERVICE_ACCOUNT_PATH'),
        // Tab name → company. Drenix skipped in v1 until confirmed.
        'tabs' => [
            'JM' => 'JM',
            'WF' => 'WF',
            'BP' => 'BP',
            // 'Drenix' => 'JM', // enable later if names are JM employees
        ],
        'headers' => [
            'time_local' => 'Time Local',
            'employee_id' => 'Employee id',
            'employee_name' => 'Employee Name',
            'action' => 'Action',
            'shift_time' => 'Shift Time',
            'shift_date' => 'Shift Date',
            'late_minutes' => 'Late Minutes',
            'status' => 'Status',
            'notes' => 'Notes',
            'didnt_come' => "Didn't Come",
        ],
    ],

];
