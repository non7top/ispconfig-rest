<?php

namespace Tests\Unit;

use App\Support\EnvironmentPath;
use PHPUnit\Framework\TestCase;

class EnvironmentPathTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/envpath-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/{.env,*}', GLOB_BRACE) ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_the_config_directory_wins_when_it_has_an_env_file(): void
    {
        file_put_contents($this->dir.'/.env', "APP_ENV=production\n");

        $this->assertSame($this->dir, EnvironmentPath::resolve('/usr/share/ispconfig-rest', $this->dir));
    }

    public function test_the_install_directory_is_used_without_one(): void
    {
        $this->assertSame('/opt/ispconfig-rest', EnvironmentPath::resolve('/opt/ispconfig-rest', $this->dir));
    }

    public function test_a_missing_config_directory_is_ignored(): void
    {
        $this->assertSame('/opt/ispconfig-rest', EnvironmentPath::resolve('/opt/ispconfig-rest', $this->dir.'/missing'));
    }

    public function test_the_default_config_directory_is_etc_ispconfig_rest(): void
    {
        $this->assertSame('/etc/ispconfig-rest', EnvironmentPath::CONFIG_DIR);
    }
}
