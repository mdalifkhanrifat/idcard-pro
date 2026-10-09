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
        'tilted_outside_card' => 'Tilted like this, the element reaches past the card edge. Move it inward, reduce the angle, or allow it to be cut at the edge.',
        'image_missing' => 'The image was not found. Upload it again.',
        'image_or_library' => 'Use either an uploaded image or a library image, not both.',
    ],
    // Printed values, in the organization's language (CardDataResolver)
    'issue' => [
        'template_unavailable' => 'Choose an active card template.',
        'family_member_unknown' => 'This family member does not belong to the cardholder.',
        'family_member_required' => 'This is a family card template: choose the family member the card is for.',
        'template_not_for_family' => 'This template is for cardholders; choose a family card template for a family member.',
        'note_required' => 'Add a note explaining the reason.',
        'document_not_for_reason' => 'A document can only be attached for promotion, lost or damaged cards.',
        'document_unknown' => 'Choose one of the cardholder’s documents.',
        'document_title' => 'Card issue proof (:reason)',
        'expiry_before_issue' => 'The expiry date cannot be before the issue date.',
        'rf_in_use' => 'This RF card number is on another person’s active card.',
        'new_not_first' => '“New” is for the first card only. This person already had a card: choose why it is issued again (e.g. renewal, lost or damaged).',
    ],

    'import' => [
        'template_unavailable' => 'Choose an active card template for cardholders.',
        'number_in_use' => 'Card number :number is on another person’s active card.',
        'printed_in_future' => 'The last print date and time is in the future.',
        'printed_before_issue' => 'The last print date is before the card’s issue date.',
        'newer_card' => 'This person already has a newer card (:number, issued :date); an older card cannot replace it.',
    ],

    'reasons' => [
        'new' => 'New',
        'renewal' => 'Renewal',
        'promotion' => 'Promotion / demotion',
        'lost' => 'Lost',
        'damaged' => 'Damaged',
        'correction' => 'Correction',
    ],

    'print' => [
        'unknown' => 'One or more cards could not be found.',
        'not_printable' => 'Only active, unexpired cards can be printed: :numbers. Issue a new card instead.',
        'pdf_unavailable' => 'The PDF could not be made right now. Try again, or print from the browser.',
    ],

    'verification' => [
        'not_found' => 'No card was found for this code, or the organization does not verify its cards here.',
        'link_invalid' => 'The link must be a web address (http or https) containing {code} where the card\'s code goes.',
    ],
    'lifecycle' => [
        'not_active' => 'Only an active card can be revoked.',
    ],

    'in_use' => [
        'cardholder' => 'This person has issued cards, which are kept as records. Mark them inactive instead of deleting.',
        'family_member' => 'This family member has issued cards, which are kept as records.',
        'template' => 'Cards were issued from this template. Deactivate it instead of deleting; issued cards keep their design.',
        'signatory' => 'Issued cards carry this signatory’s signature. Deactivate the signatory instead of deleting.',
        'file' => 'This photo or signature is printed on an issued card. Upload a new one instead; the old one stays with the card.',
        'library_image' => 'Issued cards print this image, so it cannot be deleted.',
        'font' => 'Issued cards print in “:family”, so its last file cannot be deleted.',
    ],

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
