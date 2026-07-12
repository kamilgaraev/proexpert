<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

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
    public function __construct(
        private DatabaseManager $database,
        private GeometryRegenerationIntentStore $outbox,
        private BuildingGeometryMutator $mutator,
        private GeometryDependencyInvalidator $invalidator,
    ) {}

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
            $provisional = $this->mutator->mutate($head->model, $command);
            if ($provisional->contentVersion() === $head->content_version) {
                throw new InvalidArgumentException('Geometry confirmation does not change the model.');
            }
            $evidenceId = $this->reserveEvidenceId();
            $normalized = $this->mutator->mutate($head->model, $command, $evidenceId);
            $newInputVersion = 'sha256:'.hash('sha256', $command->expectedInputVersion.'|'.$normalized->contentVersion().'|'.($command->expectedStateVersion + 1));
            $sourceEvidenceIds = array_values(array_map('intval', $head->model['evidence_ids'] ?? []));
            $evidenceValue = [
                'actor_id' => $command->actorId, 'confirmed_at' => now()->toIso8601String(),
                'operations' => $command->operations, 'scale' => $command->scale, 'source_evidence_ids' => $sourceEvidenceIds,
                'previous_state_version' => $command->expectedStateVersion, 'new_state_version' => $command->expectedStateVersion + 1,
                'previous_model_version' => $command->expectedModelVersion, 'new_model_version' => $normalized->contentVersion(),
                'previous_input_version' => $command->expectedInputVersion, 'new_input_version' => $newInputVersion,
            ];
            $fingerprint = hash('sha256', json_encode($evidenceValue, JSON_THROW_ON_ERROR));
            DB::table('estimate_generation_evidence')->insert([
                'id' => $evidenceId,
                'organization_id' => $command->organizationId, 'project_id' => $command->projectId, 'session_id' => $command->sessionId,
                'type' => 'source_fact', 'source_type' => 'user_input', 'source_ref' => 'geometry-confirmation:'.$command->actorId,
                'source_version' => $newInputVersion, 'locator' => json_encode(['building_model_id' => $head->getKey()], JSON_THROW_ON_ERROR),
                'value' => json_encode($evidenceValue, JSON_THROW_ON_ERROR), 'confidence' => 1, 'producer_name' => 'geometry-confirmation',
                'producer_version' => 'model:v1', 'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now(),
            ]);
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
            $invalidation = $this->invalidator->invalidate($command->sessionId, $command->expectedInputVersion, $command->expectedStateVersion + 1);
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
                'invalidation_summary' => $invalidation,
                'regeneration' => ['status' => 'pending'],
            ], $intentId];
        });
        $this->outbox->deliver($intentId);

        return $result;
    }

    private function reserveEvidenceId(): int
    {
        $connection = $this->database->connection();
        if ($connection->getDriverName() === 'pgsql') {
            $row = $connection->selectOne("SELECT nextval(pg_get_serial_sequence('estimate_generation_evidence', 'id')) AS id");

            return (int) $row->id;
        }

        return (int) $connection->table('estimate_generation_evidence')->lockForUpdate()->max('id') + 1;
    }
}
