<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Services\Logging\SafeLogWriter;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class SafeLogWriterTest extends TestCase
{
    public function test_channel_write_failure_does_not_escape(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('technical')
            ->andThrow(new \RuntimeException('log file is not writable'));

        $writer = new SafeLogWriter();

        $writer->write('technical', 'error', 'event.failed', ['password' => 'secret']);

        $this->assertTrue(true);
    }
}
