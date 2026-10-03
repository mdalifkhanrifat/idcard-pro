<?php

use Illuminate\Support\Facades\Route;

/*
| The Vue SPA handles every non-API route.
*/
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|sanctum|storage|up).*$')
    ->name('spa');
