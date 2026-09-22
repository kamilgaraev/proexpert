<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\Models\CompletedWork;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use Tests\TestCase;

final class WorkVolumeStatementRevisionTest extends TestCase
{
    use \Tests\Support\SubmitsWorkVolumeStatements;

    public function test_confirmed_work_without_acceptance_does_not_become_accepted_volume(): void
    {
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects']);
        $workType = WorkType::query()->create(['organization_id' => $organization->id, 'name' => 'Кладка']);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'number' => 'T14-1',
            'name' => 'Основание', 'type' => 'local', 'status' => 'approved', 'version' => 1,
            'estimate_date' => '2026-09-20',
        ]);
        $item = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Кладка', 'quantity' => 100]);
        $service = $this->app->make(WorkVolumeStatementService::class);
        $line = ['line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Кладка', 'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1'], 'estimate_item_id' => $item->id];
        $statement = $service->createDraft($actor, $project->id, ['name' => 'ВОР', 'lines' => [$line]]);
        $statement = $this->approveReviewed($service, $actor, $statement);
        CompletedWork::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'work_type_id' => $workType->id,
            'user_id' => $actor->id, 'estimate_item_id' => $item->id, 'quantity' => 80, 'completion_date' => '2026-09-20', 'status' => CompletedWork::STATUS_CONFIRMED,
        ]);

        $reduced = $line;
        $reduced['quantity'] = '70';
        $reducedRevision = $service->createRevision($actor, $statement, ['lines' => [$reduced], 'change_reason' => 'Сокращение', 'operation_key' => 'reduce-1']);
        self::assertSame('approved', $this->approveReviewed($service, $actor, $reducedRevision)->status);
        self::assertSame('100.000000', $statement->fresh('lines')->lines->first()->quantity);
    }

    public function test_foreign_project_is_rejected_and_revision_operation_replay_returns_same_revision(): void
    {
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $foreignProject = Project::factory()->for(Organization::factory())->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects']);
        $service = $this->app->make(WorkVolumeStatementService::class);
        $payload = ['lines' => [['line_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'name' => 'Работа', 'unit_code' => 'м²', 'quantity' => '10', 'place' => ['axis' => 'Б-1']]]];
        $statement = $service->createDraft($actor, $project->id, $payload);
        $approved = $this->approveReviewed($service, $actor, $statement);
        $first = $service->createRevision($actor, $approved, [...$payload, 'operation_key' => 'revision-1', 'change_reason' => 'Уточнение']);
        $replay = $service->createRevision($actor, $approved, [...$payload, 'operation_key' => 'revision-1', 'change_reason' => 'Уточнение']);
        self::assertSame($first->id, $replay->id);
        try {
            $service->createRevision($actor, $approved, ['operation_key' => 'revision-1', 'lines' => [], 'change_reason' => 'Уточнение']);
            self::fail('Повтор операции с другим телом должен быть отклонён');
        } catch (BusinessLogicException $exception) {
            self::assertSame(trans_message('budget_estimates.work_volume_statements.operation_conflict'), $exception->getMessage());
        }
        $this->expectException(BusinessLogicException::class);
        $service->createDraft($actor, $foreignProject->id, $payload);
    }

    public function test_import_preview_rejects_duplicate_identity_and_keeps_unresolved_rows_out_of_approval(): void
    {
        $result = $this->app->make(WorkVolumeStatementService::class)->previewImport([
            ['line_key' => '11111111-1111-4111-8111-111111111111', 'name' => 'Стена', 'unit_code' => 'м²', 'quantity' => '100.000001', 'place' => ['axis' => 'А-1']],
            ['line_key' => '11111111-1111-4111-8111-111111111111', 'name' => 'Стена дубль', 'unit_code' => 'м²', 'quantity' => '1', 'place' => ['axis' => 'А-1']],
            ['line_key' => '22222222-2222-4222-8222-222222222222', 'name' => 'Лоток', 'unit_code' => '', 'quantity' => '2', 'place' => []],
        ]);

        self::assertSame('100.000001', $result['rows'][0]['quantity']);
        self::assertCount(2, $result['errors']);
        self::assertCount(3, $result['rows']);
        self::assertFalse($result['can_approve']);
    }

    public function test_import_preview_rejects_invalid_quantities_without_losing_source_values(): void
    {
        $service = $this->app->make(WorkVolumeStatementService::class);
        foreach (['-1', 'abc', '1.0000001', '1000000000000000000'] as $quantity) {
            $result = $service->previewImport([
                ['line_key' => '33333333-3333-4333-8333-333333333333', 'name' => 'Кабель', 'unit_code' => 'м', 'quantity' => $quantity, 'place' => ['section' => '1']],
            ]);
            self::assertFalse($result['can_approve'], $quantity);
            self::assertSame($quantity, $result['rows'][0]['quantity']);
            self::assertSame('quantity_invalid', $result['errors'][0]['code']);
        }
    }

    public function test_import_preview_preserves_exact_decimal_quantity(): void
    {
        $result = $this->app->make(WorkVolumeStatementService::class)->previewImport([
            ['line_key' => '33333333-3333-4333-8333-333333333333', 'name' => 'Кабель', 'unit_code' => 'м', 'quantity' => '0.000001', 'place' => ['section' => '1']],
        ]);

        self::assertSame('0.000001', $result['rows'][0]['quantity']);
        self::assertTrue($result['can_approve']);
    }
}
