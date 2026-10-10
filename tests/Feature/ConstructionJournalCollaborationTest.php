<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ProjectOrganizationRole;
use App\Models\ConstructionJournal;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ConstructionJournal\ConstructionJournalAccessService;
use App\Services\Project\ProjectParticipantService;
use App\Services\Workflow\WorkflowGuardService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class ConstructionJournalCollaborationTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(false);
        });
        Event::fake([
            \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntrySubmitted::class,
            \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryApproved::class,
            \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryRejected::class,
            \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryCreated::class,
            \App\BusinessModules\Features\BudgetEstimates\Events\JournalWorkVolumesRecorded::class,
        ]);
        Notification::fake();
    }

    public function test_own_and_ancestors_read_but_only_direct_parent_approves(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $entry = $this->manualEntry($journal, $users[3]);
        $access = app(ConstructionJournalAccessService::class);

        self::assertSame($organizations[0]->id, $journal->organization_id);
        self::assertSame($organizations[3]->id, $journal->performing_organization_id);
        foreach ([0, 1, 2, 3] as $index) {
            self::assertTrue($access->canRead($users[$index], $journal));
        }
        self::assertFalse($access->canRead($users[4], $journal));
        self::assertTrue($access->canApprove($users[2], $entry));
        self::assertFalse($access->canApprove($users[0], $entry));
        self::assertFalse($access->canApprove($users[1], $entry));
        self::assertFalse($access->canApprove($users[3], $entry));
        self::assertFalse($access->canWrite($users[2], $journal));

        $users[2]->organizations()->attach($organizations[3]->id, ['is_active' => true]);
        $users[2]->current_organization_id = $organizations[3]->id;
        self::assertSame($organizations[3]->id, $journal->fresh()->performing_organization_id);
    }

    public function test_assigned_project_membership_and_active_company_are_required(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $access = app(ConstructionJournalAccessService::class);
        DB::table('organization_user')->where('user_id', $users[2]->id)->update(['project_access_mode' => 'assigned_projects']);
        self::assertFalse($access->canRead($users[2], $journal));
        $project->users()->attach($users[2]->id, ['is_active' => true]);
        self::assertTrue($access->canRead($users[2], $journal));
        DB::table('organization_user')->where('user_id', $users[2]->id)->update(['is_active' => false]);
        self::assertFalse($access->canRead($users[2], $journal));
        $organizations[3]->update(['is_active' => false]);
        self::assertFalse($access->canRead($users[0], $journal));
    }

    public function test_manual_work_can_be_rejected_resubmitted_and_confirmed_without_estimate_or_schedule(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $entry = $this->manualEntry($journal, $users[3]);
        self::assertSame([], app(WorkflowGuardService::class)->journalEntryBlockers($entry));
        $approval = app(JournalApprovalService::class);
        $entry = $approval->submitForApproval($entry, $users[3]);
        $entry = $approval->reject($entry, $users[2], 'Исправить объём');
        $entry = app(ConstructionJournalService::class)->updateEntry($entry, ['work_description' => 'Уточнено'], $users[3]);
        $entry = $approval->submitForApproval($entry, $users[3]);
        $entry = $approval->approve($entry, $users[2]);

        self::assertSame('approved', $entry->status->value);
        $fact = $entry->completedWorks()->firstOrFail();
        self::assertSame($organizations[0]->id, $fact->organization_id);
        self::assertSame('confirmed', $fact->status);
        self::assertNull($fact->estimate_item_id);
        self::assertNull($fact->price);
        self::assertNull($fact->total_amount);
        self::assertSame('Монтаж ограждения', $fact->additional_info['work_name']);
        self::assertNotEmpty($fact->additional_info['unit_of_measurement']);
        self::assertSame([$organizations[3]->id, $organizations[2]->id, $organizations[3]->id, $organizations[2]->id], $entry->approvalEvents()->orderBy('id')->pluck('actor_organization_id')->all());
    }

    public function test_external_approver_cannot_approve_own_entry_even_if_company_owner(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $entry = $this->manualEntry($journal, $users[3]);
        $users[3]->organizations()->attach($organizations[2]->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);
        $users[3]->current_organization_id = $organizations[2]->id;
        self::assertFalse(app(ConstructionJournalAccessService::class)->canApprove($users[3], $entry));
    }

    public function test_unconfigured_tree_keeps_own_draft_but_blocks_submission(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture(false);
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $entry = $this->manualEntry($journal, $users[3]);
        self::assertTrue(app(ConstructionJournalAccessService::class)->canRead($users[3], $journal));
        self::assertFalse(app(ConstructionJournalAccessService::class)->canRead($users[0], $journal));
        try {
            app(JournalApprovalService::class)->submitForApproval($entry, $users[3]);
            self::fail('Нужна настройка иерархии');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('construction_journal.errors.hierarchy_required'), $exception->getMessage());
        }
        self::assertSame('draft', $entry->fresh()->status->value);
    }

    public function test_invalid_manual_volume_is_rejected_before_entry_is_saved(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $unit = MeasurementUnit::query()->where('organization_id', $organizations[0]->id)->firstOrFail();
        foreach ([['quantity' => 1], ['work_name' => 'Работа', 'quantity' => 0, 'measurement_unit_id' => $unit->id], ['work_name' => 'Работа', 'quantity' => 1]] as $volume) {
            try {
                app(ConstructionJournalService::class)->createEntry($journal, ['entry_date' => '2026-10-10', 'work_description' => 'Работа', 'work_volumes' => [$volume]], $users[3]);
                self::fail('Неполная ручная работа должна быть отклонена');
            } catch (DomainException) {
                self::assertSame(0, $journal->entries()->count());
            }
        }
        $foreignUnit = MeasurementUnit::query()->where('organization_id', $organizations[3]->id)->firstOrFail();
        try {
            app(ConstructionJournalService::class)->createEntry($journal, [
                'entry_date' => '2026-10-10', 'work_description' => 'Работа',
                'work_volumes' => [['work_name' => 'Работа', 'quantity' => 1, 'measurement_unit_id' => $foreignUnit->id]],
            ], $users[3]);
            self::fail('Чужая единица измерения не относится к учётной организации');
        } catch (DomainException) {
            self::assertSame(0, $journal->entries()->count());
        }
    }

    public function test_current_parent_is_resolved_again_after_hierarchy_changes(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $entry = $this->manualEntry($journal, $users[3]);
        $entry = app(JournalApprovalService::class)->submitForApproval($entry, $users[3]);
        app(ProjectParticipantService::class)->saveHierarchy($project, $organizations[0]->id, [
            ['organization_id' => $organizations[1]->id, 'parent_organization_id' => $organizations[0]->id],
            ['organization_id' => $organizations[2]->id, 'parent_organization_id' => $organizations[1]->id],
            ['organization_id' => $organizations[3]->id, 'parent_organization_id' => $organizations[1]->id],
            ['organization_id' => $organizations[4]->id, 'parent_organization_id' => $organizations[0]->id],
        ]);
        self::assertFalse(app(ConstructionJournalAccessService::class)->canApprove($users[2], $entry));
        self::assertTrue(app(ConstructionJournalAccessService::class)->canApprove($users[1], $entry));
        self::assertSame('approved', app(JournalApprovalService::class)->approve($entry, $users[1])->status->value);
    }

    public function test_pending_notification_only_targets_project_accessible_direct_parent_members(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $entry = $this->manualEntry($journal, $users[3]);
        $unassigned = User::factory()->create(['current_organization_id' => $organizations[2]->id]);
        $unassigned->organizations()->attach($organizations[2]->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        app(\App\BusinessModules\Features\BudgetEstimates\Listeners\NotifyAboutPendingApprovals::class)->handle(new \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntrySubmitted($entry));
        Notification::assertSentTo($users[2], \App\Notifications\Journal\JournalEntryPendingApprovalNotification::class);
        foreach ([$users[0], $users[1], $users[3], $users[4], $unassigned] as $user) {
            Notification::assertNotSentTo($user, \App\Notifications\Journal\JournalEntryPendingApprovalNotification::class);
        }
    }

    public function test_ready_pdf_can_be_read_by_ancestor_and_is_hidden_from_sibling(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $export = \App\Models\JournalExport::create([
            'organization_id' => $journal->organization_id, 'project_id' => $project->id,
            'journal_id' => $journal->id, 'requested_by_user_id' => $users[3]->id,
            'type' => 'extended', 'format' => 'pdf', 'status' => 'completed', 'progress' => 100,
            'options' => [], 'result_path' => 'exports/ancestor-journal.pdf',
            'idempotency_key' => 'collaboration-pdf', 'request_fingerprint' => str_repeat('a', 64),
        ]);
        $this->mock(\App\Services\Storage\FileService::class, function ($mock): void {
            $mock->shouldReceive('temporaryUrl')->once()->with('exports/ancestor-journal.pdf', 15)->andReturn('https://example.test/ancestor-journal.pdf');
        });
        $workflow = app(\App\Services\ConstructionJournal\JournalExportWorkflowService::class);
        $payload = $workflow->payload($export, $users[0]);
        self::assertSame('completed', $payload['status']);
        self::assertSame('https://example.test/ancestor-journal.pdf', $payload['url']);
        $this->expectException(AuthorizationException::class);
        $workflow->payload($export, $users[4]);
    }

    public function test_root_company_retains_internal_owner_approval(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Корневой журнал', 'contract_id' => $contract->id], $users[0]);
        $entry = $this->manualEntry($journal, $users[0]);
        self::assertTrue(app(ConstructionJournalAccessService::class)->canApprove($users[0], $entry));
        self::assertSame('internal', app(ConstructionJournalAccessService::class)->approvalContext($journal)['mode']);
    }

    public function test_ready_general_pdf_requires_snapshot_document_access_before_issuing_url(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $otherJournal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Другой журнал', 'contract_id' => $contract->id], $users[3]);
        $version = \App\Models\GeneralJournalDocumentVersion::create([
            'organization_id' => $journal->organization_id, 'project_id' => $project->id,
            'journal_id' => $journal->id, 'created_by_user_id' => $users[3]->id,
            'revision' => 1, 'operation_key' => 'protected-general-pdf',
            'request_fingerprint' => str_repeat('a', 64), 'template_version' => 'test',
            'source_snapshot' => ['sources' => ['documents' => [['id' => 1, 'name' => 'Исполнительный документ']]]],
            'snapshot_hash' => str_repeat('b', 64),
        ]);
        $export = \App\Models\JournalExport::create([
            'organization_id' => $journal->organization_id, 'project_id' => $project->id,
            'journal_id' => $journal->id, 'requested_by_user_id' => $users[3]->id,
            'type' => 'general', 'format' => 'pdf', 'status' => 'completed', 'progress' => 100,
            'options' => ['document_version_id' => $version->id], 'result_path' => 'exports/general.pdf',
            'idempotency_key' => 'protected-general-pdf', 'request_fingerprint' => str_repeat('c', 64),
        ]);
        $this->mock(AuthorizationService::class, function ($mock) use ($users, $project): void {
            $mock->shouldReceive('hasRole')->andReturn(false);
            $mock->shouldReceive('can')->andReturnUsing(function ($user, string $permission, array $context) use ($users, $project): bool {
                self::assertSame((int) $user->current_organization_id, $context['organization_id']);
                self::assertSame((int) $project->id, $context['project_id']);
                self::assertTrue($context['strict_project_scope']);

                return $permission === 'construction-journal.view'
                    || ($permission === 'executive-documentation.view' && $user->id === $users[0]->id);
            });
        });
        $this->mock(\App\Services\Storage\FileService::class, function ($mock): void {
            $mock->shouldReceive('temporaryUrl')->once()->with('exports/general.pdf', 15)->andReturn('https://example.test/general.pdf');
        });
        $this->app->bind(\App\Models\JournalExport::class, fn () => $export->fresh());
        $this->withoutMiddleware()->actingAs($users[1], 'api_admin')
            ->getJson('/api/v1/admin/construction-journal-exports/'.$export->id)
            ->assertForbidden()->assertJsonPath('success', false);
        $this->actingAs($users[0], 'api_admin')
            ->getJson('/api/v1/admin/construction-journal-exports/'.$export->id)
            ->assertOk()->assertJsonPath('data.url', 'https://example.test/general.pdf');
        $export->journal_id = $otherJournal->id;
        $this->expectException(DomainException::class);
        app(\App\Services\ConstructionJournal\JournalExportWorkflowService::class)->payload($export, $users[0]);
    }

    public function test_single_company_without_hierarchy_retains_internal_workflow(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture(false);
        DB::table('project_organization')->where('project_id', $project->id)->update(['is_active' => false]);
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Один участник', 'contract_id' => $contract->id], $users[0]);
        $entry = $this->manualEntry($journal, $users[0]);
        $entry = app(JournalApprovalService::class)->submitForApproval($entry, $users[0]);
        self::assertSame('approved', app(JournalApprovalService::class)->approve($entry, $users[0])->status->value);
    }

    public function test_disabled_parent_blocks_review_and_keeps_own_draft_editable(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $entry = $this->manualEntry($journal, $users[3]);
        $organizations[2]->update(['is_active' => false]);
        $access = app(ConstructionJournalAccessService::class);
        self::assertTrue($access->canRead($users[3], $journal));
        self::assertTrue($access->canWrite($users[3], $journal));
        self::assertSame('unconfigured', $access->approvalContext($journal)['mode']);
        self::assertSame(trans_message('construction_journal.errors.hierarchy_inactive_organization'), $access->approvalContext($journal)['message']);
        self::assertFalse($access->canApprove($users[1], $entry));
        try {
            app(JournalApprovalService::class)->submitForApproval($entry, $users[3]);
            self::fail('Неактивная родительская организация блокирует отправку');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('construction_journal.errors.hierarchy_inactive_organization'), $exception->getMessage());
        }
        self::assertSame('draft', $entry->fresh()->status->value);
        self::assertSame(0, $entry->approvalEvents()->count());
    }

    public function test_general_document_without_export_permission_returns_expected_forbidden_response(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturnUsing(fn ($user, string $permission): bool => $permission === 'construction-journal.view');
            $mock->shouldReceive('hasRole')->andReturn(false);
        });
        $this->app->bind(ConstructionJournal::class, fn () => $journal->fresh());
        $this->withoutMiddleware()->actingAs($users[3], 'api_admin')
            ->getJson('/api/v1/admin/construction-journals/'.$journal->id.'/general-document')
            ->assertForbidden()->assertJsonPath('success', false)->assertJsonPath('message', trans_message('general_journal.access_denied'));
    }

    public function test_service_rejects_ancestor_edit_and_permissionless_actor(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $entry = $this->manualEntry($journal, $users[3]);
        try {
            app(ConstructionJournalService::class)->updateEntry($entry, ['work_description' => 'Недопустимо'], $users[2]);
            self::fail('Вышестоящая организация не редактирует чужую запись');
        } catch (AuthorizationException) {
            self::assertSame('Монтаж ограждения', $entry->fresh()->work_description);
        }
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturn(false);
            $mock->shouldReceive('hasRole')->andReturn(false);
        });
        self::assertFalse(app(ConstructionJournalAccessService::class)->canRead($users[3], $journal));
    }

    public function test_http_entry_edit_preserves_all_child_row_ids(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $journal = app(ConstructionJournalService::class)->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $entry = $this->manualEntry($journal, $users[3]);
        $entry = app(ConstructionJournalService::class)->updateEntry($entry, [
            'workers' => [['specialty' => 'Монтажник', 'workers_count' => 2]],
            'equipment' => [['equipment_name' => 'Кран', 'quantity' => 1]],
            'materials' => [['material_name' => 'Крепёж', 'quantity' => 3, 'measurement_unit' => 'шт']],
        ], $users[3]);
        $payload = [
            'quality_notes' => 'Проверено',
            'work_volumes' => [$entry->workVolumes()->firstOrFail()->only(['id', 'work_name', 'quantity', 'measurement_unit_id', 'notes'])],
            'workers' => [$entry->workers()->firstOrFail()->only(['id', 'specialty', 'workers_count', 'hours_worked'])],
            'equipment' => [$entry->equipment()->firstOrFail()->only(['id', 'equipment_name', 'quantity', 'hours_used'])],
            'materials' => [$entry->materials()->firstOrFail()->only(['id', 'material_name', 'quantity', 'measurement_unit', 'notes'])],
        ];
        $this->app->bind(\App\Models\ConstructionJournalEntry::class, fn () => $entry->fresh());
        $this->withoutMiddleware()->actingAs($users[3], 'api_admin')
            ->putJson('/api/v1/admin/journal-entries/'.$entry->id, $payload)->assertOk();
        foreach (['work_volumes' => 'workVolumes', 'workers' => 'workers', 'equipment' => 'equipment', 'materials' => 'materials'] as $key => $relation) {
            self::assertSame([(int) $payload[$key][0]['id']], $entry->{$relation}()->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        }
        self::assertSame('Проверено', $entry->fresh()->quality_notes);
    }

    public function test_foreign_child_ids_are_rejected_before_update_or_create(): void
    {
        [$project, $organizations, $users, $contract] = $this->fixture();
        $service = app(ConstructionJournalService::class);
        $journal = $service->createJournal($project, ['name' => 'Журнал', 'contract_id' => $contract->id], $users[3]);
        $otherJournal = $service->createJournal($project, ['name' => 'Другой журнал', 'contract_id' => $contract->id], $users[3]);
        $entry = $this->manualEntry($journal, $users[3]);
        $foreign = $service->updateEntry($this->manualEntry($otherJournal, $users[3]), [
            'workers' => [['specialty' => 'Монтажник', 'workers_count' => 2]],
            'equipment' => [['equipment_name' => 'Кран', 'quantity' => 1]],
            'materials' => [['material_name' => 'Крепёж', 'quantity' => 3, 'measurement_unit' => 'шт']],
        ], $users[3]);
        $foreignRows = [
            'work_volumes' => $foreign->workVolumes()->firstOrFail()->only(['id', 'work_name', 'quantity', 'measurement_unit_id']),
            'workers' => $foreign->workers()->firstOrFail()->only(['id', 'specialty', 'workers_count']),
            'equipment' => $foreign->equipment()->firstOrFail()->only(['id', 'equipment_name', 'quantity']),
            'materials' => $foreign->materials()->firstOrFail()->only(['id', 'material_name', 'quantity', 'measurement_unit']),
        ];
        foreach ($foreignRows as $key => $row) {
            foreach (['update', 'create'] as $operation) {
                try {
                    $data = ['entry_date' => '2026-10-10', 'work_description' => 'Подмена', $key => [$row]];
                    if ($operation === 'update') {
                        $service->updateEntry($entry, $data, $users[3]);
                    } else {
                        $service->createEntry($journal, $data, $users[3]);
                    }
                    self::fail('Чужой ID строки отклоняется до сохранения');
                } catch (DomainException $exception) {
                    self::assertSame(trans_message('construction_journal.errors.access_denied'), $exception->getMessage());
                }
                self::assertSame('Монтаж ограждения', $entry->fresh()->work_description);
                self::assertSame(1, $journal->entries()->count());
            }
        }
        self::assertSame('Монтаж ограждения', $foreign->fresh()->work_description);
        self::assertSame(2, $foreign->workers()->firstOrFail()->workers_count);
    }

    private function manualEntry(ConstructionJournal $journal, User $user): \App\Models\ConstructionJournalEntry
    {
        $unit = MeasurementUnit::query()->where('organization_id', $journal->organization_id)->firstOrFail();

        return app(ConstructionJournalService::class)->createEntry($journal, [
            'entry_date' => '2026-10-10', 'work_description' => 'Монтаж ограждения',
            'work_volumes' => [['work_name' => 'Монтаж ограждения', 'quantity' => 2, 'measurement_unit_id' => $unit->id]],
        ], $user);
    }

    private function fixture(bool $configured = true): array
    {
        $organizations = [];
        $users = [];
        for ($index = 0; $index < 5; $index++) {
            $organization = Organization::factory()->create(['is_active' => true]);
            $user = User::factory()->create(['current_organization_id' => $organization->id]);
            $user->organizations()->attach($organization->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);
            $organizations[] = $organization;
            $users[] = $user;
        }
        $project = Project::factory()->create(['organization_id' => $organizations[0]->id, 'is_archived' => false]);
        foreach ([1, 2, 3, 4] as $index) {
            $project->organizations()->attach($organizations[$index]->id, [
                'is_active' => true,
                'role' => 'child_contractor',
                'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value,
            ]);
        }
        if ($configured) {
            app(ProjectParticipantService::class)->saveHierarchy($project, $organizations[0]->id, [
                ['organization_id' => $organizations[1]->id, 'parent_organization_id' => $organizations[0]->id],
                ['organization_id' => $organizations[2]->id, 'parent_organization_id' => $organizations[1]->id],
                ['organization_id' => $organizations[3]->id, 'parent_organization_id' => $organizations[2]->id],
                ['organization_id' => $organizations[4]->id, 'parent_organization_id' => $organizations[0]->id],
            ]);
        }
        $contract = Contract::create([
            'organization_id' => $organizations[0]->id, 'project_id' => $project->id,
            'contractor_id' => Contractor::create(['organization_id' => $organizations[0]->id, 'name' => 'Подрядчик'])->id,
            'number' => 'COLLAB-1', 'date' => '2026-10-10', 'subject' => 'Работы',
            'total_amount' => 1000, 'status' => 'active',
        ]);

        return [$project, $organizations, $users, $contract];
    }
}
