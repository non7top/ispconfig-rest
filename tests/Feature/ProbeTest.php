<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitorCompletionSchema;
use Tests\Support\ServerSchema;
use Tests\TestCase;

/**
 * /livez and /readyz (spec 010 US3): unauthenticated, per-check states only.
 */
class ProbeTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/probe-log-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        config(['api.compat' => ['min_dbversion' => 100, 'min_version' => '3.3.1p1', 'tested_version' => '3.3.1p1']]);
        ServerSchema::create();
        MonitorCompletionSchema::create();
        DB::table('server')->insert(['server_id' => 1, 'server_name' => 'secret-host.example', 'dbversion' => 104]);
        Cache::flush();
        $this->enforceCompat();
    }

    protected function tearDown(): void
    {
        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_livez_is_alive_without_a_key(): void
    {
        $this->getJson('/livez')
            ->assertOk()
            ->assertExactJson(['status' => 'ok'])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_readyz_is_ready_and_exposes_only_states(): void
    {
        $response = $this->getJson('/readyz')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['database' => 'ok', 'schema' => 'ok', 'migrations' => 'ok'],
            ])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('Set-Cookie');

        foreach (['104', '100', 'secret-host', '3.3.1'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_a_schema_below_the_minimum_is_not_ready_but_still_alive(): void
    {
        DB::table('server')->update(['dbversion' => 98]);

        $this->getJson('/readyz')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('checks.schema', 'failed')
            ->assertJsonPath('checks.database', 'ok');
        $this->getJson('/livez')->assertOk();
    }

    public function test_an_unreachable_database_is_not_ready_but_still_alive(): void
    {
        config([
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => $this->dir.'/missing/none.sqlite'],
            'database.default' => 'broken',
        ]);

        try {
            $this->getJson('/readyz')
                ->assertStatus(503)
                ->assertJsonPath('checks.database', 'failed');
            $this->getJson('/livez')->assertOk();
        } finally {
            // RefreshDatabase rolls back on the default connection.
            config(['database.default' => 'sqlite']);
        }
    }

    public function test_a_pending_migration_is_not_ready(): void
    {
        DB::table('migrations')->delete();

        $this->getJson('/readyz')
            ->assertStatus(503)
            ->assertJsonPath('checks.migrations', 'failed');
    }
}
