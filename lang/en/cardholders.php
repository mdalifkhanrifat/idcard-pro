<?php

return [
    'lookup_parent_mismatch' => 'The selected item does not belong to the chosen :parent.',
    'fields' => [
        'blood_group_id' => 'blood group',
        'religion_id' => 'religion',
        'region_id' => 'region',
        'sub_region_id' => 'area',
        'department_id' => 'department',
        'unit_id' => 'unit',
        'rank_id' => 'rank',
        'designation_id' => 'designation',
        'branch_id' => 'branch',
        'trade_id' => 'trade',
        'appointment_id' => 'appointment',
    ],
    'import' => [
        'parent_required' => 'Choose a :parent to find this item in.',
        'unknown_lookup' => '“:value” is not in the :list list.',
        'duplicate_in_file' => 'Employee no :value appears more than once in the file.',
        'name_column_missing' => 'The file needs a "Name" or "Full name" column.',
        'too_many_rows' => 'The file has more than :max rows. Split it into smaller files.',
        'unreadable' => 'The file could not be read. Upload a CSV or XLSX file.',
        'empty' => 'The file has no header row.',
        'row_failed' => 'This row could not be saved.',
        'already_started' => 'This import has already been started.',
        'nothing_to_import' => 'There are no valid rows to import.',
        'running' => 'This import is running and cannot be removed.',
    ],
];
