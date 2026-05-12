<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Services\Logging\EnsureLogFailuresAreNonFatal;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnsureLogFailuresAreNonFatalTest extends TestCase
{
    public function testItRegistersMonologExceptionHandler(): void
    {
        $monolog = new MonologLogger('test');
        $logger = new IlluminateLogger($monolog);

        (new EnsureLogFailuresAreNonFatal())($logger);

        $this->assertNotNull($monolog->getExceptionHandler());
    }

    public function testItSuppressesHandlerWriteExceptions(): void
    {
        $monolog = new MonologLogger('test');
        $monolog->pushHandler(new class () extends AbstractProcessingHandler {
            protected function write(LogRecord $record): void
            {
                throw new RuntimeException('log file is not writable');
            }
        });

        $logger = new IlluminateLogger($monolog);

        (new EnsureLogFailuresAreNonFatal())($logger);

        $logger->error('event.failed');

        $this->assertTrue(true);
    }

    public function testItRedactsContextBeforeWriting(): void
    {
        $handler = new TestHandler();
        $monolog = new MonologLogger('test');
        $monolog->pushHandler($handler);
        $logger = new IlluminateLogger($monolog);

        (new EnsureLogFailuresAreNonFatal())($logger);

        $logger->warning('auth.failed', [
            'email' => 'user@example.com',
            'token_url' => 'https://example.test/?access_token=secret-token-value',
        ]);

        $record = $handler->getRecords()[0];

        $this->assertSame('[REDACTED]', $record->context['email']);
        $this->assertSame('[REDACTED]', $record->context['token_url']);
    }
}
