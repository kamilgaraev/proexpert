<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Models\CompletedWork;
use App\Models\CompletedWorkHistoryTransformation;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Services\CompletedWork\CompletedWorkHistoryTransformationRules;
use App\Services\CompletedWork\CompletedWorkRevisionToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ConstructionAccountingHistoryCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([], 200)]);
    }

    public function test_api_hides_foreign_project_and_organization_facts(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignContext = AdminApiTestContext::create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);

        $local = $this->work($context->organization->id, $project->id, $context->user->id, 1);
        $foreign = $this->work($foreignContext->organization->id, $foreignProject->id, $foreignContext->user->id, 2);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works/reconciliation?batch_size=100");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('work_id')->all();
        self::assertContains($local->id, $ids);
        self::assertNotContains($foreign->id, $ids);
    }

    public function test_canonical_line_keeps_signed_history_visible_and_unchanged(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $work = $this->work($context->organization->id, $project->id, $context->user->id, 10);
        $this->signedAct($context, $project, $work, 10);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works/reconciliation");

        $response->assertOk();
        $record = collect($response->json('data'))->firstWhere('work_id', $work->id);
        self::assertSame(ContractPerformanceAct::STATUS_SIGNED, $record['signed_history'][0]['status']);
        self::assertTrue($record['protected_history']);
        self::assertSame('10.0000', (string) $work->fresh()->quantity);
    }

    public function test_ambiguous_signed_fact_stays_available_with_original_conflict(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $work = $this->work($context->organization->id, $project->id, $context->user->id, 100, [
            'completed_quantity' => 10,
        ]);
        $act = $this->signedAct($context, $project, $work, 8);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/reconciliation/transform")
            ->assertOk();

        $fresh = $work->fresh();
        self::assertSame('100.0000', (string) $fresh->quantity);
        self::assertSame('10.0000', (string) $fresh->completed_quantity);
        self::assertSame(0, CompletedWorkHistoryTransformation::query()->where('completed_work_id', $work->id)->count());
        self::assertNotNull($act->fresh());
        self::assertSame(ContractPerformanceAct::STATUS_SIGNED, $act->fresh()->status);
        self::assertSame($act->signed_at?->toDateTimeString(), $act->fresh()->signed_at?->toDateTimeString());

        $record = collect($this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works/reconciliation")
            ->json('data'))->firstWhere('work_id', $work->id);
        self::assertContains($work->id, collect($this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works/reconciliation")
            ->json('data'))->pluck('work_id')->all());
        self::assertSame(['quantity_conflict'], $record['issues']);
        self::assertTrue($record['protected_history']);
        self::assertSame('skip_manual', $record['auto_action']);
        self::assertNotEmpty($record['signed_history']);

        $show = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works/{$work->id}")
            ->assertOk();
        self::assertSame(100.0, (float) $show->json('data.quantity'));
        self::assertSame(10.0, (float) $show->json('data.completed_quantity'));
        self::assertTrue($show->json('data.quantity_conflict'));
    }

    public function test_isolated_transform_is_idempotent_and_skips_manual_categories(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $matching = $this->work($context->organization->id, $project->id, $context->user->id, 10);
        $missingCompleted = $this->work($context->organization->id, $project->id, $context->user->id, 7, [
            'completed_quantity' => null,
        ]);
        $conflict = $this->work($context->organization->id, $project->id, $context->user->id, 100, [
            'completed_quantity' => 10,
        ]);
        $invalid = $this->work($context->organization->id, $project->id, $context->user->id, -1, [
            'completed_quantity' => -1,
        ]);

        $first = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/reconciliation/transform");
        $first->assertOk();
        self::assertTrue($first->json('meta.stop_on_discrepancy'));

        self::assertSame('10.0000', (string) $matching->fresh()->quantity);
        self::assertSame('10.0000', (string) $matching->fresh()->completed_quantity);
        self::assertSame('7.0000', (string) $missingCompleted->fresh()->quantity);
        self::assertSame('7.0000', (string) $missingCompleted->fresh()->completed_quantity);
        self::assertSame('100.0000', (string) $conflict->fresh()->quantity);
        self::assertSame('10.0000', (string) $conflict->fresh()->completed_quantity);
        self::assertSame('-1.0000', (string) $invalid->fresh()->quantity);

        $this->assertDatabaseHas('completed_work_history_transformations', [
            'completed_work_id' => $matching->id,
            'rule' => CompletedWorkHistoryTransformationRules::RULE_MATCHING,
            'fields_mutated' => false,
        ]);
        $filled = CompletedWorkHistoryTransformation::query()->where('completed_work_id', $missingCompleted->id)->firstOrFail();
        self::assertSame(CompletedWorkHistoryTransformationRules::RULE_FILL_COMPLETED, $filled->rule);
        self::assertTrue($filled->fields_mutated);
        self::assertNull($filled->original_completed_quantity);
        self::assertSame('7.0000', (string) $filled->canonical_quantity);
        $this->assertDatabaseMissing('completed_work_history_transformations', [
            'completed_work_id' => $conflict->id,
        ]);

        $second = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/reconciliation/transform");
        $second->assertOk();
        self::assertSame(2, CompletedWorkHistoryTransformation::query()->whereIn('completed_work_id', [
            $matching->id,
            $missingCompleted->id,
            $conflict->id,
            $invalid->id,
        ])->count());
        self::assertSame('7.0000', (string) $missingCompleted->fresh()->completed_quantity);
        self::assertSame('100.0000', (string) $conflict->fresh()->quantity);
    }

    public function test_manual_decision_is_idempotent_and_does_not_rewrite_signed_originals(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $signed = $this->work($context->organization->id, $project->id, $context->user->id, 100, [
            'completed_quantity' => 10,
        ]);
        $this->signedAct($context, $project, $signed, 8);
        $open = $this->work($context->organization->id, $project->id, $context->user->id, 40, [
            'completed_quantity' => 12,
            'status' => CompletedWork::STATUS_PENDING,
        ]);

        $signedPayload = [
            'operation_key' => 'history-signed-1',
            'expected_version' => CompletedWorkRevisionToken::forWork($signed),
            'reason' => 'Подписанный акт: канон по выполненному объёму, оригинал не трогаем',
            'source' => 'completed_quantity',
        ];
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/reconciliation/decisions/{$signed->id}", $signedPayload)
            ->assertCreated()
            ->assertJsonPath('data.fields_mutated', false)
            ->assertJsonPath('data.canonical_quantity', '10.0000');
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/reconciliation/decisions/{$signed->id}", $signedPayload)
            ->assertCreated();
        self::assertSame(1, CompletedWorkHistoryTransformation::query()->where('completed_work_id', $signed->id)->count());
        self::assertSame('100.0000', (string) $signed->fresh()->quantity);
        self::assertSame('10.0000', (string) $signed->fresh()->completed_quantity);
        $signedRecord = collect($this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works/reconciliation")
            ->json('data'))->firstWhere('work_id', $signed->id);
        self::assertContains('quantity_conflict', $signedRecord['issues']);
        self::assertNotNull($signedRecord['transformation']);

        $openPayload = [
            'operation_key' => 'history-open-1',
            'expected_version' => CompletedWorkRevisionToken::forWork($open),
            'reason' => 'По журналу выбран выполненный объём 12',
            'source' => 'completed_quantity',
        ];
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/reconciliation/decisions/{$open->id}", $openPayload)
            ->assertCreated()
            ->assertJsonPath('data.fields_mutated', true);
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/reconciliation/decisions/{$open->id}", $openPayload)
            ->assertCreated();
        self::assertSame(1, CompletedWorkHistoryTransformation::query()->where('completed_work_id', $open->id)->count());
        self::assertSame('12.0000', (string) $open->fresh()->quantity);
        self::assertSame('12.0000', (string) $open->fresh()->completed_quantity);
        $this->assertDatabaseHas('completed_work_history_transformations', [
            'completed_work_id' => $open->id,
            'original_quantity' => '40.0000',
            'original_completed_quantity' => '12.0000',
        ]);
    }

    public function test_old_fields_remain_readable_and_ordinary_routes_keep_write_guards(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $legacy = $this->work($context->organization->id, $project->id, $context->user->id, 10, [
            'completed_quantity' => null,
            'status' => CompletedWork::STATUS_PENDING,
        ]);
        $confirmed = $this->work($context->organization->id, $project->id, $context->user->id, 10);
        $conflict = $this->work($context->organization->id, $project->id, $context->user->id, 100, [
            'completed_quantity' => 10,
            'status' => CompletedWork::STATUS_PENDING,
        ]);

        $legacyShow = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/works/{$legacy->id}")
            ->assertOk();
        self::assertSame(10.0, (float) $legacyShow->json('data.quantity'));
        self::assertSame(10.0, (float) $legacyShow->json('data.completed_quantity'));

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/reconciliation/transform", ['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('meta.dry_run', true);
        self::assertNull($legacy->fresh()->completed_quantity);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/works/reconciliation/transform")
            ->assertOk();
        self::assertSame('10.0000', (string) $legacy->fresh()->completed_quantity);

        $this->withHeaders($context->authHeaders())->putJson(
            "/api/v1/admin/projects/{$project->id}/works/{$confirmed->id}",
            ['project_id' => $project->id, 'quantity' => 3, 'completed_quantity' => 3],
        )->assertUnprocessable();
        self::assertSame('10.0000', (string) $confirmed->fresh()->completed_quantity);

        $this->withHeaders($context->authHeaders())->putJson(
            "/api/v1/admin/projects/{$project->id}/works/{$conflict->id}",
            ['project_id' => $project->id, 'quantity' => 10, 'completed_quantity' => 10],
        )->assertUnprocessable();
        self::assertSame('100.0000', (string) $conflict->fresh()->quantity);
        self::assertSame('10.0000', (string) $conflict->fresh()->completed_quantity);

        $this->withHeaders($context->authHeaders())->deleteJson(
            "/api/v1/admin/projects/{$project->id}/works/{$confirmed->id}",
        )->assertUnprocessable();
        self::assertNull($confirmed->fresh()->deleted_at);

        $this->artisan('completed-works:transform-history', [
            '--organization' => $context->organization->id,
            '--project' => $project->id,
        ])->assertSuccessful();
        self::assertSame(2, CompletedWorkHistoryTransformation::query()->whereIn('completed_work_id', [
            $legacy->id,
            $confirmed->id,
        ])->count());
    }

    private function work(int $organizationId, int $projectId, int $userId, int $quantity, array $overrides = []): CompletedWork
    {
        $workTypeId = DB::table('work_types')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'T27 work '.$quantity.' '.uniqid(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return CompletedWork::create(array_replace([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'work_type_id' => $workTypeId,
            'user_id' => $userId,
            'quantity' => $quantity,
            'completed_quantity' => $quantity,
            'completion_date' => '2026-09-20',
            'status' => CompletedWork::STATUS_CONFIRMED,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
        ], $overrides));
    }

    private function signedAct(AdminApiTestContext $context, Project $project, CompletedWork $work, int $quantity): ContractPerformanceAct
    {
        $contractor = Contractor::create(['organization_id' => $context->organization->id, 'name' => 'Актовый подрядчик']);
        $contract = Contract::create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'T27-'.$work->id,
            'contract_side_type' => 'subcontract',
            'date' => '2026-09-20',
            'subject' => 'Работы',
            'total_amount' => 1000,
            'status' => 'active',
        ]);
        $act = ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_date' => '2026-09-20',
            'amount' => $quantity * 10,
            'status' => ContractPerformanceAct::STATUS_SIGNED,
            'is_approved' => true,
            'signed_at' => '2026-09-20 10:00:00',
            'signed_by_user_id' => $context->user->id,
        ]);
        PerformanceActLine::create([
            'performance_act_id' => $act->id,
            'completed_work_id' => $work->id,
            'line_type' => PerformanceActLine::TYPE_COMPLETED_WORK,
            'title' => 'Работа',
            'unit' => 'шт.',
            'quantity' => $quantity,
            'unit_price' => 10,
            'amount' => $quantity * 10,
        ]);

        return $act;
    }
}
