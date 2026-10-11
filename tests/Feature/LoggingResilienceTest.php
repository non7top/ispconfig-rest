<?php

namespace Tests\Feature;

use App\Support\Logging\LogHealth;
use App\Support\Logging\ResilientLogger;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Failures are logged outside /usr and a broken log destination can never
 * change a response (spec 010 US4).
 */
class LoggingResilienceTest extends TestCase
{
    private string $dir;

    private string $errorLog;

    private string|false $originalErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/resilient-log-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->errorLog = $this->dir.'/php-error.log';
        $this->originalErrorLog = ini_set('error_log', $this->errorLog);
        LogHealth::reset();
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->originalErrorLog);
        LogHealth::reset();
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    /** A path whose parent is a regular file: unwritable even for root. */
    private function brokenPath(): string
    {
        file_put_contents($this->dir.'/blocker', '');

        return $this->dir.'/blocker/app.log';
    }

    private function logger(string $path): \Monolog\Logger
    {
        return (new ResilientLogger)(['path' => $path, 'days' => 14, 'level' => 'debug']);
    }

    private function logFiles(): array
    {
        return glob($this->dir.'/app-*.log') ?: [];
    }

    public function test_writable_path_receives_one_entry_per_event(): void
    {
        $this->logger($this->dir.'/app.log')->error('boom');

        $this->assertCount(1, $this->logFiles());
        $this->assertSame(1, substr_count((string) file_get_contents($this->logFiles()[0]), 'boom'));
        $this->assertFileDoesNotExist($this->errorLog);
    }

    public function test_unwritable_path_falls_back_to_the_php_error_log(): void
    {
        $this->logger($this->brokenPath())->error('boom');

        $this->assertStringContainsString('boom', (string) file_get_contents($this->errorLog));
    }

    public function test_both_destinations_failing_raises_nothing(): void
    {
        ini_set('error_log', $this->brokenPath());

        $this->logger($this->brokenPath())->error('boom');

        $this->assertTrue(true);
    }

    public function test_a_broken_log_does_not_change_the_problem_response(): void
    {
        config([
            'logging.default' => 'resilient',
            'logging.channels.resilient.path' => $this->brokenPath(),
            'app.debug' => false,
        ]);
        Route::get('api/v1/log-probe', fn () => throw new \RuntimeException('unhandled'));

        $this->getJson('/api/v1/log-probe')
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertExactJson(['type' => 'about:blank', 'title' => 'Internal server error', 'status' => 500]);

        $this->assertStringContainsString('unhandled', (string) file_get_contents($this->errorLog));
    }

    public function test_probe_reports_ok_and_degraded(): void
    {
        config(['logging.channels.resilient.path' => $this->dir.'/app.log']);
        $this->assertSame('ok', LogHealth::state());

        config(['logging.channels.resilient.path' => $this->brokenPath()]);
        $this->assertSame('degraded', LogHealth::state());
    }

    public function test_a_fallback_marks_logging_degraded(): void
    {
        config(['logging.channels.resilient.path' => $this->dir.'/app.log']);

        $this->logger($this->brokenPath())->error('boom');

        $this->assertSame('degraded', LogHealth::state());
    }
}
