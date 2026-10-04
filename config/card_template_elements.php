<?php

/*
|--------------------------------------------------------------------------
| Card template designer: element types and data fields
|--------------------------------------------------------------------------
|
| Element positions and sizes are stored in millimetres from the top-left
| corner of the card side, so the printed card matches the design.
|
| Legacy mapping (template_sections.section_type):
|   fr_logo1-5, bg_logo1-5      => logo (any number, either side)
|   bg_image, back_bg_image     => background (one per side)
|   profile                     => photo
|   sign                        => signatory_signature (active signatory of the template's authority type)
|   emp_sign                    => cardholder_signature
|   qr_code (+ qrcode_info)     => qr_code (signed verification link in 3.7)
|   db_field (+ relation_name)  => field (see "fields" below)
|   general_section             => text
|   (new) shape                 => coloured rectangle, ellipse or line; replaces empty
|                                  general sections that were only used for their bg_color
|
*/

return [

    'types' => [
        'logo' => ['image' => true],
        'background' => ['image' => true, 'single_per_side' => true],
        'photo' => [],
        'signatory_signature' => [],
        'cardholder_signature' => [],
        'qr_code' => [],
        'field' => ['needs_field' => true],
        'text' => ['needs_content' => true],
        'shape' => [],
    ],

    /*
     * Style keys an element may carry. Sizes are millimetres unless noted.
     * Box keys apply to every element; the rest only where they make sense.
     */
    'style' => [
        'box' => ['background', 'gradient_to', 'gradient_direction', 'border_color', 'border_width', 'border_radius', 'opacity'],
        'text' => [
            'font_family', 'font_size', 'color', 'label_color', 'bold', 'italic', 'underline', 'align',
            'vertical_align', 'line_height', 'letter_spacing', 'uppercase', 'padding',
        ],
        'image' => ['fit'],
        'shape' => ['shape'],
    ],

    'gradient_directions' => ['to bottom', 'to right', 'to bottom right', 'to top right'],

    // Data fields a "field" element can show. Custom fields are added as "custom.<key>".
    // Legacy db_field names are given in comments where they differ.
    'fields' => [
        'cardholder' => [
            'full_name', 'full_name_local', 'date_of_birth', 'gender', 'marital_status', 'nationality',
            'national_id', 'passport_no', 'blood_group', 'religion', 'email', 'personal_phone', 'emergency_phone',
            'present_address', 'permanent_address', 'region', 'sub_region', 'postal_code',
            'height', 'weight', 'eye_color', 'complexion', 'identification_mark',
            'employee_no',      // service_no
            'secondary_no',     // ts_no
            'employment_type', 'department', 'unit', 'rank', 'designation', 'branch', 'trade', 'appointment',
            'joining_date', 'retirement_date', 'retired_id_no', 'posting', 'company_name',
        ],
        'family_member' => [
            'family.name', 'family.name_local', 'family.relation', 'family.date_of_birth', 'family.gender',
            'family.phone', 'family.blood_group', 'family.national_id', 'family.passport_no',
            'family.identification_mark', 'family.card_no',
        ],
        'card' => [
            'card.number',      // card_id_number
            'card.issue_date',  // card_issue_date
            'card.expiry_date', // card_expair_date
            'signatory.name', 'signatory.designation',
            'organization.name',
        ],
    ],

    'fonts' => [
        'Inter', 'Arial', 'Arial Narrow', 'Arial Black', 'Impact', 'Roboto', 'sans-serif', 'serif', 'monospace',
        'SutonnyMJ', 'SolaimanLipi', 'Nikosh', 'AdorshoLipi', 'Noto Sans Bengali',
    ],

];
