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
    | Supported ISPConfig schema level
    |--------------------------------------------------------------------------
    | Parsed from ISPCONFIG_COMPAT; the compatibility result is cached for
    | compat_cache_ttl seconds.
    */

    'compat' => \App\Support\IspconfigCompat::load(),

    'compat_cache_ttl' => (int) env('API_COMPAT_CACHE_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Development API key
    |--------------------------------------------------------------------------
    | Accepted only in local/development/testing environments; authenticates
    | as the ISPConfig admin (sys_userid 1). Leave unset in production.
    */

    'dev_key' => env('API_DEV_KEY'),

];
