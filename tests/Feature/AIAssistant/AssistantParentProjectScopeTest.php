<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantParentProjectScopeTest extends TestCase
{
    public function test_parent_project_search_filters_before_count_and_limit_and_rechecks_parent_relocation(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $journal = fn (Project $p, string $number) => Model::withoutEvents(fn () => ConstructionJournal::query()->create([
            'organization_id' => $p->organization_id, 'performing_organization_id' => $p->organization_id, 'project_id' => $p->id,
            'name' => $number, 'journal_number' => $number, 'start_date' => '2026-10-01', 'status' => 'active', 'created_by_user_id' => $fixture->owner->id]));
        $otherJournal = $journal($other, 'OTHER'); $ownJournal = $journal($project, 'OWN');
        Model::withoutEvents(function () use ($fixture, $otherJournal, $ownJournal): void {
            ConstructionJournalEntry::query()->create(['journal_id' => $otherJournal->id, 'entry_date' => '2026-10-01', 'entry_number' => 1,
                'work_description' => 'Другой проект', 'status' => 'draft', 'created_by_user_id' => $fixture->owner->id]);
            foreach (range(1, 3) as $i) {
                ConstructionJournalEntry::query()->create(['journal_id' => $ownJournal->id, 'entry_date' => '2026-10-01', 'entry_number' => $i,
                    'work_description' => 'Работа '.$i, 'status' => 'draft', 'created_by_user_id' => $fixture->owner->id]);
            }
        });
        $service = app(AssistantDomainReadService::class); $guard = app(AssistantSourceReferenceGuard::class); $policy = app(AssistantDataAccessPolicy::class);
        $arguments = ['domain' => 'core_journals', 'entity_type' => 'construction_journal_entry', 'project_id' => $project->id,
            'query' => '', 'limit' => 1, 'fields' => ['work_description', 'status']];
        $result = $service->execute('search', $arguments, $fixture->owner, $fixture->organization->id);
        self::assertCount(1, $result['results']); self::assertSame(3, $result['result_window']['total']);
        $refs = array_values(array_filter($result['source_refs'], static fn (array $ref): bool => $ref['entity_type'] === 'construction_journal_entry'));
        self::assertSame($project->id, $refs[0]['project_id']); self::assertTrue($guard->fresh($fixture->owner, $fixture->organization->id, $refs));
        $ownJournal->updateQuietly(['project_id' => $other->id]);
        self::assertFalse($guard->fresh($fixture->owner, $fixture->organization->id, $refs));
        $tampered = $refs; $tampered[0]['machinery_project_scope'] = 0;
        self::assertFalse($guard->fresh($fixture->owner, $fixture->organization->id, $tampered));
        self::assertSame(0, $service->execute('search', $arguments, $fixture->owner, $fixture->organization->id)['result_window']['total']);
        $ownJournal->updateQuietly(['project_id' => $project->id]);
        $member = $fixture->addMember(['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
            'construction-journal' => ['construction-journal.view'], 'budget-estimates' => ['budget-estimates.view']]);
        $member->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        self::assertSame(3, $service->execute('search', $arguments, $member, $fixture->organization->id)['result_window']['total']);
        try { $service->execute('search', $arguments + [], $fixture->foreignOwner, $fixture->organization->id); self::fail('Foreign organization must be denied'); }
        catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) { self::assertTrue(true); }
        $scope = Model::withoutEvents(fn () => \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'created_by' => $fixture->owner->id, 'title' => 'Зона', 'status' => 'in_progress']));
        $checklist = Model::withoutEvents(fn () => \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklist::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'acceptance_scope_id' => $scope->id, 'title' => 'Проверка', 'status' => 'planned']));
        $item = Model::withoutEvents(fn () => \App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklistItem::query()->create([
            'acceptance_checklist_id' => $checklist->id, 'title' => 'Проверить', 'status' => 'pending', 'is_required' => true]));
        $checkArguments = ['domain' => 'handover_acceptance', 'entity_type' => 'acceptance_checklist_item', 'project_id' => $project->id, 'query' => '', 'fields' => ['title', 'status']];
        $check = $service->execute('search', $checkArguments, $fixture->owner, $fixture->organization->id);
        self::assertSame($item->id, $check['results'][0]['id']); self::assertSame($project->id, $check['source_refs'][0]['project_id']);
        self::assertTrue($guard->fresh($fixture->owner, $fixture->organization->id, $check['source_refs']));
        $checklist->updateQuietly(['project_id' => $other->id]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, 'acceptance_checklist_item', $item->id));
        self::assertFalse($guard->fresh($fixture->owner, $fixture->organization->id, $check['source_refs']));
        $order = fn (Project $p, string $number) => Model::withoutEvents(fn () => \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder::query()->create([
            'organization_id' => $p->organization_id, 'project_id' => $p->id, 'created_by_user_id' => $fixture->owner->id,
            'order_number' => $number, 'title' => 'Наряд', 'status' => 'submitted']));
        $ownOrder = $order($project, 'OWN'); $otherOrder = $order($other, 'OTHER');
        $sheet = Model::withoutEvents(fn () => \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheet::query()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'work_order_id' => $ownOrder->id,
            'created_by_user_id' => $fixture->owner->id, 'shift_date' => '2026-10-01', 'status' => 'submitted']));
        $line = fn ($order) => Model::withoutEvents(fn () => \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrderLine::query()->create([
            'organization_id' => $project->organization_id, 'work_order_id' => $order->id, 'name' => 'Работа', 'unit' => 'шт', 'planned_quantity' => '10.00']));
        $ownLine = $line($ownOrder); $otherLine = $line($otherOrder);
        $entry = Model::withoutEvents(fn () => \App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborTimesheetEntry::query()->create([
            'organization_id' => $project->organization_id, 'timesheet_id' => $sheet->id, 'work_order_line_id' => $ownLine->id, 'worker_name' => 'Работник', 'hours' => '8.00']));
        $timeArguments = ['domain' => 'production_labor', 'entity_type' => 'production_labor_timesheet_entry', 'project_id' => $project->id, 'query' => '', 'fields' => ['hours']];
        $time = $service->execute('search', $timeArguments, $fixture->owner, $fixture->organization->id);
        self::assertSame($entry->id, $time['results'][0]['id']); self::assertSame($project->id, $time['source_refs'][0]['project_id']);
        self::assertTrue($guard->fresh($fixture->owner, $fixture->organization->id, $time['source_refs']));
        $entry->updateQuietly(['work_order_line_id' => $otherLine->id]);
        self::assertSame(0, $service->execute('search', $timeArguments, $fixture->owner, $fixture->organization->id)['result_window']['total']);
        self::assertFalse($guard->fresh($fixture->owner, $fixture->organization->id, $time['source_refs']));
        $entry->updateQuietly(['work_order_line_id' => $ownLine->id]);
        self::assertSame(1, $service->execute('search', $timeArguments, $fixture->owner, $fixture->organization->id)['result_window']['total']);
        $fixture->ownerAssignment->updateQuietly(['is_active' => false]);
        self::assertFalse($guard->fresh($fixture->owner, $fixture->organization->id, $time['source_refs']));
    }
}
