<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\JournalWorkVolume;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\CompletedWork\CompletedWorkFactService;
use App\Services\ConstructionJournal\JournalEntryReportingService;
use App\Services\Mobile\MobileConstructionJournalService;
use App\Services\Project\ProjectParticipantService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class JournalEntryReportingTest extends TestCase
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
            $mock->shouldReceive('hasRole')->andReturnFalse();
        });
    }

    public function test_two_levels_report_the_same_facts_with_rejection_retry_and_no_duplicate_accounting(): void
    {
        [$source, $journals, $users] = $this->fixture();
        $before = CompletedWork::query()->get()->map->getAttributes()->all();
        $volumesBefore = JournalWorkVolume::query()->count();
        $reporting = app(JournalEntryReportingService::class);
        self::assertSame([$journals[1]->id], array_column($reporting->targets($users[1], $source), 'id'));
        $report = $reporting->include($users[1], $source, $journals[1]->id, ['comment' => 'Отчёт подрядчика']);
        self::assertSame($report->id, $reporting->include($users[1], $source, $journals[1]->id)->id);
        self::assertSame($source->id, $report->source_entry_id);
        self::assertSame(JournalEntryStatusEnum::DRAFT, $report->status);
        $payload = app(ConstructionJournalPayloadService::class)->mapEntry($report, $users[1]);
        self::assertSame(3.5, $payload['workVolumes'][0]['quantity']);
        self::assertSame('Бетонирование', $payload['workVolumes'][0]['work_name']);
        self::assertSame('Бетон', $payload['materials'][0]['material_name']);
        self::assertSame('Отчёт подрядчика', $payload['report_comment']);
        self::assertContains('submit', $payload['available_actions']);
        self::assertContains('update_report', $payload['available_actions']);
        self::assertNotContains('update', $payload['available_actions']);
        self::assertNotContains('upload_attachment', $payload['available_actions']);
        self::assertSame(3.5, app(MobileConstructionJournalService::class)->mapMobileEntry($report, $users[1])['workVolumes'][0]['quantity']);
        $approval = app(JournalApprovalService::class);
        $report = $approval->submitForApproval($report, $users[1]);
        $report = $approval->reject($report, $users[0], 'Уточните пояснение');
        $report = app(ConstructionJournalService::class)->updateEntry($report, ['quality_notes' => 'Пояснение исправлено'], $users[1]);
        $report = $approval->submitForApproval($report, $users[1]);
        $report = $approval->approve($report, $users[0]);
        $upper = $reporting->include($users[0], $report, $journals[0]->id);
        self::assertSame($report->id, $upper->source_entry_id);
        self::assertSame($source->id, $upper->source_entry_snapshot['origin']['id']);
        self::assertSame(3.5, $upper->source_entry_snapshot['workVolumes'][0]['quantity']);
        self::assertSame($before, CompletedWork::query()->get()->map->getAttributes()->all());
        self::assertSame($volumesBefore, JournalWorkVolume::query()->count());
        self::assertSame(0, $report->workVolumes()->count());
        self::assertSame(0, $report->materials()->count());
        self::assertSame(0, $report->completedWorks()->count());
        self::assertSame(JournalEntryStatusEnum::APPROVED, $source->fresh()->status);
        self::assertCount(4, $report->approvalEvents);
        self::assertSame((int) $users[0]->current_organization_id, $report->approvalEvents->last()->actor_organization_id);
    }

    #[DataProvider('deniedCases')]
    public function test_access_and_readiness_are_rechecked(string $case): void
    {
        [$source, $journals, $users, $project] = $this->fixture();
        $actor = $users[1];
        $target = $journals[1];
        match ($case) {
            'draft_source' => $source->update(['status' => 'draft']),
            'distant_parent' => $actor = $users[0],
            'sibling' => $actor = $users[3],
            'own_source' => $actor = $users[2],
            'inactive_participant' => $project->organizations()->updateExistingPivot($users[2]->current_organization_id, ['is_active' => false]),
            'inactive_membership' => $actor->organizations()->updateExistingPivot($actor->current_organization_id, ['is_active' => false]),
            'unassigned' => $project->users()->updateExistingPivot($actor->id, ['is_active' => false]),
            'no_permission' => $this->deniedUser = $actor->id,
            'foreign_target' => $target->update(['project_id' => Project::factory()->create(['organization_id' => $project->organization_id])->id]),
            'wrong_performer' => $target = $journals[0],
            'closed_target' => $target->update(['status' => 'closed']),
        };
        $count = ConstructionJournalEntry::query()->count();
        try {
            app(JournalEntryReportingService::class)->include($actor, $source, $target->id);
            self::fail('Forbidden include succeeded: '.$case);
        } catch (AuthorizationException|DomainException) {
            self::assertSame($count, ConstructionJournalEntry::query()->count());
        }
    }

    public static function deniedCases(): array
    {
        return array_map(static fn (string $case): array => [$case], ['draft_source', 'distant_parent', 'sibling', 'own_source', 'inactive_participant', 'inactive_membership', 'unassigned', 'no_permission', 'foreign_target', 'wrong_performer', 'closed_target']);
    }

    public function test_report_can_be_included_in_project_journal_without_default_contract(): void
    {
        [$source, $journals, $users] = $this->fixture();
        $journals[1]->update(['contract_id' => null]);
        $factsBefore = CompletedWork::query()->get()->map->getAttributes()->all();
        $service = app(JournalEntryReportingService::class);
        self::assertSame([$journals[1]->id], array_column($service->targets($users[1], $source), 'id'));
        $report = $service->include($users[1], $source, $journals[1]->id);
        $service->assertReady($report);
        self::assertSame($source->id, $report->source_entry_snapshot['origin']['id']);
        self::assertSame($factsBefore, CompletedWork::query()->get()->map->getAttributes()->all());
    }

    public function test_report_facts_cannot_be_changed_and_export_uses_snapshot_without_persisting_rows(): void
    {
        [$source, $journals, $users] = $this->fixture();
        $service = app(JournalEntryReportingService::class);
        $report = $service->include($users[1], $source, $journals[1]->id, ['comment' => 'Пояснение подрядчика для проверки']);
        try {
            app(ConstructionJournalService::class)->updateEntry($report, ['work_volumes' => []], $users[1]);
            self::fail('Reporting facts were mutable');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('construction_journal.errors.reporting_facts_immutable'), $exception->getMessage());
        }
        $display = $service->prepareForExport($report->load('journal.project', 'createdBy', 'approvedBy'));
        self::assertSame(3.5, (float) $display->workVolumes[0]->quantity);
        $html = view('estimates.exports.journal_daily_report', ['entry' => $display])->render();
        self::assertStringContainsString('Бетонирование', $html);
        self::assertStringContainsString('Бетон', $html);
        self::assertStringContainsString('Источник:', $html);
        self::assertStringContainsString('Пояснение подрядчика для проверки', $html);
        self::assertSame(0, $report->workVolumes()->count());
        self::assertSame(0, $report->materials()->count());
        app(CompletedWorkFactService::class)->syncFromJournalEntry($report);
        self::assertSame(0, $report->completedWorks()->count());
        $source->delete();
        $this->expectException(DomainException::class);
        $service->assertReady($report->fresh());
    }

    public function test_mobile_reporting_and_hierarchy_share_scope_and_validation(): void
    {
        [$source, $journals, $users, $project] = $this->fixture();
        $request = \Illuminate\Http\Request::create('/targets', 'GET');
        $request->setUserResolver(fn () => $users[1]);
        $response = app(\App\Http\Controllers\Api\V1\Mobile\JournalEntryReportingController::class)->targets($request, $source);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($journals[1]->id, $response->getData(true)['data'][0]['id']);
        $hierarchy = app(\App\Http\Controllers\Api\V1\Mobile\ProjectHierarchyController::class);
        $response = $hierarchy->show($request, $project->id);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->getData(true)['data']['configured']);
        self::assertTrue($response->getData(true)['data']['can_manage']);
        $request = \Illuminate\Http\Request::create('/hierarchy', 'PUT', ['root_organization_id' => $users[0]->current_organization_id, 'parents' => []]);
        $request->setUserResolver(fn () => $users[1]);
        self::assertSame(422, $hierarchy->update($request, $project->id)->getStatusCode());
        $this->deniedUser = $users[1]->id;
        self::assertSame(403, $hierarchy->update($request, $project->id)->getStatusCode());
        self::assertSame(403, $hierarchy->show($request, $project->id)->getStatusCode());
        $rules = (new \App\Http\Requests\ConstructionJournal\StoreJournalEntryRequest)->rules();
        $validator = \Illuminate\Support\Facades\Validator::make(['source_entry_id' => $source->id, 'source_entry_snapshot' => ['workVolumes' => []]], $rules);
        self::assertTrue($validator->fails());
        self::assertArrayHasKey('source_entry_id', $validator->errors()->toArray());
    }

    private function fixture(): array
    {
        $users = [];
        for ($index = 0; $index < 4; $index++) {
            $organization = Organization::factory()->create(['is_active' => true]);
            $user = User::factory()->create(['current_organization_id' => $organization->id]);
            $user->organizations()->attach($organization->id, ['is_active' => true, 'is_owner' => false, 'project_access_mode' => 'assigned_projects']);
            $users[] = $user;
        }
        $project = Project::factory()->create(['organization_id' => $users[1]->current_organization_id, 'is_archived' => false]);
        foreach ([0 => 'general_contractor', 2 => 'subcontractor', 3 => 'contractor'] as $index => $role) {
            $project->organizations()->attach($users[$index]->current_organization_id, ['is_active' => true, 'role' => 'contractor', 'role_new' => $role]);
        }
        foreach ($users as $user) {
            $project->users()->attach($user->id, ['is_active' => true]);
        }
        app(ProjectParticipantService::class)->saveHierarchy($project, (int) $users[0]->current_organization_id, [
            ['organization_id' => (int) $users[1]->current_organization_id, 'parent_organization_id' => (int) $users[0]->current_organization_id],
            ['organization_id' => (int) $users[2]->current_organization_id, 'parent_organization_id' => (int) $users[1]->current_organization_id],
            ['organization_id' => (int) $users[3]->current_organization_id, 'parent_organization_id' => (int) $users[0]->current_organization_id],
        ]);
        $contractor = Contractor::query()->create(['organization_id' => $project->organization_id, 'name' => 'Строй']);
        $contract = Contract::query()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'Тест-1', 'contract_side_type' => 'subcontract', 'requires_contract_side_review' => false, 'date' => '2026-10-11', 'subject' => 'Работы', 'total_amount' => 100000, 'status' => 'active']);
        $journals = [];
        foreach ($users as $index => $user) {
            $journals[] = ConstructionJournal::query()->create(['organization_id' => $project->organization_id, 'performing_organization_id' => $user->current_organization_id, 'project_id' => $project->id, 'contract_id' => $contract->id, 'name' => 'Журнал '.$index, 'journal_number' => (string) ($index + 1), 'start_date' => '2026-10-11', 'status' => 'active', 'created_by_user_id' => $user->id]);
        }
        $unit = MeasurementUnit::query()->where('organization_id', $project->organization_id)->where('short_name', 'м³')->firstOrFail();
        $source = app(ConstructionJournalService::class)->createEntry($journals[2], ['entry_date' => '2026-10-11', 'work_description' => 'Бетонирование', 'work_volumes' => [['work_name' => 'Бетонирование', 'measurement_unit_id' => $unit->id, 'quantity' => 3.5]], 'materials' => [['material_name' => 'Бетон', 'measurement_unit' => 'м³', 'quantity' => 3.5]], 'workers' => [['specialty' => 'Бетонщик', 'workers_count' => 2, 'hours_worked' => 16]], 'equipment' => [['equipment_name' => 'Бетононасос', 'quantity' => 1, 'hours_used' => 8]]], $users[2]);
        $source->update(['status' => 'approved', 'approved_by_user_id' => $users[1]->id, 'approved_at' => now()]);
        app(CompletedWorkFactService::class)->syncFromJournalEntry($source->fresh());
        return [$source->fresh(), $journals, $users, $project];
    }
}
