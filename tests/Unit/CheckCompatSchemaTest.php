<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * scripts/check-compat-schema.php against a small fixture schema (spec 010 US5).
 */
class CheckCompatSchemaTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/compat-check-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * @param  array<string, list<string>>  $written
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function check(array $written, int $minDbversion): array
    {
        file_put_contents($this->dir.'/written.json', json_encode($written));
        file_put_contents($this->dir.'/ISPCONFIG_COMPAT', "min_dbversion={$minDbversion}\nmin_version=x\ntested_version=x\n");

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/scripts/check-compat-schema.php', $this->dir.'/written.json', dirname(__DIR__).'/fixtures/ispconfig-schema', $this->dir.'/ISPCONFIG_COMPAT'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function test_a_column_from_a_newer_patch_fails_naming_column_and_patch(): void
    {
        [$exit, $out] = $this->check(['client' => ['company_name', 'late_column']], 100);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('client.late_column needs schema level 101 (upd_0101.sql) but min_dbversion is 100', $out);
    }

    public function test_raising_the_minimum_makes_it_pass(): void
    {
        [$exit, $out] = $this->check(['client' => ['company_name', 'mid_column', 'late_column']], 101);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('required schema level: 101', $out);
    }

    public function test_a_column_from_the_base_schema_needs_no_patch(): void
    {
        [$exit, $out] = $this->check(['client' => ['company_name']], 100);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('required schema level: 0', $out);
    }

    public function test_a_written_column_in_no_schema_fails(): void
    {
        [$exit, $out] = $this->check(['client' => ['ghost']], 101);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('client.ghost exists in no ISPConfig schema', $out);
    }

    public function test_an_unclassifiable_statement_is_a_warning_not_silence(): void
    {
        [, , $err] = $this->check(['client' => ['company_name']], 100);

        $this->assertStringContainsString('upd_0100: unclassified statement: GRANT SELECT', $err);
    }
}
