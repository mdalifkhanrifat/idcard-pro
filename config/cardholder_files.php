<?php

/*
|--------------------------------------------------------------------------
| Cardholder file collections
|--------------------------------------------------------------------------
|
| Every kind of file kept for a cardholder. Adding a new kind (e.g. a copy
| of a national ID or a medical certificate) only requires a new entry here.
|
| - single:     only one current file; uploading again archives the previous
|               one (kept so issued cards can still refer to it)
| - image:      re-encoded on upload (metadata stripped, orientation fixed,
|               resized to max_dimension px)
| - mimes:      allowed file extensions, checked against the real content
| - max_kb:     maximum upload size in kilobytes
|
| Legacy mapping: photo = picture, signature = holder_sign,
| document = pdf + files. Fingerprints are biometric data (Phase 4).
|
*/

return [

    'disk' => env('CARDHOLDER_FILES_DISK', 'local'),

    // Minutes a signed download link stays valid
    'url_ttl' => 15,

    'collections' => [
        'photo' => [
            'single' => true,
            'image' => true,
            'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_kb' => 5120,
            'max_dimension' => 1200,
        ],
        'signature' => [
            'single' => true,
            'image' => true,
            'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_kb' => 2048,
            'max_dimension' => 1000,
        ],
        'document' => [
            'single' => false,
            'image' => false,
            'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
            'max_kb' => 10240,
        ],
    ],

];
