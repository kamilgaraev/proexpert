<?php

declare(strict_types=1);

namespace App\Services\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Monolog\Logger as MonologLogger;
use Throwable;

final class EnsureLogFailuresAreNonFatal
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (!$monolog instanceof MonologLogger) {
            return;
        }

        $redactor = new SensitiveDataRedactor();

        $monolog->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
            context: $redactor->redact($record->context)
        ));

        $monolog->setExceptionHandler(static function (Throwable $exception): void {
            error_log(sprintf(
                'log_write_failed exception=%s message=%s',
                $exception::class,
                $exception->getMessage()
            ));
        });
    }
}
