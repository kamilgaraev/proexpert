<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailurePersistenceDiagnostic;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SafeLogFailureRecorderObserver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\DatabaseLessTestCase;

final class SafeLogFailureRecorderObserverTest extends DatabaseLessTestCase
{
    #[Test]
    public function fallback_logs_only_typed_persistence_identity_without_raw_messages(): void
    {
        $failure = (new FailureNormalizer)->normalize(
            new RuntimeException('private prompt and signed storage path'),
            new FailureContext(
                organizationId: 1,
                projectId: 2,
                sessionId: 3,
                stage: ProcessingStage::UnderstandDocuments,
                operation: 'process_unit',
                attempt: 1,
                correlationId: '018f4a20-3f4c-7a11-8a22-123456789abc',
                eventId: '018f4a20-3f4c-7a11-8a22-123456789abd',
                documentId: 4,
                unitId: 5,
            ),
        );
        $persistenceFailure = FailurePersistenceDiagnostic::fromThrowable(
            new RuntimeException('database password and private socket path'),
        );

        Log::shouldReceive('error')->once()->with(
            '[EstimateGeneration] failure observability persistence failed',
            \Mockery::on(static function (array $context): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return ($context['persistence_failure']['code'] ?? null) === 'failure_observability_persistence_failed'
                    && ($context['persistence_failure']['exception_class'] ?? null) === 'runtime_exception'
                    && preg_match('/^sha256:[0-9a-f]{64}$/', (string) ($context['persistence_failure']['diagnostic_fingerprint'] ?? '')) === 1
                    && ! str_contains($encoded, 'password')
                    && ! str_contains($encoded, 'socket')
                    && ! str_contains($encoded, 'private prompt');
            }),
        );

        (new SafeLogFailureRecorderObserver)->recordingFailed($failure, $persistenceFailure);
    }
}
