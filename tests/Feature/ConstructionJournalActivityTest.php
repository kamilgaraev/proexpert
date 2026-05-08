<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalService;
use App\Enums\Activity\ActivityActionEnum;
use App\Models\Activity\ActivityEvent;
use App\Models\ConstructionJournal;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ConstructionJournalActivityTest extends TestCase
{
    public function test_journal_create_update_delete_records_activity_events(): void
    {
        [$organization, $project, $user] = $this->createFixture();
        Auth::login($user);

        $service = app(ConstructionJournalService::class);

        $journal = $service->createJournal($project, [
            'name' => 'Общий журнал работ',
            'journal_number' => 'ЖР-1',
            'start_date' => '2026-05-01',
            'status' => 'active',
        ], $user);

        $service->updateJournal($journal, ['name' => 'Общий журнал работ 2']);
        $service->deleteJournal($journal);

        $events = ActivityEvent::query()
            ->where('subject_type', 'construction_journal')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $events);
        $this->assertSame([
            'construction_journal.created',
            'construction_journal.updated',
            'construction_journal.deleted',
        ], $events->pluck('event_type')->all());
        $this->assertSame($organization->id, $events[0]->organization_id);
        $this->assertSame($project->id, $events[0]->project_id);
        $this->assertSame($user->id, $events[0]->actor_user_id);
        $this->assertSame(ActivityActionEnum::Created->value, $events[0]->action);
        $this->assertSame(ActivityActionEnum::Deleted->value, $events[2]->action);
        $this->assertSame('ЖР-1', $events[0]->subject_label);
    }

    public function test_journal_entry_create_update_delete_records_activity_events(): void
    {
        [$organization, $project, $user] = $this->createFixture();
        Auth::login($user);

        $journal = ConstructionJournal::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Общий журнал работ',
            'journal_number' => 'ЖР-1',
            'start_date' => '2026-05-01',
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);

        $service = app(ConstructionJournalService::class);
        $entry = $service->createEntry($journal, [
            'entry_date' => '2026-05-08',
            'entry_number' => 1,
            'work_description' => 'Монтаж опалубки',
            'status' => 'draft',
        ], $user);

        $service->updateEntry($entry, ['work_description' => 'Монтаж и проверка опалубки']);
        $service->deleteEntry($entry);

        $events = ActivityEvent::query()
            ->where('subject_type', 'construction_journal_entry')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $events);
        $this->assertSame([
            'construction_journal_entry.created',
            'construction_journal_entry.updated',
            'construction_journal_entry.deleted',
        ], $events->pluck('event_type')->all());
        $this->assertSame($organization->id, $events[0]->organization_id);
        $this->assertSame($project->id, $events[0]->project_id);
        $this->assertSame($user->id, $events[0]->actor_user_id);
        $this->assertSame(ActivityActionEnum::Created->value, $events[0]->action);
        $this->assertSame(ActivityActionEnum::Deleted->value, $events[2]->action);
        $this->assertSame('1', $events[0]->subject_label);
    }

    private function createFixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        return [$organization, $project, $user];
    }
}
