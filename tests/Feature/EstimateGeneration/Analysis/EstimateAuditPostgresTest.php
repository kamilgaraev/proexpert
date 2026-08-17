<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\TimewebEstimateAuditModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\EloquentAiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class EstimateAuditPostgresTest extends TestCase
{
    private int $organizationId;

    private int $projectId;

    private int $sessionId;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function three_cycle_audit_persists_once_and_complete_replay_does_not_call_model_again(): void
    {
        self::assertInstanceOf(TimewebEstimateAuditModel::class, $this->app->make(EstimateAuditModel::class));
        self::assertInstanceOf(ApplyComposerCorrectionCycle::class, $this->app->make(ApplyComposerCorrectionCycle::class));
        [$connection, $schema] = $this->fixture();
        try {
            $model = new PostgresRecordedEstimateAuditModel;
            $repository = new EloquentAiRoleRunRepository($connection, 180);
            $cycles = new ApplyComposerCorrectionCycle(
                new RunEstimateAudit($repository, $model, 'openai/gpt-5-mini'),
                $this->corrector(),
            );
            $input = $this->input();

            $first = $cycles->apply($input);
            $replay = (new ApplyComposerCorrectionCycle(new RunEstimateAudit(
                new EloquentAiRoleRunRepository($connection, 180),
                $model,
                'openai/gpt-5-mini',
            ), $this->corrector()))->apply($input);

            self::assertSame($first, $replay);
            self::assertSame(3, $model->calls);
            self::assertSame('accepted', $first['audit']['status']);
            self::assertSame(2, $first['audit']['correction_cycles']);
            self::assertCount(1, $first['draft']['local_estimates'][0]['sections'][0]['work_items']);
            $runs = $connection->table('estimate_generation_ai_role_runs')
                ->where('role', 'estimate_auditor')
                ->where('session_id', $this->sessionId)
                ->orderBy('subject_id')
                ->get();
            self::assertCount(3, $runs);
            self::assertSame([
                $this->sessionId.':0',
                $this->sessionId.':1',
                $this->sessionId.':2',
            ], $runs->pluck('subject_id')->all());
            self::assertSame(['completed'], $runs->pluck('status')->unique()->values()->all());
            self::assertSame(3, $runs->pluck('input_fingerprint')->unique()->count());
        } finally {
            $this->cleanup($connection, $schema);
        }
    }

    #[Test]
    public function forward_usage_constraint_accepts_all_new_roles_and_rejects_invalid_stage_pair(): void
    {
        $connection = $this->app->make('db')->connection();
        self::assertInstanceOf(PostgresConnection::class, $connection);
        self::assertTrue(
            $connection->getDatabaseName() === 'most_backend_testing'
                || ($connection->getDatabaseName() === 'most_ai_estimator_contract'
                    && getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') === '1'),
        );
        $constraints = $connection->table('pg_constraint')
            ->whereIn('conname', ['eg_usage_stage_ck', 'eg_usage_operation_ck', 'eg_usage_stage_operation_ck'])
            ->selectRaw('conname, pg_get_constraintdef(oid) AS definition, convalidated')
            ->get()
            ->keyBy('conname');
        self::assertCount(3, $constraints);
        foreach ($constraints as $constraint) {
            self::assertTrue((bool) $constraint->convalidated);
        }
        $operations = (string) $constraints['eg_usage_operation_ck']->definition;
        $pairs = (string) $constraints['eg_usage_stage_operation_ck']->definition;
        foreach (['project_synthesis', 'estimate_composition', 'estimate_audit', 'estimate_composer_correction'] as $operation) {
            self::assertStringContainsString($operation, $operations);
            self::assertStringContainsString($operation, $pairs);
        }
        self::assertStringNotContainsString("plan_work_items'::text) AND ((operation)::text = 'estimate_audit", $pairs);
    }

    #[Test]
    public function concurrent_claim_is_busy_until_the_owner_completes_then_replays_exact_result(): void
    {
        [$connection, $schema] = $this->fixture();
        try {
            $input = $this->input();
            $runInput = new AiRoleRunInput(
                $this->organizationId,
                $this->projectId,
                $this->sessionId,
                null,
                null,
                'estimate_audit_cycle',
                $this->sessionId.':0',
                'audit-cycle:0:'.$input->snapshotToken,
                AiAnalysisRole::EstimateAuditor,
                'openai/gpt-5-mini',
                RunEstimateAudit::PROMPT_CONTRACT,
                $input->fingerprint(),
            );
            $first = new EloquentAiRoleRunRepository($connection, 180);
            $second = new EloquentAiRoleRunRepository($connection, 180);
            $ownerOne = '11111111-1111-4111-8111-111111111111';
            $ownerTwo = '22222222-2222-4222-8222-222222222222';

            $owned = $first->claim($runInput, $ownerOne);
            $busy = $second->claim($runInput, $ownerTwo);
            self::assertSame('owned', $owned->disposition);
            self::assertSame('busy', $busy->disposition);
            $first->startPhysicalAttempt($owned->runId, $ownerOne, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa0');
            $first->complete($owned->runId, $ownerOne, new AiRoleRunResult(
                ['accepted' => true, 'findings' => []],
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa0',
            ));

            $replay = $second->claim($runInput, $ownerTwo);
            self::assertSame('replay', $replay->disposition);
            self::assertSame(['accepted' => true, 'findings' => []], $replay->result?->payload);
            self::assertSame(1, $connection->table('estimate_generation_ai_role_runs')->count());
        } finally {
            $this->cleanup($connection, $schema);
        }
    }

    /** @return array{PostgresConnection,string} */
    private function fixture(): array
    {
        $connection = $this->app->make('db')->connection();
        self::assertInstanceOf(PostgresConnection::class, $connection);
        self::assertSame('pgsql', $connection->getDriverName());
        self::assertTrue(
            $connection->getDatabaseName() === 'most_backend_testing'
                || ($connection->getDatabaseName() === 'most_ai_estimator_contract'
                    && getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') === '1'),
        );
        $connection->statement("SET statement_timeout TO '5000ms'");
        $connection->statement("SET lock_timeout TO '5000ms'");
        $schema = 'public';
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
        $this->organizationId = (int) $organization->id;
        $this->projectId = (int) $project->id;
        $this->sessionId = (int) $session->id;

        return [$connection, $schema];
    }

    private function cleanup(PostgresConnection $connection, string $schema): void
    {
        if ($schema !== 'public') {
            return;
        }
        foreach ([
            'estimate_generation_ai_role_runs',
            'estimate_generation_vision_physical_attempts',
            'estimate_generation_sessions',
            'projects',
            'users',
            'organizations',
        ] as $table) {
            $connection->table($table)->delete();
        }
    }

    private function corrector(): \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection
    {
        return new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection(
            new \Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository,
            new class implements \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionModel
            {
                public function correct(
                    \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionInput $input,
                    callable $onPhysicalAttemptReserved,
                ): array {
                    $onPhysicalAttemptReserved('cccccccc-cccc-4ccc-8ccc-cccccccccccc');

                    return ['corrections' => []];
                }
            },
            'openai/gpt-5-mini',
        );
    }

    private function input(): EstimateAuditInput
    {
        $items = array_map(static fn (string $key): array => [
            'key' => $key,
            'name' => 'Устройство фундамента',
            'unit' => 'м3',
            'quantity' => '10.2500',
            'normative_match' => ['norm_id' => 101, 'code' => 'ГЭСН-01'],
            'price_snapshot' => ['final_amount' => '1234.56'],
            'source_refs' => [['fact_id' => 'fact:foundation']],
        ], ['work:a', 'work:b', 'work:c']);

        return new EstimateAuditInput(
            $this->organizationId,
            $this->projectId,
            $this->sessionId,
            str_repeat('a', 64),
            0,
            [['id' => 'fact:foundation', 'status' => 'confirmed']],
            [['id' => 'quantity:foundation', 'value' => '10.2500', 'unit' => 'м3']],
            ['local_estimates' => [[
                'key' => 'estimate:house',
                'sections' => [['key' => 'section:works', 'work_items' => $items]],
            ]]],
            [['fact_id' => 'fact:foundation', 'locator' => ['document_id' => 7, 'page' => 2]]],
            RunEstimateAudit::PROMPT_CONTRACT,
        );
    }
}

final class PostgresRecordedEstimateAuditModel implements EstimateAuditModel
{
    public int $calls = 0;

    public function audit(EstimateAuditInput $input, callable $onAttemptStarted): array
    {
        $this->calls++;
        $onAttemptStarted('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa'.$input->cycle);
        if ($input->cycle === 2) {
            return ['accepted' => true, 'findings' => []];
        }
        $items = $input->draft['local_estimates'][0]['sections'][0]['work_items'];
        $retained = $items[0];
        $target = $items[1];

        return ['accepted' => false, 'findings' => [[
            'finding_id' => 'finding:duplicate:'.$input->cycle,
            'type' => 'duplicate',
            'severity' => 'material',
            'item_key' => $target['key'],
            'source_fact_ids' => ['fact:foundation'],
            'source_locator' => ['document_id' => 7, 'page' => 2],
            'reason' => 'Обнаружена точная повторная позиция в одном разделе.',
            'impact' => 'Повторная позиция завышает итоговую стоимость сметы.',
            'recommendation' => 'Удалить повтор и оставить одну подтверждённую позицию.',
            'correction' => [
                'operation' => 'remove_exact_duplicate',
                'target_item_key' => $target['key'],
                'retained_item_key' => $retained['key'],
                'expected_target_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($target),
                'expected_retained_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($retained),
            ],
        ]]];
    }
}
