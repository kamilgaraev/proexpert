<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class EstimateGenerationMutationPolicy
{
    /** @return list<EstimateGenerationStatus> */
    public static function documentStatuses(): array
    {
        return [
            EstimateGenerationStatus::Draft,
            EstimateGenerationStatus::ProcessingDocuments,
            EstimateGenerationStatus::InputReviewRequired,
            EstimateGenerationStatus::ReadyToGenerate,
            EstimateGenerationStatus::Generating,
            EstimateGenerationStatus::EstimateReviewRequired,
            EstimateGenerationStatus::ReadyToApply,
            EstimateGenerationStatus::Failed,
        ];
    }

    public function analyze(EstimateGenerationSession $session, int $expectedVersion): void
    {
        $this->assert($session, $expectedVersion, [
            EstimateGenerationStatus::Draft,
            EstimateGenerationStatus::ProcessingDocuments,
        ], 'analyze');
    }

    public function generate(EstimateGenerationSession $session, int $expectedVersion): void
    {
        $this->assert($session, $expectedVersion, [
            EstimateGenerationStatus::Draft,
            EstimateGenerationStatus::ProcessingDocuments,
            EstimateGenerationStatus::ReadyToGenerate,
            EstimateGenerationStatus::Generating,
            EstimateGenerationStatus::EstimateReviewRequired,
            EstimateGenerationStatus::ReadyToApply,
            EstimateGenerationStatus::Applied,
        ], 'generate');
    }

    public function review(EstimateGenerationSession $session, int $expectedVersion): void
    {
        $this->assert($session, $expectedVersion, [
            EstimateGenerationStatus::EstimateReviewRequired,
            EstimateGenerationStatus::ReadyToApply,
        ], 'review');
    }

    public function documents(EstimateGenerationSession $session, int $expectedVersion): void
    {
        $this->assert($session, $expectedVersion, self::documentStatuses(), 'documents_changed');
        if ($session->status === EstimateGenerationStatus::Failed
            && $session->resume_status !== EstimateGenerationStatus::ProcessingDocuments) {
            throw new InvalidEstimateGenerationState($session->status, 'documents_changed');
        }
    }

    public static function canMutateDocuments(EstimateGenerationSession $session): bool
    {
        return in_array($session->status, self::documentStatuses(), true)
            && ($session->status !== EstimateGenerationStatus::Failed
                || $session->resume_status === EstimateGenerationStatus::ProcessingDocuments);
    }

    /** @param list<EstimateGenerationStatus> $allowedStatuses */
    private function assert(
        EstimateGenerationSession $session,
        int $expectedVersion,
        array $allowedStatuses,
        string $operation,
    ): void {
        if ($session->state_version !== $expectedVersion) {
            throw new StaleEstimateGenerationState((int) $session->getKey(), $expectedVersion);
        }
        if (! in_array($session->status, $allowedStatuses, true)) {
            throw new InvalidEstimateGenerationState($session->status, $operation);
        }
    }
}
