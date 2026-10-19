<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\ReadEstimatorQaExpenseDiagnostics;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationExpenseDiagnosticsController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EstimatorQaExpenseDiagnosticsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentAiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorQaSupplierExpenseGuard;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimatorQaExpenseDiagnosticsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_scoped_diagnostics_preserve_unknown_expenses_and_reveal_only_safe_receipt_fields_without_writes(): void
    {
        [$actor, $project, $session] = $this->fixture();
        $unknown = $this->attempt($session);
        $measured = $this->attempt($session);
        foreach ([$unknown, $measured, $measured] as $physicalId) {
            $fingerprint = hash('sha256', (string) Str::uuid());
            DB::table('estimate_generation_ai_role_runs')->insert(['organization_id' => $session->organization_id,
                'project_id' => $project->id, 'session_id' => $session->id, 'subject_type' => 'session', 'subject_id' => (string) $session->id,
                'subject_version' => 'fixture', 'role' => 'estimate_audit', 'status' => 'completed', 'model' => 'openai/gpt-6-luna',
                'prompt_contract_version' => 'fixture-v1', 'input_fingerprint' => $fingerprint, 'identity_fingerprint' => $fingerprint,
                'physical_attempt_id' => $physicalId, 'result_payload' => '{}', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $price = AiPriceSnapshot::fromArray(['input_per_million' => '13.5', 'cached_input_per_million' => '13.5',
            'output_per_million' => '67.5', 'currency' => 'RUB', 'source' => 'fixture', 'version' => 'fixture-v1', 'effective_at' => '2026-10-01T00:00:00+00:00']);
        DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $unknown)->update([
            'state' => 'response_received', 'wire_started_at' => now(), 'response_received_at' => now(),
            'response_payload' => json_encode(['provider_response' => ['model' => 'openai/gpt-6-luna', 'usage_available' => true,
                'input_tokens' => 123, 'output_tokens' => 45, 'reasoning_tokens' => 'DO_NOT_DISCLOSE'], 'parsed_response' => ['secret' => 'DO_NOT_DISCLOSE'],
                'wire_response' => ['content' => 'DO_NOT_DISCLOSE', 'headers' => ['Authorization' => 'DO_NOT_DISCLOSE']]], JSON_THROW_ON_ERROR),
            'price_snapshot' => json_encode($price->toArray(), JSON_THROW_ON_ERROR)]);
        app(EloquentAiUsageStore::class)->record(new AiUsageData(
            context: new AiOperationContext((string) Str::uuid(), $measured, (int) $session->organization_id, (int) $project->id, (int) $session->id, 'understand_documents', 'vision', 1),
            provider: 'timeweb', requestedModel: 'openai/gpt-6-luna', reportedModel: 'openai/gpt-6-luna', status: 'succeeded',
            durationMs: 10, usageStatus: 'measured', inputTokens: 100, outputTokens: 10, priceSnapshot: $price));
        $anotherSession = EstimateGenerationSession::query()->create(['organization_id' => $session->organization_id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);
        $sameOrganizationDecoy = $this->attempt($anotherSession);
        [, , $foreignSession] = $this->fixture();
        $foreignDecoy = $this->attempt($foreignSession);
        $before = $this->history();
        $sent = Http::recorded()->count();

        $result = app(ReadEstimatorQaExpenseDiagnostics::class)->handle($actor, (int) $project->id, (int) $session->id);

        self::assertSame(2, (int) $result['summary']['attempt_count']);
        self::assertSame(1, (int) $result['summary']['unknown_count']);
        self::assertSame(1, (int) $result['summary']['measured_count']);
        self::assertSame('92025', $result['summary']['scoped_supplier_exposure_micro_rub']);
        self::assertCount(2, $result['attempts']);
        $rows = array_column($result['attempts'], null, 'attempt_id');
        self::assertSame('conservative_maximum', $rows[$unknown]['cost_basis']);
        self::assertSame('90000', $rows[$unknown]['supplier_exposure_micro_rub']);
        self::assertTrue($rows[$unknown]['retained_usage_available']);
        self::assertSame('123', $rows[$unknown]['retained_tokens']['input_tokens']);
        self::assertNull($rows[$unknown]['retained_tokens']['reasoning_tokens']);
        self::assertTrue($rows[$unknown]['has_provider_response']);
        self::assertTrue($rows[$unknown]['has_wire_response']);
        self::assertTrue($rows[$unknown]['has_parsed_response']);
        self::assertSame(1, (int) $rows[$unknown]['role_run']['role_run_count']);
        self::assertSame('estimate_audit', $rows[$unknown]['role_run']['role']);
        self::assertSame(2, (int) $rows[$measured]['role_run']['role_run_count']);
        self::assertNull($rows[$measured]['role_run']['role']);
        self::assertSame('measured', $rows[$measured]['cost_basis']);
        self::assertSame('2025', $rows[$measured]['supplier_exposure_micro_rub']);
        self::assertArrayNotHasKey($sameOrganizationDecoy, $rows);
        self::assertArrayNotHasKey($foreignDecoy, $rows);
        self::assertStringNotContainsString('DO_NOT_DISCLOSE', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame($before, $this->history());
        self::assertSame($sent, Http::recorded()->count());

        $unknownRows = app(ReadEstimatorQaExpenseDiagnostics::class)->handle($actor, (int) $project->id, (int) $session->id, 1, 1, true);
        self::assertSame(1, $unknownRows['pagination']['total']);
        self::assertSame($unknown, $unknownRows['attempts'][0]['attempt_id']);
        self::assertFalse($unknownRows['pagination']['has_more']);
    }

    public function test_http_contract_and_pagination_validation_do_not_start_provider_requests(): void
    {
        [$actor, $project, $session] = $this->fixture();
        $this->attempt($session);
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session);
        Route::get('/_contract/projects/{project}/sessions/{session}/qa-expense', function (EstimatorQaExpenseDiagnosticsRequest $request, Project $project, EstimateGenerationSession $session) use ($actor) {
            $request->setUserResolver(static fn () => $actor);

            return (new EstimateGenerationExpenseDiagnosticsController)->show($request, $project, $session, app(ReadEstimatorQaExpenseDiagnostics::class));
        })->middleware(SubstituteBindings::class);
        $url = '/_contract/projects/'.$project->id.'/sessions/'.$session->id.'/qa-expense';
        $sent = Http::recorded()->count();

        $this->getJson($url.'?per_page=1')->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.accounting', 'diagnostic_only_no_budget_changes')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($url.'?per_page=101')->assertUnprocessable();
        self::assertSame($sent, Http::recorded()->count());
    }

    public function test_service_rejects_a_foreign_session_before_reading_its_expenses(): void
    {
        [$actor] = $this->fixture();
        [, $foreignProject, $foreignSession] = $this->fixture();
        $this->expectException(ModelNotFoundException::class);
        app(ReadEstimatorQaExpenseDiagnostics::class)->handle($actor, (int) $foreignProject->id, (int) $foreignSession->id);
    }

    public function test_service_requires_the_view_permission_outside_http(): void
    {
        [$actor, $project, $session] = $this->fixture();
        $authorization = \Mockery::mock(EstimateGenerationActionAuthorization::class);
        $authorization->shouldReceive('authorize')->once()->withArgs(static fn ($user, $scoped, $permission): bool => $user->id === $actor->id && $scoped->id === $session->id && $permission === 'estimate_generation.view')
            ->andThrow(new AuthorizationException);
        $this->app->instance(EstimateGenerationActionAuthorization::class, $authorization);
        $this->expectException(AuthorizationException::class);
        app(ReadEstimatorQaExpenseDiagnostics::class)->handle($actor, (int) $project->id, (int) $session->id);
    }

    private function fixture(): array
    {
        Http::fake();
        config(['ai-assistant-credits.enforce' => false, 'estimate-generation.credits.enabled' => true,
            'estimate-generation.live_qa.enabled' => true]);
        if (! Str::isUuid(config('estimate-generation.live_qa.run_id'))) {
            config(['estimate-generation.live_qa.run_id' => (string) Str::uuid()]);
        }
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:diagnostics:'.$organization->id);
        app(EstimatorCreditOperations::class)->quote($actor, (int) $project->id, (int) $session->id, 0, (string) Str::uuid(), 'generation');

        return [$actor, $project, $session];
    }

    private function attempt(EstimateGenerationSession $session): string
    {
        $id = (string) Str::uuid();
        DB::table('estimate_generation_vision_physical_attempts')->insert(['attempt_id' => $id,
            'request_fingerprint' => hash('sha256', $id), 'logical_request_fingerprint' => hash('sha256', $id),
            'organization_id' => $session->organization_id, 'project_id' => $session->project_id, 'session_id' => $session->id,
            'state' => 'pre_wire', 'owner_token' => $id, 'lease_expires_at' => now()->addMinutes(5),
            'usage_recorded' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => app(EstimatorQaSupplierExpenseGuard::class)->admit($id, new AiCost('0.09000000', 'RUB', 'available')));

        return $id;
    }

    private function history(): string
    {
        $rows = [];
        foreach (['estimate_generation_qa_expense_runs' => 'run_id', 'estimate_generation_qa_expense_attempts' => 'attempt_id',
            'estimate_generation_vision_physical_attempts' => 'attempt_id', 'estimate_generation_ai_usage' => 'attempt_id',
            'estimate_generation_ai_role_runs' => 'id', 'estimate_generation_credit_attempts' => 'attempt_id',
            'estimate_generation_credit_operations' => 'operation_id', 'ai_credit_ledger_entries' => 'id'] as $table => $key) {
            $rows[$table] = DB::table($table)->orderBy($key)->get()->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
