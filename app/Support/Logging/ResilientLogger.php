<?php

namespace App\Support\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\FallbackGroupHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Throwable;

/**
 * Custom log driver (spec 010 FR-020): a rotating file first, the PHP error log
 * when the file cannot be written. FallbackGroupHandler swallows every handler
 * failure, so logging can never change the response.
 */
class ResilientLogger
{
    /**
     * @param  array{path: string, days?: int, level?: string}  $config
     */
    public function __invoke(array $config): Logger
    {
        $file = new class($config['path'], (int) ($config['days'] ?? 14), Level::fromName(ucfirst($config['level'] ?? 'debug'))) extends RotatingFileHandler
        {
            protected function write(LogRecord $record): void
            {
                try {
                    parent::write($record);
                } catch (Throwable $e) {
                    LogHealth::markFallback();

                    throw $e;
                }
            }
        };
        $file->setFormatter((new LineFormatter(null, 'Y-m-d H:i:s', true, true))->includeStacktraces());

        $errorLog = new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Level::fromName(ucfirst($config['level'] ?? 'debug')));

        return new Logger('ispconfig-rest', [new FallbackGroupHandler([$file, $errorLog])]);
    }
}
