<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\LegalDocumentCreateFailureReporter;
use App\Services\Logging\Context\RequestContext;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class LegalDocumentCreateFailureReporterTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_infrastructure_failure_is_logged_with_operational_context_without_raw_error(): void
    {
        $log = Mockery::mock(LoggerInterface::class);
        $requestContext = Mockery::mock(RequestContext::class);
        $requestContext->shouldReceive('getCorrelationId')->once()->andReturn('request-42');
        $failure = new RuntimeException('s3 secret path org-7/private/agreement.pdf');

        $log->shouldReceive('error')
            ->once()
            ->with('legal_archive.document_create_infrastructure_failure', Mockery::on(
                static function (array $context): bool {
                    $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                    return $context['organization_id'] === 7
                        && $context['actor_id'] === 11
                        && $context['document_id'] === 19
                        && $context['operation_id'] === '018f47f2-7958-7a1a-a728-55a737763901'
                        && $context['correlation_id'] === 'request-42'
                        && $context['failure_class'] === RuntimeException::class
                        && preg_match('/^[a-f0-9]{64}$/D', (string) $context['failure_fingerprint']) === 1
                        && ! str_contains($encoded, 'secret')
                        && ! str_contains($encoded, 'agreement.pdf');
                },
            ));

        (new LegalDocumentCreateFailureReporter($log, $requestContext))->report(
            failure: $failure,
            organizationId: 7,
            actorId: 11,
            documentId: 19,
            operationId: '018f47f2-7958-7a1a-a728-55a737763901',
        );

    }
}
