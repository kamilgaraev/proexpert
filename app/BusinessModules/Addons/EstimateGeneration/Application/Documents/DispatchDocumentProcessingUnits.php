<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DispatchDocumentProcessingUnits
{
    public const BATCH_SIZE = 16;

    public const RECOVERY_DELAY_SECONDS = 300;

    public function __construct(
        private DocumentUnitDispatchStore $store,
        private EstimateGenerationUnitJobDispatcher $jobs,
    ) {}

    public function forDocument(int $documentId, string $sourceVersion, bool $priority = false): int
    {
        return $this->dispatch(
            $this->store->dueForDocument($documentId, $sourceVersion, now()->toDateTimeImmutable(), self::BATCH_SIZE),
            $priority,
        );
    }

    public function recover(): int
    {
        return $this->dispatch($this->store->dueForRecovery(now()->toDateTimeImmutable(), self::BATCH_SIZE));
    }

    /** @param list<DocumentUnitDispatchCandidate> $candidates */
    private function dispatch(array $candidates, ?bool $priority = null): int
    {
        $count = 0;

        foreach ($candidates as $candidate) {
            $now = now()->toDateTimeImmutable();
            $dispatched = $this->store->dispatchIfAllowed(
                $candidate,
                $now,
                $now->modify(sprintf('+%d seconds', self::RECOVERY_DELAY_SECONDS)),
                fn () => $this->jobs->dispatch(
                    $candidate->unitId,
                    $candidate->sourceVersion,
                    $priority ?? $candidate->priority,
                ),
            );
            $count += (int) $dispatched;
        }

        return $count;
    }
}
