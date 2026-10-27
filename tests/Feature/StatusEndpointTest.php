<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitorCompletionSchema;
use Tests\Support\ServerSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /api/v1/status (spec 010 US3): administrator keys only.
 */
class StatusEndpointTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api.compat' => ['min_dbversion' => 100, 'min_version' => '3.3.1p1', 'tested_version' => '3.3.1p1']]);
        ServerSchema::create();
        TenantSchema::create();
        MonitorCompletionSchema::create();
        $this->seedTenants();
        DB::table('server')->insert(['server_id' => 1, 'server_name' => 's1', 'dbversion' => 104]);
        DB::table('monitor_data')->insert(['server_id' => 1, 'type' => 'ispc_info', 'created' => 1, 'data' => serialize(['version' => '3.3.1p1'])]);
        Cache::flush();
        $this->enforceCompat();
    }

    public function test_requires_a_key(): void
    {
        $this->getJson('/api/v1/status')->assertStatus(401);
    }

    public function test_a_non_admin_key_is_forbidden(): void
    {
        $this->getJson('/api/v1/status', $this->tenantHeaders('clientA'))->assertStatus(403);
    }

    public function test_an_admin_key_sees_the_release_levels_and_logging(): void
    {
        $this->getJson('/api/v1/status', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('release', trim(file_get_contents(base_path('VERSION'))))
            ->assertJsonPath('compatible', true)
            ->assertJsonPath('ispconfig', [
                'version' => '3.3.1p1',
                'dbversion' => 104,
                'required_dbversion' => 100,
                'minimum_version' => '3.3.1p1',
            ])
            ->assertJsonStructure(['logging' => ['state']]);
    }

    public function test_an_incompatible_schema_is_reported_not_refused(): void
    {
        DB::table('server')->update(['dbversion' => 98]);

        $this->getJson('/api/v1/status', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('compatible', false)
            ->assertJsonPath('ispconfig.dbversion', 98);
    }

    public function test_the_root_endpoint_stays_minimal(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertExactJsonStructure(['name', 'version', 'documentation']);
    }
}
