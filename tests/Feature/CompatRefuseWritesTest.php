<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientApiTestCase;

/**
 * Writes are refused before any row exists while the ISPConfig schema is below
 * the minimum; reads keep working (spec 010 US2).
 */
class CompatRefuseWritesTest extends ClientApiTestCase
{
    private const TYPE = 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#incompatible-ispconfig-schema';

    protected function setUp(): void
    {
        parent::setUp();

        config(['api.compat' => ['min_dbversion' => 100, 'min_version' => '3.3.1p1', 'tested_version' => '3.3.1p1']]);
        $this->enforceCompat();

        // The client module's reduced test schema has no dbversion column.
        if (! Schema::hasColumn('server', 'dbversion')) {
            Schema::table('server', fn (Blueprint $table) => $table->unsignedInteger('dbversion')->default(1));
        }

        DB::table('server')->update(['dbversion' => 98]);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['company_name' => 'Acme', 'contact_name' => 'John', 'email' => 'j@example.com', 'username' => 'johndoe', 'password' => 's3cr3tP@ssw0rd'];
    }

    public function test_a_write_is_refused_naming_both_levels_and_writes_nothing(): void
    {
        $this->postJson('/api/v1/clients', $this->payload(), $this->authHeaders())
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertExactJson([
                'type' => self::TYPE,
                'title' => 'Incompatible ISPConfig schema',
                'status' => 500,
                'detail' => "ISPConfig schema level 98 is below the required 100. Run ISPConfig's updater.",
                'found_dbversion' => 98,
                'required_dbversion' => 100,
            ]);

        $this->assertSame(0, DB::table('client')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_put_and_delete_are_refused_too(): void
    {
        $id = $this->seedClient();

        $this->putJson("/api/v1/clients/{$id}", ['company_name' => 'New'], $this->authHeaders())
            ->assertStatus(500)->assertJsonPath('type', self::TYPE);
        $this->deleteJson("/api/v1/clients/{$id}", [], $this->authHeaders())
            ->assertStatus(500)->assertJsonPath('type', self::TYPE);

        $this->assertSame(1, DB::table('client')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_reads_still_work(): void
    {
        $this->getJson('/api/v1/clients', $this->authHeaders())->assertOk();
    }

    public function test_an_unauthenticated_write_is_401_not_a_schema_problem(): void
    {
        $this->postJson('/api/v1/clients', $this->payload())->assertStatus(401);
    }

    public function test_an_undeterminable_level_refuses_writes_without_a_found_level(): void
    {
        DB::table('server')->delete();

        $this->postJson('/api/v1/clients', $this->payload(), $this->authHeaders())
            ->assertStatus(500)
            ->assertJsonPath('type', self::TYPE)
            ->assertJsonPath('required_dbversion', 100)
            ->assertJsonMissingPath('found_dbversion');
    }

    public function test_raising_the_level_lets_writes_through_without_a_restart(): void
    {
        $this->postJson('/api/v1/clients', $this->payload(), $this->authHeaders())->assertStatus(500);

        DB::table('server')->update(['dbversion' => 100]);

        $this->postJson('/api/v1/clients', $this->payload(), $this->authHeaders())->assertStatus(201);
    }

    public function test_every_non_safe_api_route_carries_the_middleware(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || $route->uri() === 'api/v1/problem-type-probe/{case}') {
                continue;
            }

            if (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === []) {
                continue;
            }

            $this->assertContains('compat.writes', $route->gatherMiddleware(), $route->uri());
            $checked++;
        }

        $this->assertGreaterThan(50, $checked);
    }
}
