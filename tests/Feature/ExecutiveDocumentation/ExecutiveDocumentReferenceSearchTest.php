<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Mockery\MockInterface;

final class ExecutiveDocumentReferenceSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_references_are_paginated_and_old_ids_remain_searchable(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $workType = WorkType::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Монтаж тестовый',
            'code' => 'reference-test',
            'is_active' => true,
        ]);

        $works = [];
        for ($i = 1; $i <= 151; $i++) {
            $works[] = CompletedWork::query()->create([
                'organization_id' => $context->organization->id,
                'project_id' => $project->id,
                'work_type_id' => $workType->id,
                'user_id' => $context->user->id,
                'quantity' => 1,
                'completion_date' => '2026-09-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'notes' => 'Работа reference',
                'status' => CompletedWork::STATUS_CONFIRMED,
            ]);
        }

        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'Журнал reference',
            'journal_number' => 'ЖР-REF',
            'start_date' => '2026-01-01',
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        $entries = [];
        for ($i = 1; $i <= 251; $i++) {
            $entries[] = ConstructionJournalEntry::query()->create([
                'journal_id' => $journal->id,
                'entry_date' => '2026-09-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'entry_number' => 100000 + $i,
                'work_description' => $i === 1 ? 'Старая запись основания ID-SEARCH' : 'Запись reference',
                'status' => 'draft',
                'created_by_user_id' => $context->user->id,
            ]);
        }

        $worksPage = $this->withHeaders($context->authHeaders())->getJson(
            '/api/v1/admin/executive-documentation/references/paginated?project_id='.$project->id.'&reference_type=completed_works&per_page=100&page=2'
        );
        $worksPage->assertOk()->assertJsonPath('meta.total', 151)->assertJsonPath('meta.current_page', 2);
        self::assertCount(51, $worksPage->json('data'));

        $entriesPage = $this->withHeaders($context->authHeaders())->getJson(
            '/api/v1/admin/executive-documentation/references/paginated?project_id='.$project->id.'&reference_type=journal_entries&per_page=100&page=3'
        );
        $entriesPage->assertOk()->assertJsonPath('meta.total', 251)->assertJsonPath('meta.current_page', 3);
        self::assertCount(51, $entriesPage->json('data'));

        $oldWork = $works[0];
        $oldWorkSearch = $this->withHeaders($context->authHeaders())->getJson(
            '/api/v1/admin/executive-documentation/references/paginated?project_id='.$project->id.'&reference_type=completed_works&search='.$oldWork->id
        );
        $oldWorkSearch->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $oldWork->id);
        $this->assertSearchPageMatchesTotal($oldWorkSearch);
        $oldWorkSearch->assertJsonStructure(['data' => [['hidden_work_act_defaults' => ['profile_data', 'metadata']]]]);

        $oldEntry = $entries[0];
        $oldEntrySearch = $this->withHeaders($context->authHeaders())->getJson(
            '/api/v1/admin/executive-documentation/references/paginated?project_id='.$project->id.'&reference_type=journal_entries&search='.$oldEntry->id
        );
        $oldEntrySearch->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $oldEntry->id);
        $this->assertSearchPageMatchesTotal($oldEntrySearch);
        $oldEntrySearch->assertJsonStructure(['data' => [['hidden_work_act_defaults' => ['profile_data', 'metadata']]]]);

        $uniqueEntrySearch = $this->withHeaders($context->authHeaders())->getJson(
            '/api/v1/admin/executive-documentation/references/paginated?project_id='.$project->id.'&reference_type=journal_entries&search=ID-SEARCH'
        );
        $uniqueEntrySearch->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $oldEntry->id);
        $this->assertSearchPageMatchesTotal($uniqueEntrySearch);
    }

    public function test_foreign_project_references_are_not_visible(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignOrganization = \App\Models\Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignUser = User::factory()->create();
        $workType = WorkType::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Чужая работа',
            'code' => 'foreign-reference',
            'is_active' => true,
        ]);
        $foreignWork = CompletedWork::query()->create([
            'organization_id' => $foreignOrganization->id,
            'project_id' => $foreignProject->id,
            'work_type_id' => $workType->id,
            'user_id' => $foreignUser->id,
            'quantity' => 10,
            'completion_date' => '2026-09-20',
            'notes' => 'Чужая скрытая работа',
            'status' => CompletedWork::STATUS_CONFIRMED,
        ]);

        $response = $this->withHeaders($context->authHeaders())->getJson(
            '/api/v1/admin/executive-documentation/references/paginated?project_id='.$project->id.'&reference_type=completed_works&search='.$foreignWork->id
        );
        $response->assertOk()->assertJsonPath('meta.total', 0);
        self::assertSame([], $response->json('data'));

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())->getJson(
            '/api/v1/admin/executive-documentation/references/paginated?project_id='.$foreignProject->id.'&reference_type=completed_works'
        );
        $foreignProjectResponse->assertStatus(404)->assertJsonPath('data', null);
    }

    private function assertSearchPageMatchesTotal(\Illuminate\Testing\TestResponse $response): void
    {
        $ids = collect($response->json('data'))->pluck('id')->all();
        self::assertSame($response->json('meta.total'), count($ids));
        self::assertCount(count(array_unique($ids)), $ids);
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
    }

    public function test_project_assignment_restricts_reference_search_inside_the_same_organization(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'assigned_projects']);
        $allowed = Project::factory()->create(['organization_id' => $context->organization->id]);
        $hidden = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->assignedProjects()->attach($allowed->id, ['role' => 'member', 'is_active' => true]);
        $url = '/api/v1/admin/executive-documentation/references/paginated?reference_type=completed_works&project_id=';
        $this->withHeaders($context->authHeaders())->getJson($url.$allowed->id)->assertOk();
        $this->withHeaders($context->authHeaders())->getJson($url.$hidden->id)->assertNotFound();
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
