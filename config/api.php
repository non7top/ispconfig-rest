<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Release version reported by the root endpoint
    |--------------------------------------------------------------------------
    | Read from the VERSION file, so it always matches the deployed release.
    */

    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'unknown',

    /*
    |--------------------------------------------------------------------------
    | Development API key
    |--------------------------------------------------------------------------
    | Accepted only in local/development/testing environments; authenticates
    | as the ISPConfig admin (sys_userid 1). Leave unset in production.
    */

    'dev_key' => env('API_DEV_KEY'),

];
