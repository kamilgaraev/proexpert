<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBuildingModel;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ConfirmBuildingGeometry
{
    public function __construct(private DatabaseManager $database, private GeometryRegenerationIntentStore $outbox) {}

    /** @return array<string, mixed> */
    public function handle(GeometryConfirmationCommand $command): array
    {
        [$result, $intentId] = $this->database->transaction(function () use ($command): array {
            $session = EstimateGenerationSession::query()->whereKey($command->sessionId)->lockForUpdate()->first();
            if ($session === null || (int) $session->organization_id !== $command->organizationId || (int) $session->project_id !== $command->projectId) {
                throw new NotFoundHttpException;
            }
            if ($session->status->isTerminal() || $session->applied_estimate_id !== null) {
                throw new InvalidArgumentException('Geometry confirmation is not allowed.');
            }
            if ((int) $session->state_version !== $command->expectedStateVersion) {
                throw new StaleEstimateGenerationState($command->sessionId, $command->expectedStateVersion);
            }
            $head = EstimateGenerationBuildingModel::query()->where('organization_id', $command->organizationId)
                ->where('project_id', $command->projectId)->where('session_id', $command->sessionId)
                ->latest('id')->lockForUpdate()->first();
            if ($head === null || $head->content_version !== $command->expectedModelVersion || $head->input_version !== $command->expectedInputVersion) {
                throw new StaleEstimateGenerationState($command->sessionId, $command->expectedStateVersion);
            }
            $evidenceValue = ['actor_id' => $command->actorId, 'operations' => $command->operations, 'scale' => $command->scale,
                'previous_model_version' => $command->expectedModelVersion, 'previous_input_version' => $command->expectedInputVersion];
            $fingerprint = hash('sha256', json_encode($evidenceValue, JSON_THROW_ON_ERROR));
            $evidenceId = DB::table('estimate_generation_evidence')->insertGetId([
                'organization_id' => $command->organizationId, 'project_id' => $command->projectId, 'session_id' => $command->sessionId,
                'type' => 'source_fact', 'source_type' => 'user_input', 'source_ref' => 'geometry-confirmation:'.$command->actorId,
                'source_version' => (string) ($command->expectedStateVersion + 1), 'locator' => json_encode(['building_model_id' => $head->getKey()], JSON_THROW_ON_ERROR),
                'value' => json_encode($evidenceValue, JSON_THROW_ON_ERROR), 'confidence' => 1, 'producer_name' => 'geometry-confirmation',
                'producer_version' => 'v1', 'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $model = $this->apply($head->model, $command, $evidenceId);
            $normalized = NormalizedBuildingModelData::fromArray($model);
            $newInputVersion = 'sha256:'.hash('sha256', $command->expectedInputVersion.'|'.$normalized->contentVersion().'|'.($command->expectedStateVersion + 1));
            $new = new EstimateGenerationBuildingModel;
            $new->forceFill([
                'organization_id' => $command->organizationId, 'project_id' => $command->projectId,
                'session_id' => $command->sessionId, 'input_version' => $newInputVersion,
                'model_version' => 'building-model:v1', 'content_version' => $normalized->contentVersion(),
                'scale_status' => $normalized->scaleStatus, 'scale_meters_per_unit' => $normalized->scaleMetersPerUnit,
                'model' => $normalized->toArray(), 'assumptions' => $normalized->toArray()['assumptions'],
                'metrics' => $normalized->metrics,
            ])->save();
            DB::table('estimate_generation_building_model_evidence')->insert(array_map(fn (int $id): array => [
                'building_model_id' => $new->getKey(), 'evidence_id' => $id, 'organization_id' => $command->organizationId,
                'project_id' => $command->projectId, 'session_id' => $command->sessionId, 'created_at' => now(),
            ], $normalized->evidenceIds));
            $rootIds = DB::table('estimate_generation_evidence')->where('session_id', $command->sessionId)
                ->whereNull('invalidated_at')->where('source_type', 'pipeline')->where('source_version', $command->expectedInputVersion)->pluck('id');
            $dependentIds = $rootIds;
            if ($rootIds->isNotEmpty()) {
                $descendants = DB::select('WITH RECURSIVE descendants(id) AS (
                    SELECT child_id FROM estimate_generation_evidence_edges WHERE session_id = ? AND parent_id IN ('.implode(',', array_fill(0, $rootIds->count(), '?')).')
                    UNION SELECT edge.child_id FROM estimate_generation_evidence_edges edge JOIN descendants tree ON tree.id = edge.parent_id WHERE edge.session_id = ?
                ) SELECT id FROM descendants', [$command->sessionId, ...$rootIds->all(), $command->sessionId]);
                $dependentIds = $rootIds->merge(array_map(static fn (object $row): int => (int) $row->id, $descendants))->unique();
            }
            $invalidatedEvidence = DB::table('estimate_generation_evidence')->where('session_id', $command->sessionId)
                ->whereNull('invalidated_at')->whereIn('id', $dependentIds->all())->update([
                    'invalidated_at' => now(), 'invalidation_reason' => 'geometry_confirmed',
                    'invalidation_version' => $command->expectedStateVersion + 1, 'updated_at' => now(),
                ]);
            $invalidatedCheckpoints = DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $command->sessionId)
                ->whereNull('invalidated_at')->where('input_version', $command->expectedInputVersion)->update([
                    'status' => 'invalidated', 'invalidated_at' => now(), 'invalidation_reason' => 'dependency_changed', 'updated_at' => now(),
                ]);
            $session->forceFill(['state_version' => $command->expectedStateVersion + 1, 'draft_payload' => null])->save();
            $intentId = $this->outbox->append(new GeometryRegenerationIntent(
                $command->organizationId, $command->projectId, $command->sessionId, (int) $session->state_version,
                $command->expectedInputVersion, $newInputVersion, $normalized->contentVersion(), (string) Str::uuid(),
            ));

            return [[
                'state_version' => (int) $session->state_version, 'input_version' => $newInputVersion,
                'model_version' => $normalized->contentVersion(), 'building_model' => $normalized->toArray(),
                'blocking_clarifications' => array_values(array_filter($normalized->assumptions, fn ($a): bool => $a->severity === 'blocking')),
                'readiness' => ['geometry_confirmed' => $normalized->scaleStatus === 'confirmed'],
                'invalidation_summary' => ['evidence' => $invalidatedEvidence, 'checkpoints' => $invalidatedCheckpoints],
                'regeneration' => ['status' => 'pending'],
            ], $intentId];
        });
        $this->outbox->deliver($intentId);

        return $result;
    }

    private function apply(array $model, GeometryConfirmationCommand $command, int $evidenceId): array
    {
        foreach ($command->operations as $operation) {
            $floorIndex = $this->find($model['floors'], $operation['floor_key']);
            $elementIndex = $this->find($model['floors'][$floorIndex][$operation['collection']], $operation['element_key']);
            $model['floors'][$floorIndex][$operation['collection']][$elementIndex][$operation['field']] = $operation['value'];
            $model['floors'][$floorIndex][$operation['collection']][$elementIndex]['geometry_certainty'] = 'confirmed';
            $model['floors'][$floorIndex][$operation['collection']][$elementIndex]['evidence_ids'][] = $evidenceId;
            $model['floors'][$floorIndex][$operation['collection']][$elementIndex]['evidence_ids'] = array_values(array_unique($model['floors'][$floorIndex][$operation['collection']][$elementIndex]['evidence_ids']));
        }
        if ($command->scale !== null) {
            [$x1, $y1] = $command->scale['pixel_start'];
            [$x2, $y2] = $command->scale['pixel_end'];
            $distance = hypot((float) $x2 - (float) $x1, (float) $y2 - (float) $y1);
            if ($distance <= 0.0) {
                throw new InvalidArgumentException('Scale control dimension is degenerate.');
            }
            $model['scale_status'] = 'confirmed';
            $model['scale_meters_per_unit'] = (float) $command->scale['meters'] / $distance;
            $model['assumptions'] = array_values(array_filter($model['assumptions'], fn (array $a): bool => ! in_array($a['code'], ['scale_estimated', 'scale_missing', 'scale_conflict'], true)));
            foreach ($model['floors'] as &$floor) {
                $floor['evidence_ids'][] = $evidenceId;
                $floor['evidence_ids'] = array_values(array_unique($floor['evidence_ids']));
            }
            unset($floor);
        }
        $model['metrics'] = NormalizedBuildingModelData::fromArray($model)->metrics;

        return $model;
    }

    private function find(array $items, string $key): int
    {
        foreach ($items as $index => $item) {
            if (($item['key'] ?? null) === $key) {
                return $index;
            }
        }
        throw new InvalidArgumentException('Geometry element does not belong to the locked model.');
    }
}
