<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Exceptions\BusinessLogicException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeStatementLifecycleTest extends TestCase
{
    use \Tests\Support\SubmitsWorkVolumeStatements;

    public function test_later_numbered_draft_cannot_overwrite_an_intervening_approved_revision(): void
    {
        [$service, $context, $statement, $payload] = $this->approvedStatement();
        $first = $service->createRevision($context->user, $statement, [...$payload, 'operation_key' => 'first-head', 'change_reason' => 'Первое изменение']);
        $stale = $service->createRevision($context->user, $statement, [...$payload, 'operation_key' => 'stale-head', 'change_reason' => 'Параллельное изменение']);
        $this->approveReviewed($service, $context->user, $first);
        try {
            $this->approveReviewed($service, $context->user, $stale);
            self::fail('Новая редакция должна учитывать уже согласованное изменение основания');
        } catch (BusinessLogicException $exception) {
            self::assertSame(trans_message('budget_estimates.work_volume_statements.revision_outdated'), $exception->getMessage());
            self::assertSame('approved', $first->fresh()->status);
            self::assertSame('review', $stale->fresh()->status);
        }
    }

    public function test_approval_retry_preserves_original_approval_event(): void
    {
        [$service, $context, $statement] = $this->approvedStatement();
        $approvedAt = $statement->approved_at->toISOString();
        $replayed = $this->approveReviewed($service, $context->user, $statement);
        self::assertSame($statement->id, $replayed->id);
        self::assertSame($approvedAt, $replayed->approved_at->toISOString());
        self::assertSame($context->user->id, $replayed->approved_by_user_id);
    }

    public function test_older_draft_cannot_replace_newer_approved_revision(): void
    {
        [$service, $context, $statement, $payload] = $this->approvedStatement();
        $older = $service->createRevision($context->user, $statement, [...$payload, 'operation_key' => 'older', 'change_reason' => 'Уточнение 2']);
        $newer = $service->createRevision($context->user, $statement, [...$payload, 'operation_key' => 'newer', 'change_reason' => 'Уточнение 3']);
        $this->approveReviewed($service, $context->user, $newer);

        try {
            $this->approveReviewed($service, $context->user, $older);
            self::fail('Согласование старого черновика не должно создавать вторую действующую редакцию');
        } catch (BusinessLogicException) {
            self::assertSame('review', $older->fresh()->status);
            self::assertSame('approved', $newer->fresh()->status);
            self::assertSame('100.000000', $statement->fresh('lines')->lines->first()->quantity);
        }
    }

    public function test_approved_line_cannot_be_changed_through_query_builder(): void
    {
        [, , $statement] = $this->approvedStatement();
        $this->expectException(QueryException::class);
        DB::table('work_volume_statement_lines')->where('statement_id', $statement->id)->update(['quantity' => '70']);
    }

    public function test_approved_statement_cannot_be_deleted_through_query_builder(): void
    {
        [, , $statement] = $this->approvedStatement();
        $this->expectException(QueryException::class);
        DB::table('work_volume_statements')->where('id', $statement->id)->delete();
    }

    public function test_replaced_history_rejects_insert_delete_retarget_and_status_rollback(): void
    {
        [$service, $context, $statement, $payload] = $this->approvedStatement();
        $revision = $service->createRevision($context->user, $statement, [...$payload, 'operation_key' => 'replacement', 'change_reason' => 'Уточнение']);
        $this->approveReviewed($service, $context->user, $revision);
        $draft = $service->createDraft($context->user, $statement->project_id, $payload);
        $lineId = $statement->lines->first()->id;
        $mutations = [
            fn () => DB::table('work_volume_statement_lines')->where('id', $lineId)->delete(),
            fn () => DB::table('work_volume_statement_lines')->where('id', $lineId)->update(['statement_id' => $draft->id]),
            fn () => DB::table('work_volume_statement_lines')->insert([
                'statement_id' => $statement->id, 'line_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'name' => 'Добавленная строка', 'unit_code' => 'м²', 'quantity' => '1', 'place' => '{"axis":"А-1"}',
            ]),
            fn () => DB::table('work_volume_statements')->where('id', $statement->id)->update(['name' => 'Другое основание']),
            fn () => DB::table('work_volume_statements')->where('id', $statement->id)->update(['status' => 'draft']),
        ];
        foreach ($mutations as $mutation) {
            try {
                DB::transaction($mutation);
                self::fail('Согласованная история должна сохраняться после замены редакции');
            } catch (QueryException $exception) {
                self::assertSame('55000', (string) $exception->getCode());
            }
        }
        self::assertSame('replaced', $statement->fresh()->status);
        self::assertSame('100.000000', $statement->fresh('lines')->lines->first()->quantity);
        self::assertSame('approved', $revision->fresh()->status);
    }

    private function approvedStatement(): array
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $service = $this->app->make(WorkVolumeStatementService::class);
        $payload = ['lines' => [[
            'line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'name' => 'Стена', 'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1'],
        ]]];
        $statement = $this->approveReviewed($service, $context->user, $service->createDraft($context->user, $project->id, $payload));

        return [$service, $context, $statement, $payload];
    }
}
