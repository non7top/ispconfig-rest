<?php

namespace Tests\Feature;

use Tests\TestCase;

class RootInfoTest extends TestCase
{
    public function test_root_returns_api_info(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJson([
                'name' => 'ISPConfig REST API',
                'documentation' => '/api/documentation',
            ]);
    }

    public function test_root_reports_the_release_version(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJsonPath('version', trim(file_get_contents(base_path('VERSION'))));
    }

    public function test_health_endpoint_is_up(): void
    {
        $this->get('/up')->assertOk();
    }
}
