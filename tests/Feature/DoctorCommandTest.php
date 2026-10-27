<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitorCompletionSchema;
use Tests\Support\ServerSchema;
use Tests\TestCase;

/**
 * `ispconfig:doctor` (spec 010 US3).
 */
class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api.compat' => ['min_dbversion' => 100, 'min_version' => '3.3.1p1', 'tested_version' => '3.3.1p1']]);
        ServerSchema::create();
        MonitorCompletionSchema::create();
        DB::table('server')->insert(['server_id' => 1, 'server_name' => 's1', 'dbversion' => 104]);
        Cache::flush();
        $this->enforceCompat();
    }

    public function test_healthy_prints_one_line_per_check_and_exits_zero(): void
    {
        $this->artisan('ispconfig:doctor')
            ->expectsOutputToContain('database')
            ->expectsOutputToContain('schema')
            ->expectsOutputToContain('migrations')
            ->assertSuccessful();
    }

    public function test_a_failed_check_exits_one_and_says_why(): void
    {
        DB::table('server')->update(['dbversion' => 98]);

        $this->artisan('ispconfig:doctor')
            ->expectsOutputToContain('found dbversion 98, requires 100 (ISPConfig 3.3.1p1)')
            ->assertFailed();
    }
}
