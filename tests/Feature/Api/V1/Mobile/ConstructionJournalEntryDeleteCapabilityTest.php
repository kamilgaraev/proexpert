<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\JournalEntryApprovalEvent;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ConstructionJournalEntryDeleteCapabilityTest extends TestCase
{
    public function test_mobile_delete_action_matches_the_draft_without_approval_history_guard(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'Тестовый журнал',
            'journal_number' => 'TEST-1',
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        $draft = $this->createEntry($journal, $context->user, JournalEntryStatusEnum::DRAFT, 1);
        $rejected = $this->createEntry($journal, $context->user, JournalEntryStatusEnum::REJECTED, 2);
        $draftWithHistory = $this->createEntry($journal, $context->user, JournalEntryStatusEnum::DRAFT, 3);

        JournalEntryApprovalEvent::query()->create([
            'journal_entry_id' => $draftWithHistory->id,
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'actor_user_id' => $context->user->id,
            'event' => 'rejected',
            'from_status' => 'submitted',
            'to_status' => 'rejected',
            'occurred_at' => now(),
        ]);

        $this->allowAccess();

        $draftResponse = $this->getJson('/api/v1/mobile/journal-entries/'.$draft->id, $context->mobileAuthHeaders())
            ->assertOk();
        self::assertContains('delete', collect($draftResponse->json('data.available_actions'))->pluck('action')->all());

        $rejectedResponse = $this->getJson('/api/v1/mobile/journal-entries/'.$rejected->id, $context->mobileAuthHeaders())
            ->assertOk();
        self::assertNotContains('delete', collect($rejectedResponse->json('data.available_actions'))->pluck('action')->all());

        $draftWithHistoryResponse = $this->getJson('/api/v1/mobile/journal-entries/'.$draftWithHistory->id, $context->mobileAuthHeaders())
            ->assertOk();
        self::assertNotContains('delete', collect($draftWithHistoryResponse->json('data.available_actions'))->pluck('action')->all());
    }

    private function createEntry(ConstructionJournal $journal, User $user, JournalEntryStatusEnum $status, int $entryNumber): ConstructionJournalEntry
    {
        return ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id,
            'entry_date' => now()->toDateString(),
            'entry_number' => $entryNumber,
            'work_description' => 'Проверка доступных действий',
            'status' => $status,
            'created_by_user_id' => $user->id,
        ]);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, fn (MockInterface $mock) => $mock->shouldReceive('hasModuleAccess')->andReturn(true));
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static fn (User $user, ?AuthorizationContext $authorizationContext = null) => $user->roleAssignments()->where('is_active', true)->get(),
            );
        });
    }
}
