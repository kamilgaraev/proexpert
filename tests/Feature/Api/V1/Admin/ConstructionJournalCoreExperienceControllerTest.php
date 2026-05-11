<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\JournalWorkVolume;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ConstructionJournalCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_journal_entries_through_submission_and_rejection(): void
    {
        Event::fake();
        Notification::fake();

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProjectJournal = $this->createJournal($context->organization, $anotherProject, $context->user, [
            'name' => 'Another project journal',
            'journal_number' => 'J-OTHER',
        ]);
        $this->allowAdminAccess();

        $createJournalResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/construction-journals", [
                'name' => 'Main construction journal',
                'journal_number' => 'J-001',
                'start_date' => '2026-06-01',
                'status' => 'active',
            ]);

        $createJournalResponse->assertCreated();
        $createJournalResponse->assertJsonPath('success', true);
        $createJournalResponse->assertJsonPath('data.project_id', $project->id);
        $createJournalResponse->assertJsonPath('data.organization_id', $context->organization->id);

        $journal = ConstructionJournal::query()->findOrFail($createJournalResponse->json('data.id'));
        $this->assertSame($project->id, $journal->project_id);
        $this->assertSame($context->organization->id, $journal->organization_id);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/construction-journals?per_page=20");

        $indexResponse->assertOk();
        $journalIds = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($journal->id, $journalIds);
        $this->assertNotContains($anotherProjectJournal->id, $journalIds);

        $entryResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/construction-journals/{$journal->id}/entries", [
                'entry_date' => '2026-06-03',
                'work_description' => 'Foundation preparation',
                'weather_conditions' => [
                    'temperature' => 18,
                    'precipitation' => 'none',
                    'wind_speed' => 2,
                ],
                'work_volumes' => [
                    [
                        'quantity' => 12.5,
                        'notes' => 'Axis A-B',
                    ],
                ],
                'workers' => [
                    [
                        'specialty' => 'Concrete worker',
                        'workers_count' => 4,
                        'hours_worked' => 8,
                    ],
                ],
            ]);

        $entryResponse->assertCreated();
        $entryResponse->assertJsonPath('data.journal_id', $journal->id);
        $entryResponse->assertJsonPath('data.entry_number', 1);
        $entryResponse->assertJsonPath('data.status', 'draft');
        $entryResponse->assertJsonPath('data.workVolumes.0.quantity', 12.5);
        $entryResponse->assertJsonPath('data.workers.0.workers_count', 4);

        $entry = ConstructionJournalEntry::query()->findOrFail($entryResponse->json('data.id'));
        $this->assertSame($context->user->id, $entry->created_by_user_id);
        $this->assertDatabaseHas('journal_work_volumes', [
            'journal_entry_id' => $entry->id,
            'quantity' => '12.500',
        ]);
        $this->assertDatabaseMissing('completed_works', [
            'journal_entry_id' => $entry->id,
        ]);

        $submitResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/journal-entries/{$entry->id}/submit");

        $submitResponse->assertOk();
        $submitResponse->assertJsonPath('data.status', 'submitted');
        $this->assertSame(JournalEntryStatusEnum::SUBMITTED, $entry->fresh()->status);

        $lockedUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/journal-entries/{$entry->id}", [
                'work_description' => 'Should not be saved while submitted',
            ]);

        $lockedUpdateResponse->assertForbidden();
        $this->assertSame('Foundation preparation', $entry->fresh()->work_description);

        $rejectResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/journal-entries/{$entry->id}/reject", [
                'reason' => 'Need additional measurements',
            ]);

        $rejectResponse->assertOk();
        $rejectResponse->assertJsonPath('data.status', 'rejected');
        $rejectResponse->assertJsonPath('data.rejection_reason', 'Need additional measurements');
        $this->assertSame($context->user->id, $entry->fresh()->approved_by_user_id);

        $reworkResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/journal-entries/{$entry->id}", [
                'work_description' => 'Foundation preparation with checked measurements',
                'work_volumes' => [
                    [
                        'id' => JournalWorkVolume::query()->where('journal_entry_id', $entry->id)->value('id'),
                        'quantity' => 14,
                        'notes' => 'Adjusted after measurement',
                    ],
                ],
            ]);

        $reworkResponse->assertOk();
        $reworkResponse->assertJsonPath('data.work_description', 'Foundation preparation with checked measurements');
        $reworkResponse->assertJsonPath('data.workVolumes.0.quantity', 14);
        $this->assertSame('Foundation preparation with checked measurements', $entry->fresh()->work_description);

        $entriesResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/construction-journals/{$journal->id}/entries?status=rejected");

        $entriesResponse->assertOk();
        $entriesResponse->assertJsonPath('summary.total_entries', 1);
        $entriesResponse->assertJsonPath('summary.rejected_entries', 1);
        $entryIds = collect($entriesResponse->json('data'))->pluck('id')->all();
        $this->assertSame([$entry->id], $entryIds);

        $foreignJournalShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/construction-journals/{$anotherProjectJournal->id}");

        $foreignJournalShowResponse->assertOk();
    }

    public function test_journal_rejects_foreign_contracts_and_foreign_entries_are_forbidden(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignContext = AdminApiTestContext::create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $foreignJournal = $this->createJournal($foreignContext->organization, $foreignProject, $foreignContext->user);
        $foreignEntry = $this->createEntry($foreignJournal, $foreignContext->user);
        $this->allowAdminAccess();

        $foreignEntryResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/journal-entries/{$foreignEntry->id}");

        $foreignEntryResponse->assertForbidden();

        $foreignContractResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/construction-journals", [
                'name' => 'Journal with invalid contract',
                'contract_id' => 999999,
                'start_date' => '2026-06-01',
            ]);

        $foreignContractResponse->assertStatus(422);
        $foreignContractResponse->assertJsonPath('success', false);
        $this->assertDatabaseMissing('construction_journals', [
            'project_id' => $project->id,
            'name' => 'Journal with invalid contract',
        ]);
    }

    private function createJournal(
        Organization $organization,
        Project $project,
        User $user,
        array $overrides = []
    ): ConstructionJournal {
        return ConstructionJournal::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Journal ' . random_int(1000, 9999),
            'journal_number' => 'J-' . random_int(1000, 9999),
            'start_date' => '2026-06-01',
            'status' => JournalStatusEnum::ACTIVE,
            'created_by_user_id' => $user->id,
        ], $overrides));
    }

    private function createEntry(ConstructionJournal $journal, User $user, array $overrides = []): ConstructionJournalEntry
    {
        return ConstructionJournalEntry::query()->create(array_merge([
            'journal_id' => $journal->id,
            'entry_date' => '2026-06-03',
            'entry_number' => 1,
            'work_description' => 'Existing entry',
            'status' => JournalEntryStatusEnum::DRAFT,
            'created_by_user_id' => $user->id,
        ], $overrides));
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
