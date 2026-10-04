<?php

return [
    'number_format' => [
        'seq_required' => 'The format needs exactly one {SEQ:n} with n between 1 and 10.',
        'unknown_token' => 'Use only {CODE}, {YYYY}, {YY}, {MM} and {SEQ:n}.',
        'invalid_characters' => 'Use letters, digits, tokens and / - _ . only.',
    ],
    'designer' => [
        'back_side_disabled' => 'This template is single-sided. Turn on "double-sided" to design the back.',
        'single_background' => 'Only one background is allowed on each side.',
        'field_required' => 'Choose the data field to show.',
        'field_unknown' => 'This data field is not available for this template.',
        'content_required' => 'Enter the text to show.',
        'qr_line_invalid' => 'Each QR line needs a data field or custom text.',
        'outside_card' => 'The element must stay inside the card (:width × :height mm).',
        'image_missing' => 'The image was not found. Upload it again.',
    ],
    // Printed values, in the organization's language (CardDataResolver)
    'values' => [
        'gender' => [
            'male' => 'Male',
            'female' => 'Female',
            'other' => 'Other',
        ],
        'marital_status' => [
            'single' => 'Single',
            'married' => 'Married',
            'divorced' => 'Divorced',
            'widowed' => 'Widowed',
        ],
        'employment_type' => [
            'staff' => 'Staff',
            'contractor' => 'Contractor',
            'visitor' => 'Visitor',
            'student' => 'Student',
            'other' => 'Other',
        ],
        'relation' => [
            'father' => 'Father',
            'mother' => 'Mother',
            'father_in_law' => 'Father-in-law',
            'mother_in_law' => 'Mother-in-law',
            'spouse' => 'Spouse',
            'son' => 'Son',
            'daughter' => 'Daughter',
            'sibling' => 'Sibling',
            'other' => 'Other',
        ],
        'yes' => 'Yes',
        'no' => 'No',
    ],
];
