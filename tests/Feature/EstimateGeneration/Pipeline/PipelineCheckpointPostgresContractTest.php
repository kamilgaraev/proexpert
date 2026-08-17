<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\EstimateGeneration\EstimateGenerationPostgresTestCase;

#[Group('postgres-contract')]
final class PipelineCheckpointPostgresContractTest extends EstimateGenerationPostgresTestCase
{
    public function test_completed_checkpoint_is_immutable_and_aggregate_budget_is_enforced(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'input_payload' => [],
            'state_version' => 0,
        ]);
        $scope = [
            'organization_id' => (int) $organization->id,
            'project_id' => (int) $project->id,
            'session_id' => (int) $session->id,
        ];
        $attempt = '018f4a20-3f4c-7a11-8a22-'.bin2hex(random_bytes(6));
        $first = $this->completed($scope, $attempt, 'understand_documents', 5_000_000, 'a');
        $id = (int) DB::table('estimate_generation_pipeline_checkpoints')->insertGetId($first);

        $this->assertRejected(function () use ($id): void {
            DB::table('estimate_generation_pipeline_checkpoints')->where('id', $id)->update(['artifact_bytes' => 1]);
        }, 'checkpoint_is_immutable');
        $this->assertRejected(function () use ($id): void {
            DB::table('estimate_generation_pipeline_checkpoints')->where('id', $id)->delete();
        }, 'checkpoint_delete_forbidden');
        $this->assertRejected(function () use ($scope, $attempt): void {
            DB::table('estimate_generation_pipeline_checkpoints')->insert(
                $this->completed($scope, $attempt, 'understand_object', 5_000_000, 'b'),
            );
        }, 'pipeline_artifact_budget_exceeded');

        foreach ([
            'metrics' => json_encode(['changed' => true], JSON_THROW_ON_ERROR),
            'warnings' => json_encode(['changed'], JSON_THROW_ON_ERROR),
            'attempt_count' => 2,
            'input_version' => 'sha256:'.hash('sha256', 'changed-input'),
            'dependency_versions' => json_encode(['changed' => 'sha256:'.hash('sha256', 'dependency')], JSON_THROW_ON_ERROR),
            'output_version' => 'sha256:'.hash('sha256', 'changed-output'),
            'output_payload' => json_encode(['changed' => true], JSON_THROW_ON_ERROR),
            'started_at' => now()->subMinute(),
            'completed_at' => now()->addMinute(),
        ] as $column => $value) {
            $this->assertRejected(function () use ($id, $column, $value): void {
                DB::table('estimate_generation_pipeline_checkpoints')->where('id', $id)->update([$column => $value]);
            }, 'checkpoint_is_immutable');
        }

        self::assertSame(1, DB::table('estimate_generation_pipeline_checkpoints')->where('id', $id)->update([
            'status' => 'invalidated',
            'invalidated_at' => now(),
            'invalidation_reason' => 'dependency_changed',
            'updated_at' => now(),
        ]));
        $this->assertRejected(function () use ($id): void {
            DB::table('estimate_generation_pipeline_checkpoints')->where('id', $id)->update(['metrics' => '{"changed":true}']);
        }, 'checkpoint_is_immutable');
    }

    /** @param array{organization_id:int,project_id:int,session_id:int} $scope */
    private function completed(array $scope, string $attempt, string $stage, int $bytes, string $salt): array
    {
        $version = 'sha256:'.hash('sha256', $salt);
        $now = now();

        return [
            ...$scope, 'generation_attempt_id' => $attempt, 'base_input_version' => $version,
            'stage' => $stage, 'input_version' => $version, 'dependency_versions' => '{}',
            'output_version' => $version, 'output_payload' => json_encode(['stage' => $stage], JSON_THROW_ON_ERROR),
            'artifact_bytes' => $bytes, 'status' => 'completed', 'metrics' => '{}', 'warnings' => '[]',
            'attempt_count' => 1, 'started_at' => $now, 'completed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function assertRejected(callable $mutation, string $message): void
    {
        DB::statement('SAVEPOINT pipeline_contract');
        try {
            $mutation();
            self::fail('PostgreSQL accepted an invalid pipeline mutation.');
        } catch (QueryException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT pipeline_contract');
            DB::statement('RELEASE SAVEPOINT pipeline_contract');
        }
    }
}
