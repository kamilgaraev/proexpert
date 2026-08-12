<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateDialogueContextSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateDialogueContextSnapshotRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class EloquentEstimateDialogueContextSnapshotRepository implements EstimateDialogueContextSnapshotRepository
{
    private const MAX_FACTS = 100;

    private const MAX_DECISIONS = 256;

    private const MAX_DERIVED_QUANTITIES = 5000;

    public function __construct(
        private DatabaseManager $database,
        private ProjectModelRepository $models,
    ) {}

    public function capture(int $organizationId, int $projectId, int $sessionId): EstimateDialogueContextSnapshot
    {
        $capture = function () use ($organizationId, $projectId, $sessionId): EstimateDialogueContextSnapshot {
            $session = EstimateGenerationSession::query()
                ->whereKey($sessionId)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->with('documents:id,session_id,source_version,checksum_sha256,updated_at')
                ->firstOrFail();
            $planning = $this->models->snapshotForPlanning(
                $organizationId,
                $projectId,
                $sessionId,
                self::MAX_FACTS + 1,
            );
            if (count($planning['snapshot']->facts) > self::MAX_FACTS) {
                throw new RuntimeException('estimate_generation.command_context_review_required:facts');
            }
            $factIds = array_map(
                static fn (Fact $fact): string => $fact->id,
                $planning['snapshot']->facts,
            );
            $decisions = $this->models->decisionsForSelectedFacts(
                $organizationId,
                $projectId,
                $sessionId,
                $factIds,
            );
            if (count($decisions) > self::MAX_DECISIONS) {
                throw new RuntimeException('estimate_generation.command_context_review_required:decisions');
            }
            $technology = $this->models->currentTechnologyRecommendations($organizationId, $projectId, $sessionId);
            $completeness = $this->models->currentCompleteness($organizationId, $projectId, $sessionId);
            $sourceVersion = $technology['source_version']
                ?? $completeness['source_version']
                ?? $planning['snapshot']->facts[0]->sourceVersion
                ?? null;
            $derived = is_string($sourceVersion)
                ? $this->models->currentDerivedQuantities(
                    $organizationId,
                    $projectId,
                    $sessionId,
                    $sourceVersion,
                    self::MAX_DERIVED_QUANTITIES + 1,
                )
                : [];
            if (count($derived) > self::MAX_DERIVED_QUANTITIES) {
                throw new RuntimeException('estimate_generation.command_context_review_required:derived_quantities');
            }

            return new EstimateDialogueContextSnapshot(
                $organizationId,
                $projectId,
                $sessionId,
                (int) $session->state_version,
                is_array($session->input_payload) ? $session->input_payload : [],
                is_array($session->analysis_payload) ? $session->analysis_payload : [],
                is_array($session->draft_payload) ? $session->draft_payload : [],
                $planning['snapshot'],
                (string) $planning['token'],
                $decisions,
                $technology,
                $completeness,
                $derived,
                $session->documents->map(static fn ($document): array => [
                    'id' => (int) $document->id,
                    'source_version' => $document->source_version,
                    'checksum' => $document->checksum_sha256,
                    'updated_at' => $document->updated_at?->toISOString(),
                ])->sortBy('id')->values()->all(),
            );
        };

        $connection = $this->database->connection();
        if ($connection->transactionLevel() > 0) {
            return $capture();
        }

        return $connection->transaction(function () use ($connection, $capture): EstimateDialogueContextSnapshot {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            return $capture();
        }, 3);
    }
}
