<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
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
        private GeometryConfirmationFaultInjector $faultInjector,
        private AssemblePersistedVectorGeometry $sourceAssembler,
        private SessionStateStore $stateStore,
    ) {}

    /** @return array<string, mixed> */
    public function handle(GeometryConfirmationCommand $command): array
    {
        [$result, $intentId] = $this->database->transaction(function () use ($command): array {
            $session = EstimateGenerationSession::query()->whereKey($command->sessionId)->lockForUpdate()->first();
            if ($session === null || (int) $session->organization_id !== $command->organizationId || (int) $session->project_id !== $command->projectId) {
                throw new NotFoundHttpException;
            }
            if (! in_array($session->status, [
                EstimateGenerationStatus::ReadyToGenerate,
                EstimateGenerationStatus::Generating,
                EstimateGenerationStatus::EstimateReviewRequired,
                EstimateGenerationStatus::ReadyToApply,
            ], true) || $session->applied_estimate_id !== null) {
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
            $this->faultInjector->afterLocksAcquired();
            $provisional = $command->sourceConfirmation === null
                ? $this->mutator->mutate($head->model, $command)
                : $this->sourceAssembler->handle($command);
            if ($provisional->contentVersion() === $head->content_version) {
                throw new InvalidArgumentException('Geometry confirmation does not change the model.');
            }
            $evidenceId = $this->reserveEvidenceId();
            $normalized = $command->sourceConfirmation === null
                ? $this->mutator->mutate($head->model, $command, $evidenceId)
                : $this->sourceAssembler->handle($command, $evidenceId);
            $newInputVersion = 'sha256:'.hash('sha256', $command->expectedInputVersion.'|'.$normalized->contentVersion().'|'.($command->expectedStateVersion + 1));
            $sourceEvidenceIds = array_values(array_map('intval', $head->model['evidence_ids'] ?? []));
            $evidenceValue = [
                'source_class' => 'user_geometry_confirmation', 'actor_id' => $command->actorId,
                'reviewer_ref' => 'user:'.$command->actorId, 'confirmed_at' => now()->toIso8601String(),
                'operations' => $command->operations, 'scale' => $command->scale,
                'source_confirmation' => $command->sourceConfirmation, 'source_evidence_ids' => $sourceEvidenceIds,
                'previous_state_version' => $command->expectedStateVersion, 'new_state_version' => $command->expectedStateVersion + 1,
                'previous_model_version' => $command->expectedModelVersion, 'new_model_version' => $normalized->contentVersion(),
                'previous_input_version' => $command->expectedInputVersion, 'new_input_version' => $newInputVersion,
            ];
            $fingerprint = hash('sha256', json_encode($evidenceValue, JSON_THROW_ON_ERROR));
            DB::table('estimate_generation_evidence')->insert([
                'id' => $evidenceId,
                'organization_id' => $command->organizationId, 'project_id' => $command->projectId, 'session_id' => $command->sessionId,
                'type' => 'source_fact', 'source_type' => 'user_input', 'source_ref' => 'input:'.$command->actorId,
                'source_version' => $newInputVersion, 'locator' => json_encode(['source_key' => 'source:'.substr($fingerprint, 0, 64)], JSON_THROW_ON_ERROR),
                'value' => json_encode(['fact_key' => 'element_type_code', 'fact_value' => 'element_type:room'], JSON_THROW_ON_ERROR),
                'confidence' => 1, 'producer_name' => 'user_input_normalizer',
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
            if ($command->sourceConfirmation !== null) {
                DB::table('estimate_generation_geometry_confirmations')->insert([
                    'organization_id' => $command->organizationId, 'project_id' => $command->projectId,
                    'session_id' => $command->sessionId, 'evidence_id' => $evidenceId,
                    'previous_building_model_id' => $head->getKey(), 'confirmed_building_model_id' => $new->getKey(),
                    'actor_id' => $command->actorId,
                    'previous_input_version' => $head->input_version, 'previous_content_version' => $head->content_version,
                    'confirmed_input_version' => $new->input_version, 'confirmed_content_version' => $new->content_version,
                    'source_class' => 'user_geometry_confirmation', 'reviewer_ref' => 'user:'.$command->actorId,
                    'confirmed_at' => now(),
                    'semantic_payload' => json_encode($command->sourceConfirmation, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('estimate_generation_building_model_evidence')->insert(array_map(fn (int $id): array => [
                'building_model_id' => $new->getKey(), 'evidence_id' => $id, 'organization_id' => $command->organizationId,
                'project_id' => $command->projectId, 'session_id' => $command->sessionId, 'created_at' => now(),
            ], $normalized->evidenceIds));
            $invalidation = $this->invalidator->invalidate($command->sessionId, $command->expectedInputVersion, $command->expectedStateVersion + 1);
            $this->faultInjector->afterInvalidation();
            $attemptId = (string) Str::uuid();
            $session = $this->stateStore->compareAndSet(
                $session,
                $command->expectedStateVersion,
                EstimateGenerationStatus::Generating,
                [
                    'processing_stage' => 'generating',
                    'processing_progress' => 40,
                    'last_error' => null,
                    'failure_code' => null,
                    'input_payload' => [
                        ...($session->input_payload ?? []),
                        'generation_attempt_id' => $attemptId,
                        'generation_requested' => false,
                    ],
                ],
            );
            $intentId = $this->outbox->append(new GeometryRegenerationIntent(
                $command->organizationId, $command->projectId, $command->sessionId, (int) $session->state_version,
                $command->expectedInputVersion, $newInputVersion, $normalized->contentVersion(), $attemptId,
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
        throw new \RuntimeException('estimate_generation.geometry_evidence_sequence_unsupported');
    }
}
