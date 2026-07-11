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
    ) {}

    public function handle(int $unitId, string $sourceVersion): void
    {
        $now = now()->toDateTimeImmutable();
        $claim = $this->store->claim(
            $unitId,
            $sourceVersion,
            $now,
            $now->modify(sprintf('+%d seconds', self::LEASE_SECONDS)),
            self::MAX_ATTEMPTS,
        );

        if (! $claim->acquired) {
            return;
        }

        $context = $this->store->executionContext($claim);

        if ($context === null) {
            $this->store->fail($claim, 'unit_scope_missing', hash('sha256', 'unit_scope_missing'), now()->toDateTimeImmutable());

            return;
        }

        try {
            $output = $this->processor->process($context);

            if (! $output->matches($context)) {
                throw new DocumentUnitProcessingException('unit_output_identity_mismatch');
            }

            if (! $this->store->publish($claim, $output, now()->toDateTimeImmutable())) {
                throw new LogicException('Document processing unit ownership was lost before publication.');
            }

            $this->reconciler->reconcile($context->documentId, $sourceVersion);
        } catch (Throwable $error) {
            $code = $error instanceof DocumentUnitProcessingException ? $error->safeCode : 'unit_processing_failed';
            $this->store->fail(
                $claim,
                $code,
                hash('sha256', $error::class.'|'.$code),
                now()->toDateTimeImmutable(),
            );

            throw $error;
        }
    }
}
