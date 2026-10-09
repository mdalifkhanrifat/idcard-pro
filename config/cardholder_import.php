<?php

/*
|--------------------------------------------------------------------------
| Cardholder import
|--------------------------------------------------------------------------
|
| Headers are matched case-insensitively; spaces, "_", "-" and "." are
| treated alike. Custom fields match by key or label. Unknown columns are
| ignored and listed in the preview.
|
| Legacy CSV (ExcelController, read by position): Card ID, Officer Name,
| Service ID, TS_NO, Rank Name, Blood Group, Birth Date, Comm Date, Branch
| Name, Issue Date, Last Print Date, Last Print Time, Print Reason. Here
| columns are matched by header, so these names are aliases below.
|
*/

return [

    'max_rows' => 5000,
    'max_kb' => 10240,
    'preview_rows' => 20,
    'max_errors' => 500,

    'columns' => [
        'full_name' => ['full name', 'name', 'officer name', 'employee name', 'member name'],
        'full_name_local' => ['full name local', 'local name', 'name local', 'name bn', 'bangla name'],
        'date_of_birth' => ['date of birth', 'dob', 'birth date'],
        'gender' => ['gender', 'sex'],
        'marital_status' => ['marital status'],
        'nationality' => ['nationality'],
        'national_id' => ['national id', 'nid', 'nid no'],
        'passport_no' => ['passport no', 'passport'],
        'email' => ['email', 'e mail'],
        'personal_phone' => ['personal phone', 'phone', 'mobile', 'mobile no'],
        'emergency_phone' => ['emergency phone', 'emergency contact'],
        'present_address' => ['present address', 'address'],
        'permanent_address' => ['permanent address'],
        'postal_code' => ['postal code', 'post code', 'zip'],
        'height' => ['height'],
        'weight' => ['weight'],
        'eye_color' => ['eye color', 'eye colour'],
        'complexion' => ['complexion'],
        'identification_mark' => ['identification mark'],
        'employee_no' => ['employee no', 'employee id', 'service no', 'service id', 'staff id'],
        'secondary_no' => ['secondary no', 'ts no'],
        'employment_type' => ['employment type'],
        'joining_date' => ['joining date', 'date of joining', 'joined', 'comm date', 'commission date'],
        'retirement_date' => ['retirement date', 'retired date'],
        'retired_id_no' => ['retired id no'],
        'posting' => ['posting'],
        'previous_office' => ['previous office'],
        'company_name' => ['company name', 'company'],
        'company_address' => ['company address'],
        'company_phone' => ['company phone'],
        'status' => ['status'],

        // Master data: matched by name, local name or code
        'blood_group_id' => ['blood group', 'bg group', 'blood'],
        'religion_id' => ['religion'],
        'region_id' => ['region', 'district'],
        'sub_region_id' => ['sub region', 'area', 'upazila', 'thana'],
        'department_id' => ['department', 'base'],
        'unit_id' => ['unit'],
        'rank_id' => ['rank', 'rank name', 'grade'],
        'designation_id' => ['designation'],
        'branch_id' => ['branch', 'branch name'],
        'trade_id' => ['trade'],
        'appointment_id' => ['appointment'],
    ],

    /*
     * A card the person already has (e.g. from the previous system), imported with them when the
     * import is told to and which template it belongs to. Without a number the card gets a new one;
     * without a last print date it waits for printing. Dates and times are read in the
     * organization's time zone.
     */
    'card_columns' => [
        'number' => ['card no', 'card id', 'card number', 'card id number', 'id card no'],
        'issue_date' => ['issue date', 'card issue date'],
        'expiry_date' => ['expiry date', 'card expiry date', 'expire date', 'card expair date'],
        'rf_card_no' => ['RF card no', 'rf no', 'rfid'],
        'last_print_date' => ['last print date', 'print date', 'last printed'],
        'last_print_time' => ['last print time', 'print time'],
        // Legacy "Print Reason" (kept as the card's note, as before)
        'notes' => ['card notes', 'card note', 'print reason'],
    ],

];
