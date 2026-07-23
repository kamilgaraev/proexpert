<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\Services\Logging\Context\RequestContext;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class LegalDocumentCreateFailureReporter
{
    public function __construct(
        private LoggerInterface $logger,
        private RequestContext $requestContext,
    ) {}

    public function report(
        Throwable $failure,
        int $organizationId,
        ?int $actorId,
        int $documentId,
        string $operationId,
    ): void {
        $this->logger->error('legal_archive.document_create_infrastructure_failure', [
            'organization_id' => $organizationId,
            'actor_id' => $actorId,
            'document_id' => $documentId,
            'operation_id' => $operationId,
            'correlation_id' => $this->requestContext->getCorrelationId(),
            'failure_class' => $failure::class,
            'failure_fingerprint' => hash('sha256', $failure::class.'|'.$failure->getMessage()),
        ]);
    }
}
