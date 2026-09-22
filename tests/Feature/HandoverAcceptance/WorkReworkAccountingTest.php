<?php

declare(strict_types=1);

namespace Tests\Feature\HandoverAcceptance;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceService;
use App\BusinessModules\Features\HandoverAcceptance\Services\TechnicalAcceptanceQuantityService;
use App\BusinessModules\Features\HandoverAcceptance\Services\WorkReworkService;
use App\Models\CompletedWork;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\WorkType;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkReworkAccountingTest extends TestCase
{
    public function test_rework_twenty_preserves_fact_hundred_and_prior_acceptance(): void
    {
        [$context, $scope, $work, $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $rework = $service->create($scope, $context->user->id, [
            'quantity_line_id' => $line->id, 'quantity' => '20',
            'responsible_user_id' => $context->user->id, 'reason' => 'Исправить поверхность',
            'expected_revision' => 1, 'operation_key' => 'rework-create',
        ]);
        self::assertSame('100.0000', $work->fresh()->quantity);
        self::assertSame('80.000000', $line->fresh()->accepted_quantity);
        $rework = $service->submit($rework, $context->user->id, [
            'expected_revision' => 1, 'operation_key' => 'rework-submit',
            'description' => 'Поверхность исправлена, обмерено 20 м²', 'evidence_file_ids' => [],
        ]);
        $decision = ['expected_revision' => 2, 'operation_key' => 'rework-verify', 'decision' => 'accepted', 'comment' => 'Проверено на месте'];
        $accepted = $service->verify($rework, $context->user->id, $decision);
        self::assertSame('accepted', $accepted->status);
        self::assertSame('100.0000', $work->fresh()->quantity);
        self::assertSame('100.0000', $work->fresh()->completed_quantity);
        self::assertSame('100.000000', $line->fresh()->accepted_quantity);
        self::assertSame('0.000000', $line->fresh()->defect_quantity);
        self::assertSame('80.000000', $scope->signoffs()->where('status', 'accepted')->first()->evidence_snapshot['work_quantities'][0]['accepted_quantity']);
        self::assertSame($accepted->id, $service->verify($rework, $context->user->id, $decision)->id);
        self::assertSame('100.000000', $line->fresh()->accepted_quantity);
    }

    public function test_two_findings_share_one_rework_and_each_requires_verification(): void
    {
        [$context, $scope, $work, $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $rework = $service->create($scope, $context->user->id, [
            'quantity_line_id' => $line->id, 'quantity' => '20', 'responsible_user_id' => $context->user->id,
            'reason' => 'Неровность и отслоение на одном участке', 'expected_revision' => 1, 'operation_key' => 'two-findings',
        ]);
        $session = $scope->sessions()->create([
            'organization_id' => $scope->organization_id, 'project_id' => $scope->project_id,
            'created_by_user_id' => $context->user->id, 'status' => 'planned',
        ]);
        $handover = app(HandoverAcceptanceService::class);
        $handover->createPackage($scope, $context->user->id, ['title' => 'Передача после устранения замечаний', 'documents' => []]);
        $findings = [];
        foreach (['Неровность', 'Отслоение'] as $title) {
            $findings[] = $handover->addFinding($session, $context->user->id, [
                'title' => $title, 'severity' => 'major', 'create_quality_defect' => false,
                'quality_defect_inspection_required' => false, 'work_rework_id' => $rework->id,
            ]);
        }
        self::assertSame('accepted', $scope->fresh()->status);
        $gate = app(\App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceGate::class);
        self::assertTrue($gate->evaluate($scope)['ready']);
        self::assertFalse($gate->evaluate($scope, true)['ready']);
        $rework = $service->submit($rework, $context->user->id, [
            'expected_revision' => 1, 'operation_key' => 'two-submit', 'description' => 'Оба недостатка устранены', 'evidence_file_ids' => [],
        ]);
        $handover->resolveFinding($findings[0], $context->user->id, ['resolution_comment' => 'Ровность проверена']);
        $decision = ['expected_revision' => 2, 'operation_key' => 'two-verify', 'decision' => 'accepted', 'comment' => 'Проверка участка'];
        try {
            $service->verify($rework, $context->user->id, $decision);
            self::fail('Незакрытое второе замечание должно блокировать повторную приёмку');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        self::assertSame('80.000000', $line->fresh()->accepted_quantity);
        $handover->resolveFinding($findings[1], $context->user->id, ['resolution_comment' => 'Адгезия проверена']);
        $service->verify($rework, $context->user->id, $decision);
        self::assertSame('100.000000', $line->fresh()->accepted_quantity);
        self::assertSame('100.0000', $work->fresh()->quantity);
        self::assertSame(1, $scope->signoffs()->where('status', 'rework_accepted')->count());
        self::assertTrue($gate->evaluate($scope, true)['ready']);
        self::assertSame('handed_over', $handover->handoverScope($scope, $context->user->id)->status);
    }

    public function test_foreign_actor_cannot_verify_rework(): void
    {
        [$context, $scope, $work, $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $rework = $service->create($scope, $context->user->id, [
            'quantity_line_id' => $line->id, 'quantity' => '20', 'responsible_user_id' => $context->user->id,
            'reason' => 'Устранение дефекта', 'expected_revision' => 1, 'operation_key' => 'foreign-create',
        ]);
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $this->expectExceptionCode(404);
        $service->verify($rework, $foreign->user->id, ['expected_revision' => 1, 'operation_key' => 'foreign-verify', 'decision' => 'accepted', 'comment' => 'Чужая проверка']);
    }

    public function test_http_rework_lifecycle_and_history_keep_original_fact(): void
    {
        [$context, $scope, $work, $line] = $this->fixture();
        $base = '/api/v1/admin/handover-acceptance';
        $created = $this->withHeaders($context->authHeaders())->postJson($base.'/scopes/'.$scope->id.'/reworks', [
            'quantity_line_id' => $line->id, 'quantity' => '20', 'responsible_user_id' => $context->user->id,
            'reason' => 'Устранение дефекта', 'expected_revision' => 1, 'operation_key' => 'http-create',
        ])->assertCreated()->assertJsonPath('data.status', 'open');
        $id = $created->json('data.id');
        $this->getJson($base.'/scopes/'.$scope->id.'/reworks')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson($base.'/reworks/'.$id.'/submit', [
            'expected_revision' => 1, 'operation_key' => 'http-submit', 'description' => 'Исправление выполнено', 'evidence_file_ids' => [],
        ])->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson($base.'/reworks/'.$id.'/verify', [
            'expected_revision' => 2, 'operation_key' => 'http-verify', 'decision' => 'accepted', 'comment' => 'Проверено',
        ])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->getJson($base.'/reworks/'.$id.'/history')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.result.status', 'open')->assertJsonPath('data.2.comment', 'Проверено');
        self::assertSame('100.0000', $work->fresh()->quantity);
    }

    public function test_create_replay_does_not_depend_on_object_field_order(): void
    {
        [$context, $scope, $work, $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $data = ['quantity_line_id' => $line->id, 'quantity' => '20', 'responsible_user_id' => $context->user->id,
            'reason' => 'Устранение дефекта', 'expected_revision' => 1, 'operation_key' => 'field-order'];
        $created = $service->create($scope, $context->user->id, $data);
        self::assertSame($created->id, $service->create($scope, $context->user->id, array_reverse($data, true))->id);
    }

    public function test_changed_measurement_unit_cannot_reinterpret_existing_rework(): void
    {
        [$context, $scope, $work, $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $rework = $service->create($scope, $context->user->id, [
            'quantity_line_id' => $line->id, 'quantity' => '20', 'responsible_user_id' => $context->user->id,
            'reason' => 'Устранение дефекта', 'expected_revision' => 1, 'operation_key' => 'unit-create',
        ]);
        self::assertSame($line->unit_id, $rework->unit_id);
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $context->organization->id, 'short_name' => 'шт'], ['name' => 'Штуки', 'type' => 'work']);
        $work->workType->update(['measurement_unit_id' => $unit->id]);
        $line->update(['unit_id' => $unit->id]);
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $this->expectExceptionCode(409);
        $service->submit($rework, $context->user->id, [
            'expected_revision' => 1, 'operation_key' => 'unit-submit', 'description' => 'Исправлено', 'evidence_file_ids' => [],
        ]);
    }

    public function test_resolved_finding_does_not_replace_quality_defect_verification(): void
    {
        [$context, $scope, $work, $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $rework = $service->create($scope, $context->user->id, [
            'quantity_line_id' => $line->id, 'quantity' => '20', 'responsible_user_id' => $context->user->id,
            'reason' => 'Устранение дефекта', 'expected_revision' => 1, 'operation_key' => 'quality-create',
        ]);
        $session = $scope->sessions()->create([
            'organization_id' => $scope->organization_id, 'project_id' => $scope->project_id,
            'created_by_user_id' => $context->user->id, 'status' => 'planned',
        ]);
        $handover = app(HandoverAcceptanceService::class);
        $finding = $handover->addFinding($session, $context->user->id, [
            'title' => 'Дефект покрытия', 'severity' => 'major', 'create_quality_defect' => true,
            'quality_defect_inspection_required' => true, 'work_rework_id' => $rework->id,
        ]);
        $rework = $service->submit($rework, $context->user->id, [
            'expected_revision' => 1, 'operation_key' => 'quality-submit', 'description' => 'Исправлено', 'evidence_file_ids' => [],
        ]);
        $handover->resolveFinding($finding, $context->user->id, ['resolution_comment' => 'Замечание закрыто']);
        $this->expectException(\App\BusinessModules\Features\HandoverAcceptance\Services\WorkReworkException::class);
        $this->expectExceptionMessage(trans_message('work_rework.errors.quality_unverified'));
        $service->verify($rework, $context->user->id, ['expected_revision' => 2, 'operation_key' => 'quality-verify', 'decision' => 'accepted', 'comment' => 'Проверка']);
    }

    private function fixture(): array
    {
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $context->organization->id, 'short_name' => 'м²'], ['name' => 'Площадь', 'type' => 'work']);
        $type = WorkType::query()->create(['organization_id' => $context->organization->id, 'name' => 'Отделка', 'measurement_unit_id' => $unit->id]);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'work_type_id' => $type->id, 'user_id' => $context->user->id,
            'quantity' => 100, 'completed_quantity' => 100, 'price' => 1, 'total_amount' => 100,
            'completion_date' => '2026-09-21', 'status' => CompletedWork::STATUS_CONFIRMED,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL, 'planning_status' => CompletedWork::PLANNING_PLANNED,
        ]);
        $scope = AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by_user_id' => $context->user->id, 'title' => 'Отделка этажа', 'status' => 'in_progress',
        ]);
        $line = app(TechnicalAcceptanceQuantityService::class)->draft($context->organization->id, $context->user->id, $scope->id, [[
            'completed_work_id' => $work->id, 'unit_id' => $unit->id, 'presented_quantity' => '100',
            'accepted_quantity' => '80', 'defect_quantity' => '20', 'defect_reason' => 'Дефект поверхности',
        ]], 0, 'initial-quantity')->first();
        app(HandoverAcceptanceService::class)->acceptScope($scope, $context->user->id, null);

        return [$context, $scope->fresh(), $work, $line];
    }
}
