<?php

namespace Tests\Feature;

use App\Services\CompatibilityService;
use App\Support\CompatReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MonitorCompletionSchema;
use Tests\Support\ServerSchema;
use Tests\TestCase;

/**
 * Schema level (server.dbversion) against ISPCONFIG_COMPAT (spec 010 US2).
 */
class CompatibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api.compat' => ['min_dbversion' => 100, 'min_version' => '3.3.1p1', 'tested_version' => '3.3.1p1']]);
        ServerSchema::create();
        MonitorCompletionSchema::create();
        Cache::flush();
        $this->enforceCompat();
    }

    private function level(int ...$levels): void
    {
        DB::table('server')->delete();

        foreach ($levels as $i => $level) {
            DB::table('server')->insert(['server_id' => $i + 1, 'server_name' => "s{$i}", 'dbversion' => $level]);
        }
    }

    private function report(): CompatReport
    {
        return app(CompatibilityService::class)->report();
    }

    public function test_level_above_equal_and_below_the_minimum(): void
    {
        $this->level(104);
        $this->assertSame(CompatReport::OK, $this->report()->state);

        Cache::flush();
        $this->level(100);
        $this->assertTrue($this->report()->compatible());

        Cache::flush();
        $this->level(98);
        $report = $this->report();
        $this->assertSame(CompatReport::INCOMPATIBLE, $report->state);
        $this->assertSame([98, 100], [$report->dbversion, $report->requiredDbversion]);
    }

    public function test_no_server_row_is_unknown(): void
    {
        $this->level();

        $report = $this->report();
        $this->assertSame(CompatReport::UNKNOWN, $report->state);
        $this->assertNull($report->dbversion);
    }

    public function test_a_database_error_is_unreachable(): void
    {
        Schema::drop('server');

        $this->assertSame(CompatReport::UNREACHABLE, $this->report()->state);
    }

    public function test_the_lowest_level_over_several_rows_counts(): void
    {
        $this->level(104, 98);

        $this->assertSame(98, $this->report()->dbversion);
        $this->assertFalse($this->report()->compatible());
    }

    public function test_the_ispconfig_version_is_read_from_the_newest_info_row(): void
    {
        $this->level(104);
        DB::table('monitor_data')->insert([
            ['server_id' => 1, 'type' => 'ispc_info', 'created' => 100, 'data' => serialize(['version' => '3.2.0'])],
            ['server_id' => 1, 'type' => 'ispc_info', 'created' => 200, 'data' => serialize(['version' => '3.3.1p1'])],
        ]);

        $this->assertSame('3.3.1p1', $this->report()->version);
    }

    public function test_a_missing_or_corrupt_info_row_gives_no_version(): void
    {
        $this->level(104);
        $this->assertNull($this->report()->version);

        Cache::flush();
        DB::table('monitor_data')->insert(['server_id' => 1, 'type' => 'ispc_info', 'created' => 1, 'data' => 'not serialized']);
        $this->assertNull($this->report()->version);
    }

    public function test_the_version_string_is_informational_and_compared_like_ispconfig_does(): void
    {
        $report = fn (?string $version) => new CompatReport(CompatReport::OK, 100, '3.3.1p1', 104, $version);

        $this->assertFalse($report('3.3.1p1')->versionBelowMinimum());
        $this->assertFalse($report('3.3.1p2')->versionBelowMinimum());
        $this->assertTrue($report('3.3.1')->versionBelowMinimum());
        $this->assertTrue($report('3.2.11p1')->versionBelowMinimum());
        $this->assertNull($report(null)->versionBelowMinimum());

        $this->level(104);
        DB::table('monitor_data')->insert(['server_id' => 1, 'type' => 'ispc_info', 'created' => 1, 'data' => serialize(['version' => '3.0.0'])]);
        $this->assertTrue($this->report()->compatible());
    }

    public function test_only_a_compatible_result_is_cached(): void
    {
        $this->level(104);
        $this->assertTrue($this->report()->compatible());

        DB::table('server')->update(['dbversion' => 1]);
        $this->assertTrue($this->report()->compatible(), 'served from the cache');

        Cache::flush();
        $this->assertFalse($this->report()->compatible());

        DB::table('server')->update(['dbversion' => 104]);
        $this->assertTrue($this->report()->compatible(), 'an incompatible result is re-read at once');
    }

    public function test_an_unwritable_cache_does_not_fail_the_check(): void
    {
        $this->level(104);
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache directory is not writable'));

        $this->assertTrue($this->report()->compatible());
    }
}
