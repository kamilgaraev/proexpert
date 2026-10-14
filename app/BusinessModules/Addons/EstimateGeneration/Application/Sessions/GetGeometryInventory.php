<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;

final readonly class GetGeometryInventory
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private ProjectModelRepository $models) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input = []): array
    {
        $page = $input['page'] ?? 1;
        $perPage = $input['per_page'] ?? 25;
        $search = $input['search'] ?? '';
        $parentId = $input['parent_entity_id'] ?? null;
        $relation = $input['relation'] ?? null;
        if (! is_int($page) || $page < 1 || ! is_int($perPage) || $perPage < 1 || $perPage > 50
            || ! is_string($search) || mb_strlen($search) > 100 || (($parentId === null) !== ($relation === null))
            || ($parentId !== null && (! is_string($parentId) || strlen($parentId) > 160 || ! is_string($relation)))) {
            throw ValidationException::withMessages(['geometry' => trans_message('estimate_generation.geometry_inventory.invalid_query')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $page, $perPage, $search, $parentId, $relation): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            $available = config('estimate-generation.universal_enabled', false) && ($session->input_payload['evaluation_mode'] ?? null) === 'universal';
            if (! $available) {
                return ['available' => false, 'state_version' => (int) $session->state_version, 'cost_units' => '0', 'inventory' => null,
                    'entities' => [], 'pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0]];
            }
            $snapshot = $this->models->snapshotForPlanning((int) $session->organization_id, $projectId, $sessionId, 10001)['snapshot'];
            if (count($snapshot->facts) > 10000) {
                throw ValidationException::withMessages(['geometry' => trans_message('estimate_generation.geometry_inventory.fact_limit')]);
            }
            $inventory = null;
            $documentVersions = GeometryInventory::currentDocumentVersions($snapshot);
            if ($available && $parentId !== null) {
                try {
                    $inventory = GeometryInventory::capture($snapshot, $parentId, $relation, $documentVersions);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages(['geometry' => trans_message('estimate_generation.geometry_inventory.entity_invalid')]);
                }
            }
            $proofs = array_column($snapshot->evidence, null, 'id');
            $byEntity = [];
            foreach ($snapshot->facts as $fact) {
                $byEntity[$fact->entityId][] = $fact;
            }
            $visible = $inventory === null ? null : array_fill_keys([$parentId, ...$inventory['member_ids'], ...$inventory['unassigned_ids']], true);
            $rows = [];
            foreach (GeometryInventory::entities($snapshot, $documentVersions) as $entity) {
                if ($visible !== null && ! isset($visible[$entity->id])) {
                    continue;
                }
                $type = GeometryInventory::type($entity);
                $name = $entity->attributes['name'] ?? $entity->attributes['properties']['name'] ?? null;
                $name = is_string($name) && trim($name) !== '' ? mb_substr($name, 0, 160) : trans_message('estimate_generation.geometry_inventory.types.'.$type);
                $floor = $entity->attributes['floor_id'] ?? $entity->attributes['properties']['floor_id'] ?? null;
                $zone = $entity->attributes['zone_id'] ?? $entity->attributes['properties']['zone_id'] ?? null;
                if ($search !== '' && mb_stripos($name.' '.($floor ?? '').' '.($zone ?? ''), $search) === false) {
                    continue;
                }
                $locators = $documentVersions[$entity->id]['evidence'] ?? [];
                foreach ($byEntity[$entity->id] ?? [] as $fact) {
                    foreach ($fact->evidenceIds as $id) {
                        $proof = $proofs[$id] ?? null;
                        if ($proof === null || $proof->sourceType !== 'document' || $proof->sourceVersion !== $entity->sourceVersion
                            || preg_match('/\Adocument:([1-9][0-9]*)\z/', $proof->sourceArtifactId, $document) !== 1) {
                            continue;
                        }
                        $locators[$id] = ['id' => $id, 'document_id' => (int) $document[1], 'source_version' => $proof->sourceVersion,
                            'page' => $proof->page, 'region' => $proof->region, 'native_reference' => $proof->nativeReference];
                    }
                }
                $rows[] = ['entity_id' => $entity->id, 'type' => $type, 'name' => $name, 'floor' => $floor, 'zone' => $zone,
                    'source_version' => $entity->sourceVersion, 'parent_entity_id' => $entity->attributes['room_id'] ?? $entity->attributes['wall_id'] ?? $entity->attributes['roof_id'] ?? null,
                    'relations' => array_keys(array_filter(GeometryInventory::RELATIONS, static fn (array $definition): bool => $definition[0] === $type)),
                    'evidence' => array_values($locators)];
            }
            $total = count($rows);
            $last = max(1, (int) ceil($total / $perPage));
            $current = min($page, $last);

            return ['available' => $available, 'state_version' => (int) $session->state_version, 'cost_units' => '0',
                'inventory' => $inventory, 'entities' => array_slice($rows, ($current - 1) * $perPage, $perPage),
                'pagination' => ['current_page' => $current, 'last_page' => $last, 'per_page' => $perPage, 'total' => $total]];
        }, 3);
    }
}
