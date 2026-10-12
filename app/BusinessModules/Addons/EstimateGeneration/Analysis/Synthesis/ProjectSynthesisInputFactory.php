<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\DeterministicGeometryCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope;
use InvalidArgumentException;

/** Read-only input capture shared by quote preflight and execution. */
final readonly class ProjectSynthesisInputFactory
{
    public function __construct(private ProjectModelRepository $models) {}

    public function capture(int $organizationId, int $projectId, int $sessionId, ProjectModelSnapshot $snapshot,
        int $maxFacts, ?AiSessionWireScope $wireScope = null): ProjectSynthesisInput
    {
        if ($maxFacts < 1 || $maxFacts > 10000 || $snapshot->facts === [] || count($snapshot->facts) > $maxFacts) {
            throw new InvalidArgumentException('project_synthesis_snapshot_limit_exceeded');
        }
        foreach ([...$snapshot->entities, ...$snapshot->facts, ...$snapshot->evidence, ...$snapshot->conflicts] as $record) {
            if ($record->organizationId !== $organizationId || $record->projectId !== $projectId || $record->sessionId !== $sessionId) {
                throw new InvalidArgumentException('project_synthesis_snapshot_scope_invalid');
            }
        }
        $sourceVersions = array_values(array_unique(array_map(
            static fn (Fact $fact): string => $fact->sourceVersion,
            $snapshot->facts,
        )));
        sort($sourceVersions, SORT_STRING);
        $quantities = [];
        foreach ($sourceVersions as $version) {
            $quantities = [
                ...$quantities,
                ...array_values(array_filter($this->models->currentDerivedQuantities(
                    $organizationId,
                    $projectId,
                    $sessionId,
                    $version,
                    $maxFacts,
                ), static fn (DerivedQuantity $quantity): bool => $quantity->formulaVersion
                    !== DeterministicGeometryCalculator::FORMULA_VERSION)),
            ];
        }
        $quantities = [
            ...$quantities,
            ...$this->models->currentDerivedQuantitiesForFormulaVersion(
                $organizationId,
                $projectId,
                $sessionId,
                DeterministicGeometryCalculator::FORMULA_VERSION,
                $maxFacts,
            ),
        ];
        $roleFingerprints = $this->models->completedSynthesisRoleFingerprints(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersions,
        );
        $factIds = array_map(static fn (Fact $fact): string => $fact->id, $snapshot->facts);
        $decisions = $this->models->decisionsForSelectedFacts($organizationId, $projectId, $sessionId, $factIds);

        return new ProjectSynthesisInput(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersions,
            array_map(static fn (Fact $fact): array => [
                'id' => $fact->id,
                'entity_id' => $fact->entityId,
                'type' => $fact->type,
                'value' => $fact->value,
                'unit' => $fact->unit,
                'status' => $fact->status,
                'origin' => $fact->origin,
                'evidence_ids' => $fact->evidenceIds,
                'source_version' => $fact->sourceVersion,
                'version' => $fact->version,
                'current' => $fact->status !== 'invalidated',
            ], $snapshot->facts),
            array_map(static fn (DerivedQuantity $quantity): array => [
                'id' => $quantity->id,
                'logical_id' => $quantity->logicalId,
                'entity_id' => $quantity->entityId,
                'formula_identity' => $quantity->formulaIdentity,
                'formula_version' => $quantity->formulaVersion,
                'value' => $quantity->value,
                'unit' => $quantity->unit,
                'status' => $quantity->status,
                'source_version' => $quantity->sourceVersion,
                'exact_identity' => $quantity->exactIdentity,
                'operand_fact_ids' => array_column($quantity->operands, 'fact_id'),
            ], $quantities),
            array_map(static fn ($decision): array => [
                'id' => $decision->id,
                'version' => $decision->version,
                'selected_fact_id' => $decision->selectedFactId,
            ], $decisions),
            [
                'arbiter' => $roleFingerprints['arbiter'],
                'geometry_expert' => $roleFingerprints['geometry_expert'],
            ],
            RunProjectSynthesis::PROMPT_CONTRACT,
            $wireScope,
        );
    }
}
