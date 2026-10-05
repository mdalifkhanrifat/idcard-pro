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
|   An image uploaded on profile/sign/emp_sign was printed when the real one was missing;
|   photo and signature elements keep that as an optional fallback image.
|   qr_code (+ qrcode_info)     => qr_code (signed verification link in 3.7)
|   db_field (+ relation_name)  => field (see "fields" below)
|   general_section             => text
|   (new) shape                 => coloured rectangle, ellipse or line; replaces empty
|                                  general sections that were only used for their bg_color
|   (new) barcode               => one data field as a 1D barcode; the legacy BarcodeTrait
|                                  (Code128, no text) existed but was never printed
|
*/

return [

    'types' => [
        'logo' => ['image' => true],
        'background' => ['image' => true, 'single_per_side' => true],
        // `image` here is an optional fallback printed when the real photo or signature is missing.
        'photo' => ['image' => true],
        'signatory_signature' => ['image' => true],
        'cardholder_signature' => ['image' => true],
        'qr_code' => [],
        'field' => ['needs_field' => true],
        'text' => ['needs_content' => true],
        'shape' => [],
        'barcode' => ['needs_field' => true],
    ],

    /*
     * Style keys an element may carry. Sizes are millimetres unless noted.
     * Box keys apply to every element; the rest only where they make sense.
     */
    'style' => [
        // rotation: 0/90/180/270, the content turns inside the box (legacy layouts keep working);
        // angle: −180..180°, the whole element tilts around its centre; bleed: may reach past the card edge (cut off there)
        'box' => ['background', 'gradient_to', 'gradient_direction', 'border_color', 'border_width', 'border_style', 'border_radius', 'opacity', 'rotation', 'angle', 'bleed'],
        // Shadow (on when shadow_color is set): text casts it from its letters, images from their shape, other boxes from the box
        'shadow' => ['shadow_color', 'shadow_x', 'shadow_y', 'shadow_blur', 'shadow_opacity'],
        // Editing state: locked elements cannot be moved or changed; hidden ones are kept but not printed;
        // elements of one side with the same group id are selected and moved together (no effect on printing).
        'state' => ['locked', 'hidden', 'group'],
        'text' => [
            'font_family', 'font_size', 'color', 'label_color', 'bold', 'italic', 'underline', 'align',
            'vertical_align', 'line_height', 'letter_spacing', 'uppercase', 'padding',
            // auto_fit: shrink the font (down to 4 pt) until the text fits its box; single_line: never wrap
            'auto_fit', 'single_line',
        ],
        'image' => ['fit'],
        'shape' => ['shape'],
        // barcode_text: print the encoded value under the bars, in the element's font and colour
        'barcode' => ['barcode_format', 'barcode_text'],
    ],

    /*
     * Barcode symbologies (drawn in the browser by JsBarcode). Values that a format cannot encode
     * (letters in EAN, Bangla in Code128...) show as an error box instead of a wrong barcode.
     */
    'barcode_formats' => ['code128', 'code39', 'ean13', 'ean8', 'upc', 'itf', 'codabar'],

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

    /*
     * Fonts shipped with the application (OFL-1.1, @fontsource packages, served from our own build;
     * see resources/js/pages/card-templates/designer/cardFonts.ts).
     */
    'bundled_fonts' => [
        'Inter', 'Roboto', 'Noto Sans Bengali', 'Noto Serif Bengali', 'Hind Siliguri', 'Tiro Bangla', 'Baloo Da 2', 'Anek Bangla',
    ],

    /*
     * Fonts the legacy designer offered that cannot be shipped (unclear or commercial licences).
     * They render only where installed. SutonnyMJ is an ANSI (Bijoy) font: Unicode Bangla text
     * shows as garbage in it, so prefer a Unicode Bangla font above.
     */
    'system_fonts' => [
        'SolaimanLipi', 'Nikosh', 'AdorshoLipi', 'SutonnyMJ', 'Arial', 'Arial Narrow', 'Arial Black', 'Impact',
        'sans-serif', 'serif', 'monospace',
    ],

];
