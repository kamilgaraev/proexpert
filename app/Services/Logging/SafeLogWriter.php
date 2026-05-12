<?php

declare(strict_types=1);

namespace App\Services\Logging;

use Illuminate\Support\Facades\Log;
use Throwable;

final class SafeLogWriter
{
    public function __construct(
        private readonly ?SensitiveDataRedactor $redactor = null
    ) {
    }

    public function write(string $channel, string $level, string $message, array $context = []): void
    {
        $redactedContext = ($this->redactor ?? new SensitiveDataRedactor())->redact($context);

        try {
            Log::channel($channel)->log($level, $message, $redactedContext);
        } catch (Throwable $exception) {
            $this->writeFallback($channel, $level, $message, $exception);
        }
    }

    public function default(string $level, string $message, array $context = []): void
    {
        $redactedContext = ($this->redactor ?? new SensitiveDataRedactor())->redact($context);

        try {
            Log::log($level, $message, $redactedContext);
        } catch (Throwable $exception) {
            $this->writeFallback('default', $level, $message, $exception);
        }
    }

    private function writeFallback(string $channel, string $level, string $message, Throwable $exception): void
    {
        try {
            error_log(sprintf(
                'log_write_failed channel=%s level=%s message=%s error=%s',
                $channel,
                $level,
                $message,
                $exception->getMessage()
            ));
        } catch (Throwable) {
        }
    }
}
