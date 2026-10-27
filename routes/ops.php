<?php

use App\Http\Controllers\ProbeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Operational probes (spec 010)
|--------------------------------------------------------------------------
| Registered without a middleware group: no session, no cookie, no API key.
| Orchestrators cannot present a key, and the probes expose no data.
*/

Route::get('/livez', [ProbeController::class, 'livez']);
Route::get('/readyz', [ProbeController::class, 'readyz']);
