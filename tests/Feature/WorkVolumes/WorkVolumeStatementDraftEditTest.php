<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\Project;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeStatementDraftEditTest extends TestCase
{
    public function test_service_rejects_unknown_units_and_invalid_line_identity(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $service = app(WorkVolumeStatementService::class);
        $line = ['line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена', 'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1']];
        foreach ([['unit_code' => 'неизвестная'], ['name' => ''], ['line_key' => 'not-a-uuid'], ['quantity' => ['invalid']]] as $change) {
            try {
                $service->createDraft($context->user, $project->id, ['lines' => [[...$line, ...$change]]]);
                self::fail('Сервис обязан проверять строки независимо от HTTP-формы');
            } catch (BusinessLogicException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
        try {
            $service->createDraft($context->user, $project->id, ['lines' => [$line, [...$line, 'line_key' => strtoupper($line['line_key'])]]]);
            self::fail('Регистр UUID не должен позволять дубли строк');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $uuidV1 = [...$line, 'line_key' => 'aaaaaaaa-aaaa-1aaa-8aaa-aaaaaaaaaaaa'];
        self::assertNotNull($service->createDraft($context->user, $project->id, ['lines' => [$uuidV1]]));
    }

    public function test_returned_draft_can_be_corrected_without_overwriting_later_edits_on_retry(): void
    {
        $context = AdminApiTestContext::create();
        $actor = $context->user;
        $actor->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $service = app(WorkVolumeStatementService::class);
        $line = ['line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена', 'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1']];
        $statement = $service->createDraft($actor, $project->id, ['lines' => [$line]]);
        $lineId = $statement->lines->first()->id;
        foreach ([
            ['operation_key' => '', 'lines' => [$line]],
            ['operation_key' => str_repeat('x', 129), 'lines' => [$line]],
            ['operation_key' => 'invalid-name', 'name' => null, 'lines' => [$line]],
            ['operation_key' => 'empty-lines', 'lines' => []],
        ] as $invalidPayload) {
            try {
                $service->updateDraft($actor, $statement, 1, $invalidPayload);
                self::fail('Некорректная правка должна быть отклонена');
            } catch (BusinessLogicException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
        $foreignOrganization = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignEstimate = Estimate::query()->create(['organization_id' => $foreignOrganization->id, 'project_id' => $foreignProject->id, 'number' => 'FOREIGN-1', 'name' => 'Смета', 'type' => 'local', 'status' => 'approved', 'version' => 1, 'estimate_date' => '2026-09-20']);
        $foreignUnit = MeasurementUnit::query()->where('organization_id', $foreignOrganization->id)->where('short_name', 'м²')->firstOrFail();
        $foreignItem = EstimateItem::query()->create(['estimate_id' => $foreignEstimate->id, 'position_number' => '1', 'name' => 'Чужая работа', 'quantity' => 100, 'measurement_unit_id' => $foreignUnit->id]);
        try {
            $service->updateDraft($actor, $statement, 1, ['operation_key' => 'foreign-item', 'lines' => [[...$line, 'estimate_item_id' => $foreignItem->id]]]);
            self::fail('Строка с чужой позицией сметы должна быть отклонена');
        } catch (BusinessLogicException $exception) {
            self::assertSame(404, $exception->getCode());
        }
        $firstPayload = ['operation_key' => 'draft-edit-1', 'lines' => [[...$line, 'quantity' => '70']]];
        $first = $service->updateDraft($actor, $statement, 1, $firstPayload);
        self::assertSame(2, $first['draft_version']);
        self::assertSame($lineId, $first['lines'][0]['id']);
        self::assertSame('70.000000', $first['lines'][0]['quantity']);
        $review = $service->submitForReview($actor, $statement, 0);
        try {
            $service->updateDraft($actor, $statement, 2, ['operation_key' => 'during-review', 'lines' => [$line]]);
            self::fail('Объём на проверке нельзя исправить без возврата');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $returned = $service->returnForCorrection($actor, $review, 1, 'Исправить объём');
        $second = $service->updateDraft($actor, $returned, 2, ['operation_key' => 'draft-edit-2', 'lines' => [[...$line, 'quantity' => '80']]]);
        self::assertSame(3, $second['draft_version']);
        self::assertEquals($first, $service->updateDraft($actor, $statement, 1, $firstPayload));
        self::assertSame('80.000000', $statement->fresh('lines')->lines->first()->quantity);
        foreach ([
            ['version' => 1, 'payload' => [...$firstPayload, 'lines' => [$line]]],
            ['version' => 2, 'payload' => ['operation_key' => 'stale-edit', 'lines' => [$line]]],
        ] as $conflict) {
            try {
                $service->updateDraft($actor, $statement, $conflict['version'], $conflict['payload']);
                self::fail('Несовпадающий повтор или устаревшая правка должны конфликтовать');
            } catch (BusinessLogicException $exception) {
                self::assertSame(409, $exception->getCode());
            }
        }
        self::assertCount(2, $statement->fresh()->review_history);
        $approved = $service->approve($actor, $service->submitForReview($actor, $statement, 1), 2);
        self::assertSame('80.000000', $approved->lines->first()->quantity);
    }
}
