<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkVolumeStatementReviewTest extends TestCase
{
    public function test_statement_requires_review_before_approval_and_keeps_submission_identity(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $service = app(WorkVolumeStatementService::class);
        $statement = $service->createDraft($context->user, $project->id, ['lines' => [[
            'line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена',
            'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1'],
        ]]]);
        try {
            $service->approve($context->user, $statement, 0);
            self::fail('Черновик должен сначала пройти отправку на проверку');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertSame('draft', $statement->fresh()->status);
        }
        $submitted = $service->submitForReview($context->user, $statement, 0);
        self::assertSame('review', $submitted->status);
        self::assertSame($context->user->id, $submitted->submitted_by_user_id);
        try {
            DB::transaction(fn () => DB::table('work_volume_statement_lines')->where('statement_id', $submitted->id)->update(['quantity' => '70']));
            self::fail('Отправленный на проверку объём нельзя изменить без возврата');
        } catch (QueryException $exception) {
            self::assertSame('55000', $exception->errorInfo[0]);
        }
        $retry = $service->submitForReview($context->user, $statement, 0);
        self::assertSame($submitted->submitted_at->toISOString(), $retry->submitted_at->toISOString());
        $returned = $service->returnForCorrection($context->user, $submitted, 1, 'Уточнить место работ');
        self::assertSame('draft', $returned->status);
        self::assertCount(2, $returned->review_history);
        self::assertSame('Уточнить место работ', $returned->review_history[1]['reason']);
        try {
            DB::transaction(fn () => DB::table('work_volume_statements')->where('id', $returned->id)->update(['review_history' => '[]']));
            self::fail('Историю проверки нельзя стереть после возврата в черновик');
        } catch (QueryException $exception) {
            self::assertSame('55000', $exception->errorInfo[0]);
        }
        $returnRetry = $service->returnForCorrection($context->user, $submitted, 1, 'Уточнить место работ');
        self::assertEquals($returned->review_history, $returnRetry->review_history);
        try {
            $service->submitForReview($context->user, $statement, 0);
            self::fail('Задержанный повтор отправки не должен отменять возврат на исправление');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $resubmitted = $service->submitForReview($context->user, $returned, 1);
        self::assertSame(2, $resubmitted->review_round);
        try {
            $service->returnForCorrection($context->user, $submitted, 1, 'Уточнить место работ');
            self::fail('Возврат прошлой проверки не должен затрагивать новую');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        try {
            $service->approve($context->user, $resubmitted, 1);
            self::fail('Согласование прошлой проверки не должно утверждать новую');
        } catch (BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $approved = $service->approve($context->user, $resubmitted, 2);
        self::assertSame('approved', $approved->status);
        self::assertSame($resubmitted->submitted_at->toISOString(), $approved->submitted_at->toISOString());
        self::assertCount(4, $approved->review_history);
    }
}
