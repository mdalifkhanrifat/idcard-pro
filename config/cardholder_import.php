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
| Legacy CSV mapping (ExcelController): name, service no, ts no, rank,
| bg_group, dob, branch. Card columns (card no, issue date, print history)
| are imported with the card module in Phase 3.
|
*/

return [

    'max_rows' => 5000,
    'max_kb' => 10240,
    'preview_rows' => 20,
    'max_errors' => 500,

    'columns' => [
        'full_name' => ['full name', 'name'],
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
        'employee_no' => ['employee no', 'employee id', 'service no', 'staff id'],
        'secondary_no' => ['secondary no', 'ts no'],
        'employment_type' => ['employment type'],
        'joining_date' => ['joining date', 'date of joining', 'joined'],
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
        'rank_id' => ['rank', 'grade'],
        'designation_id' => ['designation'],
        'branch_id' => ['branch'],
        'trade_id' => ['trade'],
        'appointment_id' => ['appointment'],
    ],

];
