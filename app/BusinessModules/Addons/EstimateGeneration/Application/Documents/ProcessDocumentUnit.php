<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use Throwable;

final readonly class ProcessDocumentUnit
{
    public const LEASE_SECONDS = 2100;

    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private DocumentProcessingUnitStore $store,
        private DocumentUnitProcessor $processor,
        private DocumentUnitAggregateReconciler $reconciler,
        private FailureRecorder $failureRecorder,
        private ?DocumentUnitExhaustionHandler $exhaustion = null,
    ) {}

    public function handle(int $unitId, string $sourceVersion): DocumentUnitProcessOutcome
    {
        $now = now()->toDateTimeImmutable();
        $claim = $this->store->claim(
            $unitId,
            $sourceVersion,
            $now,
            $now->modify(sprintf('+%d seconds', self::LEASE_SECONDS)),
            self::MAX_ATTEMPTS,
        );

        if ($claim->status === DocumentProcessingUnitClaimStatus::AlreadyCompleted) {
            $record = $this->store->find($unitId);
            if ($record !== null) {
                $this->reconciler->reconcile($record->documentId, $sourceVersion);
            }

            return new DocumentUnitProcessOutcome($claim->status);
        }

        if ($claim->status === DocumentProcessingUnitClaimStatus::Exhausted) {
            $this->exhaustion?->handle($unitId);

            return new DocumentUnitProcessOutcome($claim->status);
        }

        if (! $claim->acquired()) {
            return new DocumentUnitProcessOutcome($claim->status, $claim->busyUntil);
        }

        $context = $this->store->executionContext($claim);

        if ($context === null) {
            $this->store->fail($claim, 'unit_scope_missing', hash('sha256', 'unit_scope_missing'), now()->toDateTimeImmutable());

            return new DocumentUnitProcessOutcome(DocumentProcessingUnitClaimStatus::Stale);
        }

        try {
            $output = $this->processor->process($context);

            if (! $output->matches($context)) {
                throw new DocumentUnitProcessingException('unit_output_identity_mismatch');
            }

            if (! $this->store->publish($claim, $output, now()->toDateTimeImmutable())) {
                throw new TypedFailureException(FailureCategory::Recoverable, 'unit_claim_lost');
            }

            try {
                $this->failureRecorder->resolveActive($this->failureContext($context));
            } catch (Throwable) {
            }
        } catch (Throwable $error) {
            $code = $error instanceof DocumentUnitProcessingException ? $error->safeCode : 'unit_processing_failed';
            $this->store->fail($claim, $code, hash('sha256', $error::class.'|'.$code), now()->toDateTimeImmutable());
            $this->failureRecorder->capture($error, $this->failureContext($context));

            throw $error;
        }

        $this->reconciler->reconcile($context->documentId, $sourceVersion);

        return new DocumentUnitProcessOutcome(DocumentProcessingUnitClaimStatus::Acquired);
    }

    private function failureContext(DocumentUnitExecutionContext $context): FailureContext
    {
        return new FailureContext(
            organizationId: $context->organizationId,
            projectId: $context->projectId,
            sessionId: $context->sessionId,
            stage: ProcessingStage::UnderstandDocuments,
            operation: 'process_unit',
            attempt: $context->unitAttemptCount,
            correlationId: AiOperationContext::deterministicId(sprintf(
                'unit|%d|%s',
                $context->unitId,
                $context->sourceVersion,
            )),
            eventId: $context->claimToken,
            expectedSessionStateVersion: $context->sessionStateVersion,
            expectedSessionStatus: $context->sessionStatus,
            documentId: $context->documentId,
            pageId: $context->pageId,
            unitId: $context->unitId,
        );
    }
}
