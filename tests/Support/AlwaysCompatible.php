<?php

namespace Tests\Support;

use App\Services\CompatibilityService;
use App\Support\CompatReport;

/**
 * Default test double: test schemas carry an arbitrary `server.dbversion`, so
 * the write-refusal middleware would otherwise refuse every write. Compat
 * tests call TestCase::enforceCompat() to use the real service.
 */
class AlwaysCompatible extends CompatibilityService
{
    public function __construct() {}

    public function report(): CompatReport
    {
        return new CompatReport(
            CompatReport::OK,
            (int) config('api.compat.min_dbversion'),
            (string) config('api.compat.min_version'),
            (int) config('api.compat.min_dbversion'),
        );
    }
}
