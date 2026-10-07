<?php

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
    'print' => [
        'methods' => ['browser', 'pdf'],
        'max_copies' => 10,
        // Cards printed together in one batch
        'max_batch' => 200,
        /*
         * Paper for printing several cards on one sheet with crop marks (portrait, in mm), besides one card
         * per page ("card", for card printers). A new paper size only needs a new entry here.
         */
        'sheets' => [
            'a4' => ['width' => 210, 'height' => 297],
            'letter' => ['width' => 215.9, 'height' => 279.4],
        ],
    ],

    // active: the holder's current card; replaced: a newer card was issued; revoked: cancelled (e.g. reported lost)
    'statuses' => ['active', 'replaced', 'revoked'],

];
