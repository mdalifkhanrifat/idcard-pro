<?php

/*
|--------------------------------------------------------------------------
| Biometrics (Phase 4)
|--------------------------------------------------------------------------
|
| Registry of what can be captured (modalities and their positions) and with
| what (drivers). Each organization chooses in its own settings
| (tenants.settings.biometrics) which modalities it uses, which positions and
| how many, the quality it accepts and which drivers its stations may use.
| Adding a modality (e.g. iris) or a scanner brand only needs an entry here
| and, for a scanner, a browser driver.
|
| The legacy system had no biometrics: its "finger" column only held the
| "Add Document" image of a lost/promotion card (kept as the card document).
|
*/

return [

    /*
     * What can be captured. Positions use the ISO/IEC 19794 finger position codes, so captures can be
     * exchanged with HR, access control and government systems.
     *
     * - positions:        position => standard code
     * - image_formats:    image formats a driver may send
     * - template_formats: template formats a driver may send (none = image only)
     * - printable:        the image may be placed on a card (only when the organization allows it)
     */
    'modalities' => [
        'fingerprint' => [
            'positions' => [
                'right_thumb' => 1, 'right_index' => 2, 'right_middle' => 3, 'right_ring' => 4, 'right_little' => 5,
                'left_thumb' => 6, 'left_index' => 7, 'left_middle' => 8, 'left_ring' => 9, 'left_little' => 10,
            ],
            'image_formats' => ['png', 'jpeg', 'bmp', 'wsq'],
            'template_formats' => ['iso-19794-2', 'ansi-378', 'iso-19794-2-2011'],
            'printable' => true,
        ],
        'face' => [
            'positions' => ['face' => 0],
            'image_formats' => ['jpeg', 'png'],
            'template_formats' => [],
            'printable' => false,
        ],
    ],

    /*
     * Capture drivers. The browser talks to the scanner (through the vendor's local service or the
     * bridge agent on the same computer); the server only receives the result, so nothing leaves
     * the station except to this application.
     *
     * - modalities: what the driver can capture
     * - local_url:  default address of the vendor's local service (a station may use another)
     * - testing:    produces made-up data; offered only while `simulator` below is on
     */
    'drivers' => [
        'upload' => ['modalities' => ['fingerprint', 'face']],
        'simulator' => ['modalities' => ['fingerprint', 'face'], 'testing' => true],
        'webcam' => ['modalities' => ['face']],
        'secugen' => ['modalities' => ['fingerprint'], 'local_url' => 'https://localhost:8443'],
        'digitalpersona' => ['modalities' => ['fingerprint'], 'local_url' => 'https://127.0.0.1:52181'],
        'zkteco' => ['modalities' => ['fingerprint'], 'local_url' => 'http://127.0.0.1:47800'],
        // Our own small local agent (docs/BIOMETRIC_BRIDGE.md): wraps any vendor SDK
        'bridge' => ['modalities' => ['fingerprint', 'face'], 'local_url' => 'http://127.0.0.1:47800'],
    ],

    // The simulator makes up fingerprints and faces: never offered in production
    'simulator' => (bool) env('BIOMETRIC_SIMULATOR', env('APP_ENV', 'production') !== 'production'),

    // Why a position could not be captured (e.g. a missing finger); "other" asks for a note
    'unavailable_reasons' => ['missing', 'injured', 'bandaged', 'unreadable', 'other'],

    // Moments the organization may require complete biometrics before (enforced in 4.9)
    'required_before' => ['issue', 'print'],

    // Capture is off, may be skipped, or must be complete
    'modes' => ['off', 'optional', 'required'],

    'defaults' => [
        'enabled' => false,
        'modalities' => [
            'fingerprint' => [
                'mode' => 'optional',
                // How many positions are needed, the preferred ones first
                'count' => 2,
                'positions' => ['right_thumb', 'left_thumb'],
                // Another position may stand in for a preferred one that cannot be captured
                'substitutes' => true,
                // 0-100; below this the station asks for another try
                'min_quality' => 40,
                // After this many tries the best capture may be kept, marked as low quality
                'max_attempts' => 3,
                'drivers' => ['upload', 'simulator'],
            ],
            'face' => [
                'mode' => 'off',
                'count' => 1,
                'positions' => ['face'],
                'substitutes' => false,
                'min_quality' => 0,
                'max_attempts' => 3,
                'drivers' => ['webcam', 'upload', 'simulator'],
            ],
        ],
        // Family members who get cards are captured too
        'family_members' => false,
        'required_before' => [],
        // Fingerprint image on cards (designer field); off for privacy until the organization allows it
        'print_on_card' => false,
    ],

];
