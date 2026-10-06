<?php

/*
|--------------------------------------------------------------------------
| Server-side PDF
|--------------------------------------------------------------------------
|
| Pages of the app (e.g. issued cards) are printed to PDF by a browser engine, so the PDF matches the
| screen. "gotenberg" is a local container (compose service); null switches server PDFs off and the
| browser's own "Save as PDF" remains. No external service is used.
|
*/

return [

    'driver' => env('PDF_DRIVER', 'gotenberg'),

    // Address of this app as the engine sees it (inside the compose network, the nginx service)
    'app_url' => env('PDF_APP_URL', 'http://web'),

    // Minutes a signed render link stays valid (made just before the engine opens it)
    'link_minutes' => 5,

    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
        'timeout' => (int) env('GOTENBERG_TIMEOUT', 60),
    ],

];
