<?php

declare(strict_types=1);

namespace Tests\Feature\HandoverAcceptance;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceService;
use App\BusinessModules\Features\HandoverAcceptance\Services\TechnicalAcceptanceQuantityService;
use App\Exceptions\BusinessLogicException;
use App\Models\ActingPolicy;
use App\Models\CompletedWork;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\WorkType;
use App\Services\Acting\ActingQuantityReservationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class TechnicalAcceptanceQuantityTest extends TestCase
{
    public function test_accepted_only_limits_acting_to_accepted_quantity_and_default_keeps_full_fact(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $scope = $this->scope($context, $project, 'in_progress');
        $service = app(TechnicalAcceptanceQuantityService::class);
        $service->draft($context->organization->id, $context->user->id, $scope->id, [$this->line($work, $unit, '100', '80', '20')], 0, 't17-first');
        app(HandoverAcceptanceService::class)->acceptScope($scope, $context->user->id, null);

        ActingPolicy::query()->create([
            'organization_id' => $context->organization->id, 'contract_id' => null,
            'mode' => ActingPolicy::MODE_OPERATIONAL, 'allow_manual_lines' => false,
            'require_manual_line_reason' => true, 'settings' => ['technical_acceptance' => ['mode' => 'accepted_only']],
        ]);
        $locked = new \Illuminate\Database\Eloquent\Collection([$work->fresh(['estimateItem', 'workType'])]);
        DB::transaction(function () use ($locked, $work): void {
            $available = app(ActingQuantityReservationService::class)->availableQuantities($locked, null, ['settings' => ['technical_acceptance' => ['mode' => 'accepted_only']]]);
            self::assertSame(800000, $available[$work->id]);
        });
        self::assertSame([], app(TechnicalAcceptanceQuantityService::class)->acceptedQuantities($locked, []));
    }

    public function test_foreign_actor_and_immutable_scope_are_rejected(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $scope = $this->scope($context, $project, 'accepted');
        $service = app(TechnicalAcceptanceQuantityService::class);
        $this->expectException(BusinessLogicException::class);
        $service->draft($context->organization->id, $context->user->id, $scope->id, [$this->line($work, $unit, '1', '1', '0')], 0, 'immutable');
    }

    public function test_duplicate_and_invalid_unit_are_rejected(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $scope = $this->scope($context, $project, 'in_progress');
        $service = app(TechnicalAcceptanceQuantityService::class);
        $line = $this->line($work, $unit, '1', '1', '0');
        try {
            $service->draft($context->organization->id, $context->user->id, $scope->id, [$line, $line], 0, 'duplicate');
            self::fail('duplicate work must fail');
        } catch (BusinessLogicException) {
            self::assertTrue(true);
        }
        $line['unit_id'] = $unit->id + 1000;
        $this->expectException(BusinessLogicException::class);
        $service->draft($context->organization->id, $context->user->id, $scope->id, [$line], 0, 'unit');
    }

    public function test_stale_revision_and_replay_after_update_are_safe(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $scope = $this->scope($context, $project, 'in_progress');
        $service = app(TechnicalAcceptanceQuantityService::class);
        $line = $this->line($work, $unit, '100', '80', '20');
        $first = $service->draft($context->organization->id, $context->user->id, $scope->id, [$line], 0, 'replay');
        $service->draft($context->organization->id, $context->user->id, $scope->id, [$this->line($work, $unit, '100', '70', '30')], 1, 'next');
        $replayed = $service->draft($context->organization->id, $context->user->id, $scope->id, [$line], 0, 'replay');
        self::assertCount(1, $replayed);
        self::assertSame($first->first()->id, $replayed->first()->id);
        self::assertSame('80.000000', (string) $replayed->first()->accepted_quantity);
        $this->expectException(BusinessLogicException::class);
        $service->draft($context->organization->id, $context->user->id, $scope->id, [$line], 0, 'next');
    }

    public function test_accepted_quantity_keeps_scale_six_and_legacy_reservation_floors(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $scope = $this->scope($context, $project, 'in_progress');
        $service = app(TechnicalAcceptanceQuantityService::class);
        $service->draft($context->organization->id, $context->user->id, $scope->id, [$this->line($work, $unit, '100', '80.123456', '19.876544')], 0, 'scale-six');
        $scope->update(['status' => 'accepted']);
        $locked = new \Illuminate\Database\Eloquent\Collection([$work->fresh(['estimateItem', 'workType'])]);
        $decimals = $service->acceptedQuantityDecimals($locked, ['settings' => ['technical_acceptance' => ['mode' => 'accepted_only']]]);
        self::assertSame('80.123456', $decimals[$work->id]);
        self::assertSame(801234, $service->acceptedQuantities($locked, ['settings' => ['technical_acceptance' => ['mode' => 'accepted_only']]])[$work->id]);
    }

    public function test_foreign_actor_is_rejected(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $scope = $this->scope($context, $project, 'in_progress');
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->expectException(BusinessLogicException::class);
        app(TechnicalAcceptanceQuantityService::class)->draft($context->organization->id, $foreign->user->id, $scope->id, [$this->line($work, $unit, '1', '1', '0')], 0, 'foreign');
    }

    public function test_cross_scope_presented_quantity_cannot_exceed_fact(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $service = app(TechnicalAcceptanceQuantityService::class);
        $first = $this->scope($context, $project, 'in_progress');
        $second = $this->scope($context, $project, 'in_progress');
        $service->draft($context->organization->id, $context->user->id, $first->id, [$this->line($work, $unit, '80', '80', '0')], 0, 'scope-1');
        $this->expectException(BusinessLogicException::class);
        $service->draft($context->organization->id, $context->user->id, $second->id, [$this->line($work, $unit, '30', '30', '0')], 0, 'scope-2');
    }

    public function test_http_quantity_decision_keeps_revision_and_maps_validation_errors(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $scope = $this->scope($context, $project, 'in_progress');
        $url = '/api/v1/admin/handover-acceptance/scopes/'.$scope->id.'/work-quantities';
        $data = ['expected_revision' => 0, 'operation_key' => 'http-quantity', 'lines' => [$this->line($work, $unit, '100', '80', '20')]];
        $response = $this->withHeaders($context->authHeaders())->putJson($url, $data)->assertOk()->assertJsonPath('data.revision', 1);
        $rowId = $response->json('data.lines.0.id');
        $this->putJson($url, $data)->assertOk()->assertJsonPath('data.lines.0.id', $rowId);
        $data['operation_key'] = 'bad-balance';
        $data['expected_revision'] = 1;
        $data['lines'][0]['accepted_quantity'] = '90';
        $this->putJson($url, $data)->assertUnprocessable();
        self::assertSame(1, DB::table('acceptance_scope_work_quantity_operations')->where('acceptance_scope_id', $scope->id)->count());
    }

    public function test_replay_cannot_be_claimed_by_another_authorized_actor(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $scope = $this->scope($context, $project, 'in_progress');
        $service = app(TechnicalAcceptanceQuantityService::class);
        $line = $this->line($work, $unit, '100', '80', '20');
        $service->draft($context->organization->id, $context->user->id, $scope->id, [$line], 0, 'actor-key');
        $actor = \App\Models\User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($actor->id, ['is_owner' => true, 'is_active' => true]);
        \App\Domain\Authorization\Models\UserRoleAssignment::assignRole($actor, 'organization_owner', \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($context->organization->id));
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(409);
        $service->draft($context->organization->id, $actor->id, $scope->id, [$line], 0, 'actor-key');
    }

    public function test_http_foreign_scope_is_not_found(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $scope = $this->scope($foreign, $foreignProject, 'in_progress');
        $this->withHeaders($context->authHeaders())->putJson('/api/v1/admin/handover-acceptance/scopes/'.$scope->id.'/work-quantities', [
            'expected_revision' => 0, 'operation_key' => 'foreign-scope', 'lines' => [$this->line($work, $unit, '100', '80', '20')],
        ])->assertNotFound();
        self::assertSame(0, $scope->workQuantities()->count());
    }

    public function test_quantity_update_preserves_original_author(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $scope = $this->scope($context, $project, 'in_progress');
        $service = app(TechnicalAcceptanceQuantityService::class);
        $service->draft($context->organization->id, $context->user->id, $scope->id, [$this->line($work, $unit, '100', '80', '20')], 0, 'original-author');
        $actor = \App\Models\User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($actor->id, ['is_owner' => true, 'is_active' => true]);
        \App\Domain\Authorization\Models\UserRoleAssignment::assignRole($actor, 'organization_owner', \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($context->organization->id));
        $rows = $service->draft($context->organization->id, $actor->id, $scope->id, [$this->line($work, $unit, '100', '90', '10')], 1, 'updated-author');
        self::assertSame($context->user->id, $rows->first()->created_by_user_id);
        self::assertSame($actor->id, $rows->first()->updated_by_user_id);
        self::assertSame(2, DB::table('acceptance_scope_work_quantity_operations')->where('acceptance_scope_id', $scope->id)->count());
    }

    public function test_database_rejects_unbalanced_quantities(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $scope = $this->scope($context, $project, 'in_progress');
        $this->expectException(\Illuminate\Database\QueryException::class);
        $scope->workQuantities()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'completed_work_id' => $work->id, 'unit_id' => $unit->id,
            'presented_quantity' => '100', 'accepted_quantity' => '80', 'defect_quantity' => '30', 'defect_reason' => 'Замечание',
        ]);
    }

    public function test_changed_source_quantity_blocks_scope_acceptance(): void
    {
        [$context, $project, $work, $unit] = $this->fixture();
        $scope = $this->scope($context, $project, 'in_progress');
        app(TechnicalAcceptanceQuantityService::class)->draft($context->organization->id, $context->user->id, $scope->id, [$this->line($work, $unit, '100', '80', '20')], 0, 'source-change');
        $work->update(['quantity' => 70, 'completed_quantity' => 70, 'total_amount' => 70]);
        $readiness = app(\App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceGate::class)->evaluate($scope);
        self::assertFalse($readiness['ready']);
        self::assertContains('quantity_source_changed', array_column($readiness['blockers'], 'code'));
        $this->expectException(\DomainException::class);
        app(HandoverAcceptanceService::class)->acceptScope($scope, $context->user->id, null);
    }

    private function fixture(): array
    {
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $unit = MeasurementUnit::query()->create(['organization_id' => $context->organization->id, 'name' => 'Объём', 'short_name' => 'м3', 'type' => 'work']);
        $workType = WorkType::query()->create(['organization_id' => $context->organization->id, 'name' => 'Стена', 'measurement_unit_id' => $unit->id]);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'work_type_id' => $workType->id,
            'user_id' => $context->user->id, 'quantity' => 100, 'completed_quantity' => 100, 'price' => 1,
            'total_amount' => 100, 'completion_date' => '2026-09-20', 'status' => CompletedWork::STATUS_CONFIRMED,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL, 'planning_status' => CompletedWork::PLANNING_PLANNED,
        ]);
        return [$context, $project, $work, $unit];
    }

    private function scope(AdminApiTestContext $context, Project $project, string $status): AcceptanceScope
    {
        return AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by_user_id' => $context->user->id, 'title' => 'Количественная приёмка', 'status' => $status,
        ]);
    }

    private function line(CompletedWork $work, MeasurementUnit $unit, string $presented, string $accepted, string $defect): array
    {
        return ['completed_work_id' => $work->id, 'unit_id' => $unit->id, 'presented_quantity' => $presented, 'accepted_quantity' => $accepted, 'defect_quantity' => $defect, 'defect_reason' => $defect === '0' ? null : 'Замечание'];
    }
}
