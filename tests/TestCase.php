<?php

namespace Tests;

use App\Services\CompatibilityService;
use App\Services\MonitorDataService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AlwaysCompatible;
use Tests\Support\WrittenColumns;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(CompatibilityService::class, AlwaysCompatible::class);

        if ($path = getenv('COMPAT_COLLECT')) {
            WrittenColumns::register($path);
            DB::listen(fn ($query) => WrittenColumns::record($query->sql));
        }
    }

    /** Use the real CompatibilityService, reading `server.dbversion` from the test database. */
    protected function enforceCompat(): void
    {
        $this->app->bind(
            CompatibilityService::class,
            fn ($app) => new CompatibilityService($app->make(MonitorDataService::class))
        );
    }
}
