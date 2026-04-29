<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Tests\Support\ActingTestSchema;
use Tests\TestCase;

class ConstructionJournalEntryValidationTest extends TestCase
{
    use ActingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpActingSchema();
    }

    public function test_store_returns_validation_error_when_required_description_is_missing(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $journal = ConstructionJournal::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Журнал',
            'journal_number' => '1',
            'start_date' => '2026-04-01',
            'status' => JournalStatusEnum::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturn(true);
        });
        Gate::before(fn (): bool => true);

        $this->withoutMiddleware();
        $log = Log::spy();

        $this->actingAs($user, 'api_admin')
            ->postJson("/api/v1/admin/construction-journals/{$journal->id}/entries", [
                'entry_date' => '2026-04-28',
                'estimate_id' => 387,
                'work_description' => null,
                'workers' => [],
                'equipment' => [],
                'materials' => [],
                'work_volumes' => [
                    [
                        'estimate_item_id' => 218250,
                        'quantity' => 30,
                        'measurement_unit_id' => 5030,
                        'notes' => 'Позиция сметы: 5',
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['work_description'], 'errors');

        $log->shouldNotHaveReceived('error');
    }

    public function test_next_entry_number_ignores_default_entries_sorting(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $journal = ConstructionJournal::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Journal',
            'journal_number' => '1',
            'start_date' => '2026-04-01',
            'status' => JournalStatusEnum::ACTIVE,
        ]);

        ConstructionJournalEntry::create([
            'journal_id' => $journal->id,
            'entry_date' => '2026-04-28',
            'entry_number' => 1,
            'work_description' => 'First entry',
        ]);
        ConstructionJournalEntry::create([
            'journal_id' => $journal->id,
            'entry_date' => '2026-04-28',
            'entry_number' => 2,
            'work_description' => 'Second entry',
        ]);

        $this->assertSame(3, $journal->getNextEntryNumber());
    }
}
