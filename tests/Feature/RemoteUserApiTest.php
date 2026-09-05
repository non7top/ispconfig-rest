<?php

namespace Tests\Feature;

use App\Support\LegacyCrypt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemSchema;
use Tests\TestCase;

/**
 * /system/remote-users CRUD over remote_user (legacy remote/SOAP API
 * accounts - a different auth system from this API's own ApiKey/
 * X-API-Key, see App\Models\RemoteUser).
 *
 * Datalog superset note (same shape as DnsCaApiTest/spec 008): legacy
 * reads this table directly at request time and never sys_datalogs it;
 * the API deliberately journals every write - these tests pin that
 * documented behavior.
 */
class RemoteUserApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        SystemSchema::create();

        config(['api.dev_key' => self::KEY]);

        DB::table('sys_user')->insert([
            'userid' => 1,
            'username' => 'apiadmin',
            'typ' => 'admin',
            'default_group' => 1,
        ]);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedRemoteUser(array $overrides = []): int
    {
        return (int) DB::table('remote_user')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'remote_username' => 'admin',
            'remote_password' => LegacyCrypt::passwordHash('correct horse battery staple'),
            'remote_access' => 'y',
            'remote_ips' => '127.0.0.1,37.143.8.131',
            'remote_functions' => 'client_add,sites_web_domain_add',
        ], $overrides), 'remote_userid');
    }

    // ------------------------------------------------------------------
    // Auth + list
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/system/remote-users')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    public function test_list_returns_data_meta_envelope_with_remote_access_filter(): void
    {
        $this->seedRemoteUser(['remote_username' => 'admin']);
        $this->seedRemoteUser(['remote_username' => 'disabled', 'remote_access' => 'n']);

        $this->getJson('/api/v1/system/remote-users', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.remote_username', 'admin') // default sort: remote_username asc
            ->assertJsonPath('data.0.remote_access', true)
            ->assertJsonPath('data.1.remote_access', false)
            ->assertJsonMissingPath('data.0.remote_password')
            ->assertJsonMissingPath('data.0.sys_userid');

        $this->getJson('/api/v1/system/remote-users?remote_access=true', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.remote_username', 'admin');
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_contract_shape_and_never_the_password(): void
    {
        $id = $this->seedRemoteUser();

        $this->getJson('/api/v1/system/remote-users/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('remote_username', 'admin')
            ->assertJsonPath('remote_access', true)
            ->assertJsonPath('remote_ips', ['127.0.0.1', '37.143.8.131'])
            ->assertJsonPath('remote_functions', ['client_add', 'sites_web_domain_add'])
            ->assertJsonMissingPath('remote_password')
            ->assertJsonMissingPath('sys_userid');
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/system/remote-users/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_hashes_the_password_and_datalogs_insert(): void
    {
        $response = $this->postJson('/api/v1/system/remote-users', [
            'remote_username' => 'hostingfs',
            'remote_password' => 'a cleartext password',
            'remote_access' => true,
            'remote_ips' => ['127.0.0.1'],
            'remote_functions' => ['client_add'],
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('remote_username', 'hostingfs')
            ->assertJsonPath('remote_access', true)
            ->assertJsonPath('remote_ips', ['127.0.0.1'])
            ->assertJsonMissingPath('remote_password');

        $id = $response->json('id');

        $stored = DB::table('remote_user')->where('remote_userid', $id)->first();
        $this->assertNotSame('a cleartext password', $stored->remote_password);
        $this->assertTrue(LegacyCrypt::verifyPasswordHash('a cleartext password', $stored->remote_password));
        $this->assertStringStartsWith('$2y$', $stored->remote_password);

        $row = DB::table('sys_datalog')->where('dbtable', 'remote_user')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('remote_userid:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('hostingfs', $data['new']['remote_username']);
        $this->assertSame('riud', $data['new']['sys_perm_user']); // auth_preset applied
        // The datalog payload carries the HASH, never the cleartext.
        $this->assertNotSame('a cleartext password', $data['new']['remote_password']);
    }

    public function test_create_defaults_to_no_access_and_empty_lists(): void
    {
        $this->postJson('/api/v1/system/remote-users', [
            'remote_username' => 'bare',
            'remote_password' => 'a cleartext password',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('remote_access', false)
            ->assertJsonPath('remote_ips', [])
            ->assertJsonPath('remote_functions', []);

        $this->assertDatabaseHas('remote_user', ['remote_username' => 'bare', 'remote_access' => 'n', 'remote_ips' => '', 'remote_functions' => '']);
    }

    public function test_create_duplicate_username_returns_409(): void
    {
        $this->seedRemoteUser(['remote_username' => 'admin']);

        $this->postJson('/api/v1/system/remote-users', [
            'remote_username' => 'admin',
            'remote_password' => 'a cleartext password',
        ], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409);

        $this->assertSame(1, DB::table('remote_user')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_create_validation_failures_return_422(): void
    {
        $cases = [
            'missing remote_username' => [['remote_password' => 'a cleartext password'], 'remote_username'],
            'missing remote_password' => [['remote_username' => 'x'], 'remote_password'],
            'short remote_password' => [['remote_username' => 'x', 'remote_password' => 'short'], 'remote_password'],
            'bad remote_ips entry' => [['remote_username' => 'x', 'remote_password' => 'a cleartext password', 'remote_ips' => ['not-an-ip']], 'remote_ips.0'],
            'bad remote_functions entry' => [['remote_username' => 'x', 'remote_password' => 'a cleartext password', 'remote_functions' => ['Not Valid!']], 'remote_functions.0'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/system/remote-users', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json');
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('remote_user')->count());
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs_diff(): void
    {
        $id = $this->seedRemoteUser();

        $this->putJson('/api/v1/system/remote-users/'.$id, ['remote_access' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('remote_access', false)
            ->assertJsonPath('remote_username', 'admin'); // untouched

        $this->assertDatabaseHas('remote_user', ['remote_userid' => $id, 'remote_access' => 'n']);

        $row = DB::table('sys_datalog')->where('dbtable', 'remote_user')->first();
        $this->assertNotNull($row);
        $this->assertSame('u', $row->action);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('y', $data['old']['remote_access']);
        $this->assertSame('n', $data['new']['remote_access']);
    }

    /**
     * remote_ips is REPLACED wholesale, not merged - this is the exact
     * append-a-node's-own-hairpin-IP use case wr-salt-new needs (#85):
     * the caller must GET first and PUT the union.
     */
    public function test_update_replaces_remote_ips_wholesale(): void
    {
        $id = $this->seedRemoteUser(['remote_ips' => '127.0.0.1,37.143.8.131']);

        $this->putJson('/api/v1/system/remote-users/'.$id, [
            'remote_ips' => ['127.0.0.1', '37.143.8.131', '46.254.21.64'],
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('remote_ips', ['127.0.0.1', '37.143.8.131', '46.254.21.64']);

        $this->assertDatabaseHas('remote_user', [
            'remote_userid' => $id,
            'remote_ips' => '127.0.0.1,37.143.8.131,46.254.21.64',
        ]);
    }

    public function test_update_omitted_password_keeps_the_stored_hash(): void
    {
        $id = $this->seedRemoteUser();
        $original = DB::table('remote_user')->where('remote_userid', $id)->value('remote_password');

        $this->putJson('/api/v1/system/remote-users/'.$id, ['remote_access' => false], $this->authHeaders())
            ->assertOk();

        $this->assertSame($original, DB::table('remote_user')->where('remote_userid', $id)->value('remote_password'));
    }

    public function test_update_username_to_another_records_value_returns_409(): void
    {
        $this->seedRemoteUser(['remote_username' => 'admin']);
        $id = $this->seedRemoteUser(['remote_username' => 'other']);

        $this->putJson('/api/v1/system/remote-users/'.$id, ['remote_username' => 'admin'], $this->authHeaders())
            ->assertStatus(409)
            ->assertJsonPath('status', 409);

        // Re-sending its own value is fine (self excluded).
        $this->putJson('/api/v1/system/remote-users/'.$id, ['remote_username' => 'other'], $this->authHeaders())
            ->assertOk();
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/system/remote-users/999', ['remote_access' => true], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_and_datalogs_d(): void
    {
        $id = $this->seedRemoteUser();

        $this->deleteJson('/api/v1/system/remote-users/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('remote_user', ['remote_userid' => $id]);

        $row = DB::table('sys_datalog')->where('dbtable', 'remote_user')->first();
        $this->assertNotNull($row);
        $this->assertSame('d', $row->action);
        $this->assertSame('remote_userid:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('admin', $data['old']['remote_username']);
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/system/remote-users/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
