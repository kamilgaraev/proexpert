<?php

declare(strict_types=1);

namespace Tests\Feature\ConstructionJournal;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalEntryWorkflowService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class JournalEntryRetryFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_failure_keeps_draft_and_retry_reuses_entry_with_payload_conflict(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = Contractor::create(['organization_id' => $context->organization->id, 'name' => 'Retry contractor']);
        $contract = Contract::create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'RETRY-1',
            'contract_side_type' => 'subcontract',
            'date' => '2026-09-20',
            'subject' => 'Retry',
            'total_amount' => 10000,
            'status' => 'active',
        ]);
        $journal = ConstructionJournal::create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'name' => 'Retry journal',
            'journal_number' => 'RETRY-J',
            'start_date' => '2026-09-20',
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        $payload = [
            'idempotency_key' => 'retry-entry-uuid',
            'submit_after_create' => true,
            'entry_date' => '2026-09-20',
            'work_description' => 'Retryable entry',
        ];
        $approval = Mockery::mock(JournalApprovalService::class);
        $approval->shouldReceive('submitForApproval')->once()->andThrow(new DomainException('submit failed'));
        $workflow = new JournalEntryWorkflowService(app(ConstructionJournalService::class), $approval);

        try {
            $workflow->create($journal, $payload, $context->user);
            self::fail('Expected submit failure.');
        } catch (DomainException $exception) {
            self::assertSame('submit failed', $exception->getMessage());
        }

        $entry = $journal->entries()->firstOrFail();
        self::assertSame(JournalEntryStatusEnum::DRAFT, $entry->status);
        self::assertSame(1, $journal->entries()->count());

        $approval = Mockery::mock(JournalApprovalService::class);
        $approval->shouldReceive('submitForApproval')->once()->andReturnUsing(static function ($entry): mixed {
            $entry->forceFill(['status' => JournalEntryStatusEnum::SUBMITTED])->saveQuietly();
            return $entry->fresh();
        });
        $workflow = new JournalEntryWorkflowService(app(ConstructionJournalService::class), $approval);
        $replayed = $workflow->create($journal->fresh(), $payload, $context->user);

        self::assertSame($entry->id, $replayed->id);
        self::assertSame(1, $journal->entries()->count());
        $afterLostResponse = $workflow->create($journal->fresh(), $payload, $context->user);
        self::assertSame($entry->id, $afterLostResponse->id);
        self::assertSame(JournalEntryStatusEnum::SUBMITTED, $afterLostResponse->status);

        $changedPayload = [...$payload, 'work_description' => 'Changed payload', 'submit_after_create' => false];
        $this->expectException(DomainException::class);
        $workflow->create($journal->fresh(), $changedPayload, $context->user);
    }
}
