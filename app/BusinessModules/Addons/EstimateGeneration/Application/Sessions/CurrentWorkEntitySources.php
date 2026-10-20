<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ManualEntityIdentityFactEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\WorkEntityScopePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use Illuminate\Validation\ValidationException;

final readonly class CurrentWorkEntitySources
{
    public function __construct(private ProjectModelRepository $models, private EvidenceRepository $evidence,
        private CurrentRoomIdentitySources $rooms) {}

    public function rows(EstimateGenerationSession $session, array $item): array
    {
        return $this->rowsForTypes($session, WorkEntityScopePolicy::types($item));
    }

    public function rowsForTypes(EstimateGenerationSession $session, array $types): array
    {
        $rows = in_array('room', $types, true) ? array_map(static fn (array $row): array => [...$row, 'entity_type' => 'room'],
            array_values(array_filter($this->rooms->rows($session, true), static fn (array $row): bool => $row['status'] === 'confirmed'))) : [];
        $types = array_values(array_intersect($types, ['roof', 'site']));
        if ($types === []) {
            return $rows;
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = $this->models->snapshot(...[...$scope, 10001]);
        if (count($snapshot->facts) > 10000) {
            throw ValidationException::withMessages(['source' => trans_message('estimate_generation.room_identity.fact_limit')]);
        }
        $entities = array_column(array_filter($snapshot->entities, static fn ($entity): bool => in_array(GeometryInventory::type($entity), $types, true)
            && ! ($entity->attributes['contextual_only'] ?? false) && ! ($entity->attributes['excluded_by_document_note'] ?? false)
            && ! ($entity->attributes['properties']['contextual_only'] ?? false) && ! ($entity->attributes['properties']['excluded_by_document_note'] ?? false)), null, 'id');
        $facts = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'entity_identity' && isset($entities[$fact->entityId])));
        $decisions = $facts === [] ? [] : array_column($this->models->decisionsForSelectedFacts(...[...$scope, array_column($facts, 'id')]), null, 'selectedFactId');
        $proofs = array_column($snapshot->evidence, null, 'id');
        $ids = [];
        foreach ($facts as $fact) {
            foreach ($fact->evidenceIds as $id) {
                if (preg_match('/\Aevidence:([1-9][0-9]*)\z/', $id, $match) === 1) {
                    $ids[(int) $match[1]] = (int) $match[1];
                }
            }
        }
        if (count($ids) > 50000) {
            throw ValidationException::withMessages(['source' => trans_message('estimate_generation.room_identity.fact_limit')]);
        }
        $nodes = [];
        foreach (array_chunk(array_values($ids), 1000) as $chunk) {
            foreach ($this->evidence->activeNodesForUpdate(...[...$scope, $chunk]) as $node) {
                $nodes[$node->id] = $node;
            }
        }
        $eligibility = new ManualEntityIdentityFactEligibility;
        foreach ($facts as $fact) {
            $entity = $entities[$fact->entityId];
            $node = $eligibility->proof($snapshot, $fact, $entity, $decisions[$fact->id] ?? null, $nodes, $types);
            $proof = $node === null ? null : ($proofs['evidence:'.$node->id] ?? null);
            if ($proof === null) {
                continue;
            }
            $rows[] = ['source_kind' => 'user_input', 'source_fact_id' => $fact->id, 'source_fact_version' => $fact->version,
                'source_value_hash' => DirectQuantityBinding::valueHash($fact->value), 'source_version' => $fact->sourceVersion,
                'entity_id' => $entity->id, 'entity_type' => GeometryInventory::type($entity), 'name' => $fact->value['name'], 'status' => 'confirmed',
                'identity_only' => true, 'dimensions_confirmed' => false,
                'floor' => $entity->attributes['floor_id'] ?? null, 'zone' => $entity->attributes['zone_id'] ?? null,
                'evidence' => ['id' => $proof->id, 'source_artifact_id' => $proof->sourceArtifactId, 'document_id' => null,
                    'document_name' => null, 'page' => null, 'region' => null, 'native_reference' => null, 'source_reference' => $proof->sourceReference]];
        }
        usort($rows, static fn (array $left, array $right): int => [$left['entity_type'], $left['name'], $left['entity_id'], $left['source_fact_id']]
            <=> [$right['entity_type'], $right['name'], $right['entity_id'], $right['source_fact_id']]);

        return $rows;
    }
}
