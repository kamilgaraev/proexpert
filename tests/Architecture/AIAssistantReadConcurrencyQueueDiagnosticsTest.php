<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\BusinessModules\Features\AIAssistant\Services\AssistantReadConcurrencyLimiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AIAssistantReadConcurrencyQueueDiagnosticsTest extends TestCase
{
    #[Test]
    public function chat_worker_capacity_is_bounded_and_document_workers_remain_separate(): void
    {
        $horizon = file_get_contents(dirname(__DIR__, 2).'/config/horizon.php');
        self::assertIsString($horizon);
        self::assertSame(2, substr_count($horizon, "'processes' => max(1, min(6, (int) env('AI_CHAT_WORKER_PROCESSES', 6))),"));

        foreach (['production', 'local'] as $environment) {
            $sectionStart = strpos($horizon, "'{$environment}' => [");
            self::assertIsInt($sectionStart);
            $chatStart = strpos($horizon, "'supervisor-ai-chat' => [", $sectionStart);
            self::assertIsInt($chatStart);
            $chatEnd = strpos($horizon, "            ],\n", $chatStart);
            self::assertIsInt($chatEnd);
            $chatSupervisor = substr($horizon, $chatStart, $chatEnd - $chatStart);

            self::assertStringContainsString("'connection' => 'redis_ai_rag'", $chatSupervisor);
            self::assertStringContainsString("'queue' => ['ai-chat']", $chatSupervisor);
            self::assertStringContainsString("'tries' => 1", $chatSupervisor);
            self::assertStringContainsString("'timeout' => 420", $chatSupervisor);
            self::assertStringContainsString("'memory' => 512", $chatSupervisor);
            self::assertStringContainsString("'processes' => max(1, min(6, (int) env('AI_CHAT_WORKER_PROCESSES', 6))),", $chatSupervisor);
        }

        foreach (['supervisor-ai-rag', 'supervisor-ai-rag-live'] as $supervisor) {
            $start = strpos($horizon, "'{$supervisor}' => [");
            self::assertIsInt($start);
            $end = strpos($horizon, "            ],\n", $start);
            self::assertIsInt($end);
            $ragSupervisor = substr($horizon, $start, $end - $start);
            if ($supervisor === 'supervisor-ai-rag') {
                self::assertStringContainsString("'maxProcesses' => 1", $ragSupervisor);
            } else {
                self::assertStringContainsString("'processes' => 1", $ragSupervisor);
            }
        }
        self::assertStringNotContainsString('estimate-generation-documents', $horizon);
    }

    #[Test]
    public function database_admission_and_lease_deadlines_are_explicitly_bounded(): void
    {
        $limits = (new \ReflectionClass(AssistantReadConcurrencyLimiter::class))->getConstants();

        self::assertSame(2, $limits['CAPACITY']);
        self::assertSame(1, $limits['PER_ORGANIZATION_CAPACITY']);
        self::assertSame(12_000, $limits['ADMISSION_TIMEOUT_MS']);
        self::assertSame(30_000, $limits['READ_PHASE_DEADLINE_MS']);
        self::assertSame(60_000, $limits['LEASE_TTL_MS']);
        self::assertGreaterThan($limits['READ_PHASE_DEADLINE_MS'], $limits['LEASE_TTL_MS']);
    }
}
