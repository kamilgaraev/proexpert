<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TextAiWireAttempt;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimatorQaExpensePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_successful_supplier_costs_across_organizations_use_one_500_unit_ceiling(): void
    {
        $runId = $this->enableRun();
        [$session, $actor] = $this->fixture();
        $first = $this->send($session, $actor, '80');
        app(AiUsageStore::class)->record($this->usage($session, $first, 'succeeded', '50'));
        [$secondSession, $secondActor] = $this->fixture();
        $second = $this->send($secondSession, $secondActor, '39.95');
        self::assertSame('wire_started', DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $second)->value('state'));
        $run = DB::table('estimate_generation_qa_expense_runs')->where('run_id', $runId)->sole();
        self::assertSame(50000, (int) $run->maximum_units_minor);
        self::assertSame(90000000, (int) $run->maximum_cost_micro_rub);
        self::assertSame(2, DB::table('estimate_generation_qa_expense_attempts')->where('run_id', $runId)->count());
        $this->assertBlocked($secondSession, $secondActor, '0.000001');
        self::assertSame(2, DB::table('estimate_generation_qa_expense_attempts')->where('run_id', $runId)->count());
    }

    public function test_failed_cancelled_and_unavailable_supplier_costs_are_not_refunded_from_the_run(): void
    {
        foreach (['http_failed', 'cancelled', 'ambiguous'] as $outcome) {
            $runId = $this->enableRun();
            [$session, $actor] = $this->fixture();
            $first = $this->send($session, $actor, '80');
            app(AiUsageStore::class)->record($this->usage($session, $first, $outcome === 'cancelled' ? 'succeeded' : $outcome,
                $outcome === 'ambiguous' ? null : '80'));
            if ($outcome === 'cancelled') {
                $session->update(['status' => 'cancelled']);
            }
            [$nextSession, $nextActor] = $this->fixture();
            $this->assertBlocked($nextSession, $nextActor, '11');
            self::assertSame(1, DB::table('estimate_generation_qa_expense_attempts')->where('run_id', $runId)->count(), $outcome);
        }
    }

    public function test_missing_run_identity_refuses_the_legacy_wire_without_an_unknown_paid_attempt(): void
    {
        $this->enableRun();
        config(['estimate-generation.live_qa.run_id' => null]);
        [$session, $actor] = $this->fixture();
        try {
            $this->send($session, $actor, '1');
            self::fail('An unscoped live QA wire was admitted');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_live_qa_configuration_invalid', $exception->reason);
        }
        self::assertSame(0, DB::table('estimate_generation_vision_physical_attempts')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_measured_tokens_without_the_requested_model_keep_the_full_supplier_exposure(): void
    {
        foreach ([null, 'openai/gpt-6-sol'] as $reportedModel) {
            $runId = $this->enableRun();
            [$session, $actor] = $this->fixture();
            $attempt = $this->send($session, $actor, '80');
            $usage = $this->usage($session, $attempt, 'malformed_response', '1', $reportedModel);
            app(AiUsageStore::class)->record($usage);
            app(AiUsageStore::class)->record($usage);

            $row = DB::table('estimate_generation_ai_usage')->where('attempt_id', $attempt)->sole();
            self::assertSame('measured', $row->usage_status);
            self::assertSame(1000000, (int) $row->input_tokens);
            self::assertSame($reportedModel, $row->reported_model);
            self::assertSame('unavailable', $row->pricing_status);
            self::assertNull($row->cost_amount);
            self::assertNull($row->currency);
            [$nextSession, $nextActor] = $this->fixture();
            $this->assertBlocked($nextSession, $nextActor, '11');
            self::assertSame(1, DB::table('estimate_generation_qa_expense_attempts')->where('run_id', $runId)->count());
        }
    }

    public function test_run_tariff_and_closed_status_cannot_change_or_resume(): void
    {
        $runId = $this->enableRun();
        [$session, $actor] = $this->fixture();
        $this->send($session, $actor, '1');
        config(['ai-assistant-credits.rub_per_unit' => '0.19']);
        try {
            $this->send($session, $actor, '1');
            self::fail('A changed unit tariff was accepted');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_live_qa_configuration_changed', $exception->reason);
        }
        config(['ai-assistant-credits.rub_per_unit' => '0.18']);
        DB::table('estimate_generation_qa_expense_runs')->where('run_id', $runId)->update(['status' => 'completed']);
        try {
            $this->send($session, $actor, '1');
            self::fail('A closed run was resumed');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_live_qa_configuration_changed', $exception->reason);
        }
        self::assertSame(1, DB::table('estimate_generation_qa_expense_attempts')->where('run_id', $runId)->count());
    }

    public function test_concurrent_calls_in_different_organizations_cannot_each_spend_the_same_remaining_run_budget(): void
    {
        $runId = $this->enableRun();
        $ids = [];
        foreach ([0, 1] as $index) {
            [$session] = $this->fixture();
            $id = (string) Str::uuid();
            $ids[] = $id;
            DB::table('estimate_generation_vision_physical_attempts')->insert(['attempt_id' => $id, 'request_fingerprint' => hash('sha256', $id),
                'logical_request_fingerprint' => hash('sha256', $id), 'organization_id' => $session->organization_id,
                'project_id' => $session->project_id, 'session_id' => $session->id, 'state' => 'pre_wire',
                'owner_token' => $id, 'lease_expires_at' => now()->addMinutes(5), 'usage_recorded' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        $environment = array_replace(getenv(), array_filter($_ENV, static fn (mixed $value): bool => is_string($value)));
        $workerPath = dirname(__DIR__, 2).'/Support/EstimatorQaExpenseConcurrentWorker.php';
        $workers = [];
        try {
            foreach ($ids as $id) {
                $process = proc_open([PHP_BINARY, $workerPath, $runId, $id], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes, base_path(), $environment);
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as [$process, $pipes]) {
                $this->readWorkerToken($process, $pipes, 'READY');
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "GO\n");
                fclose($pipes[0]);
            }
            $outcomes = [];
            foreach ($workers as [$process, $pipes]) {
                $output = $this->readWorkerToken($process, $pipes, 'RESULT ');
                if (! str_contains($output, 'RESULT started')) {
                    self::assertStringContainsString('RESULT estimator_live_qa_expense_limit_reached', $output);
                }
                $outcomes[] = str_contains($output, 'RESULT started') ? 'started' : 'blocked';
            }
            sort($outcomes);
            self::assertSame(['blocked', 'started'], $outcomes);
            self::assertSame(1, DB::table('estimate_generation_qa_expense_attempts')->where('run_id', $runId)->count());
            self::assertSame(1, DB::table('estimate_generation_vision_physical_attempts')->whereIn('attempt_id', $ids)->where('state', 'wire_started')->count());
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }

    private function readWorkerToken($process, array $pipes, string $token): string
    {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $errors = '';
        $deadline = microtime(true) + 30;
        do {
            $chunk = fread($pipes[1], 8192);
            if ($chunk !== false) {
                $output .= $chunk;
            }
            if (str_contains($output, $token)) {
                return $output;
            }
            if (! proc_get_status($process)['running']) {
                $errors .= stream_get_contents($pipes[2]);
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::fail('Concurrent QA worker did not return '.$token.': '.$errors);
    }

    private function enableRun(): string
    {
        $id = (string) Str::uuid();
        config(['estimate-generation.live_qa.enabled' => true, 'estimate-generation.live_qa.run_id' => $id,
            'estimate-generation.credits.enabled' => false, 'ai-assistant-credits.rub_per_unit' => '0.18',
            'ai-assistant-credits.unit_minor' => 100, 'ai-assistant-credits.price_version' => 2]);

        return $id;
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization::class,
            new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorizer($permissions));
        $product = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $product->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $product);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);

        return [$session, $actor];
    }

    private function send(EstimateGenerationSession $session, User $actor, string $maximum): string
    {
        $id = (string) Str::uuid();
        app(SessionAiCostGuard::class)->authorize((int) $session->organization_id, (int) $session->project_id, (int) $session->id,
            new TextAiWireAttempt($id, hash('sha256', $id), new AiCost($maximum, 'RUB', 'available'), 60,
                stateVersion: 0, trustedActor: $actor, permission: 'estimate_generation.review'));

        return $id;
    }

    private function assertBlocked(EstimateGenerationSession $session, User $actor, string $maximum): void
    {
        try {
            $this->send($session, $actor, $maximum);
            self::fail('The overall live QA supplier ceiling was exceeded');
        } catch (SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_live_qa_expense_limit_reached', $exception->reason);
        }
    }

    private function usage(EstimateGenerationSession $session, string $id, string $status, ?string $cost, ?string $reportedModel = 'openai/gpt-6-luna'): AiUsageData
    {
        $price = AiPriceSnapshot::fromArray(['currency' => 'RUB', 'source' => 'fixture', 'version' => 'qa-fixture',
            'effective_at' => '2026-10-12T00:00:00+00:00', 'input_per_million' => $cost ?? '80',
            'cached_input_per_million' => $cost ?? '80', 'output_per_million' => '0']);

        return new AiUsageData(new AiOperationContext((string) Str::uuid(), $id, (int) $session->organization_id,
            (int) $session->project_id, (int) $session->id, 'plan_work_items', 'estimate_composition', 1),
            'timeweb', 'openai/gpt-6-luna', $status, 1, reportedModel: $reportedModel, usageStatus: $cost === null ? 'unavailable' : 'measured',
            inputTokens: $cost === null ? 0 : 1000000, httpCode: $status === 'http_failed' ? 500 : ($status === 'ambiguous' ? null : 200), priceSnapshot: $price);
    }
}
