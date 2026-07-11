<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use LogicException;
use Throwable;

final readonly class ProcessDocumentUnit
{
    public const LEASE_SECONDS = 2100;

    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private DocumentProcessingUnitStore $store,
        private DocumentUnitProcessor $processor,
        private DocumentUnitAggregateReconciler $reconciler,
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
                throw new LogicException('Document processing unit ownership was lost before publication.');
            }
        } catch (Throwable $error) {
            $code = $error instanceof DocumentUnitProcessingException ? $error->safeCode : 'unit_processing_failed';
            $this->store->fail($claim, $code, hash('sha256', $error::class.'|'.$code), now()->toDateTimeImmutable());

            throw $error;
        }

        $this->reconciler->reconcile($context->documentId, $sourceVersion);

        return new DocumentUnitProcessOutcome(DocumentProcessingUnitClaimStatus::Acquired);
    }
}
