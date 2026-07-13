<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\EstimateGenerationTrainingDatasetService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\TrainingDatasetReviewStateMachine;
use App\Filament\Support\FilamentPermission;
use App\Models\SystemAdmin;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class AdminTrainingDatasetActionService
{
    private const ACTIONS = ['process', 'submit_review', 'approve_review', 'reject_review'];

    public function __construct(private EstimateGenerationTrainingDatasetService $datasets) {}

    /** @return array{successful: bool, status: string, review_status: string, version: int, idempotent_replay: bool} */
    public function handle(AdminTrainingDatasetActionCommand $command): array
    {
        $actor = $this->assertAllowed($command);
        $this->assertCommand($command);

        return DB::transaction(function () use ($actor, $command): array {
            $operation = $this->claim($command);
            if ((string) $operation->status === 'completed') {
                return $this->replay($operation->result);
            }

            $dataset = EstimateGenerationTrainingDataset::query()
                ->whereKey($command->datasetId)
                ->where('organization_id', $command->organizationId)
                ->lockForUpdate()
                ->first();
            if (! $dataset instanceof EstimateGenerationTrainingDataset) {
                throw new DomainException('training_dataset_not_found');
            }
            if ((int) ($dataset->control_version ?? 0) !== $command->expectedVersion) {
                throw new DomainException('training_dataset_version_conflict');
            }

            $reviewStatus = (string) ($dataset->trusted_review_status ?? EstimateGenerationTrainingDataset::TRUSTED_REVIEW_DRAFT);
            $persisted = false;
            if ($command->action === 'process') {
                $this->datasets->queueProcessing($dataset);
            } elseif ($command->action === 'submit_review') {
                $reviewStatus = TrainingDatasetReviewStateMachine::submit($reviewStatus, $command->actorId, null);
                $dataset->forceFill([
                    'trusted_review_status' => $reviewStatus,
                    'trusted_review_submitted_by' => $command->actorId,
                    'trusted_review_submitted_at' => now(),
                ]);
            } elseif ($command->action === 'approve_review') {
                $reviewStatus = TrainingDatasetReviewStateMachine::approve(
                    $reviewStatus,
                    (int) $dataset->trusted_review_submitted_by,
                    $command->actorId,
                );
                $dataset->forceFill([
                    'trusted_review_status' => $reviewStatus,
                    'trusted_reviewed_by' => $command->actorId,
                    'trusted_reviewed_at' => now(),
                    'control_version' => $command->expectedVersion + 1,
                ]);
                $dataset->save();
                $persisted = true;
                if ($dataset->status === EstimateGenerationTrainingDataset::STATUS_REVIEW_REQUIRED) {
                    $dataset = $this->datasets->approve($dataset, $actor);
                }
            } else {
                $reviewStatus = TrainingDatasetReviewStateMachine::reject(
                    $reviewStatus,
                    (int) $dataset->trusted_review_submitted_by,
                    $command->actorId,
                );
                $dataset->forceFill([
                    'trusted_review_status' => $reviewStatus,
                    'trusted_reviewed_by' => $command->actorId,
                    'trusted_reviewed_at' => now(),
                    'status' => EstimateGenerationTrainingDataset::STATUS_REJECTED,
                ]);
            }

            if (! $persisted) {
                $dataset->control_version = $command->expectedVersion + 1;
                $dataset->save();
            }
            $result = [
                'successful' => true,
                'status' => (string) $dataset->status,
                'review_status' => $reviewStatus,
                'version' => $command->expectedVersion + 1,
            ];
            $this->recordAudit($command, $result);
            DB::table('estimate_generation_admin_action_operations')->where('id', $operation->id)->update([
                'status' => 'completed',
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'completed_at' => now(),
            ]);

            return [...$result, 'idempotent_replay' => false];
        });
    }

    private function assertAllowed(AdminTrainingDatasetActionCommand $command): SystemAdmin
    {
        $actor = SystemAdmin::query()->find($command->actorId);
        $allowed = $actor instanceof SystemAdmin
            && $actor->hasSystemPermission(FilamentPermission::ESTIMATE_GENERATION_DATASETS);
        if ($command->action === 'process') {
            $allowed = $allowed && $actor?->hasSystemPermission(FilamentPermission::ESTIMATE_GENERATION_OPERATE);
        }
        if (! $allowed || ! $actor instanceof SystemAdmin) {
            throw new DomainException('training_dataset_action_forbidden');
        }

        return $actor;
    }

    private function assertCommand(AdminTrainingDatasetActionCommand $command): void
    {
        if ($command->actorId <= 0 || $command->datasetId <= 0 || $command->organizationId <= 0
            || $command->expectedVersion < 0 || ! in_array($command->action, self::ACTIONS, true)
            || preg_match('/^[A-Za-z0-9._:-]{16,80}$/', $command->idempotencyKey) !== 1) {
            throw new DomainException('training_dataset_action_invalid');
        }
    }

    private function claim(AdminTrainingDatasetActionCommand $command): object
    {
        DB::table('estimate_generation_admin_action_operations')->insertOrIgnore([
            'organization_id' => $command->organizationId,
            'operation' => 'dataset_'.$command->action,
            'subject_id' => $command->datasetId,
            'idempotency_key' => $command->idempotencyKey,
            'command_fingerprint' => $command->fingerprint(),
            'status' => 'pending',
            'result' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'completed_at' => null,
        ]);
        $operation = DB::table('estimate_generation_admin_action_operations')
            ->where('organization_id', $command->organizationId)
            ->where('operation', 'dataset_'.$command->action)
            ->where('idempotency_key', $command->idempotencyKey)
            ->lockForUpdate()
            ->first();
        if (! is_object($operation) || ! hash_equals((string) $operation->command_fingerprint, $command->fingerprint())) {
            throw new DomainException('training_dataset_action_idempotency_conflict');
        }

        return $operation;
    }

    /** @param array<string, mixed> $result */
    private function recordAudit(AdminTrainingDatasetActionCommand $command, array $result): void
    {
        DB::table('estimate_generation_admin_action_audits')->insert([
            'organization_id' => $command->organizationId,
            'actor_system_admin_id' => $command->actorId,
            'operation' => 'dataset_'.$command->action,
            'subject_id' => $command->datasetId,
            'command_fingerprint' => $command->fingerprint(),
            'result' => json_encode($result, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /** @return array{successful: bool, status: string, review_status: string, version: int, idempotent_replay: bool} */
    private function replay(mixed $value): array
    {
        $result = is_string($value) ? json_decode($value, true, 8, JSON_THROW_ON_ERROR) : $value;
        if (! is_array($result) || array_keys($result) !== ['successful', 'status', 'review_status', 'version']
            || $result['successful'] !== true || ! is_string($result['status'])
            || ! is_string($result['review_status']) || ! is_int($result['version'])) {
            throw new DomainException('training_dataset_action_replay_invalid');
        }

        return [...$result, 'idempotent_replay' => true];
    }
}
