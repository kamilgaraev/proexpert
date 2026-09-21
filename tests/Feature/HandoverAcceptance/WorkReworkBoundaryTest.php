<?php

declare(strict_types=1);

namespace Tests\Feature\HandoverAcceptance;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceService;
use App\BusinessModules\Features\HandoverAcceptance\Services\TechnicalAcceptanceQuantityService;
use App\BusinessModules\Features\HandoverAcceptance\Services\WorkReworkException;
use App\BusinessModules\Features\HandoverAcceptance\Services\WorkReworkService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\File;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkReworkBoundaryTest extends TestCase
{
    public function test_rework_reservation_cannot_exceed_defect_quantity(): void
    {
        [$context, $scope, , $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $service->create($scope, $context->user->id, $this->createData($line, '20', 'limit-20'));

        $this->expectException(WorkReworkException::class);
        $this->expectExceptionCode(422);
        $service->create($scope, $context->user->id, $this->createData($line, '0.000001', 'limit-over'));
    }

    public function test_two_batches_are_verified_sequentially_without_double_counting(): void
    {
        [$context, $scope, $work, $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $first = $service->create($scope, $context->user->id, $this->createData($line, '10', 'batch-1'));
        $first = $service->submit($first, $context->user->id, $this->submitData('batch-1-submit'));
        $first = $service->verify($first, $context->user->id, $this->verifyData('batch-1-verify', 2));
        self::assertSame('accepted', $first->status);
        self::assertSame('90.000000', $line->fresh()->accepted_quantity);
        self::assertSame('10.000000', $line->fresh()->defect_quantity);

        $second = $service->create($scope->fresh(), $context->user->id, $this->createData($line->fresh(), '10', 'batch-2', 2));
        $second = $service->submit($second, $context->user->id, $this->submitData('batch-2-submit'));
        $second = $service->verify($second, $context->user->id, $this->verifyData('batch-2-verify', 2));
        self::assertSame('accepted', $second->status);
        self::assertSame('100.000000', $line->fresh()->accepted_quantity);
        self::assertSame('0.000000', $line->fresh()->defect_quantity);
        self::assertSame('100.0000', $work->fresh()->quantity);
    }

    public function test_stale_revision_and_replay_with_different_actor_body_are_conflicts(): void
    {
        [$context, $scope, , $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $created = $service->create($scope, $context->user->id, $this->createData($line, '10', 'replay-key'));
        $other = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($other->id, ['is_owner' => false, 'is_active' => true]);
        $scope->project->users()->attach($other->id, ['role' => 'member', 'is_active' => true, 'assigned_at' => now()]);

        try {
            $service->submit($created, $context->user->id, $this->submitData('stale', 2));
            self::fail('stale revision must be rejected');
        } catch (WorkReworkException $exception) {
            self::assertSame(409, $exception->getCode());
        }

        try {
            $service->create($scope, $other->id, $this->createData($line, '9', 'replay-key'));
            self::fail('replay with a different actor/body must be rejected');
        } catch (WorkReworkException $exception) {
            self::assertSame(409, $exception->getCode());
        }
    }

    public function test_evidence_file_from_foreign_organization_is_rejected(): void
    {
        [$context, $scope, , $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $rework = $service->create($scope, $context->user->id, $this->createData($line, '10', 'foreign-file'));
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $file = File::query()->create([
            'organization_id' => $foreign->organization->id, 'fileable_id' => $foreign->organization->id,
            'fileable_type' => $foreign->organization->getMorphClass(), 'user_id' => $foreign->user->id,
            'name' => 'foreign.pdf', 'original_name' => 'foreign.pdf', 'path' => 'foreign.pdf',
            'mime_type' => 'application/pdf', 'size' => 1, 'disk' => 'local',
        ]);

        $this->expectException(WorkReworkException::class);
        $this->expectExceptionCode(422);
        $service->submit($rework, $context->user->id, $this->submitData('foreign-file-submit', 1, [$file->id]));
    }

    public function test_approve_permission_denial_is_forbidden(): void
    {
        [$context, $scope, , $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $rework = $service->create($scope, $context->user->id, $this->createData($line, '10', 'denied-approve'));
        $rework = $service->submit($rework, $context->user->id, $this->submitData('denied-submit'));
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturnUsing(static fn (User $actor, string $permission): bool => $permission !== 'handover-acceptance.approve');
        });

        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $this->expectExceptionCode(403);
        app(WorkReworkService::class)->verify($rework, $context->user->id, $this->verifyData('denied-verify', 2));
    }

    public function test_rework_events_are_immutable(): void
    {
        [$context, $scope, , $line] = $this->fixture();
        $rework = app(WorkReworkService::class)->create($scope, $context->user->id, $this->createData($line, '10', 'immutable'));
        $eventId = DB::table('work_rework_events')->where('work_rework_id', $rework->id)->value('id');

        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $eventId): void {
                    if ($operation === 'update') {
                        DB::table('work_rework_events')->where('id', $eventId)->update(['action' => 'tampered']);
                    } else {
                        DB::table('work_rework_events')->where('id', $eventId)->delete();
                    }
                });
                self::fail($operation.' must be rejected');
            } catch (QueryException $exception) {
                self::assertStringContainsString('work_rework_event_immutable', $exception->getMessage());
            }
        }
    }

    public function test_rejected_rework_can_be_corrected_and_resubmitted_without_new_volume(): void
    {
        [$context, $scope, $work, $line] = $this->fixture();
        $service = app(WorkReworkService::class);
        $rework = $service->create($scope, $context->user->id, $this->createData($line, '20', 'reject-create'));
        $rework = $service->submit($rework, $context->user->id, $this->submitData('reject-submit'));
        $rework = $service->verify($rework, $context->user->id, array_replace($this->verifyData('reject-review', 2), ['decision' => 'rejected']));
        self::assertSame('80.000000', $line->fresh()->accepted_quantity);
        try {
            $service->create($scope, $context->user->id, $this->createData($line, '1', 'duplicate-rejected-area'));
            self::fail('Отклонённый участок остаётся в работе и не должен дублироваться');
        } catch (WorkReworkException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $rework = $service->submit($rework, $context->user->id, $this->submitData('reject-resubmit', 3));
        self::assertNull($rework->verified_at);
        $service->verify($rework, $context->user->id, $this->verifyData('reject-final-review', 4));
        self::assertSame('100.000000', $line->fresh()->accepted_quantity);
        self::assertSame('100.0000', $work->fresh()->quantity);
        self::assertSame(1, $scope->signoffs()->where('status', 'rework_rejected')->count());
        self::assertSame(1, $scope->signoffs()->where('status', 'rework_accepted')->count());
    }

    private function fixture(): array
    {
        $this->app->forgetInstance(AuthorizationService::class);
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturnTrue();
        });
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $context->organization->id, 'short_name' => 'м²'], ['name' => 'Площадь', 'type' => 'work']);
        $type = WorkType::query()->create(['organization_id' => $context->organization->id, 'name' => 'Отделка', 'measurement_unit_id' => $unit->id]);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'work_type_id' => $type->id, 'user_id' => $context->user->id,
            'quantity' => 100, 'completed_quantity' => 100, 'price' => 1, 'total_amount' => 100, 'completion_date' => '2026-09-21',
            'status' => CompletedWork::STATUS_CONFIRMED, 'work_origin_type' => CompletedWork::ORIGIN_MANUAL, 'planning_status' => CompletedWork::PLANNING_PLANNED,
        ]);
        $scope = AcceptanceScope::query()->create(['organization_id' => $context->organization->id, 'project_id' => $project->id, 'created_by_user_id' => $context->user->id, 'title' => 'Отделка', 'status' => 'in_progress']);
        $line = app(TechnicalAcceptanceQuantityService::class)->draft($context->organization->id, $context->user->id, $scope->id, [[
            'completed_work_id' => $work->id, 'unit_id' => $unit->id, 'presented_quantity' => '100', 'accepted_quantity' => '80', 'defect_quantity' => '20', 'defect_reason' => 'Дефект поверхности',
        ]], 0, 'initial-quantity')->first();
        app(HandoverAcceptanceService::class)->acceptScope($scope, $context->user->id, null);

        return [$context, $scope->fresh(), $work, $line];
    }

    private function createData($line, string $quantity, string $operationKey, int $revision = 1): array
    {
        return ['quantity_line_id' => $line->id, 'quantity' => $quantity, 'responsible_user_id' => $line->scope->created_by_user_id, 'reason' => 'Исправление дефекта', 'expected_revision' => $revision, 'operation_key' => $operationKey];
    }

    private function submitData(string $operationKey, int $revision = 1, array $files = []): array
    {
        return ['expected_revision' => $revision, 'operation_key' => $operationKey, 'description' => 'Исправление выполнено', 'evidence_file_ids' => $files];
    }

    private function verifyData(string $operationKey, int $revision): array
    {
        return ['expected_revision' => $revision, 'operation_key' => $operationKey, 'decision' => 'accepted', 'comment' => 'Проверено'];
    }
}
