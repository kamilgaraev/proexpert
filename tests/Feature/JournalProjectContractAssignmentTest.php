<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\CompletedWork\CompletedWorkFactService;
use App\Services\ConstructionJournal\JournalContractAssignmentService;
use App\Services\ConstructionJournal\JournalEntryQueryService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class JournalProjectContractAssignmentTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    private ?int $deniedUser = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        Notification::fake();
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturnUsing(fn (User $user): bool => $user->id !== $this->deniedUser);
            $mock->shouldReceive('hasRole')->andReturn(false);
        });
    }

    public function test_project_can_have_multiple_journals_without_contract(): void
    {
        [$user, $project] = $this->fixture();
        $service = app(ConstructionJournalService::class);
        $first = $service->createJournal($project, ['name' => 'Объект 1'], $user);
        $second = $service->createJournal($project, ['name' => 'Объект 2', 'contract_id' => null], $user);
        self::assertNull($first->contract_id);
        self::assertNull($second->contract_id);
        self::assertNotSame($first->id, $second->id);
    }

    public function test_explicit_null_does_not_inherit_the_legacy_journal_contract(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $journal = $this->journal($user, $project, $first);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 2], ['quantity' => 3, 'contract_id' => null]]);
        self::assertSame([$first->id, null], $entry->workVolumes()->orderBy('id')->pluck('contract_id')->all());
        self::assertSame([$first->id, null], $entry->completedWorks()->orderBy('id')->pluck('contract_id')->all());
    }

    public function test_old_update_preserves_selected_row_contract_and_explicit_null_clears_it(): void
    {
        [$user, $project, $item, $first, $second] = $this->fixture();
        $journal = $this->journal($user, $project, $first);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 2, 'contract_id' => $second->id]]);
        $row = $entry->workVolumes()->firstOrFail();
        $payload = ['id' => $row->id, 'estimate_item_id' => $item->id, 'quantity' => 4];
        app(ConstructionJournalService::class)->updateEntry($entry, ['work_volumes' => [$payload]], $user);
        self::assertSame($second->id, $row->fresh()->contract_id);
        app(ConstructionJournalService::class)->updateEntry($entry->fresh(), ['work_volumes' => [[...$payload, 'contract_id' => null]]], $user);
        self::assertNull($row->fresh()->contract_id);
    }

    public function test_split_work_is_approved_once_and_each_contract_gets_its_quantity(): void
    {
        [$user, $project, $item, $first, $second] = $this->fixture();
        $journal = $this->journal($user, $project);
        $entry = $this->entry($user, $journal, $item, [
            ['quantity' => 7, 'contract_id' => $first->id],
            ['quantity' => 8, 'contract_id' => $second->id],
            ['quantity' => 2, 'contract_id' => null],
        ]);
        $this->approve($entry, $user);
        app(CompletedWorkFactService::class)->syncFromJournalEntry($entry->fresh());
        self::assertSame(3, $entry->completedWorks()->count());
        self::assertSame(7.0, (float) $entry->completedWorks()->where('contract_id', $first->id)->sum('quantity'));
        self::assertSame(8.0, (float) $entry->completedWorks()->where('contract_id', $second->id)->sum('quantity'));
        self::assertSame(2.0, (float) $entry->completedWorks()->whereNull('contract_id')->sum('quantity'));
    }

    public function test_contract_reservations_are_shared_across_project_journals(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $entry = $this->entry($user, $this->journal($user, $project), $item, [['quantity' => 8, 'contract_id' => $first->id]]);
        $entry->update(['status' => 'submitted']);
        $next = $this->entry($user, $this->journal($user, $project), $item, [['quantity' => 3, 'contract_id' => $first->id]]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(trans_message('construction_journal.errors.volume_exceeds_remaining'));
        $this->approve($next, $user);
    }

    public function test_project_limit_includes_contract_and_unassigned_work(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $item->update(['quantity_total' => 10]);
        $this->approve($this->entry($user, $this->journal($user, $project), $item, [['quantity' => 8, 'contract_id' => $first->id]]), $user);
        $next = $this->entry($user, $this->journal($user, $project), $item, [['quantity' => 3, 'contract_id' => null]]);
        $this->expectException(DomainException::class);
        $this->approve($next, $user);
    }

    public function test_late_assignment_preserves_fact_identity_and_approval_history(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $journal = $this->journal($user, $project);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 3, 'contract_id' => null]]);
        $this->approve($entry, $user);
        $row = $entry->workVolumes()->firstOrFail();
        $fact = $entry->completedWorks()->firstOrFail();
        $entryBefore = $entry->fresh()->getRawOriginal();
        $eventsBefore = $entry->approvalEvents()->count();
        $document = \App\Models\GeneralJournalDocumentVersion::create([
            'organization_id' => $journal->organization_id, 'project_id' => $project->id,
            'journal_id' => $journal->id, 'created_by_user_id' => $user->id,
            'revision' => 1, 'operation_key' => 'row-contract-before',
            'request_fingerprint' => hash('sha256', 'row-contract-before'), 'template_version' => '1',
            'source_snapshot' => ['entry_id' => $entry->id, 'contract_id' => null],
            'snapshot_hash' => hash('sha256', 'original-document'),
        ]);
        $documentBefore = $document->fresh()->getRawOriginal();
        app(JournalContractAssignmentService::class)->assign($journal, $entry, 'work-volumes', $row->id, $first->id, 'Уточнён договор выполнения', $user);
        self::assertSame($fact->id, $entry->completedWorks()->firstOrFail()->id);
        self::assertSame($first->id, $fact->fresh()->contract_id);
        self::assertSame($entryBefore, $entry->fresh()->getRawOriginal());
        self::assertSame($eventsBefore, $entry->approvalEvents()->count());
        self::assertSame($documentBefore, $document->fresh()->getRawOriginal());
        $this->assertDatabaseHas('journal_contract_assignment_events', ['row_id' => $row->id, 'new_contract_id' => $first->id, 'actor_id' => $user->id]);
    }

    public function test_act_guard_failure_rolls_back_row_assignment(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $journal = $this->journal($user, $project);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 3, 'contract_id' => null]]);
        $this->approve($entry, $user);
        $row = $entry->workVolumes()->firstOrFail();
        $act = \App\Models\ContractPerformanceAct::create([
            'contract_id' => $first->id, 'project_id' => $project->id, 'act_document_number' => 'ROW-ACT',
            'act_date' => '2026-10-12', 'period_start' => '2026-10-01', 'period_end' => '2026-10-12',
            'amount' => 3000, 'status' => 'approved', 'is_approved' => true, 'created_by_user_id' => $user->id,
        ]);
        $entry->completedWorks()->firstOrFail()->performanceActs()->attach($act->id, [
            'included_quantity' => 3, 'included_amount' => 3000, 'currency' => 'RUB',
        ]);
        try {
            app(JournalContractAssignmentService::class)->assign($journal, $entry, 'work-volumes', $row->id, $first->id, 'Уточнение', $user);
            self::fail('Acted fact assignment was accepted');
        } catch (\App\Exceptions\BusinessLogicException) {
            self::assertNull($row->fresh()->contract_id);
            self::assertNull($entry->completedWorks()->firstOrFail()->contract_id);
            $this->assertDatabaseCount('journal_contract_assignment_events', 0);
        }
    }

    public function test_contract_filter_returns_only_matching_rows_and_counts(): void
    {
        [$user, $project, $item, $first, $second] = $this->fixture();
        $journal = $this->journal($user, $project);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 2, 'contract_id' => $first->id], ['quantity' => 3, 'contract_id' => $second->id], ['quantity' => 1, 'contract_id' => null]]);
        $queries = app(JournalEntryQueryService::class);
        $summary = $queries->summary($journal, ['contract_id' => $first->id]);
        self::assertSame(1, $summary['total_entries']);
        self::assertSame(1, $summary['total_rows']);
        self::assertSame(0, $summary['unassigned_rows']);
        self::assertSame(1, $queries->summary($journal, ['unassigned_only' => 'true'])['total_rows']);
        self::assertSame($entry->id, $queries->query($journal, ['contract_id' => $first->id])->firstOrFail()->id);
    }

    public function test_contract_of_another_project_is_rejected(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $first->update(['project_id' => Project::factory()->create(['organization_id' => $project->organization_id])->id]);
        $journal = $this->journal($user, $project);
        $this->expectException(DomainException::class);
        $this->entry($user, $journal, $item, [['quantity' => 2, 'contract_id' => $first->id]]);
    }

    public function test_unassigned_journal_fact_cannot_be_selected_for_either_contract_act(): void
    {
        [$user, $project, $item, $first, $second] = $this->fixture();
        $journal = $this->journal($user, $project);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 3, 'contract_id' => null]]);
        $this->approve($entry, $user);
        $eligibility = app(\App\Services\Acting\CompletedWorkActEligibilityService::class);
        self::assertSame(0, $eligibility->query($first->id, '2026-10-01', '2026-10-31')->count());
        self::assertSame(0, $eligibility->query($second->id, '2026-10-01', '2026-10-31')->count());
        $row = $entry->workVolumes()->firstOrFail();
        app(JournalContractAssignmentService::class)->assign($journal, $entry, 'work-volumes', $row->id, $first->id, 'Назначен договор', $user);
        self::assertSame(1, $eligibility->query($first->id, '2026-10-01', '2026-10-31')->count());
        self::assertSame(0, $eligibility->query($second->id, '2026-10-01', '2026-10-31')->count());
    }

    public function test_manual_resource_fact_is_idempotent_and_is_not_a_production_volume(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $journal = $this->journal($user, $project);
        $entry = app(ConstructionJournalService::class)->createEntry($journal, [
            'estimate_id' => $item->estimate_id, 'entry_date' => '2026-10-12', 'work_description' => 'Работы и материалы',
            'work_volumes' => [['estimate_item_id' => $item->id, 'quantity' => 3, 'contract_id' => $first->id]],
            'materials' => [['material_name' => 'Цемент', 'quantity' => 100, 'measurement_unit' => 'кг', 'contract_id' => null]],
        ], $user);
        $this->approve($entry, $user);
        $material = $entry->materials()->firstOrFail();
        $fact = $entry->completedWorks()->where('journal_material_id', $material->id)->firstOrFail();
        app(JournalContractAssignmentService::class)->assign($journal, $entry, 'materials', $material->id, $first->id, 'Уточнено использование материала', $user);
        app(CompletedWorkFactService::class)->syncFromJournalEntry($entry->fresh());
        self::assertSame($fact->id, $entry->completedWorks()->where('journal_material_id', $material->id)->firstOrFail()->id);
        self::assertSame(2, $entry->completedWorks()->count());
        self::assertSame(1, $entry->completedWorks()->physicalFacts()->count());
        self::assertSame(3.0, (float) $entry->completedWorks()->physicalFacts()->sum('quantity'));
        self::assertNull($material->fresh()->warehouse_movement_id);
    }

    public function test_late_assignment_requires_approval_permission_and_preserves_row_on_denial(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $journal = $this->journal($user, $project);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 3, 'contract_id' => null]]);
        $this->approve($entry, $user);
        $row = $entry->workVolumes()->firstOrFail();
        $this->deniedUser = $user->id;
        try {
            app(JournalContractAssignmentService::class)->assign($journal, $entry, 'work-volumes', $row->id, $first->id, 'Уточнение', $user);
            self::fail('Assignment without approval permission succeeded');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertNull($row->fresh()->contract_id);
            self::assertNull($entry->completedWorks()->firstOrFail()->contract_id);
            $this->assertDatabaseCount('journal_contract_assignment_events', 0);
        }
    }

    public function test_contract_can_be_changed_and_removed_without_creating_another_fact(): void
    {
        [$user, $project, $item, $first, $second] = $this->fixture();
        $journal = $this->journal($user, $project);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 3, 'contract_id' => $first->id]]);
        $this->approve($entry, $user);
        $row = $entry->workVolumes()->firstOrFail();
        $fact = $entry->completedWorks()->firstOrFail();
        $service = app(JournalContractAssignmentService::class);
        $service->assign($journal, $entry, 'work-volumes', $row->id, $second->id, 'Другой исполнитель', $user);
        self::assertSame($second->id, $fact->fresh()->contract_id);
        $service->assign($journal, $entry, 'work-volumes', $row->id, null, 'Снятие назначения', $user);
        self::assertNull($fact->fresh()->contract_id);
        self::assertNull($fact->fresh()->contractor_id);
        self::assertSame($fact->id, $entry->completedWorks()->firstOrFail()->id);
        self::assertSame(1, $entry->completedWorks()->count());
        $this->assertDatabaseCount('journal_contract_assignment_events', 2);
    }

    public function test_project_pivot_is_supported_but_foreign_organization_is_rejected(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $first->update(['project_id' => null]);
        $first->projects()->attach($project->id);
        $journal = $this->journal($user, $project);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 2, 'contract_id' => $first->id]]);
        self::assertSame($first->id, $entry->workVolumes()->firstOrFail()->contract_id);
        $first->update(['organization_id' => Organization::factory()->create()->id]);
        $this->expectException(DomainException::class);
        $this->entry($user, $journal, $item, [['quantity' => 2, 'contract_id' => $first->id]]);
    }

    public function test_backfill_uses_facts_including_null_and_preserves_historical_accounting(): void
    {
        [$user, $project, $item, $first, $second] = $this->fixture();
        $journal = $this->journal($user, $project, $first);
        $draft = $this->entry($user, $journal, $item, [['quantity' => 1]]);
        $draft->completedWorks()->delete();
        $approved = $this->entry($user, $journal, $item, [['quantity' => 2, 'contract_id' => $second->id], ['quantity' => 1, 'contract_id' => null]]);
        $this->approve($approved, $user);
        $missing = $this->entry($user, $journal, $item, [['quantity' => 1]]);
        $missing->completedWorks()->delete();
        $missing->update(['status' => 'approved']);
        $factsBefore = $approved->completedWorks()->get()->map->getAttributes()->all();
        $rowIds = $approved->workVolumes()->orderBy('id')->pluck('id');
        $migration = require database_path('migrations/2026_10_12_120000_add_contract_assignment_to_journal_rows.php');
        $migration->down();
        \Illuminate\Support\Facades\Log::spy();
        $migration->up();
        self::assertSame($first->id, $draft->workVolumes()->firstOrFail()->contract_id);
        self::assertSame($second->id, $approved->workVolumes()->findOrFail($rowIds[0])->contract_id);
        self::assertNull($approved->workVolumes()->findOrFail($rowIds[1])->contract_id);
        self::assertNull($missing->workVolumes()->firstOrFail()->contract_id);
        self::assertSame($factsBefore, $approved->completedWorks()->get()->map->getAttributes()->all());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('notice')->with('construction_journal.contract_assignment_review', \Mockery::on(
            fn (array $context): bool => $context['row_type'] === 'journal_work_volumes' && in_array($missing->workVolumes()->firstOrFail()->id, $context['row_ids'], true)
        ));
    }

    public function test_late_assignment_updates_contract_progress_and_ks6a(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $journal = $this->journal($user, $project);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 3, 'contract_id' => null]]);
        $this->approve($entry, $user);
        $row = $entry->workVolumes()->firstOrFail();
        $this->mock(AuthorizationService::class, static function ($mock): void {
            $mock->shouldReceive('can')->andReturnUsing(static fn (User $actor, string $permission): bool => $permission !== 'contracts.performance_acts.view');
            $mock->shouldReceive('hasRole')->andReturnFalse();
        });
        $links = $first->contractEstimateItems()->get();
        $progress = app(\App\BusinessModules\Features\ContractManagement\Services\ContractEstimateOperationalProgress::class);
        $progress->prepare($first, $links, $user);
        self::assertSame(0.0, $links->first()->getRelation('operationalProgress')['actual_quantity']);
        $exporter = app(\App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService::class);
        $prepare = new \ReflectionMethod($exporter, 'prepareKS6aData');
        self::assertSame(0.0, (float) $prepare->invoke($exporter, $first, '2026-10-01', '2026-10-31')['total_fact_amount']);
        app(JournalContractAssignmentService::class)->assign($journal, $entry, 'work-volumes', $row->id, $first->id, 'Договор уточнён', $user);
        $progress->prepare($first, $links, $user);
        self::assertSame(3.0, $links->first()->getRelation('operationalProgress')['actual_quantity']);
        self::assertSame(3000.0, (float) $prepare->invoke($exporter, $first, '2026-10-01', '2026-10-31')['total_fact_amount']);
    }

    public function test_external_confirmed_fact_uses_effective_quantity_in_project_remaining(): void
    {
        [$user, $project, $item] = $this->fixture();
        $item->update(['quantity_total' => 10]);
        $journal = $this->journal($user, $project);
        $this->externalFact($user, $project, $item, null);
        $options = app(\App\Services\ConstructionJournal\ConstructionJournalFormOptionsService::class)->build($user, $journal);
        self::assertSame(2.0, $options['estimates'][0]['items'][0]['remaining_quantity']);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 3, 'contract_id' => null]]);
        $this->expectException(DomainException::class);
        $this->approve($entry, $user);
    }

    public function test_external_confirmed_contract_fact_reduces_contract_remaining_without_double_counting_journal_facts(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $journal = $this->journal($user, $project);
        $this->externalFact($user, $project, $item, $first);
        $entry = $this->entry($user, $journal, $item, [['quantity' => 1, 'contract_id' => $first->id]]);
        $this->approve($entry, $user);
        $options = app(\App\Services\ConstructionJournal\ConstructionJournalFormOptionsService::class)->build($user, $journal);
        $option = $options['estimates'][0]['items'][0];
        self::assertSame(21.0, $option['remaining_quantity']);
        $link = collect($option['contract_links'])->firstWhere('contract_id', $first->id);
        self::assertSame(1.0, $link['remaining_quantity']);
        $next = $this->entry($user, $this->journal($user, $project), $item, [['quantity' => 2, 'contract_id' => $first->id]]);
        $this->expectException(DomainException::class);
        $this->approve($next, $user);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_competing_submissions_and_approvals_cannot_exceed_the_contract_quantity(): void
    {
        [$user, $project, $item, $first] = $this->fixture();
        $entries = [];
        foreach ([1, 2] as $number) {
            $entries[] = $this->entry($user, $this->journal($user, $project), $item, [['quantity' => 7, 'contract_id' => $first->id]]);
        }
        DB::commit();
        $connection = config('database.connections.pgsql');
        $raceName = 'journal-quantity-'.bin2hex(random_bytes(5));
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
            'LOG_CHANNEL' => 'stderr', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'LEGAL_ARCHIVE_AUDIT_WRITER_SECRET' => (string) config('legal_archive.audit_writer_secret'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
            'MOST_RACE_NAME' => $raceName,
        ]);
        $workers = [];
        DB::beginTransaction();
        try {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            foreach ($entries as $entry) {
                $worker = new \Symfony\Component\Process\Process([
                    PHP_BINARY, base_path('tests/Support/ConstructionJournal/approve_entry_worker.php'),
                    (string) $entry->id, (string) $user->id,
                ], base_path(), $environment, timeout: 45);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', $raceName)->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        self::fail($worker->getErrorOutput().$worker->getOutput());
                    }
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['success']), json_encode($results));
            $entryIds = array_map(static fn (ConstructionJournalEntry $entry): int => $entry->id, $entries);
            self::assertSame(1, ConstructionJournalEntry::query()->whereIn('id', $entryIds)->where('status', 'approved')->count());
            self::assertSame(1, ConstructionJournalEntry::query()->whereIn('id', $entryIds)->where('status', 'draft')->count());
            self::assertSame(7.0, (float) \App\Models\CompletedWork::query()->whereIn('journal_entry_id', $entryIds)->confirmed()->sum('quantity'));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::beginTransaction();
        }
    }

    private function externalFact(User $user, Project $project, EstimateItem $item, ?Contract $contract): void
    {
        \App\Models\CompletedWork::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'contract_id' => $contract?->id, 'estimate_item_id' => $item->id, 'work_type_id' => $item->work_type_id,
            'user_id' => $user->id, 'work_origin_type' => \App\Models\CompletedWork::ORIGIN_MANUAL,
            'planning_status' => \App\Models\CompletedWork::PLANNING_PLANNED,
            'quantity' => 9, 'completed_quantity' => 8, 'price' => 1000, 'total_amount' => 8000,
            'completion_date' => '2026-10-12', 'status' => 'confirmed',
        ]);
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $unit = MeasurementUnit::query()->where('organization_id', $organization->id)->where('short_name', 'м³')->firstOrFail();
        $workTypeId = DB::table('work_types')->insertGetId(['organization_id' => $organization->id, 'name' => 'Бетонирование', 'measurement_unit_id' => $unit->id, 'category' => 'construction', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $estimate = Estimate::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Смета', 'number' => 'ROW-EST', 'estimate_date' => '2026-10-12', 'status' => 'approved', 'total_amount' => 30000]);
        $item = EstimateItem::create(['estimate_id' => $estimate->id, 'position_number' => '1', 'item_type' => 'work', 'name' => 'Бетонирование', 'work_type_id' => $workTypeId, 'measurement_unit_id' => $unit->id, 'quantity' => 30, 'quantity_total' => 30, 'unit_price' => 1000, 'total_amount' => 30000]);
        $schedule = \App\Models\ProjectSchedule::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'created_by_user_id' => $user->id, 'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-12-31', 'name' => 'График', 'status' => 'active']);
        \App\Models\ScheduleTask::create(['organization_id' => $organization->id, 'schedule_id' => $schedule->id, 'created_by_user_id' => $user->id, 'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-12-31', 'estimate_item_id' => $item->id, 'work_type_id' => $workTypeId, 'measurement_unit_id' => $unit->id, 'name' => $item->name, 'task_type' => 'task', 'quantity' => 30, 'completed_quantity' => 0, 'progress_percent' => 0, 'status' => 'not_started', 'priority' => 'normal', 'level' => 0, 'sort_order' => 1]);
        $contractor = Contractor::create(['organization_id' => $organization->id, 'name' => 'Подрядчик']);
        $contracts = [];
        foreach ([1, 2] as $number) {
            $contract = Contract::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'ROW-'.$number, 'contract_side_type' => 'subcontract', 'requires_contract_side_review' => false, 'date' => '2026-10-01', 'subject' => 'Работы', 'total_amount' => 10000, 'status' => 'active']);
            ContractEstimateItem::create(['contract_id' => $contract->id, 'estimate_id' => $estimate->id, 'estimate_item_id' => $item->id, 'quantity' => 10, 'amount' => 10000]);
            $contracts[] = $contract;
        }

        return [$user, $project, $item, ...$contracts];
    }

    private function journal(User $user, Project $project, ?Contract $contract = null): ConstructionJournal
    {
        return app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract?->id], $user);
    }

    private function entry(User $user, ConstructionJournal $journal, EstimateItem $item, array $rows): ConstructionJournalEntry
    {
        return app(ConstructionJournalService::class)->createEntry($journal, ['estimate_id' => $item->estimate_id, 'entry_date' => '2026-10-12', 'work_description' => 'Выполнены работы', 'work_volumes' => array_map(static fn (array $row): array => ['estimate_item_id' => $item->id, ...$row], $rows)], $user);
    }

    private function approve(ConstructionJournalEntry $entry, User $user): void
    {
        $entry->update(['status' => 'submitted']);
        app(JournalApprovalService::class)->approve($entry->fresh(), $user);
    }
}
