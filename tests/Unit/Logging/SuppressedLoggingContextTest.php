<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Services\Logging\SafeLogWriter;
use App\Services\Logging\SecurityLogger;
use App\Services\Logging\TechnicalLogger;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SuppressedLoggingContextTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[DataProvider('levels')]
    public function test_only_emitted_levels_build_context_and_existing_alerts_are_preserved(string $kind, string $level, ?string $dispatch, bool $alert): void
    {
        if ($dispatch === null) {
            Log::shouldReceive('channel')->never();
        } else {
            $channel = Mockery::mock();
            $channel->shouldReceive('log')->once()->with($dispatch, '['.strtoupper($kind).'] event.checked', ['level' => $level]);
            Log::shouldReceive('channel')->once()->with($kind)->andReturn($channel);
        }
        $logger = $kind === 'technical' ? new TechnicalEntrySpyLogger(new SafeLogWriter()) : new SecurityEntrySpyLogger(new SafeLogWriter());
        $logger->log('event.checked', [], $level);

        self::assertSame($dispatch === null ? 0 : 1, $logger->entries);
        self::assertSame($alert ? 1 : 0, $logger->alerts);
    }

    public static function levels(): array
    {
        $cases = [];
        foreach (['technical', 'security'] as $kind) {
            foreach (['info', 'INFO', 'notice', 'debug', 'DEBUG', 'warning', 'WARNING', 'error', 'ERROR', 'critical', 'CRITICAL'] as $level) {
                $normalized = strtolower($level);
                $dispatch = in_array($normalized, $kind === 'technical' ? ['debug', 'warning', 'error', 'critical'] : ['warning', 'error', 'critical'], true) ? $normalized : null;
                $cases[$kind.'-'.$level] = [$kind, $level, $dispatch, in_array($level, ['error', 'critical'], true)];
            }
        }

        return $cases;
    }
}

final class TechnicalEntrySpyLogger extends TechnicalLogger
{
    public int $entries = 0;
    public int $alerts = 0;

    public function __construct(SafeLogWriter $writer) { $this->writer = $writer; }

    protected function createTechnicalEntry(string $event, array $context, string $level): array
    {
        $this->entries++;
        return ['level' => $level];
    }

    protected function sendTechnicalAlert(string $event, array $context, string $level): void { $this->alerts++; }
}

final class SecurityEntrySpyLogger extends SecurityLogger
{
    public int $entries = 0;
    public int $alerts = 0;

    public function __construct(SafeLogWriter $writer) { $this->writer = $writer; }

    protected function createSecurityEntry(string $event, array $context, string $level): array
    {
        $this->entries++;
        return ['level' => $level];
    }

    protected function sendSecurityAlert(string $event, array $context, string $level): void { $this->alerts++; }
}
