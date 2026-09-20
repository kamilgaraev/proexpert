<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Exceptions\BusinessLogicException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeStatementOperationTest extends TestCase
{
    use \Tests\Support\SubmitsWorkVolumeStatements;

    public function test_revision_requires_a_change_reason_and_can_omit_operation_key(): void
    {
        [$service, $context, $project, $payload] = $this->context();
        $statement = $this->approveReviewed($service, $context->user, $service->createDraft($context->user, $project->id, $payload));
        try {
            $service->createRevision($context->user, $statement, [...$payload, 'operation_key' => 'no-reason']);
            self::fail('Изменение согласованного основания требует причины');
        } catch (BusinessLogicException $exception) {
            self::assertSame(trans_message('budget_estimates.work_volume_statements.change_reason_required'), $exception->getMessage());
        }
        $revision = $service->createRevision($context->user, $statement, [...$payload, 'change_reason' => 'Уточнение обмера']);
        self::assertSame(2, $revision->version);
        self::assertSame('Уточнение обмера', $revision->change_reason);
    }

    public function test_operation_key_cannot_replay_a_revision_of_a_different_statement(): void
    {
        [$service, $context, $project, $payload] = $this->context();
        $first = $this->approveReviewed($service, $context->user, $service->createDraft($context->user, $project->id, $payload));
        $second = $this->approveReviewed($service, $context->user, $service->createDraft($context->user, $project->id, $payload));
        $revisionPayload = [...$payload, 'change_reason' => 'Уточнение обмера', 'operation_key' => 'shared-operation'];
        $revision = $service->createRevision($context->user, $first, $revisionPayload);
        self::assertSame($revision->id, $service->createRevision($context->user, $first, $revisionPayload)->id);

        $this->expectException(BusinessLogicException::class);
        $service->createRevision($context->user, $second, $revisionPayload);
    }

    public function test_new_statement_creation_cannot_bypass_revision_workflow(): void
    {
        [$service, $context, $project, $payload] = $this->context();
        $statement = $this->approveReviewed($service, $context->user, $service->createDraft($context->user, $project->id, $payload));

        $this->expectException(BusinessLogicException::class);
        $service->createDraft($context->user, $project->id, [...$payload, 'statement_key' => $statement->statement_key]);
    }

    public function test_initial_draft_retry_returns_original_and_rejects_changed_payload(): void
    {
        [$service, $context, $project, $payload] = $this->context();
        $payload['operation_key'] = 'initial-create';
        $original = $service->createDraft($context->user, $project->id, $payload);
        self::assertSame($original->id, $service->createDraft($context->user, $project->id, $payload)->id);
        $this->approveReviewed($service, $context->user, $original);
        self::assertSame($original->id, $service->createDraft($context->user, $project->id, $payload)->id);
        $payload['lines'][0]['quantity'] = '101';
        $this->expectException(BusinessLogicException::class);
        $service->createDraft($context->user, $project->id, $payload);
    }

    private function context(): array
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $payload = ['lines' => [[
            'line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'name' => 'Стена', 'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1'],
        ]]];

        return [$this->app->make(WorkVolumeStatementService::class), $context, $project, $payload];
    }
}
