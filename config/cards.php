<?php

use App\Services\Cards\Requirements\BiometricCardRequirement;

/*
|--------------------------------------------------------------------------
| Card issuance
|--------------------------------------------------------------------------
|
| Legacy mapping: card_information (one row per person, overwritten) + card_print_history
| (card_get = new / promotion / lost). Now every issue is its own `cards` row; the previous
| active card of the same holder becomes "replaced".
|
*/

return [

    /*
     * Why a card is issued (legacy card_get). `note` = a note is required; `document` = a proof
     * document may be attached (legacy "Add Document" for promotion and lost).
     */
    'reasons' => [
        'new' => [],
        'renewal' => [],
        'promotion' => ['note' => true, 'document' => true],   // legacy "Promotion / Demotion"
        'lost' => ['note' => true, 'document' => true],        // legacy "Lost / Damaged"
        'damaged' => ['document' => true],
        'correction' => ['note' => true],
    ],

    /*
     * Card number when the same holder gets another card from a template with the same code:
     * keep = reuse the current number (legacy behaviour, the default); new = always a new number.
     * Organizations choose in settings (tenants.settings.card_number_on_reissue).
     */
    'number_on_reissue' => ['keep', 'new'],

    'default_number_on_reissue' => 'keep',

    /*
     * Printing an issued card (3.6). Every print is logged in card_prints with its method;
     * a new method (e.g. a card printer driver) only needs a new entry here.
     */
    /*
     * What a person must meet before a card is issued or printed (App\Services\Cards\Requirements\CardRequirement),
     * each one switched on by the organization's own settings. Users with cards.override_requirements may go
     * ahead anyway with a reason, kept in the card's audit log.
     */
    'requirements' => [
        BiometricCardRequirement::class,
    ],

    'print' => [
        'methods' => ['browser', 'pdf'],
        'max_copies' => 10,
        // Cards printed together in one batch
        'max_batch' => 200,
        /*
         * Files behind signed links (photos, signatures, biometric images, fonts, design images) one user, or
         * the PDF engine, may load per minute. A batch loads several per card: keep it well above max_batch × 5.
         */
        'files_per_minute' => (int) env('CARD_FILES_PER_MINUTE', 2000),
        /*
         * Paper for printing several cards on one sheet with crop marks (portrait, in mm), besides one card
         * per page ("card", for card printers). A new paper size only needs a new entry here.
         */
        'sheets' => [
            'a4' => ['width' => 210, 'height' => 297],
            'letter' => ['width' => 215.9, 'height' => 279.4],
        ],
    ],

    /*
     * Card verification (3.7): every card carries a random code; its link (field card.verify_url, e.g. in a
     * QR code) opens a public page showing whether the card is valid. Each organization turns it on and
     * chooses what the page shows (tenants.settings.verification); off by default, so nothing is public
     * until the organization decides.
     */
    'verification' => [
        'code_length' => 24,
        // Path of the public page in this application; an organization may use its own address instead
        'path' => '/v/{code}',
        /*
         * Details the public page may show, from the card as issued. Sensitive details (national ID,
         * passport, phone, email, addresses, date of birth) are deliberately not offered.
         */
        'fields' => [
            'photo', 'full_name', 'full_name_local', 'family.name', 'family.relation', 'employee_no', 'rank',
            'designation', 'department', 'unit', 'branch', 'blood_group', 'template', 'card.number',
            'card.issue_date', 'card.expiry_date', 'signatory.name',
        ],
        'defaults' => [
            'enabled' => false,
            'fields' => ['photo', 'full_name', 'family.name', 'family.relation', 'designation', 'card.number', 'card.expiry_date'],
            // A revoked card shows only "revoked" unless the organization allows the reason to be shown
            'show_revoke_reason' => false,
            // Own address with {code}, e.g. https://hr.example.com/id/{code}; empty = this application's page
            'link' => null,
            // Text on the page, e.g. whom to contact when a card is found
            'note' => null,
            // Keep a log of checks (card details show how often and when a card was checked)
            'log_checks' => true,
        ],
        // Days a check stays in the log (pruned daily)
        'log_days' => 365,
        // A device checking the same card again within this many seconds (e.g. a refresh) is not logged again
        'log_repeat_seconds' => 60,
        // Latest checks listed in a card's details
        'history_limit' => 50,
        // Lookups per minute from one address
        'throttle' => 30,
    ],

    // active: the holder's current card; replaced: a newer card was issued; revoked: cancelled (e.g. reported lost)
    'statuses' => ['active', 'replaced', 'revoked'],

];
