<?php

namespace Tests\Unit;

use App\Support\IspconfigCompat;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class IspconfigCompatTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/compat-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);

        parent::tearDown();
    }

    private function file(string $content): string
    {
        $path = $this->dir.'/ISPCONFIG_COMPAT';
        file_put_contents($path, $content);

        return $path;
    }

    public function test_parses_values_ignoring_comments_and_blank_lines(): void
    {
        $path = $this->file("# note\n\nmin_dbversion=100\nmin_version=3.3.1p1\n  tested_version = 3.3.1p1\n");

        $this->assertSame(
            ['min_dbversion' => 100, 'min_version' => '3.3.1p1', 'tested_version' => '3.3.1p1'],
            IspconfigCompat::load($path)
        );
    }

    public function test_accepts_the_optional_tarball_hash(): void
    {
        $hash = str_repeat('a1', 32);
        $path = $this->file("min_dbversion=1\nmin_version=1\ntested_version=1\nschema_tarball_sha256={$hash}\n");

        $this->assertSame($hash, IspconfigCompat::load($path)['schema_tarball_sha256']);
    }

    public function test_the_repository_file_is_valid(): void
    {
        $this->assertGreaterThanOrEqual(1, IspconfigCompat::load(dirname(__DIR__, 2).'/ISPCONFIG_COMPAT')['min_dbversion']);
    }

    public function test_errors_name_the_file(): void
    {
        $cases = [
            'missing file' => null,
            'missing key' => "min_dbversion=100\nmin_version=3.3.1p1\n",
            'non-integer level' => "min_dbversion=abc\nmin_version=1\ntested_version=1\n",
            'zero level' => "min_dbversion=0\nmin_version=1\ntested_version=1\n",
            'bad hash' => "min_dbversion=1\nmin_version=1\ntested_version=1\nschema_tarball_sha256=xyz\n",
            'unknown key' => "min_dbversion=1\nmin_version=1\ntested_version=1\nextra=1\n",
            'no equals sign' => "min_dbversion\n",
        ];

        foreach ($cases as $name => $content) {
            $path = $content === null ? $this->dir.'/absent' : $this->file($content);

            try {
                IspconfigCompat::load($path);
                $this->fail("{$name}: expected an exception");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('ISPCONFIG_COMPAT', $e->getMessage(), $name);
            }
        }
    }
}
